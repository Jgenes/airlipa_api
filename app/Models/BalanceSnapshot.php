<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class BalanceSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_type',
        'subject_type',
        'subject_id',
        'as_of_date',
        'currency',
        'balance_minor',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'balance_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * Stored as a plain Y-m-d string, not a datetime.
     *
     * A `date` cast serialises through the connection's datetime format, so the
     * column held "2026-10-20 00:00:00" and every exact-match lookup for
     * "2026-10-20" missed. Daily close is upserted and read back by date, so the
     * value has to round-trip exactly.
     */
    protected function asOfDate(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : substr((string) $value, 0, 10),
            set: fn ($value) => $value === null
                ? null
                : ($value instanceof \DateTimeInterface
                    ? $value->format('Y-m-d')
                    : substr((string) $value, 0, 10)),
        );
    }
}
