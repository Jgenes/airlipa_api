<?php

namespace App\Models;

use App\Enums\DisputeCaseType;
use App\Enums\DisputeOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisputeCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'case_id',
        'case_reference',
        'correlation_id',
        'protected_payment_id',
        'opened_by_user_id',
        'case_type',
        'currency',
        'disputed_amount_minor',
        'reason',
        'status',
        'primary_provider_code',
        'decision_outcome',
        'release_to_merchant_minor',
        'refund_to_buyer_minor',
        'decision_reason_code',
        'decision_notes',
        'decided_by_user_id',
        'approved_by_user_id',
        'sla_deadline_at',
        'decided_at',
        'appeal_deadline_at',
        'appeal_count',
        'original_decision_outcome',
        'appeal_reason',
        'appealed_at',
        'appealed_by_user_id',
        'case_file_path',
        'case_file_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'case_type' => DisputeCaseType::class,
            'decision_outcome' => DisputeOutcome::class,
            'disputed_amount_minor' => 'integer',
            'release_to_merchant_minor' => 'integer',
            'refund_to_buyer_minor' => 'integer',
            'sla_deadline_at' => 'datetime',
            'decided_at' => 'datetime',
            'appeal_deadline_at' => 'datetime',
            'appeal_count' => 'integer',
            'appealed_at' => 'datetime',
            'case_file_generated_at' => 'datetime',
        ];
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function appealedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appealed_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class)->orderBy('created_at');
    }

    public function holds(): HasMany
    {
        return $this->hasMany(DisputeHold::class);
    }
}
