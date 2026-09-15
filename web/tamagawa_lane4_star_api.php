<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function validDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

function placeNames(): array
{
    return [
        'KRY' => '桐生', 'TDA' => '戸田', 'EDG' => '江戸川', 'HWJ' => '平和島',
        'TMG' => '多摩川', 'HMN' => '浜名湖', 'GMG' => '蒲郡', 'TKN' => '常滑',
        'TSU' => '津', 'MKN' => '三国', 'BWK' => 'びわこ', 'SME' => '住之江',
        'AMG' => '尼崎', 'NRT' => '鳴門', 'MRG' => '丸亀', 'KJM' => '児島',
        'MYJ' => '宮島', 'TKY' => '徳山', 'SMS' => '下関', 'WKM' => '若松',
        'ASY' => '芦屋', 'FKO' => '福岡', 'KRT' => '唐津', 'OMR' => '大村',
    ];
}

function courseSignalRules(): array
{
    static $rules;
    if ($rules !== null) {
        return $rules;
    }
    $path = __DIR__ . '/../config/course_signal_rules.json';
    if (!is_file($path)) {
        return $rules = ['places' => []];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return $rules = is_array($decoded) ? $decoded : ['places' => []];
}

function primaryEnabled(array $venueRules, int $course, string $variant = 'main'): bool
{
    $configured = $venueRules[(string)$course]['primary_rules'][$variant] ?? null;
    if (is_array($configured) && array_key_exists('enabled', $configured)) {
        return !empty($configured['enabled']);
    }
    return !array_key_exists((string)$course, $venueRules)
        || !empty($venueRules[(string)$course]['primary_enabled']);
}

function primaryThreshold(array $venueRules, int $course, string $variant = 'main'): float
{
    $configured = $venueRules[(string)$course]['primary_rules'][$variant]['threshold'] ?? null;
    if (is_numeric($configured)) {
        return (float)$configured;
    }
    return match ([$course, $variant]) {
        [1, 'main'] => 55.0,
        [2, 'sashi'] => 10.0,
        [2, 'makuri'] => 5.0,
        [3, 'main'] => 15.0,
        [4, 'main'] => 15.0,
        [5, 'main'] => 10.0,
        [6, 'main'] => 5.0,
        default => 0.0,
    };
}

function secondaryEnabled(array $venueRules, int $course, string $variant, string $level, string $placeCode): bool
{
    // 多摩川は既存の検証済み表示を維持する。他場は場別集計で許可した条件だけ昇格。
    if ($placeCode === 'TMG') {
        return true;
    }
    return !empty($venueRules[(string)$course]['secondary'][$variant][$level . '_enabled']);
}

function termInfoForDate(DateTimeImmutable $date): string
{
    $year = (int)$date->format('Y');
    $month = (int)$date->format('n');
    if ($month <= 4) {
        return sprintf('%02d10', ($year - 1) % 100);
    }
    if ($month <= 10) {
        return sprintf('%02d04', $year % 100);
    }
    return sprintf('%02d10', $year % 100);
}

function nullableFloat(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    return is_finite($number) ? $number : null;
}

function avgNonNull(array $values): ?float
{
    $valid = [];
    foreach ($values as $value) {
        $number = nullableFloat($value);
        if ($number !== null) {
            $valid[] = $number;
        }
    }
    return $valid ? array_sum($valid) / count($valid) : null;
}

function calcExhibitionScore(float $diff): float
{
    if ($diff <= -0.10) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <= 0.05) return 3.0;
    if ($diff <= 0.10) return 2.0;
    return 1.0;
}

function calcStScore(float $st): float
{
    if ($st <= 0.00) return 3.0;
    if ($st <= 0.12) return 5.0;
    if ($st <= 0.20) return 3.0;
    if ($st <= 0.30) return 2.0;
    return 1.0;
}

function calcLapScore(float $value, float $avg): float
{
    $diff = $value - $avg;
    if ($diff <= -0.30) return 5.0;
    if ($diff <= -0.10) return 4.0;
    if ($diff <= 0.10) return 3.0;
    if ($diff <= 0.30) return 2.0;
    return 1.0;
}

function calcMawariScore(float $value, float $avg): float
{
    $diff = $value - $avg;
    if ($diff <= -0.20) return 5.0;
    if ($diff <= -0.05) return 4.0;
    if ($diff <= 0.05) return 3.0;
    if ($diff <= 0.20) return 2.0;
    return 1.0;
}

function calcStraightScore(float $value, float $avg): float
{
    $diff = $value - $avg;
    if ($diff <= -0.04) return 5.0;
    if ($diff <= -0.01) return 4.0;
    if ($diff <= 0.01) return 3.0;
    if ($diff <= 0.04) return 2.0;
    return 1.0;
}

/**
 * 現行本番Web（ApiClientProduction）と同じ二次スコアを再現する。
 * 旧2・4固定+1は加算しない。
 */
