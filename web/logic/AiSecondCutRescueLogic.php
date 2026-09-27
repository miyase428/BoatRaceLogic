<?php

declare(strict_types=1);

/**
 * 現行で切られた艇のAI2着率が、現在の2着候補最下位の1.5倍以上なら、
 * 2着候補だけを最大1艇入れ替える。
 *
 * 頭・切る艇判定・3着候補は変更しない。AI着順率v1の完全ホールドアウトで、
 * 的中率・1000点当たり的中・回収率が揃って改善した固定条件だけを適用する。
 */
final class AiSecondCutRescueLogic
{
    private const PROBABILITY_RATIO_THRESHOLD = 1.5;
    private const REQUIRED_SOURCE = 'ai_place_v1_joint120';

    public function apply(array $summary, array $finalPredictions): array
    {
        $summary['ai_second_cut_rescue_v1_applied'] = false;
        $summary['ai_second_cut_rescue_v1_sides'] = [];
        $summary['ai_second_cut_rescue_v1_ratio_threshold'] = self::PROBABILITY_RATIO_THRESHOLD;

        $summary = $this->applySide($summary, $finalPredictions, 'honmei');
        $summary = $this->applySide($summary, $finalPredictions, 'taikou');

        $sides = [];
        foreach (['honmei', 'taikou'] as $side) {
            if (!empty($summary[$side . '_ai_second_cut_rescue_applied'])) {
                $sides[] = $side;
            }
        }
        $summary['ai_second_cut_rescue_v1_sides'] = $sides;
        $summary['ai_second_cut_rescue_v1_applied'] = $sides !== [];
        $summary['ai_second_cut_rescue_v1_reason'] = $sides !== []
            ? 'applied'
            : 'threshold_not_met';

        return $summary;
    }

