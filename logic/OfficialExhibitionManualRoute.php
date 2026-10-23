<?php

declare(strict_types=1);

/**
 * Pure route decision for the existing Web/PWA update button.
 *
 * Only venues with an implemented official adapter are moved.  Every other
 * venue continues through the existing manual route, rather than being
 * silently described as an official fetch.
 */
final class OfficialExhibitionManualRoute
{
    public const AMAGASAKI_OFFICIAL_START_DATE = '20261021';
    public const TAMAGAWA_OFFICIAL_START_DATE = '20261023';
    public const SUMINOE_OFFICIAL_START_DATE = '20261023';

    /** @return array{source:string,reason:string} */
    public static function decide(string $raceCode): array
    {
        if (!preg_match('/^(\d{8})([A-Z]{3})(0[1-9]|1[0-2])$/', $raceCode, $matches)) {
            return ['source' => 'legacy', 'reason' => 'RACE_CODE_INVALID'];
        }
        if ($matches[2] === 'AMG' && $matches[1] >= self::AMAGASAKI_OFFICIAL_START_DATE) {
            return ['source' => 'official_amagasaki', 'reason' => 'OFFICIAL_AMAGASAKI_MANUAL_ROUTE'];
        }
        if ($matches[2] === 'TMG' && $matches[1] >= self::TAMAGAWA_OFFICIAL_START_DATE) {
            return ['source' => 'official_tamagawa', 'reason' => 'OFFICIAL_TAMAGAWA_MANUAL_ROUTE'];
        }
        if ($matches[2] === 'SME' && $matches[1] >= self::SUMINOE_OFFICIAL_START_DATE) {
            return ['source' => 'official_suminoe', 'reason' => 'OFFICIAL_SUMINOE_MANUAL_ROUTE'];
        }
        return ['source' => 'legacy', 'reason' => 'LEGACY_MANUAL_ROUTE'];
    }
}