function buildSecondEval(array $rows, float $avgExhibition, string $targetPlayerId): ?array
{
    if (count($rows) !== 6) {
        return null;
    }

    $courses = [];
    $players = [];
    foreach ($rows as $row) {
        $course = (int)($row['entry_course'] ?? 0);
        $pid = trim((string)($row['player_id'] ?? ''));
        if ($course < 1 || $course > 6 || $pid === '' || isset($courses[$course]) || isset($players[$pid])) {
            return null;
        }
        $courses[$course] = true;
        $players[$pid] = true;
    }
    if (count($courses) !== 6) {
        return null;
    }

    $avgLap = avgNonNull(array_column($rows, 'lap_time'));
    $avgMawari = avgNonNull(array_column($rows, 'around_time'));
    $avgStraight = avgNonNull(array_column($rows, 'straight_time'));

    $topScore = null;
    $targetEval = null;
    $scores = [];

    foreach ($rows as $row) {
        $exhibition = nullableFloat($row['exhibition_time'] ?? null);
        $st = nullableFloat($row['start_timing'] ?? null);
        $lap = nullableFloat($row['lap_time'] ?? null);
        $mawari = nullableFloat($row['around_time'] ?? null);
        $straight = nullableFloat($row['straight_time'] ?? null);

        $exScore = $exhibition === null ? 3.0 : calcExhibitionScore($exhibition - $avgExhibition);
        $stScore = $st === null ? 3.0 : calcStScore($st);
        $lapScore = ($lap === null || $avgLap === null) ? 3.0 : calcLapScore($lap, $avgLap);
        $mawariScore = ($mawari === null || $avgMawari === null) ? 3.0 : calcMawariScore($mawari, $avgMawari);
        $straightScore = ($straight === null || $avgStraight === null) ? 3.0 : calcStraightScore($straight, $avgStraight);

        $exTotal = $exScore + $lapScore + $mawariScore + $straightScore;
        $attackPotential = $stScore + $straightScore;
        $stableScore = $lapScore + $mawariScore;
        $finalScore = $exTotal + $attackPotential + $stableScore;
        $scores[] = $finalScore;

        $topScore = $topScore === null ? $finalScore : max($topScore, $finalScore);

        if (trim((string)$row['player_id']) === $targetPlayerId) {
            $targetEval = [
                'second_score' => $finalScore,
                'attack_potential' => $attackPotential,
                'straight_score' => $straightScore,
                'mawari_score' => $mawariScore,
                'lap_score' => $lapScore,
                'st_score' => $stScore,
            ];
        }
    }

    if ($targetEval === null || $topScore === null) {
        return null;
    }

    $targetEval['top_score'] = $topScore;
    $targetEval['gap_to_top'] = $topScore - $targetEval['second_score'];
    $targetEval['second_rank'] = 1;
    foreach ($scores as $score) {
        if ($score > $targetEval['second_score']) {
            $targetEval['second_rank']++;
        }
    }
    return $targetEval;
}

/** コースサインの固定検証期間（2024-09-10～2026-09-09）における実績。 */
function laneHistoricalStats(int $course, int $level, string $placeCode = 'TMG', string $variant = 'main'): ?array
{
    $venueRules = courseSignalRules()['places'][$placeCode] ?? [];
    $rule = $venueRules[(string)$course] ?? null;
    $optimized = is_array($rule) ? ($rule['primary_rules'][$variant]['optimization'] ?? null) : null;
    if ($level === 1 && is_array($optimized) && !empty($optimized['enabled'])) {
        $overall = $optimized['overall'] ?? [];
        $baseline = $optimized['baseline'] ?? [];
        return [
            'period' => '2024-09-10～2026-09-09',
            'n' => (int)($overall['n'] ?? 0),
            'first_rate' => round((float)($overall['first'] ?? 0.0), 2),
            'top2_rate' => round((float)($overall['top2'] ?? 0.0), 2),
            'top3_rate' => round((float)($overall['top3'] ?? 0.0), 2),
            'first_delta' => round((float)($overall['first'] ?? 0.0) - (float)($baseline['first'] ?? 0.0), 2),
            'top2_delta' => round((float)($overall['top2'] ?? 0.0) - (float)($baseline['top2'] ?? 0.0), 2),
            'top3_delta' => round((float)($overall['top3'] ?? 0.0) - (float)($baseline['top3'] ?? 0.0), 2),
            'baseline_n' => (int)($baseline['n'] ?? 0),
            'baseline_first_rate' => round((float)($baseline['first'] ?? 0.0), 2),
            'baseline_top2_rate' => round((float)($baseline['top2'] ?? 0.0), 2),
            'baseline_top3_rate' => round((float)($baseline['top3'] ?? 0.0), 2),
        ];
    }
    if ($placeCode !== 'TMG') {
        if (!is_array($rule) || !$rule['primary_enabled'] || $level !== 1) {
            return null;
        }
        return $rule['primary_stats'] ?? null;
    }
    $baseline = [
        1 => ['n' => 3903, 'first' => 54.50, 'top2' => 70.46, 'top3' => 79.71],
        2 => ['n' => 3900, 'first' => 13.87, 'top2' => 38.64, 'top3' => 57.10],
        3 => ['n' => 3897, 'first' => 12.88, 'top2' => 34.74, 'top3' => 54.22],
        4 => ['n' => 3907, 'first' => 10.44, 'top2' => 26.90, 'top3' => 46.66],
        5 => ['n' => 3907, 'first' => 6.09, 'top2' => 19.96, 'top3' => 38.39],
        6 => ['n' => 3899, 'first' => 2.28, 'top2' => 9.49, 'top3' => 23.80],
    ][$course] ?? null;
    $values = [
        1 => [
            1 => ['n' => 1707, 'first' => 68.72, 'top2' => 82.43, 'top3' => 88.40],
            2 => ['n' => 1571, 'first' => 69.19, 'top2' => 82.88, 'top3' => 88.92],
            3 => ['n' => 1022, 'first' => 71.04, 'top2' => 82.97, 'top3' => 88.65],
        ],
        2 => [
            1 => ['n' => 1467, 'first' => 17.59, 'top2' => 46.22, 'top3' => 64.14],
            2 => ['n' => 1098, 'first' => 19.40, 'top2' => 48.82, 'top3' => 67.58],
            3 => ['n' => 381, 'first' => 25.20, 'top2' => 51.44, 'top3' => 70.08],
        ],
        3 => [
            1 => ['n' => 943, 'first' => 18.56, 'top2' => 45.28, 'top3' => 66.81],
            2 => ['n' => 170, 'first' => 30.00, 'top2' => 55.29, 'top3' => 74.12],
            3 => ['n' => 55, 'first' => 45.45, 'top2' => 65.45, 'top3' => 83.64],
        ],
        4 => [
            1 => ['n' => 160, 'first' => 23.75, 'top2' => 40.63, 'top3' => 62.50],
            2 => ['n' => 49, 'first' => 34.69, 'top2' => 55.10, 'top3' => 79.59],
            3 => ['n' => 24, 'first' => 41.67, 'top2' => 50.00, 'top3' => 75.00],
        ],
        5 => [
            1 => ['n' => 686, 'first' => 11.37, 'top2' => 30.47, 'top3' => 50.58],
            2 => ['n' => 383, 'first' => 14.10, 'top2' => 35.77, 'top3' => 59.01],
            3 => ['n' => 105, 'first' => 19.05, 'top2' => 42.86, 'top3' => 62.86],
        ],
        6 => [
            1 => ['n' => 180, 'first' => 11.11, 'top2' => 25.56, 'top3' => 45.00],
            2 => ['n' => 78, 'first' => 14.10, 'top2' => 29.49, 'top3' => 48.72],
            // 6Cでは★★★を公式採用しないため、万一の表示時も★★実績を基準にする。
            3 => ['n' => 78, 'first' => 14.10, 'top2' => 29.49, 'top3' => 48.72],
        ],
    ];
    $courseValues = $values[$course] ?? [];
    $stat = $courseValues[$level] ?? ($courseValues[1] ?? ['n' => 0, 'first' => 0.0, 'top2' => 0.0, 'top3' => 0.0]);
    if ($baseline === null) {
        $baseline = ['n' => 0, 'first' => 0.0, 'top2' => 0.0, 'top3' => 0.0];
    }
    return [
        'period' => '2024-09-10～2026-09-09',
        'n' => $stat['n'],
        'first_rate' => $stat['first'],
        'top2_rate' => $stat['top2'],
        'top3_rate' => $stat['top3'],
        'first_delta' => round($stat['first'] - $baseline['first'], 2),
        'top2_delta' => round($stat['top2'] - $baseline['top2'], 2),
        'top3_delta' => round($stat['top3'] - $baseline['top3'], 2),
        'baseline_n' => $baseline['n'],
        'baseline_first_rate' => $baseline['first'],
        'baseline_top2_rate' => $baseline['top2'],
        'baseline_top3_rate' => $baseline['top3'],
    ];
}

