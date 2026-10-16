<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationRun extends Model
{
    protected $fillable = [
        'run_reference',
        'provider_code',
        'statement_date',
        'currency',
        'total_lines',
        'matched_lines',
        'exception_lines',
        'statement_total_minor',
        'ledger_clearing_balance_minor',
        'protected_obligation_balance_minor',
        'variance_minor',
        'status',
        'executed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'total_lines' => 'integer',
            'matched_lines' => 'integer',
            'exception_lines' => 'integer',
            'statement_total_minor' => 'integer',
            'ledger_clearing_balance_minor' => 'integer',
            'protected_obligation_balance_minor' => 'integer',
            'variance_minor' => 'integer',
        ];
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(ReconciliationStatementLine::class, 'reconciliation_run_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }
}
