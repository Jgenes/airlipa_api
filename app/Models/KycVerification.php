<?php

namespace App\Models;

use App\Enums\KycVerificationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'nida_number',
        'nida_hash',
        'questions_asked',
        'status',
        'attempts',
        'verified_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'nida_number' => 'encrypted',
            'questions_asked' => 'array',
            'status' => KycVerificationStatus::class,
            'attempts' => 'integer',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return $this->status === KycVerificationStatus::Verified;
    }

    public function getMaskedNidaNumberAttribute(): string
    {
        $raw = (string) $this->nida_number;
        if (strlen($raw) < 4) {
            return '********************';
        }

        $last4 = substr($raw, -4);
        return str_repeat('*', max(0, strlen($raw) - 4)) . $last4;
    }
}
