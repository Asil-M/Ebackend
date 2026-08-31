<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationResponse;
use App\Models\DonationTeam;
use App\Models\MatchedDonation;
use App\Models\SosRequest;
use App\Models\SosTeam;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AdminStatisticsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'accounts' => [
                    'users' => User::where('role', 'user')->count(),
                    'sos_teams' => SosTeam::count(),
                    'active_sos_teams' => SosTeam::where('is_active', true)->count(),
                    'donation_teams' => DonationTeam::count(),
                    'active_donation_teams' => DonationTeam::where('is_active', true)->count(),
                ],
                'sos' => [
                    'total' => SosRequest::count(),
                    'by_status' => $this->countsBy(SosRequest::class, 'status', [
                        'pending', 'accepted', 'rejected', 'failed',
                    ]),
                    'by_type' => $this->countsBy(SosRequest::class, 'type', [
                        'ambulance', 'fire', 'police',
                    ]),
                ],
                'donations' => [
                    'total' => Donation::count(),
                    'by_type' => $this->countsBy(Donation::class, 'type', [
                        'request', 'donation',
                    ]),
                    'by_category' => $this->countsBy(Donation::class, 'category', [
                        'blood', 'money', 'clothes', 'food', 'medicine', 'other',
                    ]),
                    'by_status' => $this->countsBy(Donation::class, 'status', [
                        'pending', 'matched', 'accepted', 'failed', 'expired',
                    ]),
                ],
                'responses' => [
                    'total' => DonationResponse::count(),
                    'by_status' => $this->countsBy(DonationResponse::class, 'status', [
                        'pending', 'accepted', 'rejected',
                    ]),
                ],
                'matches' => [
                    'total' => MatchedDonation::count(),
                    'by_status' => $this->countsBy(MatchedDonation::class, 'status', [
                        'matched', 'accepted', 'rejected',
                    ]),
                ],
                'activity' => $this->recentActivity(),
            ],
        ]);
    }

    private function recentActivity(): array
    {
        $start = today()->subDays(6);
        $sosCounts = SosRequest::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');
        $donationCounts = Donation::where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        return collect(range(0, 6))->map(function (int $offset) use ($start, $sosCounts, $donationCounts) {
            $day = Carbon::parse($start)->addDays($offset);
            $key = $day->toDateString();

            return [
                'date' => $key,
                'sos' => (int) ($sosCounts[$key] ?? 0),
                'donations' => (int) ($donationCounts[$key] ?? 0),
            ];
        })->all();
    }

    /** @param array<int, string> $values */
    private function countsBy(string $model, string $column, array $values): array
    {
        $counts = $model::query()
            ->selectRaw("{$column}, COUNT(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', $column);

        return collect($values)
            ->mapWithKeys(fn (string $value) => [$value => (int) ($counts[$value] ?? 0)])
            ->all();
    }
}
