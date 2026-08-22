<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Admin;
use App\Models\Donation;
use App\Models\DonationResponse;
use App\Models\DonationTeam;
use App\Models\SosRequest;
use App\Models\SosTeam;
use App\Models\User;
use App\Notifications\DomainNotification;
use App\Services\DonationExpirationService;
use App\Services\DonationMatchingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_only_sees_and_reads_admin_relevant_notifications(): void
    {
        $admin = Admin::factory()->create();
        $admin->user->notify(new DomainNotification([
            'event' => 'contact_message',
            'message' => 'A client contacted the administration.',
        ]));
        $admin->user->notify(new DomainNotification([
            'event' => 'donation_match_accepted',
            'matched_donation_id' => 1,
        ]));

        Sanctum::actingAs($admin->user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data.event', 'contact_message');

        $hiddenNotification = $admin->user->notifications()
            ->where('data->event', 'donation_match_accepted')
            ->firstOrFail();
        $this->patchJson("/api/notifications/{$hiddenNotification->id}/read")
            ->assertNotFound();

        $this->patchJson('/api/notifications/read-all')->assertNoContent();

        $this->assertNotNull(
            $admin->user->notifications()
                ->where('data->event', 'contact_message')
                ->firstOrFail()
                ->read_at
        );
        $this->assertNull($hiddenNotification->fresh()->read_at);
    }

    public function test_admin_can_create_deactivate_edit_and_delete_team_accounts(): void
    {
        $admin = Admin::factory()->create();
        Sanctum::actingAs($admin->user);

        $response = $this->postJson('/api/admin/teams', [
            'first_name' => 'Beirut',
            'last_name' => 'SOS',
            'email' => 'beirut-sos@example.com',
            'phone_number' => '+96170000100',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'sos_team',
            'service_area' => 'beirut',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'sos_team')
            ->assertJsonPath('data.is_active', true);

        $user = User::findOrFail($response->json('data.id'));

        $this->patchJson("/api/admin/sos-teams/{$user->sosTeam->id}", [
            'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->putJson("/api/admin/accounts/{$user->id}", [
            'service_area' => 'mount_lebanon',
        ])->assertOk()->assertJsonPath('data.service_area', 'mount_lebanon');

        $this->deleteJson("/api/admin/accounts/{$user->id}")->assertNoContent();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertDatabaseHas('sos_teams', ['id' => $user->sosTeam->id]);
    }

    public function test_normal_users_cannot_be_activated_or_deactivated(): void
    {
        $admin = Admin::factory()->create();
        $normalUser = User::factory()->create();
        Sanctum::actingAs($admin->user);

        $this->putJson("/api/admin/accounts/{$normalUser->id}", [
            'is_active' => false,
        ])->assertOk();

        $this->assertFalse(Schema::hasColumn('users', 'is_active'));
    }

    public function test_user_can_register_login_and_manage_profile(): void
    {
        $registration = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '+96171111111',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $this->postJson('/api/register', $registration)
            ->assertCreated()
            ->assertJsonPath('user.first_name', 'Jane')
            ->assertJsonStructure(['token']);

        $this->postJson('/api/login', [
            'email' => $registration['email'],
            'password' => $registration['password'],
        ])->assertOk()->assertJsonStructure(['token']);

        $user = User::first();
        Sanctum::actingAs($user);

        $this->postJson('/api/client-profile', [
            'date_of_birth' => '1990-01-01',
            'blood_type' => 'A+',
            'emergency_contact_number' => '+96172222222',
            'emergency_contact_relation' => 'sister',
        ])->assertCreated()->assertJsonPath('data.blood_type', 'A+');

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonMissingPath('data.password');

        $this->postJson('/api/verify-password', ['password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->putJson('/api/me', [
            'first_name' => 'Janet',
            'last_name' => 'Doe',
            'phone_number' => '+96173333333',
        ])->assertOk()
            ->assertJsonPath('data.first_name', 'Janet')
            ->assertJsonPath('data.phone_number', '+96173333333');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'first_name' => 'Janet',
            'phone_number' => '+96173333333',
        ]);
    }

    public function test_inactive_team_is_forbidden_but_can_authenticate(): void
    {
        $team = SosTeam::factory()->create(['is_active' => false]);

        $this->postJson('/api/login', [
            'email' => $team->user->email,
            'password' => 'password',
        ])->assertOk();

        Sanctum::actingAs($team->user);

        $this->getJson('/api/sos-team/requests/pending')->assertForbidden();
    }

    public function test_sos_acceptance_is_transactional_and_calculates_distance(): void
    {
        $sosRequest = SosRequest::factory()->create();
        $team = SosTeam::factory()->create();
        Sanctum::actingAs($team->user);

        $this->postJson("/api/sos-team/requests/{$sosRequest->id}/accept", [
            'service_name' => 'AUBMC',
            'service_latitude' => 33.9,
            'service_longitude' => 35.48,
        ])->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonStructure([
                'data' => ['distance_km', 'eta_minutes'],
            ]);

        $this->assertDatabaseHas('sos_requests', [
            'id' => $sosRequest->id,
            'accepted_by_sos_team_id' => $team->id,
            'status' => 'accepted',
        ]);

        $this->assertFalse(Schema::hasColumn('sos_requests', 'distance_km'));
        $this->assertFalse(Schema::hasColumn('sos_requests', 'eta_minutes'));
    }

    public function test_rejection_restores_pending_and_preserves_pair(): void
    {
        $requestClient = Client::factory()->create();
        $offerClient = Client::factory()->create();

        $requestDonation = Donation::factory()->create([
            'client_id' => $requestClient->id,
        ]);
        $offeredDonation = Donation::factory()->create([
            'client_id' => $offerClient->id,
            'type' => 'donation',
        ]);

        $matchingService = app(DonationMatchingService::class);
        $match = $matchingService->match($requestDonation, $offeredDonation);

        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);

        $this->postJson("/api/donation-team/matches/{$match->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('donations', [
            'id' => $requestDonation->id,
            'status' => 'pending',
        ]);

        $this->expectException(ValidationException::class);

        $matchingService->match(
            $requestDonation->fresh(),
            $offeredDonation->fresh()
        );
    }

    public function test_accepting_partial_match_keeps_larger_offer_pending(): void
    {
        $requestDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'category' => 'food',
            'details' => ['food_type' => 'rice', 'quantity' => 2],
        ]);
        $offeredDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'type' => 'donation',
            'category' => 'food',
            'details' => ['food_type' => 'rice', 'quantity' => 4],
        ]);

        $match = app(DonationMatchingService::class)->match(
            $requestDonation,
            $offeredDonation
        );
        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);

        $this->postJson("/api/donation-team/matches/{$match->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.matched_quantity', '2.00');

        $this->assertSame('accepted', $requestDonation->fresh()->status->value);
        $this->assertSame(0, $requestDonation->fresh()->details['quantity']);
        $this->assertSame('pending', $offeredDonation->fresh()->status->value);
        $this->assertSame(2, $offeredDonation->fresh()->details['quantity']);
    }

    public function test_accepting_partial_match_keeps_larger_request_pending(): void
    {
        $requestDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'category' => 'clothes',
            'details' => [
                'clothing_type' => 'T-shirt',
                'gender' => 'Any',
                'size' => 'M',
                'quantity' => 4,
            ],
        ]);
        $offeredDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'type' => 'donation',
            'category' => 'clothes',
            'details' => [
                'clothing_type' => 'T-shirt',
                'gender' => 'Any',
                'size' => 'M',
                'quantity' => 2,
            ],
        ]);

        $match = app(DonationMatchingService::class)->match(
            $requestDonation,
            $offeredDonation
        );
        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);

        $this->postJson("/api/donation-team/matches/{$match->id}/accept")
            ->assertOk();

        $this->assertSame('pending', $requestDonation->fresh()->status->value);
        $this->assertSame(2, $requestDonation->fresh()->details['quantity']);
        $this->assertSame('accepted', $offeredDonation->fresh()->status->value);
        $this->assertSame(0, $offeredDonation->fresh()->details['quantity']);
    }

    public function test_accepting_equal_quantities_accepts_both_donations(): void
    {
        $requestDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'details' => ['blood_type' => 'O+', 'units' => 3],
        ]);
        $offeredDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'type' => 'donation',
            'details' => ['blood_type' => 'O+', 'units' => 3],
        ]);

        $match = app(DonationMatchingService::class)->match(
            $requestDonation,
            $offeredDonation
        );
        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);

        $this->postJson("/api/donation-team/matches/{$match->id}/accept")
            ->assertOk();

        $this->assertSame('accepted', $requestDonation->fresh()->status->value);
        $this->assertSame('accepted', $offeredDonation->fresh()->status->value);
    }

    public function test_fulfilling_request_closes_other_pending_help_responses(): void
    {
        $requestDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'details' => ['blood_type' => 'O+', 'units' => 2],
        ]);
        $offeredDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'type' => 'donation',
            'details' => ['blood_type' => 'O+', 'units' => 2],
        ]);
        $helper = Client::factory()->create();
        $response = DonationResponse::factory()->create([
            'request_donation_id' => $requestDonation->id,
            'responder_client_id' => $helper->id,
        ]);

        $match = app(DonationMatchingService::class)->match(
            $requestDonation,
            $offeredDonation
        );
        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);

        $this->postJson("/api/donation-team/matches/{$match->id}/accept")
            ->assertOk();

        $this->assertSame('rejected', $response->fresh()->status->value);
        $this->assertNotNull($response->fresh()->rejected_at);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $helper->user->id,
            'data->event' => 'help_offer_closed',
        ]);
    }

    public function test_partial_match_keeps_help_responses_pending(): void
    {
        $requestDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'category' => 'food',
            'details' => ['food_type' => 'rice', 'quantity' => 4],
        ]);
        $offeredDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'type' => 'donation',
            'category' => 'food',
            'details' => ['food_type' => 'rice', 'quantity' => 2],
        ]);
        $response = DonationResponse::factory()->create([
            'request_donation_id' => $requestDonation->id,
            'responder_client_id' => Client::factory(),
        ]);

        $match = app(DonationMatchingService::class)->match(
            $requestDonation,
            $offeredDonation
        );
        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);

        $this->postJson("/api/donation-team/matches/{$match->id}/accept")
            ->assertOk();

        $this->assertSame('pending', $response->fresh()->status->value);
    }

    public function test_help_offer_does_not_create_match_until_team_accepts_it(): void
    {
        $owner = Client::factory()->create();
        $helper = Client::factory()->create();
        $requestDonation = Donation::factory()->create([
            'client_id' => $owner->id,
            'category' => 'food',
            'details' => ['food_type' => 'rice', 'quantity' => 4],
        ]);

        Sanctum::actingAs($helper->user);
        $responseId = $this->postJson(
            "/api/donations/{$requestDonation->id}/responses",
              [
                  'additional_note' => 'I can deliver tomorrow.',
                  'location' => 'tripoli',
              ]
          )->assertOk()
              ->assertJsonPath('data.status', 'pending')
              ->assertJsonPath('data.location', 'tripoli')
            ->json('data.id');

        $this->assertDatabaseCount('matched_donations', 0);

        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);
        $this->postJson(
            "/api/donation-team/responses/{$responseId}/accept",
            ['matched_quantity' => 2]
        )->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('matched_donations', [
            'request_donation_id' => $requestDonation->id,
            'matched_quantity' => 2,
            'status' => 'accepted',
        ]);
        $this->assertSame(2, $requestDonation->fresh()->details['quantity']);
        $this->assertSame('pending', $requestDonation->fresh()->status->value);
    }

    public function test_helper_cannot_offer_help_twice_for_the_same_request(): void
    {
        $owner = Client::factory()->create();
        $helper = Client::factory()->create();
        $requestDonation = Donation::factory()->create([
            'client_id' => $owner->id,
        ]);
        DonationResponse::factory()->create([
            'request_donation_id' => $requestDonation->id,
            'responder_client_id' => $helper->id,
        ]);

        Sanctum::actingAs($helper->user);
        $this->postJson(
            "/api/donations/{$requestDonation->id}/responses",
            ['location' => 'beirut']
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('donation');
    }

    public function test_rejecting_help_offer_does_not_change_request_quantity(): void
    {
        $requestDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'details' => ['blood_type' => 'O+', 'units' => 4],
        ]);
        $response = DonationResponse::factory()->create([
            'request_donation_id' => $requestDonation->id,
            'responder_client_id' => Client::factory(),
        ]);

        $team = DonationTeam::factory()->create();
        Sanctum::actingAs($team->user);
        $this->postJson("/api/donation-team/responses/{$response->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertSame(4, $requestDonation->fresh()->details['units']);
        $this->assertDatabaseCount('matched_donations', 0);
    }

    public function test_expired_offer_is_failed_and_unfinished_match_is_released(): void
    {
        $requestDonation = Donation::factory()->create([
            'client_id' => Client::factory(),
            'category' => 'food',
            'details' => ['food_type' => 'rice', 'quantity' => 2],
        ]);
        $offerClient = Client::factory()->create();
        $offeredDonation = Donation::factory()->create([
            'client_id' => $offerClient->id,
            'type' => 'donation',
            'category' => 'food',
            'details' => [
                'food_type' => 'rice',
                'quantity' => 2,
                'expiration_date' => CarbonImmutable::tomorrow('Asia/Beirut')->format('Y-m-d'),
            ],
        ]);
        $match = app(DonationMatchingService::class)->match(
            $requestDonation,
            $offeredDonation
        );
        $offeredDonation->update([
            'details' => [
                'food_type' => 'rice',
                'quantity' => 2,
                'expiration_date' => CarbonImmutable::yesterday('Asia/Beirut')->format('Y-m-d'),
            ],
        ]);

        $this->assertTrue(
            app(DonationExpirationService::class)->expire($offeredDonation->fresh())
        );

        $this->assertSame('expired', $offeredDonation->fresh()->status->value);
        $this->assertSame('pending', $requestDonation->fresh()->status->value);
        $this->assertSame('rejected', $match->fresh()->status->value);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $offerClient->user->id,
            'data->event' => 'donation_expired',
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $requestDonation->client->user->id,
            'data->event' => 'donation_match_expired',
        ]);
    }

    public function test_expired_offer_date_is_rejected_when_created(): void
    {
        $client = Client::factory()->create();
        Sanctum::actingAs($client->user);

        $this->postJson('/api/donations', [
            'type' => 'donation',
            'category' => 'medicine',
            'location' => 'beirut',
            'details' => [
                'medicine_name' => 'Pain relief',
                'dose' => '500 mg',
                'quantity' => 1,
                'expiration_date' => CarbonImmutable::yesterday('Asia/Beirut')->format('Y-m-d'),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('details.expiration_date');
    }

    public function test_donation_detail_rules(): void
    {
        $user = User::factory()->create();
        Client::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson('/api/donations', [
            'type' => 'request',
            'category' => 'food',
            'location' => 'beirut',
            'details' => [
                'food_type' => 'rice',
                'quantity' => 2,
                'expiration_date' => '2027-01-01',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('details.expiration_date');

        $this->postJson('/api/donations', [
            'type' => 'donation',
            'category' => 'clothes',
            'location' => 'beirut',
            'details' => [
                'clothing_type' => 'shirt',
                'gender' => 'unisex',
                'size' => 'M',
                'quantity' => 2,
                'condition' => 'new',
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('details.condition');
    }

    public function test_team_member_must_change_admin_issued_password_once(): void
    {
        $team = SosTeam::factory()->create();
        $team->user->update([
            'password' => 'temporary123',
            'must_change_password' => true,
        ]);
        Sanctum::actingAs($team->user);

        $this->getJson('/api/sos-team/requests/pending')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'You must change your temporary password before continuing.'
            );

        $this->putJson('/api/initial-password', [
            'current_password' => 'temporary123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk()
            ->assertJsonPath('user.must_change_password', false);

        $this->assertFalse($team->user->fresh()->must_change_password);
        $this->putJson('/api/initial-password', [
            'current_password' => 'new-password-123',
            'password' => 'another-password-123',
            'password_confirmation' => 'another-password-123',
        ])->assertUnprocessable();
    }
}
