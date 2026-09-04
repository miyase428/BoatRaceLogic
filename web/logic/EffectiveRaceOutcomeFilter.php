<?php

declare(strict_types=1);

require_once __DIR__ . '/../../common/db_connect.php';

/**
 * 展示5指標がすべてNULLの艇を「実質欠場艇」とみなし、
 * 3連単出目確率から除外して残り艇だけで100%へ再正規化する。
 *
 * 6艇通常時は計算結果を一切変えない。
 */
final class EffectiveRaceOutcomeFilter
{
    /**
     * @return int[] 有効艇番
     */
    public function detectActiveBoats(string $raceCode): array
    {
        $default = range(1, 6);
        $raceCode = strtoupper(trim($raceCode));
        if (!preg_match('/^\d{8}[A-Z0-9]{3}\d{2}$/', $raceCode)) {
            return $default;
        }

        try {
            $pdo = getPDO();
            $stmt = $pdo->prepare(<<<SQL
SELECT
    re.lane_number AS boat,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time
FROM boat_race.race_entry re
LEFT JOIN boat_race.exhibition_live el
  ON el.race_code = re.race_code
 AND el.player_id = re.player_id
WHERE re.race_code = :race_code
ORDER BY re.lane_number
SQL);
            $stmt->execute([':race_code' => $raceCode]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $default;
        }

        // 現行DBでは真正の5行race_entryではなく、6艇枠を維持したまま
        // 欠場艇の展示5指標が全NULLになるため、その形だけを対象にする。
        if (count($rows) !== 6) {
            return $default;
        }

        $active = [];
        $inactive = [];
        foreach ($rows as $row) {
            $boat = (int)($row['boat'] ?? 0);
            if ($boat < 1 || $boat > 6) {
                return $default;
            }

            $allNull = true;
            foreach (['exhibition_time', 'start_timing', 'lap_time', 'around_time', 'straight_time'] as $key) {
                $value = $row[$key] ?? null;
                if ($value !== null && trim((string)$value) !== '') {
                    $allNull = false;
                    break;
                }
            }

            if ($allNull) {
                $inactive[] = $boat;
            } else {
                $active[] = $boat;
            }
        }

        // 展示前は6艇すべてNULLになり得るため、その場合は6艇通常扱いを維持する。
        // 少なくとも3艇が実測済みで、かつ1艇以上だけ全NULLの時に限定する。
        if (count($active) >= 3 && count($active) < 6 && count($inactive) >= 1) {
            sort($active, SORT_NUMERIC);
            return array_values(array_unique($active));
        }

        return $default;
    }

    public function apply(string $raceCode, array $data): array
    {
        $activeBoats = $this->detectActiveBoats($raceCode);
        $activeSet = array_fill_keys($activeBoats, true);
        $excludedBoats = array_values(array_diff(range(1, 6), $activeBoats));
        $n = count($activeBoats);

        $data['active_boats'] = $activeBoats;
        $data['excluded_boats'] = $excludedBoats;
        $data['exacta_count'] = $n >= 2 ? $n * ($n - 1) : 0;

        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        if (($data['status'] ?? '') !== 'ok' || $n >= 6 || empty($rows)) {
            $data['outcome_count'] = count($rows);
            return $data;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $boats = is_array($row['boats'] ?? null) ? array_map('intval', $row['boats']) : [];
            if (count($boats) !== 3) {
                continue;
            }
            if (isset($activeSet[$boats[0]], $activeSet[$boats[1]], $activeSet[$boats[2]])) {
                $filtered[] = $row;
            }
        }

        $expected = $n * ($n - 1) * ($n - 2);
        if (count($filtered) !== $expected || $expected <= 0) {
            // 想定外の入力では元の6艇結果を壊さない。
            $data['active_boats'] = range(1, 6);
            $data['excluded_boats'] = [];
            $data['exacta_count'] = 30;
            $data['outcome_count'] = count($rows);
            return $data;
        }

        $sumBase = 0.0;
        $sumStep2 = 0.0;
        $sumFinal = 0.0;
        foreach ($filtered as $row) {
            $sumBase += max(0.0, (float)($row['base_probability'] ?? 0.0));
            $sumStep2 += max(0.0, (float)($row['step2_probability'] ?? 0.0));
            $sumFinal += max(0.0, (float)($row['probability'] ?? 0.0));
        }

        if ($sumBase <= 0.0 || $sumStep2 <= 0.0 || $sumFinal <= 0.0) {
            return $data;
        }

        foreach ($filtered as &$row) {
            $row['base_probability'] = max(0.0, (float)($row['base_probability'] ?? 0.0)) / $sumBase;
            $row['step2_probability'] = max(0.0, (float)($row['step2_probability'] ?? 0.0)) / $sumStep2;
            $row['probability'] = max(0.0, (float)($row['probability'] ?? 0.0)) / $sumFinal;
        }
        unset($row);

        usort($filtered, static function (array $a, array $b): int {
            $cmp = ((float)($b['probability'] ?? 0.0)) <=> ((float)($a['probability'] ?? 0.0));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($a['key'] ?? ''), (string)($b['key'] ?? ''));
        });

        $cum = 0.0;
        foreach ($filtered as $index => &$row) {
            $cum += (float)$row['probability'];
            $row['rank'] = $index + 1;
            $row['cumulative_probability'] = $cum;
        }
        unset($row);

        $data['rows'] = $filtered;
        $data['top20'] = array_slice($filtered, 0, 20);
        $data['outcome_count'] = count($filtered);
        $data['totals'] = [
            'base' => array_sum(array_column($filtered, 'base_probability')),
            'step2' => array_sum(array_column($filtered, 'step2_probability')),
            'final' => array_sum(array_column($filtered, 'probability')),
        ];
        $data['method'] = is_array($data['method'] ?? null) ? $data['method'] : [];
        $data['method']['effective_boat_filter'] = 'ALL_NULL_EXHIBITION_EXCLUDE_AND_RENORMALIZE';

        return $data;
    }
}