$dateText = trim((string)($_GET['date'] ?? ''));
if (!validDate($dateText)) {
    respond(['status' => 'error', 'error' => 'invalid date'], 400);
}

$placeCode = strtoupper(trim((string)($_GET['place'] ?? 'TMG')));
$places = placeNames();
if (!isset($places[$placeCode])) {
    respond(['status' => 'error', 'error' => 'invalid place'], 400);
}
$placeName = $places[$placeCode];
$rules = courseSignalRules();
$venueRules = $rules['places'][$placeCode] ?? [];

$date = new DateTimeImmutable($dateText);
$historyStart = $date->modify('-12 months')->format('Y-m-d');
$term = termInfoForDate($date);
$datePrefix = $date->format('Ymd') . $placeCode;

try {
    $pdo = getPDO();

    // 当日の進入は展示進入を優先し、展示前は枠番を仮コースとして使う。
    $targetSql = <<<'SQL'
WITH ex_map AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id,
        el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    WHERE el.race_code LIKE :prefix_ex
      AND el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
), current_entries AS (
    SELECT
        re.race_code,
        re.player_id::text AS player_id,
        COALESCE(ex.entry_course, re.lane_number::integer) AS entry_course
    FROM boat_race.race_entry re
    LEFT JOIN ex_map ex
      ON ex.race_code = re.race_code
     AND ex.player_id = re.player_id
    WHERE re.race_code LIKE :prefix_entry
)
SELECT
    race_code,
    MAX(player_id) FILTER (WHERE entry_course = 1) AS lane1_player_id,
    MAX(player_id) FILTER (WHERE entry_course = 2) AS lane2_player_id,
    MAX(player_id) FILTER (WHERE entry_course = 3) AS lane3_player_id,
    MAX(player_id) FILTER (WHERE entry_course = 4) AS lane4_player_id,
    MAX(player_id) FILTER (WHERE entry_course = 5) AS lane5_player_id,
    MAX(player_id) FILTER (WHERE entry_course = 6) AS lane6_player_id
