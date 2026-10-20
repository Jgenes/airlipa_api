<?php

namespace App\Models;

use App\Enums\SubledgerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubledgerEntry extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'entry_id',
        'subledger_type',
        'subledger_id',
        'bucket',
        'direction',
        'currency',
        'amount_minor',
        'balance_after_minor',
        'ledger_journal_id',
        'reference',
        'reason_code',
        'actor_user_id',
        'idempotency_key',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'subledger_type' => SubledgerType::class,
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function journal(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'ledger_journal_id');
    }

    public function scopeForSubledger(Builder $query, SubledgerType $type, int $subledgerId): Builder
    {
        return $query->where('subledger_type', $type)->where('subledger_id', $subledgerId);
    }
}
