<?php

namespace App\Enums;

enum DisputeMessageType: string
{
    case Message = 'MESSAGE';
    case Timeline = 'TIMELINE';
    case Decision = 'DECISION';
    case Appeal = 'APPEAL';
    case System = 'SYSTEM';

    /**
     * @return array{en: string, sw: string}
     */
    public function label(): array
    {
        return match ($this) {
            self::Message => ['en' => 'Message', 'sw' => 'Ujumbe'],
            self::Timeline => ['en' => 'Timeline event', 'sw' => 'Tukio la mfuatano'],
            self::Decision => ['en' => 'Mediator decision', 'sw' => 'Uamuzi wa mpatanishi'],
            self::Appeal => ['en' => 'Appeal', 'sw' => 'Rufaa'],
            self::System => ['en' => 'System event', 'sw' => 'Tukio la mfumo'],
        };
    }
}
