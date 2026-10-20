<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MilestoneSubledgerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'milestone_id',
        'escrow_contract_id',
        'currency',
        'funded_minor',
        'available_minor',
        'frozen_minor',
        'pending_release_minor',
        'released_minor',
        'refunded_minor',
        'state_version',
    ];

    protected function casts(): array
    {
        return [
            'funded_minor' => 'integer',
            'available_minor' => 'integer',
            'frozen_minor' => 'integer',
            'pending_release_minor' => 'integer',
            'released_minor' => 'integer',
            'refunded_minor' => 'integer',
            'state_version' => 'integer',
        ];
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProtectedPaymentMilestone::class, 'milestone_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SubledgerEntry::class, 'subledger_id')
            ->where('subledger_type', 'MILESTONE');
    }
}
