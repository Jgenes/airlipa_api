<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerJournal extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_id',
        'correlation_id',
        'protected_payment_id',
        'event_type',
        'posting_rule_code',
        'posting_rule_version',
        'idempotency_key',
        'currency',
        'total_debit_minor',
        'total_credit_minor',
        'provider_reference',
        'actor_user_id',
        'reversal_of_journal_id',
        'description',
        'metadata',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'total_debit_minor' => 'integer',
            'total_credit_minor' => 'integer',
            'metadata' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('BPA-DEF-07 / FR-M08-03 violation: Posted financial journals are immutable and cannot be updated. Use a linked reversal or compensating entry.');
        });

        static::deleting(function (): void {
            throw new DomainException('BPA-DEF-07 / FR-M08-03 violation: Posted financial journals are immutable and cannot be deleted.');
        });
    }

    public function postings(): HasMany
    {
        return $this->hasMany(LedgerPosting::class, 'ledger_journal_id');
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reversedJournal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_journal_id');
    }

    public function isBalanced(): bool
    {
        return $this->total_debit_minor > 0 && $this->total_debit_minor === $this->total_credit_minor;
    }
}
