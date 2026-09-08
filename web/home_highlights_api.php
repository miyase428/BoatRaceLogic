<?php
require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../common/PayoutSignalFeatureBuilder.php';
require_once __DIR__ . '/../common/PayoutSignalClassifier.php';

date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function homeHighlightsJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function homeHighlightsValidDate(string $value): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $dt !== false && $dt->format('Y-m-d') === $value;
}

function homeHighlightsLevelWeight(string $level): int
{
    return match ($level) {
        'strong' => 2,
        'watch' => 1,
        default => 0,
    };
}

$dateText = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!homeHighlightsValidDate($dateText)) {
    homeHighlightsJson([
        'status' => 'error',
        'error' => '日付の形式が不正です。',
        'rows' => [],
    ], 400);
}

$force = (string)($_GET['force'] ?? '') === '1';
$datePrefix = str_replace('-', '', $dateText);

// 荒れ警戒は展示前から使う固定サインで、入力は当日の出走表＋前日までの過去成績だけ。
// レース進行中に判定値そのものは変わらないため、短時間ごとの再集計は不要。
// 日付別・版別キャッシュを1日使い回し、TOP再表示時の重い1年集計を避ける。
$cacheFile = sys_get_temp_dir() . '/boatrace_home_upset_v2_' . $datePrefix . '.json';

if (!$force && is_file($cacheFile)) {
    $cached = file_get_contents($cacheFile);
    if (is_string($cached) && $cached !== '') {
        $data = json_decode($cached, true);
        if (is_array($data) && (string)($data['date'] ?? '') === $dateText) {
            $data['cache_used'] = true;
            homeHighlightsJson($data);
        }
    }
}

$placeNames = [
    'KRY' => '桐生',   'TDA' => '戸田',   'EDG' => '江戸川', 'HWJ' => '平和島',
    'TMG' => '多摩川', 'HMN' => '浜名湖', 'GMG' => '蒲郡',   'TKN' => '常滑',
    'TSU' => '津',     'MKN' => '三国',   'BWK' => 'びわこ', 'SME' => '住之江',
    'AMG' => '尼崎',   'NRT' => '鳴門',   'MRG' => '丸亀',   'KJM' => '児島',
    'MYJ' => '宮島',   'TKY' => '徳山',   'SMS' => '下関',   'WKM' => '若松',
    'ASY' => '芦屋',   'FKO' => '福岡',   'KRT' => '唐津',   'OMR' => '大村',
];

