<?php

namespace App\Models;

use App\Enums\ContractVersionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EscrowContractVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'escrow_contract_id',
        'version',
        'terms',
        'terms_hash',
        'proposed_by',
        'status',
        'initiator_approved_at',
        'counterparty_approved_at',
    ];

    protected function casts(): array
    {
        return [
            'terms' => 'array',
            'version' => 'integer',
            'status' => ContractVersionStatus::class,
            'initiator_approved_at' => 'datetime',
            'counterparty_approved_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class, 'escrow_contract_version_id');
    }
}
