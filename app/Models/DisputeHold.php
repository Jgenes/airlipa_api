<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'hold_id',
        'escrow_contract_id',
        'protected_payment_id',
        'dispute_case_id',
        'currency',
        'amount_minor',
        'status',
        'frozen_at',
        'resolved_at',
        'resolution',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'resolution' => 'array',
            'frozen_at' => 'datetime',
            'resolved_at' => 'datetime',
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

    public function disputeCase(): BelongsTo
    {
        return $this->belongsTo(DisputeCase::class, 'dispute_case_id');
    }
}