FROM current_entries
GROUP BY race_code
ORDER BY race_code
SQL;

    $stmt = $pdo->prepare($targetSql);
    $stmt->execute([
        ':prefix_ex' => $datePrefix . '%',
        ':prefix_entry' => $datePrefix . '%',
    ]);
    $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $threshold1 = primaryThreshold($venueRules, 1, 'main');
    $threshold2Sashi = primaryThreshold($venueRules, 2, 'sashi');
    $threshold2Makuri = primaryThreshold($venueRules, 2, 'makuri');
    $threshold3 = primaryThreshold($venueRules, 3, 'main');
    $threshold4 = primaryThreshold($venueRules, 4, 'main');
    $threshold5 = primaryThreshold($venueRules, 5, 'main');
    $threshold6 = primaryThreshold($venueRules, 6, 'main');
    $conditionText = static function (bool $enabled, string $text): string {
        return $enabled ? $text : '場別最適条件で安定性不足のため停止';
    };
    $baseResponse = [
        'status' => 'ok',
        'date' => $dateText,
        'place' => $placeCode,
        'place_name' => $placeName,
        'profile_months' => 12,
        'conditions' => [
            'star' => $conditionText(primaryEnabled($venueRules, 4), sprintf('4コースまくり率%.0f%%以上 + 4が3より平均ST順位上', $threshold4)),
            'double_star' => '★ + 二次24以上 + TOP差5以内',
            'triple_star' => '★★ + 二次27以上 + 直線評価4以上（検証中）',
        ],
        'lane3_conditions' => [
            'star' => $conditionText(primaryEnabled($venueRules, 3), sprintf('3コース攻め率（まくり+まくり差し）%.0f%%以上', $threshold3)),
            'double_star' => '★ + 二次30以上 + TOP差2以内',
            'triple_star' => '★★ + 直線評価5 + 周り足4以上（検証中）',
        ],
        'lane6_conditions' => [
            'star' => $conditionText(primaryEnabled($venueRules, 6), sprintf('6コース攻め率%.0f%%以上 + 6が5より平均ST順位上', $threshold6)),
            'double_star' => '★ + 二次評価3位以内',
            'triple_star' => '未採用',
        ],
        'lane1_conditions' => [
            'star' => $conditionText(primaryEnabled($venueRules, 1), sprintf('1コース過去逃げ率%.0f%%以上', $threshold1)),
            'double_star' => '★ + 二次評価3位以内',
            'triple_star' => '★ + 二次評価1位',
        ],
        'lane2_sashi_conditions' => [
            'star' => $conditionText(primaryEnabled($venueRules, 2, 'sashi'), sprintf('2コース差し率%.0f%%以上', $threshold2Sashi)),
            'double_star' => '★ + 二次評価3位以内 または 周回評価4以上',
            'triple_star' => '★ + 二次評価1位',
        ],
        'lane2_makuri_conditions' => [
            'star' => $conditionText(primaryEnabled($venueRules, 2, 'makuri'), sprintf('2コースまくり率%.0f%%以上 + 2が1より平均ST順位上', $threshold2Makuri)),
            'double_star' => '★ + 周回評価4以上',
            'triple_star' => '★ + 二次評価1位',
        ],
        'lane2_sashi_matches' => [],
        'lane2_makuri_matches' => [],
        'matches' => [],
        'lane3_matches' => [],
        'lane5_matches' => [],
        'lane6_matches' => [],
        'lane1_matches' => [],
        'lane5_conditions' => [
            'star' => $conditionText(primaryEnabled($venueRules, 5), sprintf('5コース攻め率（まくり+まくり差し）%.0f%%以上', $threshold5)),
            'double_star' => '★ + 二次評価3位以内 または 周回評価4以上',
            'triple_star' => '★ + 二次評価1位',
        ],
    ];

    if (!$targets) {
        respond($baseResponse);
    }

    $playerIds = [];
    foreach ($targets as $row) {
        foreach (['lane1_player_id', 'lane2_player_id', 'lane3_player_id', 'lane4_player_id', 'lane5_player_id', 'lane6_player_id'] as $key) {
            $pid = trim((string)($row[$key] ?? ''));
            if ($pid !== '') {
                $playerIds[$pid] = true;
            }
        }
    }
    $playerIds = array_keys($playerIds);
    if (!$playerIds) {
        respond($baseResponse);
    }

    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));

    // 期別のコース別平均ST順位。数値が小さいほど順位が上。
    $rankSql = "SELECT player_id::text, course1_average_rank, course2_average_rank, course3_average_rank, course4_average_rank, course5_average_rank, course6_average_rank\n"
        . "FROM boat_race.racer_results\n"
        . "WHERE term_info::text = ? AND player_id::text IN ({$placeholders})";
    $rankStmt = $pdo->prepare($rankSql);
    $rankStmt->execute(array_merge([$term], $playerIds));
    $ranks = [];
    foreach ($rankStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = trim((string)$row['player_id']);
        $ranks[$pid] = [
            1 => is_numeric($row['course1_average_rank'] ?? null) ? (float)$row['course1_average_rank'] : null,
            2 => is_numeric($row['course2_average_rank'] ?? null) ? (float)$row['course2_average_rank'] : null,
            3 => is_numeric($row['course3_average_rank'] ?? null) ? (float)$row['course3_average_rank'] : null,
            4 => is_numeric($row['course4_average_rank'] ?? null) ? (float)$row['course4_average_rank'] : null,
            5 => is_numeric($row['course5_average_rank'] ?? null) ? (float)$row['course5_average_rank'] : null,
            6 => is_numeric($row['course6_average_rank'] ?? null) ? (float)$row['course6_average_rank'] : null,
        ];
    }

    // 2～5コース選手の過去12ヶ月profileを一括集計する。対象日は含めない。
    // 1回の履歴走査で各コースを作り、TOP表示の待ち時間増加を抑える。
    $profiles = [];
    $lane2Profiles = [];
    $lane1Profiles = [];
    $lane3Profiles = [];
    $lane5Profiles = [];
    $lane6Profiles = [];
    $historySql = <<<SQL