    private function applySide(
        array $summary,
        array $finalPredictions,
        string $side
    ): array {
        $prefix = $side . '_ai_second_cut_rescue';
        $meta = $side === 'honmei' ? 'common_second' : 'taikou_common_second';
        $summary[$prefix . '_applied'] = false;
        $summary[$prefix . '_reason'] = '';
        $summary[$prefix . '_rescue_boat'] = 0;
        $summary[$prefix . '_replaced_boat'] = 0;
        $summary[$prefix . '_probability_ratio'] = 0.0;

        if (empty($summary[$meta . '_applied'])) {
            $summary[$prefix . '_reason'] = 'ai_second_not_applied';
            return $summary;
        }
        if ((string)($summary[$meta . '_probability_source'] ?? '') !== self::REQUIRED_SOURCE) {
            $summary[$prefix . '_reason'] = 'ai_place_v1_not_active';
            return $summary;
        }

        $formation = $this->parseFormation((string)($summary[$side . '_kai'] ?? ''));
        if ($formation === null) {
            $summary[$prefix . '_reason'] = 'formation_invalid';
            return $summary;
        }
        [$head, $seconds, $thirds] = $formation;

        $probabilityByBoat = $this->normalizeProbabilities(
            is_array($summary[$meta . '_probability_by_boat'] ?? null)
                ? $summary[$meta . '_probability_by_boat']
                : []
        );
        if (count($probabilityByBoat) < 5) {
            $summary[$prefix . '_reason'] = 'probability_incomplete';
            return $summary;
        }

        $kiruKey = $side === 'honmei' ? 'kiru' : 'kiru_original';
        $cutBoats = [];
        foreach ($finalPredictions as $boatKey => $prediction) {
            $boat = (int)($prediction['boat'] ?? $boatKey);
            $isCut = (int)($prediction[$kiruKey] ?? ($prediction['kiru'] ?? 0)) === 1;
            if (
                $boat >= 1 && $boat <= 6
                && $boat !== $head
                && $isCut
                && isset($probabilityByBoat[$boat])
                && !in_array($boat, $seconds, true)
            ) {
                $cutBoats[] = $boat;
            }
        }
        if ($cutBoats === [] || $seconds === []) {
            $summary[$prefix . '_reason'] = 'no_cut_candidate';
            return $summary;
        }

        $rescueBoat = $this->maxProbabilityBoat($cutBoats, $probabilityByBoat);
        $replacedBoat = $this->minProbabilityBoat($seconds, $probabilityByBoat);
        $rescueProbability = (float)($probabilityByBoat[$rescueBoat] ?? 0.0);
        $replacedProbability = (float)($probabilityByBoat[$replacedBoat] ?? 0.0);
        if ($replacedProbability <= 0.0) {
            $summary[$prefix . '_reason'] = 'comparison_probability_missing';
            return $summary;
        }

        $ratio = $rescueProbability / $replacedProbability;
        $summary[$prefix . '_rescue_boat'] = $rescueBoat;
        $summary[$prefix . '_replaced_boat'] = $replacedBoat;
        $summary[$prefix . '_probability_ratio'] = $ratio;
        // 0.15 / 0.10 のような境界値が浮動小数点誤差で
        // 1.499999... になっても、「1.5倍以上」を正しく判定する。
        if ($ratio + 1.0e-12 < self::PROBABILITY_RATIO_THRESHOLD) {
            $summary[$prefix . '_reason'] = 'threshold_not_met';
            return $summary;
        }

        $newSeconds = [];
        foreach ($seconds as $boat) {
            $newSeconds[] = $boat === $replacedBoat ? $rescueBoat : $boat;
        }
        $newSeconds = array_values(array_unique($newSeconds));
        usort($newSeconds, static function (int $a, int $b) use ($probabilityByBoat): int {
            $pa = (float)($probabilityByBoat[$a] ?? 0.0);
            $pb = (float)($probabilityByBoat[$b] ?? 0.0);
            if ($pa === $pb) {
                return $a <=> $b;
            }
            return $pb <=> $pa;
        });

        $betSeconds = $newSeconds;
        sort($betSeconds, SORT_NUMERIC);
        $betThirds = $thirds;
        sort($betThirds, SORT_NUMERIC);
        $secondKako = implode('', $betSeconds);
        $thirdKako = implode('', $betThirds);

        $summary[$side . '_aite_str'] = implode('・', $newSeconds);
        $summary[$side . '_aite_kako'] = $secondKako;
        $summary[$side . '_kai'] = $head . '-' . $secondKako . '-' . $thirdKako;
        $summary[$prefix . '_applied'] = true;
        $summary[$prefix . '_reason'] = 'applied';

        return $summary;
    }

    private function normalizeProbabilities(array $values): array
    {
        $result = [];
        foreach ($values as $boatKey => $value) {
            $boat = (int)$boatKey;
            if ($boat < 1 || $boat > 6 || !is_numeric($value)) {
                continue;
            }
            $probability = (float)$value;
            if ($probability < 0.0 || !is_finite($probability)) {
                continue;
            }
            $result[$boat] = $probability;
        }
        return $result;
    }

    private function maxProbabilityBoat(array $boats, array $probabilities): int
    {
        usort($boats, static function (int $a, int $b) use ($probabilities): int {
            $pa = (float)($probabilities[$a] ?? 0.0);
            $pb = (float)($probabilities[$b] ?? 0.0);
            if ($pa === $pb) {
                return $a <=> $b;
            }
            return $pb <=> $pa;
        });
        return (int)$boats[0];
    }

    private function minProbabilityBoat(array $boats, array $probabilities): int
    {
        usort($boats, static function (int $a, int $b) use ($probabilities): int {
            $pa = (float)($probabilities[$a] ?? 0.0);
            $pb = (float)($probabilities[$b] ?? 0.0);
            if ($pa === $pb) {
                return $b <=> $a;
            }
            return $pa <=> $pb;
        });
        return (int)$boats[0];
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
