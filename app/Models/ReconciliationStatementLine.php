<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationStatementLine extends Model
{
    protected $fillable = [
        'reconciliation_run_id',
        'provider_code',
        'provider_reference',
        'attempt_reference',
        'direction',
        'amount_minor',
        'currency',
        'match_status',
        'exception_code',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ReconciliationRun::class, 'reconciliation_run_id');
    }
}
