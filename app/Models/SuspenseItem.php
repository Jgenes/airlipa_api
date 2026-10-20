<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SuspenseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'suspense_id',
        'provider_code',
        'provider_reference',
        'currency',
        'amount_minor',
        'received_at',
        'status',
        'matched_contract_id',
        'matched_journal_id',
        'approval_request_id',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function matchedContract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'matched_contract_id');
    }

    public function matchedJournal(): BelongsTo
    {
        return $this->belongsTo(LedgerJournal::class, 'matched_journal_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
