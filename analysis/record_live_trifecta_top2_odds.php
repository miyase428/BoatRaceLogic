<?php

declare(strict_types=1);

/**
 * 買い目最適化 STEP 3：固定3連単2点戦略の実戦オッズ前方記録
 *
 * STEP1/2/2.5で固定した戦略
 *   - P(1C頭) >= 65%
 *   - 最終3連単確率 上位2点
 *   - 500円 / 500円
 * を変更せず、現在取得できる公式3連単オッズだけを前方記録する。
 *
 * 重要:
 * - 過去へ遡ってオッズを復元しない。
 * - 1レースにつき主記録は1回だけ。既存race_codeは上書きしない。
 * - オッズ閾値では購入可否を決めない。まず記録だけ行う。
 * - PredictionLogicや買い目ロジックは変更しない。
 *
 * Usage:
 *   php analysis/record_live_trifecta_top2_odds.php 20260901SMS07
 *   php analysis/record_live_trifecta_top2_odds.php 20260901SMS07 --refresh
 */

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/OfficialOddsLogic.php';

date_default_timezone_set('Asia/Tokyo');

const FIXED_HEAD1_GATE = 0.65;
const FIXED_TOP_N = 2;
const FIXED_STAKE_EACH = 500;

function usage(): never
{
    fwrite(STDERR,
        "使用方法:\n" .
        "  php analysis/record_live_trifecta_top2_odds.php RACE_CODE [--refresh]\n" .
        "例:\n" .
        "  php analysis/record_live_trifecta_top2_odds.php 20260901SMS07 --refresh\n"
    );
    exit(1);
}

function parseRaceCode(string $raceCode): array
{
    $raceCode = strtoupper(trim($raceCode));
    if (!preg_match('/^(\d{4})(\d{2})(\d{2})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
        throw new InvalidArgumentException('race_code形式が不正です: ' . $raceCode);
    }

    return [
        'race_code' => $raceCode,
        'date' => $m[1] . '-' . $m[2] . '-' . $m[3],
        'place' => $m[4],
        'race' => (int)$m[5],
    ];
}

function comboKey(array $row): string
{
    $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
    if (count($boats) !== 3) {
        return '';
    }
    return implode('-', array_map('intval', $boats));
}

function calculateHead1Mass(array $rows): float
{
    $sum = 0.0;
    foreach ($rows as $row) {
        $courses = is_array($row['courses'] ?? null) ? $row['courses'] : [];
        if (count($courses) === 3 && (int)$courses[0] === 1) {
            $sum += (float)($row['probability'] ?? 0.0);
        }
    }
    return $sum;
}

function formatPct(float $v): string
{
    return number_format($v * 100.0, 2) . '%';
}

function existingRaceCodes(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        return [];
    }

    $header = fgetcsv($fp);
    if (!is_array($header)) {
        fclose($fp);
        return [];
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
    $idx = array_search('race_code', $header, true);
    if ($idx === false) {
        fclose($fp);
        return [];
    }

    $out = [];
    while (($row = fgetcsv($fp)) !== false) {
        $code = strtoupper(trim((string)($row[$idx] ?? '')));
        if ($code !== '') {
            $out[$code] = true;
        }
    }
    fclose($fp);
    return $out;
}

function appendCsv(string $path, array $header, array $row): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('出力ディレクトリを作成できません: ' . $dir);
    }

    $newFile = !is_file($path) || filesize($path) === 0;
    $fp = fopen($path, 'ab');
    if ($fp === false) {
        throw new RuntimeException('記録CSVを開けません: ' . $path);
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        throw new RuntimeException('記録CSVをロックできません: ' . $path);
    }

    if ($newFile) {
        fputcsv($fp, $header);
    }
    fputcsv($fp, $row);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

