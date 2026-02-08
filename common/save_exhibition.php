<?php
/**
 * save_exhibition.php
 * ------------------------------------------------------------
 * 展示情報を DB に保存する共通モジュール。
 *
 * ・INSERT / UPDATE を自動判定
 * ・重複チェック
 * ・ログ出力
 *
 * 使用例:
 *   save_exhibition($pdo, $race_code, [$calc]);
 *
 * ------------------------------------------------------------
 */

require_once __DIR__ . '/log_message.php';

/**
 * "-" や "" を null に変換（DB INSERT 用）
 */
function toNullOrFloat($v)
{
    return ($v === "-" || $v === "" || $v === null) ? null : floatval($v);
}

function save_exhibition($pdo, $race_code, $results)
{
    foreach ($results as $row) {

        $entry_course     = $row['entry_course'];
        $player_id        = $row['player_id'];
        $exh_time         = toNullOrFloat($row['exhibition_time']);
        $start_timing     = $row['start_timing'];  // calc_exhibition() で変換済み
        $lap_time         = toNullOrFloat($row['lap_time']);
        $around_time      = toNullOrFloat($row['around_time']);
        $straight_time    = toNullOrFloat($row['straight_time']);
        $exh_score        = $row['exhibition_score'];
        $exh_type         = $row['exhibition_type'];

        // 既存データチェック
        $sql = "SELECT COUNT(*) FROM boat_race.exhibition_live
                WHERE race_code = :race_code AND entry_course = :entry_course";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':race_code'     => $race_code,
            ':entry_course'  => $entry_course
        ]);
        $exists = $stmt->fetchColumn() > 0;

        if ($exists) {
            // UPDATE
            $sql = "UPDATE boat_race.exhibition_live SET
                        player_id        = :player_id,
                        exhibition_time  = :exh,
                        start_timing     = :start,
                        lap_time         = :lap,
                        around_time      = :around,
                        straight_time    = :straight,
                        exhibition_score = :score,
                        exhibition_type  = :type,
                        updated_date     = NOW()
                    WHERE race_code = :race_code AND entry_course = :entry_course";
            $action = "UPDATE";

        } else {
            // INSERT
            $sql = "INSERT INTO boat_race.exhibition_live (
                        race_code, entry_course, player_id,
                        exhibition_time, start_timing, lap_time,
                        around_time, straight_time,
                        exhibition_score, exhibition_type,
                        created_date, updated_date
                    ) VALUES (
                        :race_code, :entry_course, :player_id,
                        :exh, :start, :lap,
                        :around, :straight,
                        :score, :type,
                        NOW(), NOW()
                    )";
            $action = "INSERT";
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':race_code'     => $race_code,
            ':entry_course'  => $entry_course,
            ':player_id'     => $player_id,
            ':exh'           => $exh_time,
            ':start'         => $start_timing,
            ':lap'           => $lap_time,
            ':around'        => $around_time,
            ':straight'      => $straight_time,
            ':score'         => $exh_score,
            ':type'          => $exh_type
        ]);

        log_message("【save_exhibition】{$action} 完了: race_code={$race_code}, course={$entry_course}");
    }
}