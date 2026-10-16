<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SettlementBatch extends Model
{
    protected $fillable = [
        'batch_reference',
        'provider_code',
        'currency',
        'instructions_count',
        'total_amount_minor',
        'status',
        'triggered_by_user_id',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'instructions_count' => 'integer',
            'total_amount_minor' => 'integer',
            'settled_at' => 'datetime',
        ];
    }

    public function instructions(): HasMany
    {
        return $this->hasMany(PayoutInstruction::class, 'settlement_batch_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
