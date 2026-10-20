<?php

namespace App\Models;

use App\Enums\EscrowContractStatus;
use App\Enums\EscrowFeePayer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EscrowContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'reference',
        'correlation_id',
        'initiator_id',
        'initiator_role',
        'counterparty_name',
        'counterparty_phone',
        'counterparty_email',
        'counterparty_user_id',
        'title',
        'description',
        'category',
        'currency',
        'amount_minor',
        'milestones',
        'deliverables',
        'attachments',
        'delivery_deadline_at',
        'inspection_hours',
        'refund_terms',
        'dispute_terms',
        'fee_payer',
        'status',
        'current_version',
        'acceptance_expires_at',
        'accepted_at',
        'rejected_at',
        'cancelled_at',
        'expired_at',
        'rejection_reason',
        'cancellation_reason',
        'state_version',
    ];

    protected function casts(): array
    {
        return [
            'status' => EscrowContractStatus::class,
            'fee_payer' => EscrowFeePayer::class,
            'amount_minor' => 'integer',
            'milestones' => 'array',
            'deliverables' => 'array',
            'attachments' => 'array',
            'inspection_hours' => 'integer',
            'current_version' => 'integer',
            'state_version' => 'integer',
            'delivery_deadline_at' => 'datetime',
            'acceptance_expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiator_id');
    }

    public function counterpartyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counterparty_user_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EscrowContractVersion::class, 'escrow_contract_id');
    }

    public function currentVersionRecord(): HasOne
    {
        return $this->hasOne(EscrowContractVersion::class, 'escrow_contract_id')
            ->whereColumn('version', 'current_version');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ContractShare::class, 'escrow_contract_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ContractSignature::class, 'escrow_contract_id');
    }

    public function subledgerAccount(): HasOne
    {
        return $this->hasOne(ContractSubledgerAccount::class, 'escrow_contract_id');
    }

    public function partySubledgerAccounts(): HasMany
    {
        return $this->hasMany(PartySubledgerAccount::class, 'escrow_contract_id');
    }

    public function protectedPayment(): HasOne
    {
        return $this->hasOne(ProtectedPayment::class, 'escrow_contract_id');
    }

    public function isAcceptanceWindowOpen(): bool
    {
        if ($this->status !== EscrowContractStatus::Shared) {
            return false;
        }

        return $this->acceptance_expires_at === null || $this->acceptance_expires_at->isFuture();
    }
}
