<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractSubledgerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'escrow_contract_id',
        'protected_payment_id',
        'currency',
        'deposited_minor',
        'available_minor',
        'held_milestones_minor',
        'frozen_minor',
        'pending_release_minor',
        'pending_refund_minor',
        'released_minor',
        'refunded_minor',
        'fees_minor',
        'state_version',
    ];

    protected function casts(): array
    {
        return [
            'deposited_minor' => 'integer',
            'available_minor' => 'integer',
            'held_milestones_minor' => 'integer',
            'frozen_minor' => 'integer',
            'pending_release_minor' => 'integer',
            'pending_refund_minor' => 'integer',
            'released_minor' => 'integer',
            'refunded_minor' => 'integer',
            'fees_minor' => 'integer',
            'state_version' => 'integer',
        ];
    }

    /**
     * Buckets that must always sum to the deposited total (money conservation).
     *
     * @return array<int, string>
     */
    public static function conservationBuckets(): array
    {
        return [
            'available_minor',
            'held_milestones_minor',
            'frozen_minor',
            'pending_release_minor',
            'pending_refund_minor',
            'released_minor',
            'refunded_minor',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SubledgerEntry::class, 'subledger_id')
            ->where('subledger_type', 'CONTRACT');
    }

    public function conservationDifference(): int
    {
        $sum = 0;
        foreach (self::conservationBuckets() as $bucket) {
            $sum += (int) $this->{$bucket};
        }

        return (int) $this->deposited_minor - $sum;
    }
}