WITH hr AS (
    SELECT race_code, race_date
    FROM boat_race.race_master
    WHERE race_date >= ?::date
      AND race_date < ?::date
),
rd_map AS (
    SELECT DISTINCT ON (rrd.race_code, rrd.player_id)
        rrd.race_code,
        rrd.player_id,
        rrd.entry_course::integer AS entry_course
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE rrd.entry_course BETWEEN 1 AND 6
    ORDER BY rrd.race_code, rrd.player_id
),
ex_map AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id,
        el.entry_course::integer AS entry_course
    FROM boat_race.exhibition_live el
    JOIN hr ON hr.race_code = el.race_code
    WHERE el.entry_course BETWEEN 1 AND 6
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
),
winner AS (
    SELECT DISTINCT ON (rrd.race_code)
        rrd.race_code,
        rrd.player_id::text AS winner_player_id,
        TRIM(COALESCE(rrd.technique, '')) AS winner_technique
    FROM boat_race.race_result_detail rrd
    JOIN hr ON hr.race_code = rrd.race_code
    WHERE TRIM(rrd.rank::text) = '1'
    ORDER BY rrd.race_code
)
SELECT
    re.player_id::text AS player_id,
    COALESCE(rd.entry_course, ex.entry_course)::integer AS entry_course,
    COUNT(*) AS history_n,
    COUNT(*) FILTER (
        WHERE w.winner_player_id = re.player_id::text
          AND w.winner_technique = 'まくり'
    ) AS makuri_n,
    COUNT(*) FILTER (
        WHERE w.winner_player_id = re.player_id::text
          AND w.winner_technique = 'まくり差し'
    ) AS makurizashi_n,
    COUNT(*) FILTER (
        WHERE w.winner_player_id = re.player_id::text
          AND w.winner_technique = '逃げ'
    ) AS nige_n,
    COUNT(*) FILTER (
        WHERE w.winner_player_id = re.player_id::text
          AND w.winner_technique = '差し'
    ) AS sashi_n
FROM boat_race.race_entry re
JOIN hr ON hr.race_code = re.race_code
LEFT JOIN rd_map rd
  ON rd.race_code = re.race_code
 AND rd.player_id = re.player_id
LEFT JOIN ex_map ex
  ON ex.race_code = re.race_code
 AND ex.player_id = re.player_id
JOIN winner w ON w.race_code = re.race_code
WHERE re.player_id::text IN ({$placeholders})
      AND COALESCE(rd.entry_course, ex.entry_course) IN (1, 2, 3, 4, 5, 6)
