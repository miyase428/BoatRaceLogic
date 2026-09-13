<?php

declare(strict_types=1);

if (!function_exists('getPDO')) {
    require_once __DIR__ . '/../../common/db_connect.php';
}

/**
 * BOAT RACE公式の今節成績へ、過去各走の実際の艇番をDBから補う。
 * 表示専用。予想ロジックには接続しない。
 */
final class CurrentMeetBoatNumberEnricher
{
    public function enrich(array $data): array
    {
        if (($data['status'] ?? '') !== 'ok') {
            return $data;
        }

        $raceCode = strtoupper(trim((string)($data['race_code'] ?? '')));
        if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            return $data;
        }

        $boats = is_array($data['boats'] ?? null) ? $data['boats'] : [];
        $dayCount = (int)($data['day_count'] ?? 0);
        if (!$boats || $dayCount <= 0) {
            return $data;
        }

        try {
            $targetDate = DateTimeImmutable::createFromFormat('!Ymd', $m[1]);
            if (!$targetDate instanceof DateTimeImmutable) {
                return $data;
            }
            $placeCode = $m[2];

            $wanted = [];
            $raceCodes = [];

            foreach ($boats as $currentBoat => $boat) {
                if (!is_array($boat)) continue;
                $playerId = trim((string)($boat['player_id'] ?? ''));
                if ($playerId === '') continue;

                $records = is_array($boat['records'] ?? null) ? $boat['records'] : [];
                foreach ($records as $recordIndex => $record) {
                    if (!is_array($record)) continue;
                    $dayIndex = (int)($record['day_index'] ?? 0);
                    $raceNo = (int)($record['race_no'] ?? 0);
                    if ($dayIndex < 1 || $dayIndex > $dayCount || $raceNo < 1 || $raceNo > 12) {
                        continue;
                    }

                    $daysBack = $dayCount - $dayIndex;
                    $raceDate = $targetDate->modify('-' . $daysBack . ' days');
                    $pastRaceCode = $raceDate->format('Ymd') . $placeCode . sprintf('%02d', $raceNo);
                    $key = $pastRaceCode . '|' . $playerId;

                    $wanted[$key][] = [
                        'current_boat' => (int)$currentBoat,
                        'record_index' => (int)$recordIndex,
                        'race_date' => $raceDate->format('Y-m-d'),
                    ];
                    $raceCodes[$pastRaceCode] = true;
                }
            }

            if (!$wanted || !$raceCodes) {
                return $data;
            }

            $codes = array_keys($raceCodes);
            $placeholders = [];
            $params = [];
            foreach ($codes as $i => $code) {
                $name = ':rc' . $i;
                $placeholders[] = $name;
                $params[$name] = $code;
            }

            $pdo = getPDO();
            $sql = "
                SELECT race_code, lane_number, player_id::text AS player_id
                FROM boat_race.race_entry
                WHERE race_code IN (" . implode(',', $placeholders) . ")
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rc = strtoupper(trim((string)($row['race_code'] ?? '')));
                $pid = trim((string)($row['player_id'] ?? ''));
                $lane = (int)($row['lane_number'] ?? 0);
                if ($rc === '' || $pid === '' || $lane < 1 || $lane > 6) continue;
                $map[$rc . '|' . $pid] = $lane;
            }

            foreach ($wanted as $key => $targets) {
                $historicalBoat = (int)($map[$key] ?? 0);
                foreach ($targets as $target) {
                    $currentBoat = (int)$target['current_boat'];
                    $recordIndex = (int)$target['record_index'];
                    if (!isset($data['boats'][$currentBoat]['records'][$recordIndex])) continue;

                    $data['boats'][$currentBoat]['records'][$recordIndex]['race_date'] = $target['race_date'];
                    $data['boats'][$currentBoat]['records'][$recordIndex]['boat_number'] =
                        ($historicalBoat >= 1 && $historicalBoat <= 6) ? $historicalBoat : null;
                }
            }
        } catch (Throwable $e) {
            // 表示補助なので、DB補完に失敗しても公式取得データ自体はそのまま返す。
            $data['boat_number_enrichment_error'] = $e->getMessage();
        }

        return $data;
    }
}
