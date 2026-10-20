<?php

namespace App\Services;

use App\Models\PayoutFreezeState;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Global (or per-provider) payout freeze — the "stop the money" control.
 *
 * Reconciliation activates it automatically on an invariant breach; release
 * requires an explicit, audited administrator action.
 */
class PayoutFreezeService
{
    public function state(string $scope = 'GLOBAL'): PayoutFreezeState
    {
        return PayoutFreezeState::firstOrCreate(
            ['scope' => strtoupper($scope)],
            ['is_active' => false]
        );
    }

    public function isFrozen(string $scope = 'GLOBAL'): bool
    {
        return (bool) $this->state($scope)->is_active;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public function activate(User|string|null $actor, string $reason, array $evidence = [], string $scope = 'GLOBAL'): PayoutFreezeState
    {
        return DB::transaction(function () use ($actor, $reason, $evidence, $scope): PayoutFreezeState {
            $state = $this->state($scope);
            $actorId = $actor instanceof User ? $actor->id : null;

            $state->update([
                'is_active' => true,
                'reason' => $reason,
                'evidence' => $evidence === [] ? null : $evidence,
                'activated_by_user_id' => $actorId,
                'activated_at' => now(),
                'released_at' => null,
                'released_by_user_id' => null,
            ]);

            AuditLogService::log('payout_freeze.activated', $actor instanceof User ? $actor : null, [
                'scope' => $state->scope,
                'reason' => $reason,
                'evidence' => $evidence,
            ]);

            return $state->fresh();
        });
    }

    public function release(User $actor, string $reason, string $scope = 'GLOBAL'): PayoutFreezeState
    {
        return DB::transaction(function () use ($actor, $reason, $scope): PayoutFreezeState {
            $state = $this->state($scope);

            if (!$state->is_active) {
                return $state;
            }

            $state->update([
                'is_active' => false,
                'released_by_user_id' => $actor->id,
                'released_at' => now(),
                'reason' => $state->reason . ' | Released: ' . $reason,
            ]);

            AuditLogService::log('payout_freeze.released', $actor, [
                'scope' => $state->scope,
                'release_reason' => $reason,
            ]);

            return $state->fresh();
        });
    }

    /**
     * Hard guard used by every disbursement path.
     */
    public function assertNotFrozen(): void
    {
        $state = $this->state();

        if ($state->is_active) {
            throw new ConflictHttpException(
                'Payouts are frozen platform-wide while reconciliation is unresolved: '
                . ($state->reason ?? 'invariant breach') . '. Finance must clear the freeze before funds can move.'
            );
        }
    }
}
