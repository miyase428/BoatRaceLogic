<?php
declare(strict_types=1);

/**
 * export_kimarite_cache.py が生成したJSONを、現行 kimarite_api.php と全レース比較する。
 * 数値は1e-9以内を同値とみなす。
 *
 * 使い方:
 *   php analysis/validate_kimarite_cache.php \
 *     analysis/output/kimarite_cache_20260814_20260814.json
 */

if ($argc !== 2) {
    fwrite(STDERR,
        "Usage: php analysis/validate_kimarite_cache.php CACHE_JSON\n"
    );
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    fwrite(STDERR, "キャッシュがありません: {$path}\n");
    exit(1);
}

$raw = file_get_contents($path);
$cache = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($cache)) {
    fwrite(STDERR, "キャッシュJSONの読込に失敗しました。\n");
    exit(1);
}

function sameValue(mixed $a, mixed $b, string $path, array &$diffs): void
{
    if (is_array($a) || is_array($b)) {
        if (!is_array($a) || !is_array($b)) {
            $diffs[] = "{$path}: 型不一致";
            return;
        }

        $keysA = array_keys($a);
        $keysB = array_keys($b);
        sort($keysA);
        sort($keysB);
        if ($keysA !== $keysB) {
            $diffs[] = "{$path}: キー不一致";
            return;
        }

        foreach ($a as $key => $value) {
            sameValue($value, $b[$key], $path . '.' . (string)$key, $diffs);
            if (count($diffs) >= 20) {
                return;
            }
        }
        return;
    }

    if (is_numeric($a) && is_numeric($b)) {
        if (abs((float)$a - (float)$b) > 1e-9) {
            $diffs[] = "{$path}: cache={$a} api={$b}";
        }
        return;
    }

    if ($a !== $b) {
        $diffs[] = "{$path}: cache=" . var_export($a, true)
            . " api=" . var_export($b, true);
    }
}

$total = count($cache);
$ok = 0;
$ng = 0;
$errors = 0;
$firstDiffs = [];
$started = microtime(true);

 echo "\n";
echo "============================================================\n";
echo "決まり手一括キャッシュ vs 現行API 完全一致検証\n";
echo "============================================================\n";
echo "対象 : {$total}レース\n";
echo "============================================================\n\n";

$i = 0;
foreach ($cache as $raceCode => $cachedData) {
    $i++;

    $url = 'http://localhost/kimarite_api.php?'
        . http_build_query([
            'race_code' => $raceCode,
            'in_course' => '123456',
        ]);

    $apiRaw = @file_get_contents($url);
    if ($apiRaw === false) {
        $errors++;
        if (count($firstDiffs) < 20) {
            $firstDiffs[] = "{$raceCode}: API取得失敗";
        }
        continue;
    }

    $apiData = json_decode($apiRaw, true);
    if (!is_array($apiData)) {
        $errors++;
        if (count($firstDiffs) < 20) {
            $firstDiffs[] = "{$raceCode}: API JSON不正";
        }
        continue;
    }

    $diffs = [];
    sameValue($cachedData, $apiData, $raceCode, $diffs);

    if ($diffs) {
        $ng++;
        if (count($firstDiffs) < 20) {
            foreach ($diffs as $diff) {
                $firstDiffs[] = $diff;
                if (count($firstDiffs) >= 20) {
                    break;
                }
            }
        }
    } else {
        $ok++;
    }

    if ($i % 25 === 0 || $i === $total) {
        echo sprintf(
            "[%d/%d] 一致=%d 差分=%d エラー=%d\n",
            $i,
            $total,
            $ok,
            $ng,
            $errors
        );
    }
}

$elapsed = microtime(true) - $started;

echo "\n============================================================\n";
echo "検証完了\n";
echo "============================================================\n";
echo "対象       : {$total}\n";
echo "完全一致   : {$ok}\n";
echo "差分あり   : {$ng}\n";
echo "APIエラー  : {$errors}\n";
echo "所要時間   : " . number_format($elapsed, 2) . "秒\n";
echo "------------------------------------------------------------\n";

if ($firstDiffs) {
    echo "先頭差分:\n";
    foreach ($firstDiffs as $diff) {
        echo "  {$diff}\n";
    }
}

$pass = ($ok === $total && $ng === 0 && $errors === 0);
echo "最終判定   : " . ($pass ? "PASS（完全一致）" : "FAIL") . "\n";
echo "============================================================\n\n";

exit($pass ? 0 : 2);
