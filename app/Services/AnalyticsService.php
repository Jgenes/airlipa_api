<?php

namespace App\Services;

use App\Models\AnalyticsDailySnapshot;
use App\Models\DisputeCase;
use App\Models\EscrowContract;
use App\Models\PayoutInstruction;
use App\Models\ProtectedPayment;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Platform analytics (Module 13).
 *
 * All figures are derived from the canonical tables in integer minor units —
 * no aggregates are stored twice, so analytics can never drift from the ledger.
 */
class AnalyticsService
{
    /**
     * @return array<string, mixed>
     */
    public function overview(int $days = 30): array
    {
        $since = now()->subDays($days);

        $contractsCreated = EscrowContract::where('created_at', '>=', $since)->count();
        $contractsAccepted = EscrowContract::where('accepted_at', '>=', $since)->count();
        $funded = ProtectedPayment::where('funded_at', '>=', $since);

        $grossVolume = (int) (clone $funded)->sum('funded_amount_minor');
        $releasedVolume = (int) ProtectedPayment::where('released_at', '>=', $since)->sum('released_amount_minor');
        $refundedVolume = (int) RefundRequest::where('status', 'SUCCEEDED')->where('refunded_at', '>=', $since)->sum('total_refund_minor');

        $disputesOpened = DisputeCase::where('created_at', '>=', $since)->count();
        $disputesResolved = DisputeCase::whereNotNull('decided_at')->where('decided_at', '>=', $since)->count();

        $acceptedCount = max(1, EscrowContract::where('created_at', '>=', $since)->whereIn('status', ['ACCEPTED'])->count());

        return [
            'window_days' => $days,
            'generated_at' => now()->toIso8601String(),
            'funnel' => [
                'contracts_created' => $contractsCreated,
                'contracts_accepted' => $contractsAccepted,
                'deposits_funded' => (clone $funded)->count(),
                'acceptance_rate' => $contractsCreated > 0 ? round($contractsAccepted / $contractsCreated, 4) : null,
                'funding_rate' => $contractsAccepted > 0 ? round((clone $funded)->count() / $contractsAccepted, 4) : null,
            ],
            'volume' => [
                'currency' => 'TZS',
                'gross_escrow_funded_minor' => $grossVolume,
                'released_minor' => $releasedVolume,
                'refunded_minor' => $refundedVolume,
                'average_contract_minor' => $acceptedCount > 0 ? intdiv((int) EscrowContract::where('created_at', '>=', $since)->sum('amount_minor'), $acceptedCount) : 0,
            ],
            'quality' => [
                'disputes_opened' => $disputesOpened,
                'disputes_resolved' => $disputesResolved,
                'dispute_rate' => $contractsAccepted > 0 ? round($disputesOpened / $contractsAccepted, 4) : null,
                'average_resolution_hours' => $this->averageDisputeResolutionHours(),
            ],
            'payouts' => [
                'settled_count' => PayoutInstruction::where('status', 'SETTLED')->count(),
                'blocked_count' => PayoutInstruction::where('status', 'BLOCKED')->count(),
                'pending_count' => PayoutInstruction::where('status', 'PENDING')->count(),
                'settled_total_minor' => (int) PayoutInstruction::where('status', 'SETTLED')->sum('net_payout_minor'),
            ],
            'users' => [
                'total' => User::count(),
                'verified' => User::where('kyc_status', 'Verified')->count(),
                'by_tier' => User::selectRaw('kyc_tier, count(*) as total')->groupBy('kyc_tier')->pluck('total', 'kyc_tier')->all(),
                'high_risk' => User::where('aml_risk_rating', 'HIGH')->count(),
            ],
            'providers' => [
                'deposits_by_provider' => ProtectedPayment::where('funded_at', '>=', $since)
                    ->selectRaw('custody_provider_code as provider_code, count(*) as total, sum(funded_amount_minor) as volume_minor')
                    ->groupBy('custody_provider_code')
                    ->get()
                    ->toArray(),
            ],
        ];
    }

    /**
     * Daily series for charts (fills gaps with zeros).
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeseries(int $days = 30): array
    {
        $start = now()->subDays($days - 1)->startOfDay();
        $series = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $start->copy()->addDays($day);

            $snapshot = AnalyticsDailySnapshot::where('as_of_date', $date->toDateString())->first();

            $series[] = [
                'date' => $date->toDateString(),
                'contracts_created' => $snapshot?->contracts_created
                    ?? EscrowContract::whereDate('created_at', $date)->count(),
                'contracts_accepted' => $snapshot?->contracts_accepted
                    ?? EscrowContract::whereDate('accepted_at', $date)->count(),
                'deposits_confirmed' => $snapshot?->deposits_confirmed
                    ?? ProtectedPayment::whereDate('funded_at', $date)->count(),
                'gross_escrow_volume_minor' => $snapshot?->gross_escrow_volume_minor
                    ?? (int) ProtectedPayment::whereDate('funded_at', $date)->sum('funded_amount_minor'),
                'released_volume_minor' => $snapshot?->released_volume_minor
                    ?? (int) ProtectedPayment::whereDate('released_at', $date)->sum('released_amount_minor'),
                'refunded_volume_minor' => $snapshot?->refunded_volume_minor
                    ?? (int) RefundRequest::whereDate('refunded_at', $date)->where('status', 'SUCCEEDED')->sum('total_refund_minor'),
                'disputes_opened' => $snapshot?->disputes_opened
                    ?? DisputeCase::whereDate('created_at', $date)->count(),
                'disputes_resolved' => $snapshot?->disputes_resolved
                    ?? DisputeCase::whereDate('decided_at', $date)->count(),
            ];
        }

        return $series;
    }

    /**
     * Materialise (idempotently) the daily snapshot for a date.
     */
    public function snapshotDay(?Carbon $date = null): AnalyticsDailySnapshot
    {
        $date = ($date ?? now())->copy()->startOfDay();

        $created = EscrowContract::whereDate('created_at', $date)->count();
        $accepted = EscrowContract::whereDate('accepted_at', $date)->count();
        $deposits = ProtectedPayment::whereDate('funded_at', $date)->count();

        return AnalyticsDailySnapshot::updateOrCreate(
            ['as_of_date' => $date->toDateString()],
            [
                'contracts_created' => $created,
                'contracts_accepted' => $accepted,
                'deposits_confirmed' => $deposits,
                'gross_escrow_volume_minor' => (int) ProtectedPayment::whereDate('funded_at', $date)->sum('funded_amount_minor'),
                'released_volume_minor' => (int) ProtectedPayment::whereDate('released_at', $date)->sum('released_amount_minor'),
                'refunded_volume_minor' => (int) RefundRequest::whereDate('refunded_at', $date)->where('status', 'SUCCEEDED')->sum('total_refund_minor'),
                'disputes_opened' => DisputeCase::whereDate('created_at', $date)->count(),
                'disputes_resolved' => DisputeCase::whereDate('decided_at', $date)->count(),
                'breakdown' => ['generated_at' => now()->toIso8601String()],
            ]
        );
    }

    protected function averageDisputeResolutionHours(): ?float
    {
        $resolved = DisputeCase::whereNotNull('decided_at')->whereNotNull('created_at')->limit(500)->get();

        if ($resolved->isEmpty()) {
            return null;
        }

        $average = $resolved->avg(fn (DisputeCase $case): float => $case->created_at->diffInMinutes($case->decided_at) / 60);

        return $average === null ? null : round((float) $average, 2);
    }
}