try {
    $pdo = getPDO();

    // 展示前から判定できるよう、kimarite_api.php と同じ「当日艇番=コース」基準で
    // 各選手の直近1年決まり手を日単位で一括集計する。
    // 当日展示・展示進入・二次評価・補正後1着率は一切使わない。
    //
    // 以前は結果済みレースをSQLで除外していたため、レース結果が入るたびに母集団が変わり、
    // 短いキャッシュ期限で再集計する必要があった。現在は当日全レースを一度だけ判定し、
    // 終了済みかどうかはTOP側の締切時刻表示で下段へ移動する。
    $sql = <<<SQL
WITH target_date AS (
    SELECT TO_DATE(:target_date, 'YYYYMMDD') AS d
),
targets_raw AS (
    SELECT
        re.race_code,
        SUBSTRING(re.race_code FROM 9 FOR 3) AS place,
        CAST(SUBSTRING(re.race_code FROM 12 FOR 2) AS integer) AS race_no,
        re.lane_number::integer AS course,
        re.player_id
    FROM boat_race.race_entry re
    WHERE re.race_code LIKE :entry_prefix
      AND re.lane_number BETWEEN 1 AND 6
),
eligible_races AS (
    SELECT race_code
    FROM targets_raw
    GROUP BY race_code
    HAVING COUNT(DISTINCT course) = 6
),
target_members AS (
    SELECT tr.*
    FROM targets_raw tr
    JOIN eligible_races er ON er.race_code = tr.race_code
),
past AS (
    SELECT
        tm.race_code AS target_race_code,
        tm.place,
        tm.race_no,
        tm.course,
        tm.player_id AS target_player_id,
        re.race_code AS past_race_code,
        rm.race_date,
        COALESCE(rd.entry_course, ex.entry_course)::integer AS past_course,
        w.player_id AS winner_player_id,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM target_members tm
    CROSS JOIN target_date td
    JOIN boat_race.race_entry re
      ON re.player_id = tm.player_id
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code

    LEFT JOIN LATERAL (
        SELECT rrd.entry_course
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND rrd.player_id = re.player_id
          AND rrd.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) rd ON TRUE

    LEFT JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE

    JOIN LATERAL (
        SELECT
            rrd.player_id,
            rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE

    WHERE rm.race_date >= td.d - INTERVAL '12 months'
      AND rm.race_date < td.d
),
matched AS (
    SELECT *
    FROM past
    WHERE past_course = course
),
agg AS (
    SELECT
        tm.race_code,
        tm.place,
        tm.race_no,
        tm.course,
        tm.player_id,
        COUNT(m.past_race_code)::int AS total_12,
        COUNT(*) FILTER (
            WHERE m.winner_player_id = tm.player_id
        )::int AS win_12,
        COUNT(*) FILTER (
            WHERE tm.course = 1
              AND m.winner_player_id = tm.player_id
              AND m.winner_technique = '逃げ'
        )::int AS nige_12,
        COUNT(*) FILTER (
            WHERE tm.course <> 1
              AND m.winner_player_id = tm.player_id
              AND m.winner_technique = '差し'
        )::int AS sashi_12,
        COUNT(*) FILTER (
            WHERE tm.course <> 1
              AND m.winner_player_id = tm.player_id
              AND m.winner_technique = 'まくり'
        )::int AS makuri_12
    FROM target_members tm
    LEFT JOIN matched m
      ON m.target_race_code = tm.race_code
     AND m.course = tm.course
     AND m.target_player_id = tm.player_id
    GROUP BY tm.race_code, tm.place, tm.race_no, tm.course, tm.player_id
)
SELECT *
FROM agg
ORDER BY race_code, course
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':target_date' => $datePrefix,
        ':entry_prefix' => $datePrefix . '%',
    ]);

    $byRace = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $raceCode = (string)($r['race_code'] ?? '');
        $course = (int)($r['course'] ?? 0);
        if ($raceCode === '' || $course < 1 || $course > 6) {
            continue;
        }

        $total = (int)($r['total_12'] ?? 0);
        $pct = static fn(int $count): float => $total > 0
            ? round(100.0 * $count / $total, 1)
            : 0.0;

        if (!isset($byRace[$raceCode])) {
            $byRace[$raceCode] = [
                'place' => (string)($r['place'] ?? ''),
                'race_no' => (int)($r['race_no'] ?? 0),
                'kimarite' => [],
            ];
        }

        $byRace[$raceCode]['kimarite'][$course] = [
            '1year' => [
                '_sample_n' => $total,
                'nige' => $pct((int)($r['nige_12'] ?? 0)),
                'win' => $pct((int)($r['win_12'] ?? 0)),
                'sashi' => $pct((int)($r['sashi_12'] ?? 0)),
                'makuri' => $pct((int)($r['makuri_12'] ?? 0)),
            ],
        ];
    }

    $rows = [];
    $evaluated = 0;
    $waiting = 0;

    foreach ($byRace as $raceCode => $race) {
        $kimarite = is_array($race['kimarite'] ?? null) ? $race['kimarite'] : [];
        if (count($kimarite) !== 6) {
            $waiting++;
            continue;
        }

        $built = PayoutSignalFeatureBuilder::build(
            (int)($race['race_no'] ?? 0),
            $kimarite,
            [] // TOP荒れ警戒は展示不要の暫定判定。Web本命/対抗は入れない。
        );
        if (($built['status'] ?? '') !== 'ok') {
            $waiting++;
            continue;
        }

        $evaluated++;
        $classified = PayoutSignalClassifier::classify($built['input']);
        $medium = is_array($classified['payout']['medium'] ?? null) ? $classified['payout']['medium'] : [];
        $high = is_array($classified['payout']['high'] ?? null) ? $classified['payout']['high'] : [];
        $big = is_array($classified['payout']['big'] ?? null) ? $classified['payout']['big'] : [];
        $chaos = is_array($classified['chaos'] ?? null) ? $classified['chaos'] : [];

        $hasAlert = (string)($medium['level'] ?? 'low') !== 'low'
            || (string)($high['level'] ?? 'low') !== 'low'
            || (string)($big['level'] ?? 'low') !== 'low'
            || (string)($chaos['primary'] ?? '平常') !== '平常';
        if (!$hasAlert) {
            continue;
        }

        $input = is_array($built['input'] ?? null) ? $built['input'] : [];
        $place = (string)($race['place'] ?? '');
        $weight = homeHighlightsLevelWeight((string)($big['level'] ?? 'low')) * 100
            + homeHighlightsLevelWeight((string)($high['level'] ?? 'low')) * 10
            + homeHighlightsLevelWeight((string)($medium['level'] ?? 'low'));

        $strongestLabel = '荒れ注意';
        $strongestLevel = 'watch';
        foreach ([
            ['大穴', $big],
            ['高配当', $high],
            ['中配当', $medium],
        ] as [$label, $bucket]) {
            $level = (string)($bucket['level'] ?? 'low');
            if ($level === 'strong') {
                $strongestLabel = $label . ' 強';
                $strongestLevel = 'strong';
                break;
            }
            if ($level === 'watch' && $strongestLabel === '荒れ注意') {
                $strongestLabel = $label . ' 注';
                $strongestLevel = 'watch';
            }
        }

        $rows[] = [
            'race_code' => $raceCode,
            'place' => $place,
            'venue' => $placeNames[$place] ?? $place,
            'race_no' => (int)($race['race_no'] ?? 0),
            'primary' => (string)($chaos['primary'] ?? '平常'),
            'types' => array_values(is_array($chaos['types'] ?? null) ? $chaos['types'] : []),
            'badge' => $strongestLabel,
            'severity' => $strongestLevel,
            'medium_level' => (string)($medium['level'] ?? 'low'),
            'high_level' => (string)($high['level'] ?? 'low'),
            'big_level' => (string)($big['level'] ?? 'low'),
            'nige' => isset($input['nige']) ? round((float)$input['nige'], 1) : null,
            'attack_max' => isset($input['attack_max']) ? round((float)$input['attack_max'], 1) : null,
            'weight' => $weight,
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        $weightCmp = ((int)($b['weight'] ?? 0)) <=> ((int)($a['weight'] ?? 0));
        if ($weightCmp !== 0) {
            return $weightCmp;
        }
        $chaosCmp = ((string)($a['primary'] ?? '平常') === '平常' ? 1 : 0)
            <=> ((string)($b['primary'] ?? '平常') === '平常' ? 1 : 0);
        if ($chaosCmp !== 0) {
            return $chaosCmp;
        }
        return strcmp((string)($a['race_code'] ?? ''), (string)($b['race_code'] ?? ''));
    });

    $totalAlerts = count($rows);
    foreach ($rows as &$row) {
        unset($row['weight']);
    }
    unset($row);

    $payload = [
        'status' => 'ok',
        'date' => $dateText,
        'rows' => $rows,
        'total_alerts' => $totalAlerts,
        'candidate_races' => count($byRace),
        'evaluated_races' => $evaluated,
        'waiting_races' => $waiting,
        'source' => '決まり手1年 / 展示情報不使用 / 日次固定',
        'classifier_version' => PayoutSignalClassifier::VERSION,
        'generated_at' => date(DATE_ATOM),
        'cache_used' => false,
        'cache_scope' => 'daily',
    ];

    @file_put_contents(
        $cacheFile,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        LOCK_EX
    );

    homeHighlightsJson($payload);
} catch (Throwable $e) {
    homeHighlightsJson([
        'status' => 'error',
        'error' => $e->getMessage(),
        'rows' => [],
    ], 500);
}
