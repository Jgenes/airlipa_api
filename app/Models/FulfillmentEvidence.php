<?php

namespace App\Models;

use App\Enums\EvidenceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentEvidence extends Model
{
    use HasFactory;

    protected $table = 'fulfillment_evidences';

    protected $fillable = [
        'evidence_id',
        'correlation_id',
        'protected_payment_id',
        'milestone_id',
        'submitted_by_user_id',
        'submitter_role',
        'evidence_type',
        'courier_name',
        'tracking_number',
        'description',
        'file_path',
        'file_mime',
        'file_hash',
        'metadata',
        'verified_at',
    ];

    protected $hidden = [
        'file_path',
    ];

    protected function casts(): array
    {
        return [
            'evidence_type' => EvidenceType::class,
            'metadata' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProtectedPaymentMilestone::class, 'milestone_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
