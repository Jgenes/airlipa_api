<?php

namespace App\Enums;

/**
 * KYC/AML trading tiers (Module 9).
 *
 * Tier 0 = unverified (browse only), Tier 1 = phone + NIDA identity (consumer),
 * Tier 2 = standard verification with source-of-funds declaration, Tier 3 =
 * enhanced due diligence for high-value business escrow.
 */
enum KycTier: string
{
    case Tier0Unverified = 'TIER_0';
    case Tier1Basic = 'TIER_1';
    case Tier2Standard = 'TIER_2';
    case Tier3Enhanced = 'TIER_3';

    /**
     * @return array{en: string, sw: string}
     */
    public function label(): array
    {
        return match ($this) {
            self::Tier0Unverified => ['en' => 'Unverified', 'sw' => 'Hakuna uthibitisho'],
            self::Tier1Basic => ['en' => 'Basic (NIDA identity)', 'sw' => 'Msingi (utambulisho wa NIDA)'],
            self::Tier2Standard => ['en' => 'Standard verification', 'sw' => 'Uthibitisho wa kawaida'],
            self::Tier3Enhanced => ['en' => 'Enhanced due diligence', 'sw' => 'Uchunguzi wa kina'],
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Tier0Unverified => 0,
            self::Tier1Basic => 1,
            self::Tier2Standard => 2,
            self::Tier3Enhanced => 3,
        };
    }

    public static function fromRank(int $rank): self
    {
        return match (true) {
            $rank >= 3 => self::Tier3Enhanced,
            $rank === 2 => self::Tier2Standard,
            $rank === 1 => self::Tier1Basic,
            default => self::Tier0Unverified,
        };
    }
}
