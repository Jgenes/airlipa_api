<?php

namespace App\Services;

use App\Models\EscrowContract;
use App\Models\EscrowRating;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Two-sided escrow ratings (Module 12).
 *
 * Only parties to an accepted/executed contract may rate, one rating per party
 * per contract, and only after the money side is settled or under dispute
 * resolution. Ratings are immutable — corrections are new ratings on a new
 * contract, never edits.
 */
class RatingService
{
    /**
     * @param array<string, mixed> $data
     */
    public function rate(EscrowContract $contract, User $actor, array $data): EscrowRating
    {
        $isInitiator = $actor->id === $contract->initiator_id;
        $isCounterparty = $contract->counterparty_user_id && $actor->id === $contract->counterparty_user_id;

        if (!$isInitiator && !$isCounterparty) {
            throw new AccessDeniedHttpException('Only the parties to this contract can rate it.');
        }

        if (!in_array($contract->status->value, ['ACCEPTED', 'CANCELLED', 'EXPIRED'], true)) {
            throw new ConflictHttpException('You can rate this contract once it has been accepted.');
        }

        $existing = EscrowRating::where('escrow_contract_id', $contract->id)
            ->where('rater_user_id', $actor->id)
            ->exists();

        if ($existing) {
            throw new ConflictHttpException('You have already rated this contract. Ratings cannot be edited.');
        }

        $score = (int) ($data['score'] ?? 0);

        if ($score < 1 || $score > 5) {
            throw ValidationException::withMessages(['score' => ['Score must be between 1 and 5.']]);
        }

        $initiatorRole = strtoupper((string) $contract->initiator_role);
        $actorRole = $isInitiator ? $initiatorRole : ($initiatorRole === 'BUYER' ? 'SELLER' : 'BUYER');

        $rateeId = $isInitiator
            ? $contract->counterparty_user_id
            : $contract->initiator_id;

        if (!$rateeId || $rateeId === $actor->id) {
            throw ValidationException::withMessages([
                'ratee' => ['The other party must have an Airlipa account before they can be rated.'],
            ]);
        }

        $rating = EscrowRating::create([
            'rating_id' => 'rat_' . Str::lower(Str::ulid()),
            'escrow_contract_id' => $contract->id,
            'protected_payment_id' => $contract->protectedPayment?->id,
            'rater_user_id' => $actor->id,
            'ratee_user_id' => $rateeId,
            'rater_role' => $actorRole,
            'score' => $score,
            'comment' => isset($data['comment']) ? trim((string) $data['comment']) : null,
            'dimensions' => $data['dimensions'] ?? null,
        ]);

        AuditLogService::log('escrow.rating_submitted', $actor, [
            'rating_id' => $rating->rating_id,
            'contract_reference' => $contract->reference,
            'ratee_user_id' => $rateeId,
            'score' => $score,
        ]);

        return $rating;
    }

    /**
     * Aggregate reputation for a user (public trust surface).
     *
     * @return array<string, mixed>
     */
    public function reputationFor(User $user): array
    {
        $ratings = EscrowRating::where('ratee_user_id', $user->id);

        $count = (clone $ratings)->count();
        $average = $count > 0 ? round((float) (clone $ratings)->avg('score'), 2) : null;

        $distribution = (clone $ratings)
            ->selectRaw('score, count(*) as total')
            ->groupBy('score')
            ->pluck('total', 'score')
            ->all();

        return [
            'user_id' => $user->id,
            'rating_count' => $count,
            'average_score' => $average,
            'distribution' => $distribution,
            'as_seller' => $this->roleAggregate($user, 'SELLER'),
            'as_buyer' => $this->roleAggregate($user, 'BUYER'),
            'latest' => (clone $ratings)->latest()->limit(5)->get(['score', 'comment', 'created_at'])->toArray(),
        ];
    }

    /**
     * @return array{average: float|null, count: int}
     */
    protected function roleAggregate(User $user, string $role): array
    {
        $query = EscrowRating::where('ratee_user_id', $user->id)->where('rater_role', $role === 'SELLER' ? 'BUYER' : 'SELLER');
        $count = (clone $query)->count();

        return [
            'average' => $count > 0 ? round((float) (clone $query)->avg('score'), 2) : null,
            'count' => $count,
        ];
    }
}
