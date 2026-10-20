<?php

namespace App\Models;

use App\Enums\ContractSignerRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractSignature extends Model
{
    use HasFactory;

    protected $fillable = [
        'signature_id',
        'escrow_contract_id',
        'escrow_contract_version_id',
        'user_id',
        'signer_role',
        'full_name',
        'phone',
        'email',
        'ip_address',
        'user_agent',
        'contract_hash',
        'terms_version',
        'otp_verified_at',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signer_role' => ContractSignerRole::class,
            'otp_verified_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(EscrowContract::class, 'escrow_contract_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EscrowContractVersion::class, 'escrow_contract_version_id');
    }

    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
