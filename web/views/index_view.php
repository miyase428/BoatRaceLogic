<!-- Part 1: Header + Form -->

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BoatRace Analytics</title>

    <!-- CSS 分離版 -->
    <link rel="stylesheet" href="/web/assets/css/style.css">
</head>

<body>
<div class="container">

    <h1>艇 BoatRace Analytics</h1>

    <!-- ■ 入力情報 -->
    <div class="form-section">
        <form method="GET" action="">
            <div class="form-grid">

                <div class="form-group">
                    <label>日付</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                </div>

                <div class="form-group">
                    <label>開催場所</label>
                    <select name="place">
                        <?php foreach ($place_names as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $selected_place === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?> (<?= htmlspecialchars($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>レース番号</label>
                    <select name="race">
                        <?php for($i=1; $i<=12; $i++): ?>
                            <?php $r = sprintf('%02d', $i); ?>
                            <option value="<?= $r ?>" <?= $selected_race === $r ? 'selected' : '' ?>>
                                <?= $i ?>R
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>進入コース (6桁)</label>
                    <input type="text" name="in_course" maxlength="6" value="<?= htmlspecialchars($in_course) ?>">
                </div>

            </div>

            <button type="submit">レース情報取得</button>
        </form>
    </div>

    <div class="code-box">
        <div class="code-label">生成されたレースコード</div>
        <div class="code-value"><?= htmlspecialchars($race_code) ?></div>
    </div>
    <!-- Part 2: 出走表情報 -->

    <h2>📋 出走表情報</h2>

    <?php if (!empty($entries)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>枠</th>
                        <th>選手名</th>
                        <th>級別/支部</th>
                        <th>全国勝率</th>
                        <th>当地勝率</th>
                        <th>モータ</th>
                        <th>ボート</th>
                        <th>平均ST</th>
                        <th>地力</th>
                        <th>一次総合</th>
                        <th>足スコア</th>
                        <th>評価</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $index => $e): ?>
                        <?php
                            $lane = (int)$e['lane_number'];
                            $c = $lane_colors[$lane] ?? $lane_colors[1];
                            $r = $results[$index] ?? [];
                            $ashi_score = $r['ashi_score'] ?? 0;
                        ?>
                        <tr>
                            <td>
                                <span class="lane-badge"
                                    style="background-color: <?= $c['bg'] ?>;
                                            color: <?= $c['text'] ?>;
                                            border: 1px solid <?= $c['border'] ?>;">
                                    <?= $lane ?>
                                </span>
                            </td>

                            <td class="player-name"><?= htmlspecialchars($e['player_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($e['class'] ?? '') ?> / <?= htmlspecialchars($e['branch'] ?? '') ?></td>
                            <td><?= htmlspecialchars($e['national_win_rate'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($e['local_win_rate'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($e['motor_exacta_rate'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($e['boat_exacta_rate'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($e['average_start'] ?? '-') ?></td>

                            <td><?= htmlspecialchars($r['jiryoku_score'] ?? '-') ?></td>
                            <td class="score-highlight"><?= htmlspecialchars($r['total_score'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($ashi_score) ?></td>
                            <td><?= htmlspecialchars($r['ichiji_eval'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="no-data"><?= htmlspecialchars($api_error ?: 'データが存在しません。') ?></div>
    <?php endif; ?>
    <!-- Part 3: 決まり手情報 -->

    <h2>🎯 決まり手情報</h2>

    <?php if (!empty($kimarite_data)): ?>
        <div class="table-container">
            <table class="kimarite-table">
                <thead>
                    <tr>
                        <th>枠</th>
                        <th>期間</th>
                        <th>逃げ</th>
                        <th>差し</th>
                        <th>まくり</th>
                        <th>まくり差し</th>
                        <th>逃がし</th>
                        <th>差され</th>
                        <th>まくられ</th>
                        <th>まくられ差</th>
                    </tr>
                </thead>
                <tbody>

                    <?php for ($course = 1; $course <= 6; $course++): ?>
                        <?php
                            $c_str = (string)$course;
                            $data_1y = $kimarite_data[$c_str]['1year'] ?? [];
                            $data_6m = $kimarite_data[$c_str]['6month'] ?? [];
                            $c = $lane_colors[$course] ?? $lane_colors[1];
                        ?>

                        <!-- 1年データ -->
                        <tr class="border-top-course">
                            <td rowspan="2" style="vertical-align: middle;">
                                <span class="lane-badge"
                                    style="background-color: <?= $c['bg'] ?>;
                                            color: <?= $c['text'] ?>;
                                            border: 1px solid <?= $c['border'] ?>;">
                                    <?= $course ?>
                                </span>
                            </td>

                            <td>1年</td>
                            <td><?= number_format(($data_1y['nige'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_1y['sashi'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_1y['makuri'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_1y['makurizashi'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_1y['nogashi'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_1y['sasare'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_1y['makurare'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_1y['makurarezashi'] ?? 0), 1) ?>%</td>
                        </tr>

                        <!-- 6ヶ月データ -->
                        <tr>
                            <td style="color: #94a3b8;">6ヶ月</td>
                            <td><?= number_format(($data_6m['nige'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_6m['sashi'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_6m['makuri'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_6m['makurizashi'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_6m['nogashi'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_6m['sasare'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_6m['makurare'] ?? 0), 1) ?>%</td>
                            <td><?= number_format(($data_6m['makurarezashi'] ?? 0), 1) ?>%</td>
                        </tr>

                    <?php endfor; ?>

                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="no-data"><?= htmlspecialchars($kimarite_error ?: '決まり手データが存在しません。') ?></div>
    <?php endif; ?>

    <!-- Part 4: 展示情報 -->

    <h2>⏱️ 展示情報</h2>

    <?php if (!empty($tenji_list)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>艇番</th>
                        <th>展示進入コース</th>
                        <th>展示タイム</th>
                        <th>周回</th>
                        <th>周り足</th>
                        <th>直線</th>

                        <!-- J / K / L 評価列 -->
                        <th style="color:#a5b4fc;">J列</th>
                        <th style="color:#a5b4fc;">K列</th>
                        <th style="color:#38bdf8;">L列(メイン評価)</th>

                        <th>ST</th>
                        <th>展示タイム場平均差</th>
                        <th>展示タイム評価</th>
                        <th>ST評価</th>
                        <th>周回評価</th>
                        <th>周り足評価</th>
                        <th>直線評価</th>

                        <th>展示足トータル</th>
                        <th>展示攻めポテンシャル</th>
                        <th>展示安定感</th>
                        <th>展示補正スコア</th>
                        <th>展示総合スコア</th>

                        <th>展示タイプ補正</th>
                        <th>展示タイプ名</th>

                        <th>展開キー</th>
                        <th>展開もらい補正</th>

                        <th>最終二次予想スコア</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($tenji_list as $t): ?>
                        <?php
                            $boat = (int)$t['teiban'];
                            $c = $lane_colors[$boat] ?? $lane_colors[1];

                            // 展示タイプバッジ
                            $badge_class = 'type-badge';
                            if ($t['dtype'] === '超伸び型') $badge_class .= ' type-super';
                            elseif ($t['dtype'] === '攻め型') $badge_class .= ' type-attack';
                            elseif ($t['dtype'] === '差し型') $badge_class .= ' type-sashi';
                        ?>

                        <tr>
                            <td>
                                <span class="lane-badge"
                                    style="background-color: <?= $c['bg'] ?>;
                                            color: <?= $c['text'] ?>;
                                            border: 1px solid <?= $c['border'] ?>;">
                                    <?= $boat ?>
                                </span>
                            </td>

                            <td><?= htmlspecialchars($t['tenji_course']) ?></td>
                            <td><?= htmlspecialchars($t['exhibition']) ?></td>
                            <td><?= htmlspecialchars($t['lap']) ?></td>
                            <td><?= htmlspecialchars($t['mawari']) ?></td>
                            <td><?= htmlspecialchars($t['straight']) ?></td>

                            <!-- J / K / L -->
                            <td style="color:#c7d2fe;"><?= htmlspecialchars($t['tenji_J']) ?></td>
                            <td style="color:#c7d2fe;"><?= htmlspecialchars($t['tenji_K']) ?></td>
                            <td class="score-highlight"><?= htmlspecialchars($t['tenji_L']) ?></td>

                            <td><?= htmlspecialchars($t['st']) ?></td>
                            <td><?= htmlspecialchars($t['ex_diff']) ?></td>
                            <td><?= htmlspecialchars($t['ex_score']) ?></td>
                            <td><?= htmlspecialchars($t['st_score']) ?></td>
                            <td><?= htmlspecialchars($t['lap_score']) ?></td>
                            <td><?= htmlspecialchars($t['mawari_score']) ?></td>
                            <td><?= htmlspecialchars($t['straight_score']) ?></td>

                            <td><?= htmlspecialchars($t['ex_total']) ?></td>
                            <td><?= htmlspecialchars($t['attack_potential']) ?></td>
                            <td><?= htmlspecialchars($t['stable_score']) ?></td>

                            <td><?= htmlspecialchars($t['ex_hosei']) ?></td>
                            <td><?= htmlspecialchars($t['ex_sougou']) ?></td>

                            <td><?= htmlspecialchars($t['type_hosei']) ?></td>

                            <td><span class="<?= $badge_class ?>"><?= htmlspecialchars($t['dtype']) ?></span></td>

                            <td><?= htmlspecialchars($t['tenkai_key']) ?></td>
                            <td><?= htmlspecialchars($t['tenkai_morai']) ?></td>

                            <td class="score-highlight" style="font-size: 14px;">
                                <?= htmlspecialchars($t['final_2nd_score']) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="no-data"><?= htmlspecialchars($tenji_error ?: '展示データが存在しません。') ?></div>
    <?php endif; ?>

    <!-- Part 5: 最終予想（Excel完全一致） -->

    <h2>📊 最終予想（Excel完全一致）</h2>

    <?php if (!empty($final_predictions)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>艇番</th>
                        <th>枠番</th>
                        <th>直近6ヶ月3連対率</th>
                        <th>直近3ヶ月3連対率</th>
                        <th>↓3連対期待値</th>
                        <th>展開フラグ_差し</th>
                        <th>展開フラグ_まくり</th>
                        <th>展開フラグ_まくり差し</th>
                        <th>展開フラグ_逃し</th>
                        <th>決まり手タイプ</th>
                        <th>決まり手補正 (X)</th>
                        <th>三次予想スコア</th>
                        <th>切る艇</th>
                    </tr>
                </thead>

                <tbody>
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <?php
                            $fp = $final_predictions[$i];
                            $c  = $lane_colors[$i] ?? $lane_colors[1];
                        ?>
                        <tr>
                            <td><?= $fp['boat'] ?></td>

                            <td>
                                <span class="lane-badge"
                                    style="background-color: <?= $c['bg'] ?>;
                                            color: <?= $c['text'] ?>;
                                            border: 1px solid <?= $c['border'] ?>;">
                                    <?= $fp['waku'] ?>
                                </span>
                            </td>

                            <td><?= number_format($fp['rate6_dec'] * 100, 1) ?>%</td>
                            <td><?= number_format($fp['rate3_dec'] * 100, 1) ?>%</td>
                            <td><?= number_format($fp['kitai_dec'] * 100, 1) ?>%</td>

                            <td><?= htmlspecialchars($fp['flg_sashi']) ?></td>
                            <td><?= htmlspecialchars($fp['flg_makuri']) ?></td>
                            <td><?= htmlspecialchars($fp['flg_makurizashi']) ?></td>
                            <td><?= htmlspecialchars($fp['flg_nogashi']) ?></td>

                            <td><?= htmlspecialchars($fp['type']) ?></td>
                            <td><?= $fp['typeBonus'] ?></td>

                            <td class="score-highlight" style="font-size: 14px;">
                                <?= $fp['final3'] ?>
                            </td>

                            <td>
                                <?php if ($fp['kiru'] == 1): ?>
                                    <span style="color:#ef4444; font-weight:bold;">1</span>
                                <?php else: ?>
                                    0
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- ■ 集計・買い目テーブル（下部） -->
        <div class="summary-box">
            <table>
                <thead>
                    <tr>
                        <th>本命/対抗</th>
                        <th>頭</th>
                        <th>相手候補</th>
                        <th>切る艇</th>
                        <th>相手候補(加工)</th>
                        <th>切る艇(加工)</th>
                        <th>買い目候補 (3連単)</th>
                    </tr>
                </thead>

                <tbody>
                    <tr>
                        <td style="font-weight:bold; color:#38bdf8;">本命</td>
                        <td><?= $honmei_head ?></td>
                        <td><?= htmlspecialchars($honmei_aite_str) ?></td>
                        <td><?= htmlspecialchars($kiru_str) ?></td>
                        <td><?= htmlspecialchars($honmei_aite_kako) ?></td>
                        <td><?= htmlspecialchars($kiru_kako) ?></td>
                        <td class="score-highlight"><?= htmlspecialchars($honmei_kai) ?></td>
                    </tr>

                    <tr>
                        <td style="font-weight:bold; color:#f59e0b;">対抗</td>
                        <td><?= $taikou_head ?></td>
                        <td><?= htmlspecialchars($taikou_aite_str) ?></td>
                        <td><?= htmlspecialchars($kiru_str) ?></td>
                        <td><?= htmlspecialchars($taikou_aite_kako) ?></td>
                        <td><?= htmlspecialchars($kiru_kako) ?></td>
                        <td class="score-highlight"><?= htmlspecialchars($taikou_kai) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="no-data">最終予想データが存在しません。</div>
    <?php endif; ?>

<!-- Part 6: サム理論マスタデータ -->

<h2>📐 サム理論（コース・区間別マスタ）</h2>

<?php if (!empty($sam_master_data)): ?>
    <div class="table-container">
        <table class="sam-table">
            <thead>
                <tr>
                    <th>コース</th>
                    <th>区間</th>
                    <th>win</th>
                    <th>place2</th>
                    <th>place3</th>
                    <th>trio</th>
                </tr>
            </thead>

            <tbody>
                <?php for ($course = 1; $course <= 6; $course++): ?>
                    <?php 
                        $c_str = (string)$course;
                        $course_data = $sam_master_data[$c_str] ?? [];
                        $c = $lane_colors[$course] ?? $lane_colors[1];
                        $bg_class = "sam-course-bg-" . $course;
                    ?>

                    <?php foreach ($sam_intervals as $idx => $interval): ?>
                        <?php $row_metrics = $course_data[$interval] ?? []; ?>

                        <tr class="<?= $bg_class ?> <?= ($idx === 0) ? 'border-top-course' : '' ?>">

                            <?php if ($idx === 0): ?>
                                <td rowspan="8" style="vertical-align: middle;">
                                    <span class="lane-badge"
                                          style="background-color: <?= $c['bg'] ?>;
                                                 color: <?= $c['text'] ?>;
                                                 border: 1px solid <?= $c['border'] ?>;">
                                        <?= $course ?>
                                    </span>
                                </td>
                            <?php endif; ?>

                            <td style="text-align: center; color: #a5b4fc;">
                                <?= htmlspecialchars($interval) ?>
                            </td>

                            <?php foreach ($sam_metrics as $m): ?>
                                <?php 
                                    $val = (float)($row_metrics[$m] ?? 0);
                                    $color_style = "";
                                    if ($val > 0) $color_style = "color: #38bdf8;";
                                    elseif ($val < 0) $color_style = "color: #f87171;";
                                ?>
                                <td style="<?= $color_style ?>">
                                    <?= number_format($val * 100, 0) ?>%
                                </td>
                            <?php endforeach; ?>

                        </tr>
                    <?php endforeach; ?>

                <?php endfor; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <div class="no-data"><?= htmlspecialchars($sam_error ?: 'サム理論マスタデータが存在しません。') ?></div>
<?php endif; ?>


<!-- ■ 展示サム理論 (Excel完全再現) -->

<h2>📐 展示サム理論（レース適用値）</h2>

<?php if (!empty($sam_applied_list)): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>コース</th>
                    <th>J列</th>
                    <th>K列</th>
                    <th>L列</th>
                    <th>合計</th>
                    <th>平均差</th>
                    <th>1着率</th>
                    <th>2着率</th>
                    <th>3着率</th>
                    <th>3連対率</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($sam_applied_list as $s): ?>
                    <?php $c = $lane_colors[$s['course']] ?? $lane_colors[1]; ?>

                    <tr>
                        <td>
                            <span class="lane-badge"
                                  style="background-color: <?= $c['bg'] ?>;
                                         color: <?= $c['text'] ?>;
                                         border: 1px solid <?= $c['border'] ?>;">
                                <?= $s['course'] ?>
                            </span>
                        </td>

                        <td><?= number_format($s['val_j'], 2) ?></td>
                        <td><?= number_format($s['val_k'], 2) ?></td>
                        <td><?= number_format($s['val_l'], 2) ?></td>

                        <td style="font-weight: bold;">
                            <?= number_format($s['sum'], 2) ?>
                        </td>

                        <td style="color: <?= $s['avg_diff'] < 0 ? '#38bdf8' : '#f87171' ?>; font-weight: bold;">
                            <?= sprintf('%+.3f', $s['avg_diff']) ?>
                        </td>

                        <td style="color: <?= $s['win'] > 0 ? '#38bdf8' : ($s['win'] < 0 ? '#f87171' : '#fff') ?>;">
                            <?= number_format($s['win'] * 100, 0) ?>%
                        </td>

                        <td style="color: <?= $s['place2'] > 0 ? '#38bdf8' : ($s['place2'] < 0 ? '#f87171' : '#fff') ?>;">
                            <?= number_format($s['place2'] * 100, 0) ?>%
                        </td>

                        <td style="color: <?= $s['place3'] > 0 ? '#38bdf8' : ($s['place3'] < 0 ? '#f87171' : '#fff') ?>;">
                            <?= number_format($s['place3'] * 100, 0) ?>%
                        </td>

                        <td style="font-weight: bold; color: <?= $s['trio'] > 0 ? '#38bdf8' : ($s['trio'] < 0 ? '#f87171' : '#fff') ?>;">
                            <?= number_format($s['trio'] * 100, 0) ?>%
                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>

            <tfoot>
                <tr style="background-color: #0f172a; font-weight: bold;">
                    <td colspan="4" style="text-align: right; color: #94a3b8;">全体平均:</td>
                    <td style="color: #38bdf8;"><?= number_format($overall_avg, 3) ?></td>
                    <td colspan="5"></td>
                </tr>
            </tfoot>

        </table>
    </div>
<?php endif; ?>


<!-- ■ スリット体系 -->

<div style="margin-top: 30px; background-color: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 20px;">
    <h2 style="font-size: 18px; font-weight: bold; color: #f8fafc; margin-bottom: 15px;">📊 スリット体系</h2>

    <?php if (!empty($slit_data)): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>コース</th>
                        <th>1着率</th>
                        <th>2着率</th>
                        <th>3着率</th>
                        <th>3連対率</th>
                    </tr>
                </thead>

                <tbody>
                    <?php for ($c = 1; $c <= 6; $c++): ?>
                        <?php 
                            $metrics = $slit_data[$c] ?? $slit_data[(string)$c] ?? [];
                            $color = $lane_colors[$c] ?? $lane_colors[1];

                            $win    = (float)($metrics['win'] ?? 0) * 100;
                            $place2 = (float)($metrics['place2'] ?? 0) * 100;
                            $place3 = (float)($metrics['place3'] ?? 0) * 100;
                            $trio   = (float)($metrics['trio'] ?? 0) * 100;

                            $getColorStyle = function($val) {
                                $rounded = round($val, 2);
                                if ($rounded > 0)  return 'color: #38bdf8;';
                                if ($rounded < 0)  return 'color: #f87171;';
                                return 'color: #ffffff;';
                            };
                        ?>

                        <tr>
                            <td>
                                <span class="lane-badge"
                                      style="background-color: <?= $color['bg'] ?>;
                                             color: <?= $color['text'] ?>;
                                             border: 1px solid <?= $color['border'] ?>;">
                                    <?= $c ?>
                                </span>
                            </td>

                            <td style="<?= $getColorStyle($win) ?>">
                                <?= sprintf('%.2f%%', abs($win) < 0.005 ? 0 : $win) ?>
                            </td>

                            <td style="<?= $getColorStyle($place2) ?>">
                                <?= sprintf('%.2f%%', abs($place2) < 0.005 ? 0 : $place2) ?>
                            </td>

                            <td style="<?= $getColorStyle($place3) ?>">
                                <?= sprintf('%.2f%%', abs($place3) < 0.005 ? 0 : $place3) ?>
                            </td>

                            <td style="font-weight: bold; <?= $getColorStyle($trio) ?>">
                                <?= sprintf('%.2f%%', abs($trio) < 0.005 ? 0 : $trio) ?>
                            </td>
                        </tr>

                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <!-- パターン情報表示 -->
        <div style="margin-top: 15px; padding: 12px; background-color: #1e293b; border-radius: 6px; border-left: 4px solid #38bdf8;">

            <div style="display: flex; gap: 20px; align-items: center; margin-bottom: 6px;">
                <div>
                    <span style="color: #94a3b8; font-size: 12px;">パターンID:</span>
                    <strong style="color: #f8fafc; font-size: 16px;">
                        <?= htmlspecialchars($slit_pattern['id']) ?>
                    </strong>
                </div>

                <div>
                    <span style="color: #94a3b8; font-size: 12px;">パターン名:</span>
                    <span class="badge"
                          style="background-color: #0284c7; color: #fff; padding: 3px 8px; border-radius: 4px; font-weight: bold;">
                        <?= htmlspecialchars($slit_pattern['name']) ?>
                    </span>
                </div>
            </div>

            <div style="font-size: 13px; color: #cbd5e1;">
                <span style="color: #94a3b8;">説明:</span>
                <?= htmlspecialchars($slit_pattern['desc']) ?>
            </div>

            <?php if (!empty($slit_pattern['features'])): ?>
                <div class="card mt-2">
                    <div class="card-header">スリット特徴</div>
                    <div class="card-body">
                        <?php foreach ($slit_pattern['features'] as $key => $value): ?>
                            <?php if ($value === true): ?>
                                <?= "✅ " . ($feature_name[$key] ?? $key) ?><br>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    <?php else: ?>
        <p style="color: #94a3b8;">※スリット体系データが取得できませんでした。</p>
    <?php endif; ?>

</div>

</div> <!-- container -->

</body>
</html>
