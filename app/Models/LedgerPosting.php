<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerPosting extends Model
{
    use HasFactory;

    protected $fillable = [
        'posting_id',
        'ledger_journal_id',
        'ledger_account_id',
        'party_user_id',
        'direction',
        'currency',
        'amount_minor',
        'narration',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('BPA-DEF-07 / BPA-PER-05 violation: Posted ledger entries are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new DomainException('BPA-DEF-07 / BPA-PER-05 violation: Posted ledger entries are append-only and cannot be deleted.');
        });
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'ledger_journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(User::class, 'party_user_id');
    }
}
