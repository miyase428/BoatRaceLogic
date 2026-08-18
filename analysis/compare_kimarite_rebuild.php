<?php

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php analysis/compare_kimarite_rebuild.php RACE_CODE\n");
    exit(1);
}

$raceCode = strtoupper(trim($argv[1]));
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "race_codeが不正です: {$raceCode}\n");
    exit(1);
}

$place = substr($raceCode, 8, 3);
$api = new ApiClientProduction();
[$entries, $firstResults, $calcError] = $api->fetchCalcScores($raceCode);
[$tenjiList, $tenjiError] = $api->fetchTenji($raceCode, $firstResults, $place);

$nameByBoat = [];
foreach ($entries as $entry) {
    $boat = (int)($entry['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $nameByBoat[$boat] = trim((string)($entry['player_name'] ?? ''));
    }
}

$courseByBoat = [];
$boatByCourse = [];
foreach ($tenjiList as $idx => $t) {
    $boat = (int)($t['teiban'] ?? ($idx + 1));
    $course = (int)($t['tenji_course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $courseByBoat[$boat] = $course;
        $boatByCourse[$course] = $boat;
    }
}

if (count($courseByBoat) !== 6 || count($boatByCourse) !== 6) {
    fwrite(STDERR, "展示進入1～6が揃っていないため比較できません。\n");
    if ($calcError !== '') fwrite(STDERR, "calc: {$calcError}\n");
    if ($tenjiError !== '') fwrite(STDERR, "tenji: {$tenjiError}\n");
    exit(1);
}
ksort($courseByBoat);
ksort($boatByCourse);

$effectiveInCourse = '';
for ($boat = 1; $boat <= 6; $boat++) {
    $effectiveInCourse .= (string)$courseByBoat[$boat];
}
[$current, $kimariteError] = $api->fetchKimarite($raceCode, $effectiveInCourse);

$pdo = getPDO();
$stmt = $pdo->prepare(<<<SQL
    SELECT lane_number, player_id::text
    FROM boat_race.race_entry
    WHERE race_code = :race_code
    ORDER BY lane_number
SQL);
$stmt->execute([':race_code' => $raceCode]);
$playerByBoat = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $boat = (int)($row['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $playerByBoat[$boat] = trim((string)($row['player_id'] ?? ''));
    }
}

function rebuiltKimarite(PDO $pdo, string $playerId, int $course, int $months): array
{
    if ($course < 1 || $course > 6) {
        throw new RuntimeException('courseが不正です');
    }
    if (!in_array($months, [6, 12], true)) {
        throw new RuntimeException('monthsは6または12のみです');
    }

    $sql = "
WITH base AS (
    SELECT
        re.race_code,
        COALESCE(rd.entry_course, ex.entry_course) AS resolved_course,
        rd.entry_course AS rd_course,
        ex.entry_course AS ex_course,
        w.player_id::text AS winner_player_id,
        w.entry_course AS winner_course,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM boat_race.race_entry re
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
        SELECT rrd.player_id, rrd.entry_course, rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE
    WHERE rm.race_date >= CURRENT_DATE - INTERVAL '{$months} months'
      AND re.player_id::text = :player_id
), filtered AS (
    SELECT *
    FROM base
    WHERE resolved_course = {$course}
), typed AS (
    SELECT
        CASE
            WHEN winner_player_id = :player_id AND {$course} = 1 THEN 'nige'
            WHEN winner_player_id <> :player_id AND {$course} = 1 AND winner_technique = '差し' THEN 'sasare'
            WHEN winner_player_id <> :player_id AND {$course} = 1 AND winner_technique = 'まくり' THEN 'makurare'
            WHEN winner_player_id <> :player_id AND {$course} = 1 AND winner_technique = 'まくり差し' THEN 'makurarezashi'
            WHEN winner_player_id <> :player_id AND {$course} = 2 AND winner_course = 1 THEN 'nogashi'
            WHEN winner_player_id = :player_id AND winner_technique = '差し' THEN 'sashi'
            WHEN winner_player_id = :player_id AND winner_technique = 'まくり' THEN 'makuri'
            WHEN winner_player_id = :player_id AND winner_technique = 'まくり差し' THEN 'makurizashi'
            ELSE NULL
        END AS technique_type,
        CASE
            WHEN rd_course IS NOT NULL AND ex_course IS NOT NULL AND rd_course <> ex_course THEN 1
            ELSE 0
        END AS conflict
    FROM filtered
)
SELECT
    COUNT(*) AS sample_n,
    COUNT(*) FILTER (WHERE technique_type = 'nige') AS nige,
    COUNT(*) FILTER (WHERE technique_type = 'sashi') AS sashi,
    COUNT(*) FILTER (WHERE technique_type = 'makuri') AS makuri,
    COUNT(*) FILTER (WHERE technique_type = 'makurizashi') AS makurizashi,
    COUNT(*) FILTER (WHERE technique_type = 'nogashi') AS nogashi,
    COUNT(*) FILTER (WHERE technique_type = 'sasare') AS sasare,
    COUNT(*) FILTER (WHERE technique_type = 'makurare') AS makurare,
    COUNT(*) FILTER (WHERE technique_type = 'makurarezashi') AS makurarezashi,
    SUM(conflict) AS conflicts
FROM typed
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':player_id' => $playerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $keys = ['nige', 'sashi', 'makuri', 'makurizashi', 'nogashi', 'sasare', 'makurare', 'makurarezashi'];
    $n = (int)($row['sample_n'] ?? 0);
    $counts = [];
    $rates = [];
    foreach ($keys as $key) {
        $cnt = (int)($row[$key] ?? 0);
        $counts[$key] = $cnt;
        $rates[$key] = $n > 0 ? round(100.0 * $cnt / $n, 1) : 0.0;
    }

    return [
        'n' => $n,
        'counts' => $counts,
        'rates' => $rates,
        'conflicts' => (int)($row['conflicts'] ?? 0),
    ];
}

function relevantMetrics(int $course): array
{
    if ($course === 1) {
        return [
            'nige' => '逃げ',
            'sasare' => '差され',
            'makurare' => 'まくられ',
            'makurarezashi' => 'まくられ差',
        ];
    }
    if ($course === 2) {
        return [
            'nogashi' => '逃がし',
            'sashi' => '差し',
            'makuri' => 'まくり',
            'makurizashi' => 'まくり差し',
        ];
    }
    return [
        'sashi' => '差し',
        'makuri' => 'まくり',
        'makurizashi' => 'まくり差し',
    ];
}

function currentPeriod(array $current, int $course, string $period): array
{
    $d = $current[$course][$period] ?? $current[(string)$course][$period] ?? [];
    return is_array($d) ? $d : [];
}

printf("%s\n", str_repeat('=', 132));
printf("決まり手 現行 vs race_entry再構築 比較: %s\n", $raceCode);
printf("%s\n", str_repeat('=', 132));
printf("艇→展示C            : %s\n", $effectiveInCourse);
printf("再構築母集団         : race_entry / 完了レースのみ\n");
printf("実進入               : result_detail優先、本人行欠損時のみexhibition_live\n");
printf("集計範囲             : 全場 / 選手×展示進入コース\n");
if ($kimariteError !== '') printf("kimarite error      : %s\n", $kimariteError);
printf("\n");

foreach ([12 => ['label' => '直近1年', 'period' => '1year'], 6 => ['label' => '直近6ヶ月', 'period' => '6month']] as $months => $cfg) {
    printf("【%s】\n", $cfg['label']);

    for ($course = 1; $course <= 6; $course++) {
        $boat = (int)($boatByCourse[$course] ?? 0);
        $pid = (string)($playerByBoat[$boat] ?? '');
        $name = $nameByBoat[$boat] ?? $pid;
        $old = currentPeriod($current, $course, $cfg['period']);
        $new = rebuiltKimarite($pdo, $pid, $course, $months);
        $oldN = (int)($old['_sample_n'] ?? 0);

        printf(
            "%dコース / %d号艇 / %-18s   N %d → %d",
            $course,
            $boat,
            $name,
            $oldN,
            $new['n']
        );
        if ($new['conflicts'] > 0) {
            printf("  [進入競合 %d件]", $new['conflicts']);
        }
        printf("\n");

        foreach (relevantMetrics($course) as $key => $label) {
            $oldRate = (float)($old[$key] ?? 0);
            $oldCount = (int)($old['_counts'][$key] ?? 0);
            $newRate = (float)($new['rates'][$key] ?? 0);
            $newCount = (int)($new['counts'][$key] ?? 0);
            printf(
                "  %-12s %5.1f%%（%d/%d） → %5.1f%%（%d/%d）  %+5.1fpt\n",
                $label,
                $oldRate,
                $oldCount,
                $oldN,
                $newRate,
                $newCount,
                $new['n'],
                $newRate - $oldRate
            );
        }
        printf("\n");
    }
}

printf("【見方】\n");
printf("・外コースでNが大きく増えて勝ち回数が同じなら、現行率は分母欠損で過大評価されていた可能性が高い。\n");
printf("・1コース敗戦系は勝者の決まり手から再構築するため、本人result_detail行欠損の影響を受けない。\n");
printf("・この比較は診断のみ。本番kimarite_api.phpの率計算はまだ変更しない。\n");
printf("%s\n", str_repeat('=', 132));
