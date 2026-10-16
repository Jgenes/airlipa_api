<?php

namespace App\Services;

use App\Models\ProtectedPayment;
use App\Models\RiskAlert;
use App\Models\RiskAssessment;
use App\Models\User;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class RiskEngineService
{
    /**
     * Evaluate transaction risk at key lifecycle checkpoints (M07, FR-029, BPA-SEC-08).
     */
    public function evaluateProtectedPayment(
        User $actor,
        int $principalAmountMinor,
        ?User $counterparty = null,
        ?ProtectedPayment $payment = null,
        string $checkpoint = 'AGREEMENT_CREATION'
    ): RiskAssessment {
        $score = 0;
        $triggeredRules = [];
        $decision = 'ALLOW';

        // Rule 1: Self-dealing check (buyer == merchant or identical phone)
        if ($counterparty && ($actor->id === $counterparty->id || ($actor->phone && $actor->phone === $counterparty->phone))) {
            $score += 90;
            $triggeredRules[] = 'SELF_DEALING_ATTEMPT';
            $decision = 'BLOCK';
        }

        // Rule 2: High single-transaction value (>= 5,000,000.00 TZS = 500,000,000 minor units)
        if ($principalAmountMinor >= 500_000_000) {
            $score += 40;
            $triggeredRules[] = 'HIGH_VALUE_TXN';
            if ($decision !== 'BLOCK') {
                $decision = 'STEP_UP';
            }
        }

        // Rule 3: Daily velocity check (>= 5 agreements created in the last 1 hour)
        $recentCount = ProtectedPayment::where('buyer_id', $actor->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($recentCount >= 5) {
            $score += 35;
            $triggeredRules[] = 'VELOCITY_LIMIT';
            if ($decision === 'ALLOW') {
                $decision = 'REVIEW';
            }
        }

        // Rule 4: Rapid release without evidence on high-value payment
        if ($checkpoint === 'RELEASE_EXECUTION' && $payment !== null) {
            $hasEvidence = $payment->evidences()->exists();
            if (! $hasEvidence && $payment->principal_amount_minor >= 100_000_000) {
                $score += 30;
                $triggeredRules[] = 'RAPID_RELEASE_NO_EVIDENCE';
                if ($decision === 'ALLOW') {
                    $decision = 'STEP_UP';
                }
            }
        }

        $score = min(100, $score);

        $assessment = RiskAssessment::create([
            'assessment_id' => (string) Str::uuid(),
            'user_id' => $actor->id,
            'protected_payment_id' => $payment?->id,
            'checkpoint' => $checkpoint,
            'risk_score' => $score,
            'decision' => $decision,
            'triggered_rules' => $triggeredRules,
            'correlation_id' => Request::header('X-Correlation-Id'),
        ]);

        if (! empty($triggeredRules)) {
            $severity = $score >= 70 ? 'HIGH' : ($score >= 40 ? 'MEDIUM' : 'LOW');
            RiskAlert::create([
                'alert_code' => 'rsk_' . Str::lower(Str::random(16)),
                'risk_assessment_id' => $assessment->id,
                'user_id' => $actor->id,
                'protected_payment_id' => $payment?->id,
                'rule_code' => $triggeredRules[0],
                'severity' => $severity,
                'status' => 'OPEN',
                'details' => sprintf(
                    'Checkpoint %s triggered rules [%s] with score %d.',
                    $checkpoint,
                    implode(', ', $triggeredRules),
                    $score
                ),
            ]);
        }

        return $assessment;
    }

    /**
     * Review and resolve a RiskAlert (Admin / Compliance Ops).
     */
    public function reviewAlert(RiskAlert $alert, User $reviewer, string $status, string $notes): RiskAlert
    {
        $alert->update([
            'status' => $status,
            'reviewed_by_user_id' => $reviewer->id,
            'review_notes' => $notes,
            'reviewed_at' => now(),
        ]);

        AuditLogService::log('risk.alert.reviewed', $reviewer, [
            'alert_code' => $alert->alert_code,
            'status' => $status,
        ]);

        return $alert->fresh();
    }
}
