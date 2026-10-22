<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiOperationalPolicy.php';

/**
 * Pure route decision used by the Web/PWA update button.  It prevents a
 * button press from silently falling back to Kyotei Biyori while no official
 * parser exists for the requested venue.
 */
final class OfficialExhibitionManualRoute
{
    /** @return array{supported:bool,source:?string,reason:string} */
    public static function decide(OfficialAmagasakiOperationalPolicy $policy, string $raceCode): array
    {
        if (!preg_match('/^(\d{8})([A-Z]{3})(0[1-9]|1[0-2])$/', $raceCode, $matches)) {
            return ['supported' => false, 'source' => null, 'reason' => 'RACE_CODE_INVALID'];
        }
        if ($matches[2] !== 'AMG' || $matches[1] < OfficialExhibitionSourceRouter::OFFICIAL_START_DATE) {
            return ['supported' => false, 'source' => null, 'reason' => 'OFFICIAL_EXHIBITION_UNSUPPORTED_VENUE'];
        }
        $decision = $policy->decide($raceCode, 'manual');
        return $decision['mode'] === 'manual_official'
            ? ['supported' => true, 'source' => 'official_amagasaki', 'reason' => $decision['reason']]
            : ['supported' => false, 'source' => 'official_amagasaki', 'reason' => $decision['reason']];
    }
}
