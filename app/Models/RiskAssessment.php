<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAssessment extends Model
{
    protected $fillable = [
        'assessment_id',
        'user_id',
        'protected_payment_id',
        'checkpoint',
        'risk_score',
        'decision',
        'triggered_rules',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'risk_score' => 'integer',
            'triggered_rules' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class);
    }
}
