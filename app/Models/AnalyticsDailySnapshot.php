<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class AnalyticsDailySnapshot extends Model
{
    protected $fillable = [
        'as_of_date',
        'contracts_created',
        'contracts_accepted',
        'deposits_confirmed',
        'gross_escrow_volume_minor',
        'released_volume_minor',
        'refunded_volume_minor',
        'disputes_opened',
        'disputes_resolved',
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'contracts_created' => 'integer',
            'contracts_accepted' => 'integer',
            'deposits_confirmed' => 'integer',
            'gross_escrow_volume_minor' => 'integer',
            'released_volume_minor' => 'integer',
            'refunded_volume_minor' => 'integer',
            'disputes_opened' => 'integer',
            'disputes_resolved' => 'integer',
            'breakdown' => 'array',
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
