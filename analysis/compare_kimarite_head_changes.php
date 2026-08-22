<?php
declare(strict_types=1);

/**
 * 修正前kimarite CSV と 修正後kimarite CSV の本命変化を比較する。
 *
 * DB/APIは使わず、レース別CSVのみを読み込む軽量分析。
 *
 * Usage:
 *   php analysis/compare_kimarite_head_changes.php \
 *     analysis/output/final_prediction_races_20260215_20260814.csv \
 *     analysis/output/final_prediction_races_fast_cached_20260215_20260814.csv
 */

function loadRaceCsv(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("CSVが見つかりません: {$path}");
    }

    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVヘッダーを読み込めません: {$path}");
    }

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $required = ['race_code', 'honmei_head', 'actual_1st'];
    foreach ($required as $name) {
        if (!in_array($name, $header, true)) {
            fclose($fp);
            throw new RuntimeException("必須列がありません: {$name} ({$path})");
        }
    }

    $rows = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }

        $data = [];
        foreach ($header as $i => $name) {
            $data[$name] = $row[$i] ?? '';
        }

        $raceCode = trim((string)($data['race_code'] ?? ''));
        if ($raceCode === '') {
            continue;
        }

        $rows[$raceCode] = $data;
    }

    fclose($fp);
    return $rows;
}

function pct(int $num, int $den, int $digits = 2): string
{
    if ($den <= 0) {
        return '-';
    }
    return number_format($num / $den * 100.0, $digits) . '%';
}

if ($argc < 3) {
    fwrite(STDERR,
        "使用方法:\n" .
        "  php analysis/compare_kimarite_head_changes.php OLD_RACES_CSV NEW_RACES_CSV\n"
    );
    exit(1);
}

$oldPath = $argv[1];
$newPath = $argv[2];

