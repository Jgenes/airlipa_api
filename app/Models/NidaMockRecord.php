<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NidaMockRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'nida_number',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'place_of_birth',
        'mother_first_name',
        'region',
        'district',
        'ward',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
        ];
    }
}