try {
    if ($argc < 2 || $argc > 3) {
        usage();
    }

    $parsed = parseRaceCode((string)$argv[1]);
    $forceRefresh = ($argc === 3 && (string)$argv[2] === '--refresh');
    if ($argc === 3 && !$forceRefresh) {
        usage();
    }

    $raceCode = $parsed['race_code'];
    $outputPath = __DIR__ . '/output/live_trifecta_top2_odds_forward.csv';

    // 主記録は1レース1回だけ。後から都合の良いオッズへ差し替えない。
    $existing = existingRaceCodes($outputPath);
    if (isset($existing[$raceCode])) {
        echo "既に主記録があります: {$raceCode}\n";
        echo "上書きしません: {$outputPath}\n";
        exit(0);
    }

    // Web本番と同じControllerを使って、現在レースの展示・補正後1着率などを再構築する。
    $_GET = [
        'date' => $parsed['date'],
        'place' => $parsed['place'],
        'race' => (string)$parsed['race'],
    ];
    $_POST = [];

    $controller = new IndexController();
    $view = $controller->handle();

    if (empty($view['entry_map_ready'])) {
        throw new RuntimeException('展示進入6艇が未確定です。展示取得後に実行してください。');
    }

    $courseByBoat = is_array($view['entry_course_by_boat'] ?? null)
        ? $view['entry_course_by_boat']
        : [];
    if (count($courseByBoat) !== 6) {
        throw new RuntimeException('展示進入マップが6艇分ありません。');
    }

    $corrected = is_array($view['corrected_win_rate_data'] ?? null)
        ? $view['corrected_win_rate_data']
        : [];
    if ((string)($corrected['status'] ?? '') !== 'ok') {
        throw new RuntimeException(
            '補正後1着率が計算待ちです: ' . (string)($corrected['error'] ?? '')
        );
    }
    $correctedBoats = is_array($corrected['boats'] ?? null) ? $corrected['boats'] : [];

    $aiTrioLogic = new AiTrioRateLogic();
    $aiTrio = $aiTrioLogic->calculate(
        $raceCode,
        is_array($view['results'] ?? null) ? $view['results'] : [],
        is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [],
        $courseByBoat,
        false
    );
    if ((string)($aiTrio['status'] ?? '') !== 'ok') {
        throw new RuntimeException('AI3連対率が計算できません: ' . (string)($aiTrio['error'] ?? ''));
    }
    $aiTrioBoats = is_array($aiTrio['boats'] ?? null) ? $aiTrio['boats'] : [];

    $trifectaLogic = new TrifectaProbabilityLogic();
    $trifecta = $trifectaLogic->calculate(
        $raceCode,
        $correctedBoats,
        $aiTrioBoats,
        $courseByBoat
    );
    if ((string)($trifecta['status'] ?? '') !== 'ok') {
        throw new RuntimeException('最終出目確率が計算できません: ' . (string)($trifecta['error'] ?? ''));
    }

    $rows = is_array($trifecta['rows'] ?? null) ? $trifecta['rows'] : [];
    if (count($rows) !== 120) {
        throw new RuntimeException('最終出目確率が120通りありません。');
    }

    $head1Mass = calculateHead1Mass($rows);
    $eligible = $head1Mass >= FIXED_HEAD1_GATE;

    echo str_repeat('=', 92) . PHP_EOL;
    echo "固定3連単2点戦略 実戦オッズ前方記録\n";
    echo str_repeat('=', 92) . PHP_EOL;
    echo "race_code      : {$raceCode}\n";
    echo "固定条件       : P(1C頭)>=65% × 最終3連単Top2 × 500円/500円\n";
    echo "P(1C頭)        : " . formatPct($head1Mass) . PHP_EOL;

    if (!$eligible) {
        echo "判定           : 固定条件の対象外（記録しません）\n";
        echo "※65%閾値はSTEP1で固定済み。現在オッズを見て変更しません。\n";
        echo str_repeat('=', 92) . PHP_EOL;
        exit(0);
    }

    $top = array_slice($rows, 0, FIXED_TOP_N);
    $oddsLogic = new OfficialOddsLogic();
    $oddsData = $oddsLogic->load($raceCode, $forceRefresh);
    if ((string)($oddsData['status'] ?? '') !== 'ok' || (int)($oddsData['count'] ?? 0) !== 120) {
        throw new RuntimeException(
            '公式3連単オッズ120通りを取得できません: ' . (string)($oddsData['error'] ?? '')
        );
    }
    $oddsMap = is_array($oddsData['odds'] ?? null) ? $oddsData['odds'] : [];

    $items = [];
    foreach ($top as $rank => $row) {
        $key = comboKey($row);
        $odds = isset($oddsMap[$key]) ? (float)$oddsMap[$key] : 0.0;
        if ($key === '' || $odds <= 0.0) {
            throw new RuntimeException('Top2の公式オッズがありません: ' . $key);
        }
        $prob = (float)($row['probability'] ?? 0.0);
        $items[] = [
            'rank' => $rank + 1,
            'key' => $key,
            'prob' => $prob,
            'odds' => $odds,
            'model_value' => $prob * $odds,
        ];
    }

    $cover = $items[0]['prob'] + $items[1]['prob'];
    $inverseOdds = (1.0 / $items[0]['odds']) + (1.0 / $items[1]['odds']);
    $combinedOdds = $inverseOdds > 0.0 ? 1.0 / $inverseOdds : 0.0;
    $equalStakeExpectedRoi = 0.5 * (
        $items[0]['prob'] * $items[0]['odds']
        + $items[1]['prob'] * $items[1]['odds']
    );

    echo "判定           : 固定条件の対象\n";
    foreach ($items as $item) {
        echo sprintf(
            "Top%d           : %-7s P=%6.3f%% / odds=%6.1f / p×odds=%6.3f\n",
            $item['rank'],
            $item['key'],
            $item['prob'] * 100.0,
            $item['odds'],
            $item['model_value']
        );
    }
    echo "Top2確率合計   : " . formatPct($cover) . PHP_EOL;
    echo "2点合成オッズ  : " . number_format($combinedOdds, 2) . "倍\n";
    echo "モデル期待ROI  : " . number_format($equalStakeExpectedRoi * 100.0, 2) . "%（500/500・参考値）\n";
    echo "オッズ取得時刻 : " . (string)($oddsData['fetched_at'] ?? '') . PHP_EOL;

    $header = [
        'observed_at', 'race_code', 'race_date', 'place_code', 'race_number',
        'head1_probability', 'fixed_gate', 'top2_cover_probability',
        'top1_combo', 'top1_probability', 'top1_odds', 'top1_model_value',
        'top2_combo', 'top2_probability', 'top2_odds', 'top2_model_value',
        'combined_odds', 'equal_stake_model_expected_roi',
        'stake_top1', 'stake_top2', 'official_odds_fetched_at', 'official_odds_cache_used',
    ];

    $row = [
        date('c'),
        $raceCode,
        $parsed['date'],
        $parsed['place'],
        $parsed['race'],
        number_format($head1Mass, 8, '.', ''),
        number_format(FIXED_HEAD1_GATE, 8, '.', ''),
        number_format($cover, 8, '.', ''),
        $items[0]['key'],
        number_format($items[0]['prob'], 8, '.', ''),
        number_format($items[0]['odds'], 2, '.', ''),
        number_format($items[0]['model_value'], 8, '.', ''),
        $items[1]['key'],
        number_format($items[1]['prob'], 8, '.', ''),
        number_format($items[1]['odds'], 2, '.', ''),
        number_format($items[1]['model_value'], 8, '.', ''),
        number_format($combinedOdds, 8, '.', ''),
        number_format($equalStakeExpectedRoi, 8, '.', ''),
        FIXED_STAKE_EACH,
        FIXED_STAKE_EACH,
        (string)($oddsData['fetched_at'] ?? ''),
        !empty($oddsData['cache']['used']) ? 1 : 0,
    ];

    appendCsv($outputPath, $header, $row);

    echo "記録           : {$outputPath}\n";
    echo "※オッズ閾値はまだ作りません。この前方記録を蓄積してから評価します。\n";
    echo str_repeat('=', 92) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