try {
    $oldRows = loadRaceCsv($oldPath);
    $newRows = loadRaceCsv($newPath);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$commonCodes = array_values(array_intersect(array_keys($oldRows), array_keys($newRows)));
sort($commonCodes, SORT_STRING);

$valid = 0;
$headChanged = 0;
$headSame = 0;

$oldCorrectAll = 0;
$newCorrectAll = 0;

$changedOldOnly = 0;
$changedNewOnly = 0;
$changedBothCorrect = 0;
$changedBothWrong = 0;

$sameCorrect = 0;
$sameWrong = 0;

$transitions = [];
$changedByNewHead = [];
$changedByOldHead = [];

foreach ($commonCodes as $raceCode) {
    $old = $oldRows[$raceCode];
    $new = $newRows[$raceCode];

    $actualOld = (int)($old['actual_1st'] ?? 0);
    $actualNew = (int)($new['actual_1st'] ?? 0);

    // 両CSVで同じ実1着が取れるレースだけを公平に比較する。
    if ($actualOld < 1 || $actualOld > 6 || $actualNew !== $actualOld) {
        continue;
    }

    $oldHead = (int)($old['honmei_head'] ?? 0);
    $newHead = (int)($new['honmei_head'] ?? 0);
    if ($oldHead < 1 || $oldHead > 6 || $newHead < 1 || $newHead > 6) {
        continue;
    }

    $valid++;
    $actual = $actualOld;
    $oldCorrect = ($oldHead === $actual);
    $newCorrect = ($newHead === $actual);

    if ($oldCorrect) $oldCorrectAll++;
    if ($newCorrect) $newCorrectAll++;

    if ($oldHead === $newHead) {
        $headSame++;
        if ($oldCorrect) {
            $sameCorrect++;
        } else {
            $sameWrong++;
        }
        continue;
    }

    $headChanged++;

    if ($oldCorrect && $newCorrect) {
        // 本命が違う以上、通常は起こらない。データ異常検知用に残す。
        $changedBothCorrect++;
    } elseif ($oldCorrect) {
        $changedOldOnly++;
    } elseif ($newCorrect) {
        $changedNewOnly++;
    } else {
        $changedBothWrong++;
    }

    $key = "{$oldHead}->{$newHead}";
    if (!isset($transitions[$key])) {
        $transitions[$key] = [
            'old_head' => $oldHead,
            'new_head' => $newHead,
            'n' => 0,
            'old_correct' => 0,
            'new_correct' => 0,
        ];
    }
    $transitions[$key]['n']++;
    if ($oldCorrect) $transitions[$key]['old_correct']++;
    if ($newCorrect) $transitions[$key]['new_correct']++;

    if (!isset($changedByNewHead[$newHead])) {
        $changedByNewHead[$newHead] = ['n' => 0, 'correct' => 0];
    }
    $changedByNewHead[$newHead]['n']++;
    if ($newCorrect) $changedByNewHead[$newHead]['correct']++;

    if (!isset($changedByOldHead[$oldHead])) {
        $changedByOldHead[$oldHead] = ['n' => 0, 'correct' => 0];
    }
    $changedByOldHead[$oldHead]['n']++;
    if ($oldCorrect) $changedByOldHead[$oldHead]['correct']++;
}

uasort($transitions, static function (array $a, array $b): int {
    if ($a['n'] === $b['n']) {
        return ($a['old_head'] <=> $b['old_head']) ?: ($a['new_head'] <=> $b['new_head']);
    }
    return $b['n'] <=> $a['n'];
});

ksort($changedByOldHead);
ksort($changedByNewHead);

$netAll = $newCorrectAll - $oldCorrectAll;
$netChanged = $changedNewOnly - $changedOldOnly;

$oldRate = $valid > 0 ? $oldCorrectAll / $valid * 100.0 : 0.0;
$newRate = $valid > 0 ? $newCorrectAll / $valid * 100.0 : 0.0;

printf("\n");
printf("========================================================================================================================\n");
printf("kimarite修正前 → 修正後　本命変更分析\n");
printf("========================================================================================================================\n");
printf("旧CSV              : %s\n", $oldPath);
printf("新CSV              : %s\n", $newPath);
printf("旧CSVレース数      : %d\n", count($oldRows));
printf("新CSVレース数      : %d\n", count($newRows));
printf("共通race_code      : %d\n", count($commonCodes));
printf("比較可能           : %d\n", $valid);
printf("------------------------------------------------------------------------------------------------------------------------\n");
printf("本命変更           : %d / %d (%s)\n", $headChanged, $valid, pct($headChanged, $valid));
printf("本命同一           : %d / %d (%s)\n", $headSame, $valid, pct($headSame, $valid));
printf("========================================================================================================================\n");

printf("\n【全体の1着的中】\n");
printf("修正前             : %d / %d (%.2f%%)\n", $oldCorrectAll, $valid, $oldRate);
printf("修正後             : %d / %d (%.2f%%)\n", $newCorrectAll, $valid, $newRate);
printf("差                 : %+d件 / %+.2fpt\n", $netAll, $newRate - $oldRate);

printf("\n【本命が変わったレースだけ】\n");
printf("対象               : %d\n", $headChanged);
printf("旧だけ正解         : %d (%s)\n", $changedOldOnly, pct($changedOldOnly, $headChanged));
printf("新だけ正解         : %d (%s)\n", $changedNewOnly, pct($changedNewOnly, $headChanged));
printf("両方正解           : %d (%s)\n", $changedBothCorrect, pct($changedBothCorrect, $headChanged));
printf("両方外れ           : %d (%s)\n", $changedBothWrong, pct($changedBothWrong, $headChanged));
printf("変更による純増減   : %+d件（新だけ正解 - 旧だけ正解）\n", $netChanged);

printf("\n【本命が同じレース】\n");
printf("同一本命で正解     : %d (%s)\n", $sameCorrect, pct($sameCorrect, $headSame));
printf("同一本命で外れ     : %d (%s)\n", $sameWrong, pct($sameWrong, $headSame));

printf("\n【変更方向 TOP20】\n");
printf("旧→新        N      旧正解     新正解      純増減\n");
printf("------------------------------------------------------------\n");
$shown = 0;
foreach ($transitions as $t) {
    printf(
        "%d→%d      %6d   %6d   %6d      %+6d\n",
        $t['old_head'],
        $t['new_head'],
        $t['n'],
        $t['old_correct'],
        $t['new_correct'],
        $t['new_correct'] - $t['old_correct']
    );
    $shown++;
    if ($shown >= 20) break;
}
if ($shown === 0) {
    printf("変更なし\n");
}

printf("\n【変更前の本命艇別】\n");
printf("艇    変更R      旧正解    旧正解率\n");
printf("----------------------------------------\n");
for ($head = 1; $head <= 6; $head++) {
    $r = $changedByOldHead[$head] ?? ['n' => 0, 'correct' => 0];
    printf("%d    %6d      %6d    %8s\n", $head, $r['n'], $r['correct'], pct($r['correct'], $r['n']));
}

printf("\n【変更後の本命艇別】\n");
printf("艇    変更R      新正解    新正解率\n");
printf("----------------------------------------\n");
for ($head = 1; $head <= 6; $head++) {
    $r = $changedByNewHead[$head] ?? ['n' => 0, 'correct' => 0];
    printf("%d    %6d      %6d    %8s\n", $head, $r['n'], $r['correct'], pct($r['correct'], $r['n']));
}

printf("\n========================================================================================================================\n");
printf("分析完了\n");
printf("========================================================================================================================\n\n");
