<?php

namespace App\Http\Controllers\Api;

use App\Enums\DonationCategory;
use App\Enums\DonationLocation;
use App\Enums\DonationResponseStatus;
use App\Enums\DonationStatus;
use App\Enums\DonationType;
use App\Http\Controllers\Controller;
use App\Http\Resources\DonationResponseResource;
use App\Models\Donation;
use App\Models\DonationResponse;
use App\Notifications\DomainNotification;
use App\Services\DonationMatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DonationResponseController extends Controller
{
    public function mine(Request $request)
    {
        abort_unless($request->user()->client, 403, 'Client profile required.');

        return DonationResponseResource::collection(
            DonationResponse::with(['requestDonation.client.user', 'responder.user'])
                ->where('responder_client_id', $request->user()->client->id)
                ->latest()
                ->paginate(min((int) $request->input('per_page', 15), 100))
        );
    }

    public function store(Request $request, Donation $donation): DonationResponseResource
    {
        $validated = $request->validate([
            'additional_note' => ['nullable', 'string', 'max:2000'],
            'location' => ['required', Rule::enum(DonationLocation::class)],
        ]);
        $client = $request->user()->client;
        abort_unless($client, 403, 'Client profile required.');

        $response = DB::transaction(function () use ($donation, $client, $validated) {
            $lockedDonation = Donation::lockForUpdate()->findOrFail($donation->id);

            if ($lockedDonation->type !== DonationType::Request) {
                throw ValidationException::withMessages([
                    'donation' => 'Help can only be offered for a request.',
                ]);
            }
            if ($lockedDonation->status !== DonationStatus::Pending) {
                throw ValidationException::withMessages([
                    'donation' => 'This request is no longer available.',
                ]);
            }
            if ($lockedDonation->client_id === $client->id) {
                throw ValidationException::withMessages([
                    'donation' => 'You cannot offer help on your own request.',
                ]);
            }

            $alreadyOffered = DonationResponse::where(
                'request_donation_id',
                $lockedDonation->id
            )->where('responder_client_id', $client->id)->exists();
            if ($alreadyOffered) {
                throw ValidationException::withMessages([
                    'donation' => 'Help has already been offered for this request.',
                ]);
            }

            $response = DonationResponse::create([
                'request_donation_id' => $lockedDonation->id,
                'responder_client_id' => $client->id,
                'additional_note' => $validated['additional_note'] ?? null,
                'location' => $validated['location'],
                'status' => DonationResponseStatus::Pending,
            ]);

            $lockedDonation->update([
                'status' => DonationStatus::AwaitingReview,
            ]);

            return $response;
        });

        return new DonationResponseResource(
            $response->load(['requestDonation.client.user', 'responder.user'])
        );
    }

    public function teamIndex(Request $request)
    {
        return DonationResponseResource::collection(
            DonationResponse::with(['requestDonation.client.user', 'responder.user'])
                ->latest()
                ->paginate(min((int) $request->input('per_page', 15), 100))
        );
    }

    public function accept(
        Request $request,
        DonationResponse $donationResponse,
        DonationMatchingService $matchingService
    ): DonationResponseResource {
        $validated = $request->validate([
            'matched_quantity' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $response = DB::transaction(function () use (
            $request,
            $donationResponse,
            $matchingService,
            $validated
        ) {
            $lockedResponse = DonationResponse::lockForUpdate()
                ->findOrFail($donationResponse->id);
            if ($lockedResponse->status !== DonationResponseStatus::Pending) {
                throw ValidationException::withMessages([
                    'response' => 'Only a pending response can be accepted.',
                ]);
            }

            $requestDonation = Donation::lockForUpdate()->findOrFail(
                $lockedResponse->request_donation_id
            );
            if (! in_array($requestDonation->status, [
                DonationStatus::AwaitingReview,
                DonationStatus::Pending,
            ], true)) {
                throw ValidationException::withMessages([
                    'request' => 'This request is no longer awaiting review.',
                ]);
            }

            $quantityKey = $this->quantityKey($requestDonation->category);
            $matchedQuantity = $quantityKey === null
                ? 1
                : ($validated['matched_quantity'] ?? null);
            $this->validateMatchedQuantity(
                $requestDonation,
                $quantityKey,
                $matchedQuantity
            );

            $offerDetails = $requestDonation->details;
            if ($quantityKey !== null) {
                $offerDetails[$quantityKey] = $requestDonation->category === DonationCategory::Money
                    ? number_format((float) $matchedQuantity, 2, '.', '')
                    : (int) $matchedQuantity;
            }

            $offer = Donation::create([
                'client_id' => $lockedResponse->responder_client_id,
                'type' => DonationType::Donation,
                'category' => $requestDonation->category,
                'additional_note' => $lockedResponse->additional_note,
                'location' => $lockedResponse->location ?? $requestDonation->location,
                'details' => $offerDetails,
                'status' => DonationStatus::Pending,
            ]);

            // The matching operation requires an available request. This
            // temporary transition is contained by the outer transaction.
            $requestDonation->update(['status' => DonationStatus::Pending]);
            $match = $matchingService->match(
                $requestDonation,
                $offer,
                null,
                $lockedResponse->id
            );
            $matchingService->accept(
                $match,
                $request->user()->donationTeam->id,
                $lockedResponse->id
            );

            $lockedResponse->update([
                'status' => DonationResponseStatus::Accepted,
                'accepted_at' => now(),
            ]);

            return $lockedResponse;
        });

        $response->loadMissing([
            'responder.user',
            'requestDonation.client.user',
        ]);
        foreach ([
            $response->responder->user,
            $response->requestDonation->client->user,
        ] as $user) {
            $user->notify(new DomainNotification([
                'event' => 'donation_match_accepted',
                'donation_response_id' => $response->id,
                'request_donation_id' => $response->request_donation_id,
            ]));
        }

        return new DonationResponseResource(
            $response->load(['requestDonation.client.user', 'responder.user'])
        );
    }

    public function reject(
        DonationResponse $donationResponse,
        DonationMatchingService $matchingService
    ): DonationResponseResource
    {
        $requestToRematch = null;
        $response = DB::transaction(function () use (
            $donationResponse,
            &$requestToRematch
        ) {
            $lockedResponse = DonationResponse::lockForUpdate()
                ->findOrFail($donationResponse->id);
            if ($lockedResponse->status !== DonationResponseStatus::Pending) {
                throw ValidationException::withMessages([
                    'response' => 'Only a pending response can be rejected.',
                ]);
            }

            $lockedResponse->update([
                'status' => DonationResponseStatus::Rejected,
                'rejected_at' => now(),
            ]);

            $requestDonation = Donation::lockForUpdate()->findOrFail(
                $lockedResponse->request_donation_id
            );
            if ($requestDonation->status === DonationStatus::AwaitingReview) {
                $requestDonation->update(['status' => DonationStatus::Pending]);
                $requestToRematch = $requestDonation;
            }

            return $lockedResponse;
        });

        if ($requestToRematch) {
            $matchingService->autoMatch($requestToRematch->fresh());
        }

        $response->loadMissing('responder.user');
        $response->responder->user->notify(new DomainNotification([
            'event' => 'help_offer_rejected',
            'donation_response_id' => $response->id,
            'request_donation_id' => $response->request_donation_id,
        ]));

        return new DonationResponseResource(
            $response->load(['requestDonation.client.user', 'responder.user'])
        );
    }

    private function quantityKey(DonationCategory $category): ?string
    {
        return match ($category) {
            DonationCategory::Blood => 'units',
            DonationCategory::Money => 'amount',
            DonationCategory::Clothes,
            DonationCategory::Food,
            DonationCategory::Medicine => 'quantity',
            DonationCategory::Other => null,
        };
    }

    private function validateMatchedQuantity(
        Donation $requestDonation,
        ?string $quantityKey,
        mixed $matchedQuantity
    ): void {
        if ($quantityKey === null) {
            return;
        }

        $remaining = $requestDonation->details[$quantityKey] ?? null;
        if (! is_numeric($matchedQuantity) || (float) $matchedQuantity <= 0) {
            throw ValidationException::withMessages([
                'matched_quantity' => 'Enter a positive matched quantity.',
            ]);
        }
        if (! is_numeric($remaining) || (float) $matchedQuantity > (float) $remaining) {
            throw ValidationException::withMessages([
                'matched_quantity' => 'Matched quantity cannot exceed the remaining request.',
            ]);
        }
        if (
            $requestDonation->category !== DonationCategory::Money
            && filter_var($matchedQuantity, FILTER_VALIDATE_INT) === false
        ) {
            throw ValidationException::withMessages([
                'matched_quantity' => 'Matched quantity must be a whole number.',
            ]);
        }
    }
}
