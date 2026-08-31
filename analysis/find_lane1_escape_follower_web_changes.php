<?php

declare(strict_types=1);

/**
 * 過去レースを現行Webロジックで再計算し、
 * 場別1逃げ相手補正によって本命買い目が実際に変わるレースを探す確認用スクリプト。
 *
 * Usage:
 * php analysis/find_lane1_escape_follower_web_changes.php FORWARD_RACES_CSV [LIMIT]
 */

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} FORWARD_RACES_CSV [LIMIT]\n");
    exit(1);
}

$racesPath = $argv[1];
$limit = isset($argv[2]) ? max(1, (int)$argv[2]) : 10;

if (!is_file($racesPath)) {
    throw new RuntimeException("必要ファイルがありません: {$racesPath}");
}

require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/Lane1EscapeFollowerLogic.php';

function readRaceCsv(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function parseRaceCode(string $raceCode): ?array
{
    if (!preg_match('/^(\d{8})([A-Z]{3})(\d{2})$/', $raceCode, $m)) {
        return null;
    }

    $date = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
    return [$date, $m[2], (string)((int)$m[3])];
}

$rows = readRaceCsv($racesPath);
$controller = new IndexController();
$followerLogic = new Lane1EscapeFollowerLogic();

$checked = 0;
$applied = 0;
$changed = 0;
$errors = 0;

printf("%s\n", str_repeat('=', 120));
printf("場別1逃げ相手補正 Web実表示候補検索\n");
printf("CSV   : %s\n", $racesPath);
printf("上限  : %d件\n", $limit);
printf("%s\n", str_repeat('=', 120));

foreach ($rows as $row) {
    if ((int)($row['honmei_head'] ?? 0) !== 1) {
        continue;
    }

    $raceCode = trim((string)($row['race_code'] ?? ''));
    $parts = parseRaceCode($raceCode);
    if ($parts === null) {
        continue;
    }

    [$date, $place, $race] = $parts;
    $checked++;

    try {
        $_GET = [
            'date' => $date,
            'place' => $place,
            'race' => $race,
        ];
        $_POST = [];

        $base = $controller->handle();
        $before = (string)($base['honmei_kai'] ?? '');

        $afterData = $followerLogic->apply(
            $base,
            $base['final_predictions'] ?? [],
            $base['place_names'][$place] ?? '',
            $base['entry_course_by_boat'] ?? [],
            !empty($base['entry_map_ready']) && empty($base['simulation_active'])
        );

        $isApplied = !empty($afterData['lane1_escape_follower_applied']);
        if ($isApplied) {
            $applied++;
        }

        $after = (string)($afterData['honmei_kai'] ?? '');
        if (!$isApplied || $before === '' || $after === '' || $before === $after) {
            continue;
        }

        $changed++;
        printf(
            "%2d. %s  %-6s  現行=%-14s → 補正=%-14s  N=%d\n",
            $changed,
            $raceCode,
            (string)($base['place_names'][$place] ?? $place),
            $before,
            $after,
            (int)($afterData['lane1_escape_follower_sample_n'] ?? 0)
        );

        if ($changed >= $limit) {
            break;
        }
    } catch (Throwable $e) {
        $errors++;
        fwrite(STDERR, "[WARN] {$raceCode}: {$e->getMessage()}\n");
    }
}

printf("%s\n", str_repeat('-', 120));
printf("確認=%d / 補正適用=%d / 買い目変更=%d / エラー=%d\n", $checked, $applied, $changed, $errors);

if ($changed === 0) {
    echo "変更レースが見つかりませんでした。別期間CSVでも同じスクリプトを実行してください。\n";
}

printf("%s\n", str_repeat('=', 120));
