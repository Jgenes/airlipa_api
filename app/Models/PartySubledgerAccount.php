<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PartySubledgerAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'escrow_contract_id',
        'protected_payment_id',
        'user_id',
        'role',
        'currency',
        'funded_minor',
        'entitled_minor',
        'held_minor',
        'paid_out_minor',
        'refunded_minor',
        'state_version',
    ];

    protected function casts(): array
    {
        return [
            'funded_minor' => 'integer',
            'entitled_minor' => 'integer',
            'held_minor' => 'integer',
            'paid_out_minor' => 'integer',
            'refunded_minor' => 'integer',
            'state_version' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(SubledgerEntry::class, 'subledger_id')
            ->where('subledger_type', 'PARTY');
    }
}
