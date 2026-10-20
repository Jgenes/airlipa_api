<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscrowRating extends Model
{
    protected $fillable = [
        'rating_id',
        'escrow_contract_id',
        'protected_payment_id',
        'rater_user_id',
        'ratee_user_id',
        'rater_role',
        'score',
        'comment',
        'dimensions',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'dimensions' => 'array',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function rater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rater_user_id');
    }

    public function ratee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ratee_user_id');
    }
}
