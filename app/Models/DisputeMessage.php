<?php

namespace App\Models;

use App\Enums\DisputeMessageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeMessage extends Model
{
    protected $fillable = [
        'dispute_case_id',
        'author_user_id',
        'message_type',
        'author_role',
        'body',
        'attachments',
        'is_internal',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'message_type' => DisputeMessageType::class,
            'attachments' => 'array',
            'metadata' => 'array',
            'is_internal' => 'boolean',
        ];
    }

    public function disputeCase(): BelongsTo
    {
        return $this->belongsTo(DisputeCase::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
