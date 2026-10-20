<?php

namespace App\Enums;

enum AmlRiskRating: string
{
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
}
