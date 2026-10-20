<?php

namespace App\Models;

use App\Enums\ContractShareChannel;
use App\Enums\ContractShareStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'share_id',
        'escrow_contract_id',
        'version',
        'channel',
        'recipient',
        'token',
        'token_expires_at',
        'status',
        'delivered_at',
        'opened_at',
        'last_viewed_at',
        'view_count',
        'payload',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'channel' => ContractShareChannel::class,
            'status' => ContractShareStatus::class,
            'version' => 'integer',
            'view_count' => 'integer',
            'payload' => 'array',
            'token_expires_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'last_viewed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
