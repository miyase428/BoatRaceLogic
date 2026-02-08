
<?php
/**
 * exhibition_calc.php
 * ------------------------------------------------------------
 * 展示データの計算処理を共通化したモジュール。
 *
 * ・過去平均の取得
 * ・差分計算
 * ・スコア算出
 * ・タイプ判定
 * ・start_timing の変換
 * ・スコア計算用の toFloatOrZero
 *
 * 使用例:
 *   $calc = calc_exhibition($pdo, $place_code, $row);
 *
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/log_message.php';

/* ------------------------------------------------------------
 * start_timing の変換（F.04 → -0.04）
 * ------------------------------------------------------------ */
function convertStartTiming($value)
{
    $value = trim($value);

    if ($value === "" || $value === "--" || $value === "-") {
        return null;
    }

    if (str_starts_with($value, "F")) {
        return -1 * floatval(substr($value, 1));
    }

    if (str_starts_with($value, "L")) {
        return floatval(substr($value, 1));
    }

    return floatval($value);
}

/* ------------------------------------------------------------
 * "-" や "" を 0 に変換（score 計算用）
 * ------------------------------------------------------------ */
function toFloatOrZero($v)
{
    return ($v === "-" || $v === "" || $v === null) ? 0.0 : floatval($v);
}

/* ------------------------------------------------------------
 * 過去平均の取得
 * ------------------------------------------------------------ */
function get_exhibition_average($pdo, $place_code)
{
    $sql = "
        SELECT
            AVG(exhibition_time) AS avg_exh,
            AVG(lap_time)        AS avg_lap,
            AVG(around_time)     AS avg_around,
            AVG(straight_time)   AS avg_straight
        FROM boat_race.exhibition_live
        WHERE race_code LIKE :place_prefix
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':place_prefix' => "%{$place_code}%"]);
    $avg = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'avg_exh'      => $avg['avg_exh']      ?? 0,
        'avg_lap'      => $avg['avg_lap']      ?? 0,
        'avg_around'   => $avg['avg_around']   ?? 0,
        'avg_straight' => $avg['avg_straight'] ?? 0,
    ];
}

/* ------------------------------------------------------------
 * スコア算出 + タイプ判定
 * ------------------------------------------------------------ */
function calc_exhibition_score_and_type($avg, $row)
{
    $exh      = toFloatOrZero($row['exhibition_time']);
    $lap      = toFloatOrZero($row['lap_time']);
    $around   = toFloatOrZero($row['around_time']);
    $straight = toFloatOrZero($row['straight_time']);

    $diff_straight = $avg['avg_straight'] - $straight;
    $diff_around   = $avg['avg_around']   - $around;
    $diff_lap      = $avg['avg_lap']      - $lap;
    $diff_exh      = $avg['avg_exh']      - $exh;

    // スコア算出
    $score =
        $diff_straight * 0.4 +
        $diff_around   * 0.3 +
        $diff_lap      * 0.2 +
        $diff_exh      * 0.1;

    // タイプ判定
    if ($diff_straight > 0.10) {
        $type = '伸び型';
    } elseif ($diff_around > 0.10) {
        $type = '差し型';
    } else {
        $type = 'バランス';
    }

    return [$score, $type];
}

/* ------------------------------------------------------------
 * 展示データ1艇分の計算をまとめる
 * ------------------------------------------------------------ */
function calc_exhibition($pdo, $place_code, $row)
{
    // 過去平均取得
    $avg = get_exhibition_average($pdo, $place_code);

    // スコア & タイプ
    [$score, $type] = calc_exhibition_score_and_type($avg, $row);

    return [
        'player_id'        => $row['player_id'],
        'entry_course'     => $row['entry_course'],
        'exhibition_time'  => $row['exhibition_time'],
        'start_timing'     => convertStartTiming($row['start_timing']),
        'lap_time'         => $row['lap_time'],
        'around_time'      => $row['around_time'],
        'straight_time'    => $row['straight_time'],
        'exhibition_score' => $score,
        'exhibition_type'  => $type
    ];
}