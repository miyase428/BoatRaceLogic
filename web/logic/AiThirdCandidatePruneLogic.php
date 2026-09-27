<?php

declare(strict_types=1);

/**
 * 3着候補が5艇ある時だけ、AI3着率v1の確率質量が3%以下の最下位艇を1艇外す。
 *
 * 頭・2着候補・切る艇は変更しない。調整期間・完全ホールドアウト期間の双方で
 * 的中を落とさず点数だけ削減できた固定条件のみを適用する。
 */
final class AiThirdCandidatePruneLogic
{
    private const SHARE_THRESHOLD = 0.03;

    public function apply(array $summary, array $aiPlaceData): array
    {
        $summary['ai_third_prune_v1_applied'] = false;
        $summary['ai_third_prune_v1_sides'] = [];
        $summary['ai_third_prune_v1_threshold'] = self::SHARE_THRESHOLD;

        if ((string)($aiPlaceData['status'] ?? '') !== 'ok') {
            $summary['ai_third_prune_v1_reason'] = 'ai_place_not_ready';
            return $summary;
        }

        $combinations = is_array($aiPlaceData['combinations'] ?? null)
            ? $aiPlaceData['combinations']
            : [];
        if (count($combinations) !== 120) {
            $summary['ai_third_prune_v1_reason'] = 'joint120_incomplete';
            return $summary;
        }

        $summary = $this->applySide($summary, $combinations, 'honmei');
        $summary = $this->applySide($summary, $combinations, 'taikou');
        $sides = [];
        foreach (['honmei', 'taikou'] as $side) {
            if (!empty($summary[$side . '_ai_third_prune_applied'])) {
                $sides[] = $side;
            }
        }
        $summary['ai_third_prune_v1_sides'] = $sides;
        $summary['ai_third_prune_v1_applied'] = $sides !== [];
        $summary['ai_third_prune_v1_reason'] = $sides !== [] ? 'applied' : 'threshold_not_met';
        return $summary;
    }

    private function applySide(array $summary, array $combinations, string $side): array
    {
        $prefix = $side . '_ai_third_prune';
        $summary[$prefix . '_applied'] = false;
        $summary[$prefix . '_reason'] = '';
        $summary[$prefix . '_drop_boat'] = 0;
        $summary[$prefix . '_drop_share'] = 0.0;

        $formation = $this->parseFormation((string)($summary[$side . '_kai'] ?? ''));
        if ($formation === null) {
            $summary[$prefix . '_reason'] = 'formation_invalid';
            return $summary;
        }
        [$head, $seconds, $thirds] = $formation;
        if (count($thirds) !== 5) {
            $summary[$prefix . '_reason'] = 'third_count_not_five';
            return $summary;
        }

        $secondSet = array_fill_keys($seconds, true);
        $thirdSet = array_fill_keys($thirds, true);
        $massByThird = array_fill_keys($thirds, 0.0);
        $totalMass = 0.0;

        foreach ($combinations as $row) {
            if (!is_array($row)) {
                continue;
            }
            $boats = is_array($row['boats'] ?? null) ? array_values($row['boats']) : [];
            $probability = $row['probability'] ?? null;
            if (count($boats) !== 3 || !is_numeric($probability)) {
                continue;
            }
            $first = (int)$boats[0];
            $second = (int)$boats[1];
            $third = (int)$boats[2];
            $p = (float)$probability;
            if (
                $first !== $head
                || !isset($secondSet[$second])
                || !isset($thirdSet[$third])
                || $p < 0.0
                || !is_finite($p)
            ) {
                continue;
            }
            $massByThird[$third] += $p;
            $totalMass += $p;
        }

        if ($totalMass <= 0.0) {
            $summary[$prefix . '_reason'] = 'probability_mass_missing';
            return $summary;
        }

        $dropBoat = 0;
        $dropMass = INF;
        foreach ($thirds as $boat) {
            $mass = (float)($massByThird[$boat] ?? 0.0);
            if ($mass < $dropMass || ($mass === $dropMass && $boat > $dropBoat)) {
                $dropBoat = $boat;
                $dropMass = $mass;
            }
        }
        $dropShare = $dropMass / $totalMass;
        $summary[$prefix . '_drop_boat'] = $dropBoat;
        $summary[$prefix . '_drop_share'] = $dropShare;

        if ($dropBoat < 1 || $dropBoat > 6 || $dropShare > self::SHARE_THRESHOLD) {
            $summary[$prefix . '_reason'] = 'threshold_not_met';
            return $summary;
        }

        $newThirds = array_values(array_filter(
            $thirds,
            static fn(int $boat): bool => $boat !== $dropBoat
        ));
        sort($newThirds, SORT_NUMERIC);
        $thirdKako = implode('', $newThirds);
        $aiteKako = preg_replace('/[^1-6]/', '', (string)($summary[$side . '_aite_kako'] ?? '')) ?? '';
        if ($aiteKako === '') {
            $aiteKako = implode('', $seconds);
        }

        $summary[$side . '_third_kako'] = $thirdKako;
        $summary[$side . '_kai'] = $head . '-' . $aiteKako . '-' . $thirdKako;
        $summary[$prefix . '_applied'] = true;
        $summary[$prefix . '_reason'] = 'applied';
        return $summary;
    }

    private function parseFormation(string $formation): ?array
    {
        $parts = explode('-', trim($formation));
        if (count($parts) !== 3) {
            return null;
        }
        $groups = [];
        foreach ($parts as $part) {
            $part = preg_replace('/[^1-6]/', '', $part) ?? '';
            $boats = array_values(array_unique(array_map('intval', str_split($part))));
            if ($boats === []) {
                return null;
            }
            $groups[] = $boats;
        }
        if (count($groups[0]) !== 1) {
            return null;
        }
        return [$groups[0][0], $groups[1], $groups[2]];
    }
}
