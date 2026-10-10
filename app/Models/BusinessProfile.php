<?php

namespace App\Models;

use App\Enums\BusinessProfileStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_name',
        'registration_number',
        'tin_number',
        'business_type',
        'date_of_incorporation',
        'business_address',
        'region',
        'district',
        'contact_person',
        'contact_phone',
        'contact_email',
        'registration_document_path',
        'status',
        'rejection_reason',
    ];

    protected $hidden = [
        'registration_document_path',
    ];

    protected function casts(): array
    {
        return [
            'status' => BusinessProfileStatus::class,
            'date_of_incorporation' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === BusinessProfileStatus::Pending;
    }

    public function isVerified(): bool
    {
        return $this->status === BusinessProfileStatus::Verified;
    }

    public function isRejected(): bool
    {
        return $this->status === BusinessProfileStatus::Rejected;
    }
}