GROUP BY re.player_id, COALESCE(rd.entry_course, ex.entry_course)
SQL;
    $historyStmt = $pdo->prepare($historySql);
    $historyStmt->execute(array_merge([$historyStart, $dateText], $playerIds));
    foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = trim((string)$row['player_id']);
        $course = (int)($row['entry_course'] ?? 0);
        $n = (int)($row['history_n'] ?? 0);
        $makuriN = (int)($row['makuri_n'] ?? 0);
        $makurizashiN = (int)($row['makurizashi_n'] ?? 0);
        $nigeN = (int)($row['nige_n'] ?? 0);
        $sashiN = (int)($row['sashi_n'] ?? 0);
        if ($course === 1) {
            $lane1Profiles[$pid] = [
                'n' => $n,
                'nige_n' => $nigeN,
                'nige_rate' => $n > 0 ? (100.0 * $nigeN / $n) : null,
            ];
        } elseif ($course === 2) {
            $lane2Profiles[$pid] = [
                'n' => $n,
                'sashi_rate' => $n > 0 ? (100.0 * $sashiN / $n) : null,
                'makuri_rate' => $n > 0 ? (100.0 * $makuriN / $n) : null,
                'makurizashi_rate' => $n > 0 ? (100.0 * $makurizashiN / $n) : null,
            ];
        } elseif ($course === 3) {
            $lane3Profiles[$pid] = [
                'n' => $n,
                'makuri_n' => $makuriN,
                'makurizashi_n' => $makurizashiN,
                'attack_rate' => $n > 0 ? (100.0 * ($makuriN + $makurizashiN) / $n) : null,
            ];
        } elseif ($course === 4) {
            $profiles[$pid] = [
                'n' => $n,
                'makuri_n' => $makuriN,
                'makuri_rate' => $n > 0 ? (100.0 * $makuriN / $n) : null,
            ];
        } elseif ($course === 5) {
            $lane5Profiles[$pid] = [
                'n' => $n,
                'makuri_n' => $makuriN,
                'makurizashi_n' => $makurizashiN,
                'attack_rate' => $n > 0 ? (100.0 * ($makuriN + $makurizashiN) / $n) : null,
                'makuri_rate' => $n > 0 ? (100.0 * $makuriN / $n) : null,
                'makurizashi_rate' => $n > 0 ? (100.0 * $makurizashiN / $n) : null,
            ];
        } elseif ($course === 6) {
            $lane6Profiles[$pid] = [
                'n' => $n,
                'makuri_n' => $makuriN,
                'makurizashi_n' => $makurizashiN,
                'attack_rate' => $n > 0 ? (100.0 * ($makuriN + $makurizashiN) / $n) : null,
            ];
        }
    }

    // 展示取得済みレースだけ★★/★★★へ昇格判定する。
    $avgExhibition = null;
    $avgStmt = $pdo->prepare(
        "SELECT avg_exhibition_time_6m FROM boat_race.exhibition_avg_6m WHERE stadium_name = :stadium LIMIT 1"
    );
    $avgStmt->execute([':stadium' => $placeName]);
    $avgValue = $avgStmt->fetchColumn();
    if (is_numeric($avgValue) && (float)$avgValue > 0) {
        $avgExhibition = (float)$avgValue;
    }

    $exhibitionByRace = [];
    if ($avgExhibition !== null) {
        $exSql = <<<'SQL'
WITH latest AS (
    SELECT DISTINCT ON (el.race_code, el.player_id)
        el.race_code,
        el.player_id::text AS player_id,
        el.entry_course::integer AS entry_course,
        el.exhibition_time,
        el.start_timing,
        el.lap_time,
        el.around_time,
        el.straight_time
    FROM boat_race.exhibition_live el
    WHERE el.race_code LIKE :prefix
    ORDER BY el.race_code, el.player_id, el.created_date DESC NULLS LAST
)
SELECT *
FROM latest
WHERE entry_course BETWEEN 1 AND 6
ORDER BY race_code, entry_course
SQL;
        $exStmt = $pdo->prepare($exSql);
        $exStmt->execute([':prefix' => $datePrefix . '%']);
        foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $raceCode = trim((string)$row['race_code']);
            $exhibitionByRace[$raceCode][] = $row;
        }
    }

    $lane1Matches = [];
    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid1 = trim((string)($row['lane1_player_id'] ?? ''));
        if ($raceCode === '' || $pid1 === '') {
            continue;
        }
        if (!primaryEnabled($venueRules, 1)) {
            continue;
        }

        $profile = $lane1Profiles[$pid1] ?? null;
        $escapeRate = is_array($profile) ? ($profile['nige_rate'] ?? null) : null;
        if (!is_numeric($escapeRate) || (float)$escapeRate < primaryThreshold($venueRules, 1)) {
            continue;
        }

        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid1);
        }
        $starLevel = 1;
        if (is_array($secondary) && secondaryEnabled($venueRules, 1, 'main', 'double', $placeCode) && (int)$secondary['second_rank'] <= 3) {
            $starLevel = 2;
            if (secondaryEnabled($venueRules, 1, 'main', 'triple', $placeCode) && (int)$secondary['second_rank'] === 1) {
                $starLevel = 3;
            }
        }

        $detail = [
            'race_code' => $raceCode,
            'course' => 1,
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => match ($starLevel) {
                3 => '1頭強',
                2 => '1軸',
                default => '1逃げ',
            },
            'nige_rate' => round((float)$escapeRate, 2),
            'history_n' => (int)($profile['n'] ?? 0),
            'secondary_ready' => is_array($secondary),
            'historical_stats' => laneHistoricalStats(1, $starLevel, $placeCode),
        ];
        if (is_array($secondary)) {
            $detail['second_rank'] = (int)$secondary['second_rank'];
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['lap_score'] = round((float)$secondary['lap_score'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['mawari_score'] = round((float)$secondary['mawari_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }
        $lane1Matches[$raceCode] = $detail;
    }

    $lane2SashiMatches = [];
    $lane2MakuriMatches = [];
    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid2 = trim((string)($row['lane2_player_id'] ?? ''));
        if ($raceCode === '' || $pid2 === '') {
            continue;
        }
        if (!primaryEnabled($venueRules, 2, 'sashi')) {
            continue;
        }
        $profile = $lane2Profiles[$pid2] ?? null;
        if (!is_array($profile) || !is_numeric($profile['sashi_rate'] ?? null)) {
            continue;
        }

        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid2);
        }
        $starLevel = 1;
        if (is_array($secondary)) {
            $secondRank = (int)$secondary['second_rank'];
            $lapScore = (float)$secondary['lap_score'];
            if (secondaryEnabled($venueRules, 2, 'sashi', 'double', $placeCode) && ($secondRank <= 3 || $lapScore >= 4.0)) {
                $starLevel = 2;
                if (secondaryEnabled($venueRules, 2, 'sashi', 'triple', $placeCode) && $secondRank === 1) {
                    $starLevel = 3;
                }
            }
        }
        if ((float)$profile['sashi_rate'] < primaryThreshold($venueRules, 2, 'sashi')) {
            continue;
        }
        $detail = [
            'race_code' => $raceCode,
            'course' => 2,
            'technique' => 'sashi',
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => match ($starLevel) {
                3 => '2差し強',
                2 => '2差し候補',
                default => '2差し',
            },
            'sashi_rate' => round((float)$profile['sashi_rate'], 2),
            'history_n' => (int)($profile['n'] ?? 0),
            'secondary_ready' => is_array($secondary),
            'historical_stats' => laneHistoricalStats(2, $starLevel, $placeCode, 'sashi'),
        ];
        if (is_array($secondary)) {
            $detail['second_rank'] = (int)$secondary['second_rank'];
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['lap_score'] = round((float)$secondary['lap_score'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['mawari_score'] = round((float)$secondary['mawari_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }
        $lane2SashiMatches[$raceCode] = $detail;
    }

    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid2 = trim((string)($row['lane2_player_id'] ?? ''));
        $pid1 = trim((string)($row['lane1_player_id'] ?? ''));
        if ($raceCode === '' || $pid2 === '' || $pid1 === '') {
            continue;
        }
        if (!primaryEnabled($venueRules, 2, 'makuri')) {
            continue;
        }
        $profile = $lane2Profiles[$pid2] ?? null;
        $rank1 = $ranks[$pid1][1] ?? null;
        $rank2 = $ranks[$pid2][2] ?? null;
        if (!is_array($profile) || !is_numeric($profile['makuri_rate'] ?? null)
            || !is_numeric($rank1) || !is_numeric($rank2)
            || (float)$profile['makuri_rate'] < primaryThreshold($venueRules, 2, 'makuri') || (float)$rank2 >= (float)$rank1) {
            continue;
        }
        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid2);
        }
        $starLevel = 1;
        if (is_array($secondary)) {
            $secondRank = (int)$secondary['second_rank'];
            $lapScore = (float)$secondary['lap_score'];
            if (secondaryEnabled($venueRules, 2, 'makuri', 'double', $placeCode) && $lapScore >= 4.0) {
                $starLevel = 2;
                if (secondaryEnabled($venueRules, 2, 'makuri', 'triple', $placeCode) && $secondRank === 1) {
                    $starLevel = 3;
                }
            }
        }
        $detail = [
            'race_code' => $raceCode,
            'course' => 2,
            'technique' => 'makuri',
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => match ($starLevel) {
                3 => '2まくり強',
                2 => '2まくり候補',
                default => '2まくり',
            },
            'makuri_rate' => round((float)$profile['makuri_rate'], 2),
            'lane1_avg_rank' => round((float)$rank1, 2),
            'lane2_avg_rank' => round((float)$rank2, 2),
            'history_n' => (int)($profile['n'] ?? 0),
            'secondary_ready' => is_array($secondary),
            'historical_stats' => laneHistoricalStats(2, $starLevel, $placeCode, 'makuri'),
        ];
        if (is_array($secondary)) {
            $detail['second_rank'] = (int)$secondary['second_rank'];
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['lap_score'] = round((float)$secondary['lap_score'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['mawari_score'] = round((float)$secondary['mawari_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }
        $lane2MakuriMatches[$raceCode] = $detail;
    }

    $matches = [];
    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid3 = trim((string)($row['lane3_player_id'] ?? ''));
        $pid4 = trim((string)($row['lane4_player_id'] ?? ''));
        if ($raceCode === '' || $pid3 === '' || $pid4 === '') {
            continue;
        }
        if (!primaryEnabled($venueRules, 4, 'main')) {
            continue;
        }

        $rank3 = $ranks[$pid3][3] ?? null;
        $rank4 = $ranks[$pid4][4] ?? null;
        $profile = $profiles[$pid4] ?? null;
        $makuriRate = is_array($profile) ? ($profile['makuri_rate'] ?? null) : null;

        if (!is_numeric($rank3) || !is_numeric($rank4) || !is_numeric($makuriRate)) {
            continue;
        }
        $rank3 = (float)$rank3;
        $rank4 = (float)$rank4;
        $makuriRate = (float)$makuriRate;
        if ($rank3 < 1.0 || $rank3 > 6.0 || $rank4 < 1.0 || $rank4 > 6.0) {
            continue;
        }

        if ($makuriRate < primaryThreshold($venueRules, 4, 'main') || $rank4 >= $rank3) {
            continue;
        }

        $starLevel = 1;
        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid4);
            if (is_array($secondary)) {
                $score = (float)$secondary['second_score'];
                $gap = (float)$secondary['gap_to_top'];
                $straightScore = (float)$secondary['straight_score'];

                if (secondaryEnabled($venueRules, 4, 'main', 'double', $placeCode) && $score >= 24.0 && $gap <= 5.0) {
                    $starLevel = 2;
                    if (secondaryEnabled($venueRules, 4, 'main', 'triple', $placeCode) && $score >= 27.0 && $straightScore >= 4.0) {
                        $starLevel = 3;
                    }
                }
            }
        }

        $detail = [
            'race_code' => $raceCode,
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => match ($starLevel) {
                3 => '4頭強',
                2 => '4軸',
                default => '4攻め',
            },
            'makuri_rate' => round($makuriRate, 2),
            'history_n' => (int)($profile['n'] ?? 0),
            'lane3_avg_rank' => round($rank3, 2),
            'lane4_avg_rank' => round($rank4, 2),
            'secondary_ready' => is_array($secondary),
            'historical_stats' => laneHistoricalStats(4, $starLevel, $placeCode),
        ];

        if (is_array($secondary)) {
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }

        $matches[$raceCode] = $detail;
    }

    $lane3Matches = [];
    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid3 = trim((string)($row['lane3_player_id'] ?? ''));
        if ($raceCode === '' || $pid3 === '') {
            continue;
        }
        if (!primaryEnabled($venueRules, 3, 'main')) {
            continue;
        }

        $profile = $lane3Profiles[$pid3] ?? null;
        $attackRate = is_array($profile) ? ($profile['attack_rate'] ?? null) : null;
        if (!is_numeric($attackRate) || (float)$attackRate < primaryThreshold($venueRules, 3, 'main')) {
            continue;
        }
        $attackRate = (float)$attackRate;

        $starLevel = 1;
        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid3);
            if (is_array($secondary)) {
                $score = (float)$secondary['second_score'];
                $gap = (float)$secondary['gap_to_top'];
                $straightScore = (float)$secondary['straight_score'];
                $mawariScore = (float)$secondary['mawari_score'];

                if (secondaryEnabled($venueRules, 3, 'main', 'double', $placeCode) && $score >= 30.0 && $gap <= 2.0) {
                    $starLevel = 2;
                    if (secondaryEnabled($venueRules, 3, 'main', 'triple', $placeCode) && $straightScore >= 5.0 && $mawariScore >= 4.0) {
                        $starLevel = 3;
                    }
                }
            }
        }

        $detail = [
            'race_code' => $raceCode,
            'course' => 3,
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => match ($starLevel) {
                3 => '3頭強',
                2 => '3軸',
                default => '3攻め',
            },
            'attack_rate' => round($attackRate, 2),
            'makuri_rate' => round(
                (int)($profile['n'] ?? 0) > 0
                    ? 100.0 * (int)($profile['makuri_n'] ?? 0) / (int)$profile['n']
                    : 0.0,
                2
            ),
            'makurizashi_rate' => round(
                (int)($profile['n'] ?? 0) > 0
                    ? 100.0 * (int)($profile['makurizashi_n'] ?? 0) / (int)$profile['n']
                    : 0.0,
                2
            ),
            'history_n' => (int)($profile['n'] ?? 0),
            'secondary_ready' => is_array($secondary),
            'historical_stats' => laneHistoricalStats(3, $starLevel, $placeCode),
        ];

        if (is_array($secondary)) {
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['mawari_score'] = round((float)$secondary['mawari_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }

        $lane3Matches[$raceCode] = $detail;
    }

    $lane5Matches = [];
    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid5 = trim((string)($row['lane5_player_id'] ?? ''));
        if ($raceCode === '' || $pid5 === '') {
            continue;
        }
        if (!primaryEnabled($venueRules, 5, 'main')) {
            continue;
        }

        $profile = $lane5Profiles[$pid5] ?? null;
        $attackRate = is_array($profile) ? ($profile['attack_rate'] ?? null) : null;
        if (!is_numeric($attackRate) || (float)$attackRate < primaryThreshold($venueRules, 5, 'main')) {
            continue;
        }
        $attackRate = (float)$attackRate;

        $starLevel = 1;
        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid5);
            if (is_array($secondary)) {
                $secondRank = (int)$secondary['second_rank'];
                $lapScore = (float)$secondary['lap_score'];
                if (secondaryEnabled($venueRules, 5, 'main', 'double', $placeCode) && ($secondRank <= 3 || $lapScore >= 4.0)) {
                    $starLevel = 2;
                    if (secondaryEnabled($venueRules, 5, 'main', 'triple', $placeCode) && $secondRank === 1) {
                        $starLevel = 3;
                    }
                }
            }
        }

        $detail = [
            'race_code' => $raceCode,
            'course' => 5,
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => match ($starLevel) {
                3 => '5頭強',
                2 => '5候補',
                default => '5攻め',
            },
            'attack_rate' => round($attackRate, 2),
            'makuri_rate' => round((float)($profile['makuri_rate'] ?? 0.0), 2),
            'makurizashi_rate' => round((float)($profile['makurizashi_rate'] ?? 0.0), 2),
            'history_n' => (int)($profile['n'] ?? 0),
            'secondary_ready' => is_array($secondary),
            'historical_stats' => laneHistoricalStats(5, $starLevel, $placeCode),
        ];

        if (is_array($secondary)) {
            $detail['second_rank'] = (int)$secondary['second_rank'];
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['lap_score'] = round((float)$secondary['lap_score'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['mawari_score'] = round((float)$secondary['mawari_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }

        $lane5Matches[$raceCode] = $detail;
    }

    $lane6Matches = [];
    foreach ($targets as $row) {
        $raceCode = trim((string)($row['race_code'] ?? ''));
        $pid6 = trim((string)($row['lane6_player_id'] ?? ''));
        $pid5 = trim((string)($row['lane5_player_id'] ?? ''));
        if ($raceCode === '' || $pid6 === '' || $pid5 === '') {
            continue;
        }
        if (!primaryEnabled($venueRules, 6, 'main')) {
            continue;
        }

        $profile = $lane6Profiles[$pid6] ?? null;
        $rank6 = $ranks[$pid6][6] ?? null;
        $rank5 = $ranks[$pid5][5] ?? null;
        $attackRate = is_array($profile) ? ($profile['attack_rate'] ?? null) : null;
        if (!is_numeric($attackRate) || !is_numeric($rank6) || !is_numeric($rank5)
            || (float)$attackRate < primaryThreshold($venueRules, 6, 'main') || (float)$rank6 >= (float)$rank5) {
            continue;
        }

        $secondary = null;
        if ($avgExhibition !== null && isset($exhibitionByRace[$raceCode])) {
            $secondary = buildSecondEval($exhibitionByRace[$raceCode], $avgExhibition, $pid6);
        }
        $starLevel = 1;
        if (is_array($secondary) && secondaryEnabled($venueRules, 6, 'main', 'double', $placeCode) && (int)$secondary['second_rank'] <= 3) {
            $starLevel = 2;
        }

        $detail = [
            'race_code' => $raceCode,
            'course' => 6,
            'star_level' => $starLevel,
            'star_text' => str_repeat('★', $starLevel),
            'signal' => $starLevel >= 2 ? '6本命' : '6攻め',
            'attack_rate' => round((float)$attackRate, 2),
            'lane5_avg_rank' => round((float)$rank5, 2),
            'lane6_avg_rank' => round((float)$rank6, 2),
            'history_n' => (int)($profile['n'] ?? 0),
            'secondary_ready' => is_array($secondary),
            'historical_stats' => laneHistoricalStats(6, $starLevel, $placeCode),
        ];
        if (is_array($secondary)) {
            $detail['second_rank'] = (int)$secondary['second_rank'];
            $detail['second_score'] = round((float)$secondary['second_score'], 2);
            $detail['top_score'] = round((float)$secondary['top_score'], 2);
            $detail['gap_to_top'] = round((float)$secondary['gap_to_top'], 2);
            $detail['lap_score'] = round((float)$secondary['lap_score'], 2);
            $detail['straight_score'] = round((float)$secondary['straight_score'], 2);
            $detail['mawari_score'] = round((float)$secondary['mawari_score'], 2);
            $detail['st_score'] = round((float)$secondary['st_score'], 2);
            $detail['attack_potential'] = round((float)$secondary['attack_potential'], 2);
        }
        $lane6Matches[$raceCode] = $detail;
    }

    $baseResponse['matches'] = $matches;
    $baseResponse['lane2_sashi_matches'] = $lane2SashiMatches;
    $baseResponse['lane2_makuri_matches'] = $lane2MakuriMatches;
    $baseResponse['lane3_matches'] = $lane3Matches;
    $baseResponse['lane5_matches'] = $lane5Matches;
    $baseResponse['lane6_matches'] = $lane6Matches;
    $baseResponse['lane1_matches'] = $lane1Matches;
    respond($baseResponse);
} catch (Throwable $e) {
    respond([
        'status' => 'error',
        'error' => $e->getMessage(),
    ], 500);
}
