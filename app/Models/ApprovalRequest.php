<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'approval_code',
        'action_type',
        'target_type',
        'target_identifier',
        'proposed_payload',
        'reason',
        'status',
        'maker_user_id',
        'checker_user_id',
        'checker_notes',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_user_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checker_user_id');
    }
}
