<?php
declare(strict_types=1);

require_once __DIR__ . '/../../common/db_connect.php';
require_once __DIR__ . '/../../analysis/lib/FinalPredictionExporter.php';

/**
 * 選択場の直近5開催日（結果が12R揃っている日）について、
 * 現在の本番PredictionLogicを過去レースへ再適用し、
 * 本命/対抗買い目と実3連単の一致・回収率を確認するための表示専用集計。
 *
 * 注意:
 * - 「当時保存した予想ログ」ではなく、現行ロジックの過去再現。
 * - 1点100円均等購入。
 * - 本命+対抗は重複買い目を1点として扱う。
 * - 計算結果は /tmp にロジック版込みでキャッシュする。
 */
class RecentPredictionHistoryLogic
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = getPDO();
    }

    public function load(string $placeCode, string $endDate, bool $force = false): array
    {
        $placeCode = strtoupper(trim($placeCode));
        $endDate = trim($endDate);

        if (!preg_match('/^[A-Z0-9]{3}$/', $placeCode)) {
            throw new InvalidArgumentException('場コードが不正です。');
        }
        if (!$this->isValidDate($endDate)) {
            throw new InvalidArgumentException('基準日が不正です。');
        }

        $logicVersion = $this->logicVersion();
        $cachePath = $this->cachePath($placeCode, $endDate, $logicVersion);

        if (!$force && is_file($cachePath)) {
            $cached = json_decode((string)file_get_contents($cachePath), true);
            if (is_array($cached) && ($cached['status'] ?? '') === 'ok') {
                $cached['cache'] = [
                    'used' => true,
                    'logic_version' => $logicVersion,
                ];
                return $cached;
            }
        }

        $data = $this->build($placeCode, $endDate);
        $data['logic_version'] = $logicVersion;
        $data['generated_at'] = date('c');
        $data['cache'] = [
            'used' => false,
            'logic_version' => $logicVersion,
        ];

        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir)) {
            @file_put_contents(
                $cachePath,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }

        return $data;
    }

    private function build(string $placeCode, string $endDate): array
    {
        $raceRows = $this->loadRecentCompletedRaces($placeCode, $endDate);
        if (empty($raceRows)) {
            return [
                'status' => 'error',
                'error' => '結果が12R揃っている開催日を取得できませんでした。',
                'place_code' => $placeCode,
                'end_date' => $endDate,
                'dates' => [],
                'rows' => [],
                'summary' => $this->blankSummary(),
                'daily' => [],
            ];
        }

        $dates = array_values(array_unique(array_map(
            static fn(array $row): string => (string)$row['race_date'],
            $raceRows
        )));

        $raceCodes = array_values(array_map(
            static fn(array $row): string => (string)$row['race_code'],
            $raceRows
        ));
        $payouts = $this->loadPayouts($raceCodes);

        $exporter = new FinalPredictionExporter();
        $rows = [];
        $daily = [];
        $errors = [];

        $summary = $this->blankSummary();
        $summary['requested_races'] = count($raceRows);

        foreach ($raceRows as $raceRow) {
            $raceCode = (string)$raceRow['race_code'];
            $raceDate = (string)$raceRow['race_date'];
            $raceNumber = (int)$raceRow['race_number'];

            try {
                $export = $exporter->exportRace($raceCode);
                $summaryData = is_array($export['summary'] ?? null) ? $export['summary'] : [];
                $actualData = is_array($export['actual'] ?? null) ? $export['actual'] : [];
                $rankMap = is_array($export['ranks']['final'] ?? null) ? $export['ranks']['final'] : [];

                $honmeiKai = trim((string)($summaryData['honmei_kai'] ?? ''));
                $taikouKai = trim((string)($summaryData['taikou_kai'] ?? ''));
                $actual = trim((string)($actualData['trifecta'] ?? ''));

                if ($actual === '') {
                    throw new RuntimeException('実3連単を取得できません。');
                }

                $honmeiBets = self::expandTrifecta($honmeiKai);
                $taikouBets = self::expandTrifecta($taikouKai);
                $combinedBets = array_values(array_unique(array_merge($honmeiBets, $taikouBets)));

                $honmeiHit = in_array($actual, $honmeiBets, true);
                $taikouHit = in_array($actual, $taikouBets, true);
                $combinedHit = in_array($actual, $combinedBets, true);
                $payout = array_key_exists($raceCode, $payouts) ? $payouts[$raceCode] : null;

                $rankBoats = [];
                if (!empty($rankMap)) {
                    asort($rankMap, SORT_NUMERIC);
                    $rankBoats = array_map('intval', array_keys($rankMap));
                }

                $row = [
                    'race_code' => $raceCode,
                    'race_date' => $raceDate,
                    'race_number' => $raceNumber,
                    'honmei_head' => (int)($summaryData['honmei_head'] ?? 0),
                    'taikou_head' => (int)($summaryData['taikou_head'] ?? 0),
                    'honmei_kai' => $honmeiKai,
                    'taikou_kai' => $taikouKai,
                    'honmei_points' => count($honmeiBets),
                    'taikou_points' => count($taikouBets),
                    'combined_points' => count($combinedBets),
                    'final_top3' => array_slice($rankBoats, 0, 3),
                    'actual_trifecta' => $actual,
                    'honmei_hit' => $honmeiHit,
                    'taikou_hit' => $taikouHit,
                    'combined_hit' => $combinedHit,
                    'payout' => $payout,
                ];
                $rows[] = $row;

                $summary['evaluated_races']++;
                $this->accumulate($summary['honmei'], $honmeiBets, $honmeiHit, $payout);
                $this->accumulate($summary['taikou'], $taikouBets, $taikouHit, $payout);
                $this->accumulate($summary['combined'], $combinedBets, $combinedHit, $payout);

                if (!isset($daily[$raceDate])) {
                    $daily[$raceDate] = $this->blankDaily($raceDate);
                }
                $daily[$raceDate]['races']++;
                $this->accumulate($daily[$raceDate]['honmei'], $honmeiBets, $honmeiHit, $payout);
                $this->accumulate($daily[$raceDate]['taikou'], $taikouBets, $taikouHit, $payout);
                $this->accumulate($daily[$raceDate]['combined'], $combinedBets, $combinedHit, $payout);
            } catch (Throwable $e) {
                $summary['error_races']++;
                $errors[] = [
                    'race_code' => $raceCode,
                    'race_date' => $raceDate,
                    'race_number' => $raceNumber,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->finishSummary($summary);
        foreach ($daily as &$day) {
            $this->finishBucket($day['honmei'], (int)$day['races']);
            $this->finishBucket($day['taikou'], (int)$day['races']);
            $this->finishBucket($day['combined'], (int)$day['races']);
        }
        unset($day);

        // 最新開催日から表示し、各日は1R→12R。
        usort($rows, static function (array $a, array $b): int {
            $dateCmp = strcmp((string)$b['race_date'], (string)$a['race_date']);
            if ($dateCmp !== 0) return $dateCmp;
            return (int)$a['race_number'] <=> (int)$b['race_number'];
        });
        krsort($daily);

        return [
            'status' => 'ok',
            'error' => '',
            'place_code' => $placeCode,
            'end_date' => $endDate,
            'dates' => $dates,
            'rows' => $rows,
            'summary' => $summary,
            'daily' => array_values($daily),
            'errors' => $errors,
            'definition' => [
                'scope' => '結果TOP3が揃う直近5開催日（各日12R）',
                'prediction' => '現在の本番PredictionLogicを過去レースへ再適用',
                'stake' => '1点100円均等',
                'combined' => '本命+対抗の重複買い目は1点に統合',
            ],
        ];
    }

    private function loadRecentCompletedRaces(string $placeCode, string $endDate): array
    {
        $sql = <<<'SQL'
WITH completed_races AS (
    SELECT
        rm.race_code,
        rm.race_date,
        NULLIF(regexp_replace(rm.race_number, '[^0-9]', '', 'g'), '')::int AS race_number
    FROM boat_race.race_master rm
    JOIN boat_race.race_result_detail rrd
      ON rrd.race_code = rm.race_code
    WHERE SUBSTRING(rm.race_code, 9, 3) = :place_code
      AND rm.race_date <= :end_date::date
      AND NULLIF(regexp_replace(rm.race_number, '[^0-9]', '', 'g'), '')::int BETWEEN 1 AND 12
    GROUP BY rm.race_code, rm.race_date, rm.race_number
    HAVING COUNT(DISTINCT CASE
        WHEN rrd.rank IN ('1', '2', '3') THEN rrd.rank
        ELSE NULL
    END) = 3
), recent_days AS (
    SELECT race_date
    FROM completed_races
    GROUP BY race_date
    HAVING COUNT(*) = 12
    ORDER BY race_date DESC
    LIMIT 5
)
SELECT c.race_code, c.race_date, c.race_number
FROM completed_races c
JOIN recent_days d ON d.race_date = c.race_date
ORDER BY c.race_date DESC, c.race_number ASC
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':place_code' => $placeCode,
            ':end_date' => $endDate,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function loadPayouts(array $raceCodes): array
    {
        if (empty($raceCodes)) return [];

        $placeholders = implode(',', array_fill(0, count($raceCodes), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT race_code, trifecta_payout FROM boat_race.race_payouts WHERE race_code IN ({$placeholders})"
        );
        $stmt->execute($raceCodes);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['race_code']] = $row['trifecta_payout'] !== null
                ? (int)$row['trifecta_payout']
                : null;
        }
        return $out;
    }

    public static function expandTrifecta(string $bet): array
    {
        $bet = trim($bet);
        if ($bet === '') return [];

        $parts = explode('-', $bet);
        if (count($parts) !== 3) return [];

        $first = str_split(trim($parts[0]));
        $second = str_split(trim($parts[1]));
        $third = str_split(trim($parts[2]));
        $bets = [];

        foreach ($first as $a) {
            foreach ($second as $b) {
                foreach ($third as $c) {
                    if (!preg_match('/^[1-6]$/', $a . '') || !preg_match('/^[1-6]$/', $b . '') || !preg_match('/^[1-6]$/', $c . '')) {
                        continue;
                    }
                    if ($a === $b || $a === $c || $b === $c) continue;
                    $bets[] = "{$a}-{$b}-{$c}";
                }
            }
        }

        return array_values(array_unique($bets));
    }

    private function accumulate(array &$bucket, array $bets, bool $hit, ?int $payout): void
    {
        $points = count($bets);
        $bucket['bet_points'] += $points;

        if ($hit) {
            $bucket['hits']++;
        }

        if ($payout !== null) {
            $bucket['roi_races']++;
            $bucket['investment'] += $points * 100;
            if ($hit) {
                $bucket['payout'] += $payout;
            }
        }
    }

    private function blankSummary(): array
    {
        return [
            'requested_races' => 0,
            'evaluated_races' => 0,
            'error_races' => 0,
            'honmei' => $this->blankBucket(),
            'taikou' => $this->blankBucket(),
            'combined' => $this->blankBucket(),
        ];
    }

    private function blankDaily(string $date): array
    {
        return [
            'race_date' => $date,
            'races' => 0,
            'honmei' => $this->blankBucket(),
            'taikou' => $this->blankBucket(),
            'combined' => $this->blankBucket(),
        ];
    }

    private function blankBucket(): array
    {
        return [
            'hits' => 0,
            'bet_points' => 0,
            'roi_races' => 0,
            'investment' => 0,
            'payout' => 0,
            'hit_rate' => 0.0,
            'avg_points' => 0.0,
            'roi' => 0.0,
        ];
    }

    private function finishSummary(array &$summary): void
    {
        $n = (int)$summary['evaluated_races'];
        $this->finishBucket($summary['honmei'], $n);
        $this->finishBucket($summary['taikou'], $n);
        $this->finishBucket($summary['combined'], $n);
    }

    private function finishBucket(array &$bucket, int $raceCount): void
    {
        $bucket['hit_rate'] = $raceCount > 0
            ? ((int)$bucket['hits'] / $raceCount) * 100.0
            : 0.0;
        $bucket['avg_points'] = $raceCount > 0
            ? (int)$bucket['bet_points'] / $raceCount
            : 0.0;
        $bucket['roi'] = (int)$bucket['investment'] > 0
            ? ((int)$bucket['payout'] / (int)$bucket['investment']) * 100.0
            : 0.0;
    }

    private function cachePath(string $placeCode, string $endDate, string $logicVersion): string
    {
        $safeDate = str_replace('-', '', $endDate);
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'boatrace_recent_prediction_history'
            . DIRECTORY_SEPARATOR . "{$placeCode}_{$safeDate}_{$logicVersion}.json";
    }

    private function logicVersion(): string
    {
        $paths = [
            __DIR__ . '/PredictionLogic.php',
            __DIR__ . '/PredictionLogicProduction.php',
            __DIR__ . '/../api/ApiClient.php',
            __DIR__ . '/../api/ApiClientProduction.php',
            __DIR__ . '/../../analysis/lib/FinalPredictionExporter.php',
        ];

        $parts = [];
        foreach ($paths as $path) {
            $parts[] = is_file($path) ? (string)sha1_file($path) : basename($path);
        }
        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    private function isValidDate(string $date): bool
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt !== false && $dt->format('Y-m-d') === $date;
    }
}
