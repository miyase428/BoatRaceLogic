<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/api/ApiClientProduction.php';
require_once __DIR__ . '/logic/EffectiveRaceOutcomeFilter.php';
require_once __DIR__ . '/logic/CorrectedWinRateLogic.php';
require_once __DIR__ . '/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/logic/TrifectaProbabilityLogic.php';

function respondEffectiveFive(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raceCode = strtoupper(trim((string)($_GET['race_code'] ?? $_POST['race_code'] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
    respondEffectiveFive([
        'status' => 'error',
        'error' => 'race_codeが不正です',
        'rows' => [],
    ], 400);
}

try {
    $filter = new EffectiveRaceOutcomeFilter();
    $activeBoats = $filter->detectActiveBoats($raceCode);
    sort($activeBoats, SORT_NUMERIC);
    if (count($activeBoats) !== 5) {
        throw new RuntimeException('実質5艇立てではありません');
    }
    $excludedBoats = array_values(array_diff(range(1, 6), $activeBoats));
    if (count($excludedBoats) !== 1) {
        throw new RuntimeException('除外艇の判定が不正です');
    }
    $excludedBoat = (int)$excludedBoats[0];

    $placeCode = $m[2];
    $api = new ApiClientProduction();
    [$entries, $primaryResults, $calcError] = $api->fetchCalcScores($raceCode);
    if (count($primaryResults) !== 6) {
        throw new RuntimeException($calcError !== '' ? $calcError : '一次評価が6艇分そろっていません');
    }

    [$tenjiList, $tenjiError] = $api->fetchTenji($raceCode, $primaryResults, $placeCode);
    if (count($tenjiList) !== 6) {
        throw new RuntimeException($tenjiError !== '' ? $tenjiError : '展示評価が6艇分そろっていません');
    }

    // 実質5艇立てでも展示進入自体は6枠ぶん保存されているため、
    // 欠場艇のタイム欠損は許可しつつ course map だけ実測値を使う。
    $courseByBoat = [];
    $seenCourses = [];
    foreach ($tenjiList as $index => $row) {
        $boat = (int)($row['teiban'] ?? ($index + 1));
        $course = (int)($row['tenji_course'] ?? 0);
        if (
            $boat < 1 || $boat > 6
            || $course < 1 || $course > 6
            || isset($courseByBoat[$boat])
            || isset($seenCourses[$course])
        ) {
            throw new RuntimeException('実質5艇立ての展示進入マップが不完全です');
        }
        $courseByBoat[$boat] = $course;
        $seenCourses[$course] = true;
    }
    ksort($courseByBoat);
    if (array_keys($courseByBoat) !== range(1, 6) || count($seenCourses) !== 6) {
        throw new RuntimeException('実質5艇立ての展示進入1～6がそろっていません');
    }

    $correctedLogic = new CorrectedWinRateLogic();
    $correctedData = $correctedLogic->calculateEffective($raceCode, $activeBoats);
    if ((string)($correctedData['status'] ?? '') !== 'ok') {
        throw new RuntimeException((string)($correctedData['error'] ?? '5艇補正後1着率の計算に失敗しました'));
    }
    $correctedActive = is_array($correctedData['boats'] ?? null) ? $correctedData['boats'] : [];
    if (count($correctedActive) !== 5) {
        throw new RuntimeException('5艇補正後1着率が5艇分ありません');
    }

    $aiTrioLogic = new AiTrioRateLogic();
    $aiTrioData = $aiTrioLogic->calculate(
        $raceCode,
        $primaryResults,
        $tenjiList,
        $courseByBoat,
        false
    );
    if ((string)($aiTrioData['status'] ?? '') !== 'ok') {
        throw new RuntimeException((string)($aiTrioData['error'] ?? 'AI3連対率の計算に失敗しました'));
    }
    $aiTrioBoats = is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [];
    if (count($aiTrioBoats) !== 6) {
        throw new RuntimeException('AI3連対率が6艇分ありません');
    }

    // TrifectaProbabilityLogicは検証済み6艇ロジックのまま保持する。
    // 欠場艇のみ極小値にして120通りを作り、直後に共通Filterで60通りへ条件化する。
    $tiny = 1.0e-9;
    $correctedForCore = [];
    $aiTrioForCore = $aiTrioBoats;
    foreach (range(1, 6) as $boat) {
        if ($boat === $excludedBoat) {
            $correctedForCore[$boat] = ['corrected_rate' => $tiny];
            if (!isset($aiTrioForCore[$boat]) || !is_array($aiTrioForCore[$boat])) {
                $aiTrioForCore[$boat] = [];
            }
            $aiTrioForCore[$boat]['ai_rate'] = $tiny;
            continue;
        }

        $row = $correctedActive[(string)$boat] ?? $correctedActive[$boat] ?? null;
        $rate = is_array($row) ? ($row['corrected_rate'] ?? null) : null;
        if (!is_numeric($rate)) {
            throw new RuntimeException($boat . '号艇の5艇補正後1着率がありません');
        }
        $correctedForCore[$boat] = ['corrected_rate' => (float)$rate];
    }

    $trifectaLogic = new TrifectaProbabilityLogic();
    $data = $trifectaLogic->calculate(
        $raceCode,
        $correctedForCore,
        $aiTrioForCore,
        $courseByBoat
    );
    $data = $filter->apply($raceCode, $data);

    if ((string)($data['status'] ?? '') !== 'ok' || count($data['rows'] ?? []) !== 60) {
        throw new RuntimeException((string)($data['error'] ?? '5艇3連単60通りを構築できません'));
    }

    $data['display_mode'] = 'effective5';
    $data['active_boats'] = $activeBoats;
    $data['excluded_boats'] = $excludedBoats;
    $data['outcome_count'] = 60;
    $data['exacta_count'] = 20;
    $data['effective5'] = [
        'corrected_win_rate' => $correctedData,
        'ai_trio_method' => $aiTrioData['method'] ?? [],
        'course_by_boat' => $courseByBoat,
        'excluded_boat' => $excludedBoat,
    ];
    $data['method'] = is_array($data['method'] ?? null) ? $data['method'] : [];
    $data['method']['effective5_mode'] = 'ACTIVE5_EXHIBITION_CORRECTED';

    respondEffectiveFive($data);
} catch (Throwable $e) {
    respondEffectiveFive([
        'status' => 'error',
        'error' => $e->getMessage(),
        'race_code' => $raceCode,
        'rows' => [],
    ], 422);
}
