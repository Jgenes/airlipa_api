<?php

namespace App\Models;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'purpose',
        'code_hash',
        'channel',
        'expires_at',
        'attempts',
        'max_attempts',
        'consumed_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'channel' => OtpChannel::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isConsumed() && !$this->hasExceededAttempts();
    }
}
