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

    <!-- ■ 総合出走・展示マトリクス -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h2>📋 総合出走・展示マトリクス（選手横並び・全項目）</h2>

        <!-- 展示情報を更新するフォーム -->
        <form method="POST" action="" style="margin: 0;">
            <input type="hidden" name="race_code" value="<?= htmlspecialchars($race_code) ?>">
            <button type="submit" name="update_exhibition" value="1" style="background-color: #0284c7; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold;">
                🔄 展示情報を更新
            </button>
        </form>
    </div>

    <!-- 更新完了メッセージ -->
    <?php if (!empty($update_message)): ?>
        <div style="background-color: #065f46; color: #a7f3d0; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
            <?= htmlspecialchars($update_message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($entries)): ?>
        <div class="table-container" style="overflow-x: auto;">
            <table style="white-space: nowrap; width: 100%;">
                <thead>
                    <tr style="background-color: #1e293b; text-align: center;">
                        <th style="position: sticky; left: 0; background-color: #1e293b; z-index: 2; min-width: 120px;">項目 / 艇番</th>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <?php $c = $lane_colors[$i] ?? $lane_colors[1]; ?>
                            <th style="min-width: 130px;">
                                <span class="lane-badge"
                                    style="background-color: <?= $c['bg'] ?>; color: <?= $c['text'] ?>; border: 1px solid <?= $c['border'] ?>; padding: 2px 8px; border-radius: 4px;">
                                    <?= $i ?>号艇
                                </span>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                        // 1〜6号艇のデータをインデックス配列として整理
                        $map_entries = [];
                        foreach ($entries as $e) { $map_entries[(int)$e['lane_number']] = $e; }

                        $map_results = [];
                        foreach ($results as $idx => $r) {
                            $b = $r['boat'] ?? ($idx + 1);
                            $map_results[(int)$b] = $r;
                        }

                        $map_tenji = [];
                        foreach ($tenji_list as $t) { $map_tenji[(int)$t['teiban']] = $t; }

                        // 決まり手データの取得用ヘルパー関数
                        $get_kimarite_val = function($course, $period, $key) use ($kimarite_data) {
                            if (empty($kimarite_data[$course][$period][$key])) return '-';
                            return number_format($kimarite_data[$course][$period][$key], 1) . '%';
                        };

                        // コースごとの決まり手項目定義
                        $kimarite_columns = [
                            ['key' => 'nige', 'label' => '逃げ'],
                            ['key' => 'sashi', 'label' => '差し'],
                            ['key' => 'makuri', 'label' => 'まくり'],
                            ['key' => 'makurizashi', 'label' => 'まくり差し'],
                            ['key' => 'nogashi', 'label' => '逃がし'],
                            ['key' => 'sasare', 'label' => '差され'],
                            ['key' => 'makurare', 'label' => 'まくられ'],
                            ['key' => 'makurarezashi', 'label' => 'まくられ差'],
                        ];
                    ?>

                    <!-- 1. 出走表・基本情報セクション -->
                    <tr style="background-color: #1e293b;">
                        <td colspan="7" style="text-align: left; padding: 8px 12px; font-weight: bold; color: #38bdf8; border-top: 2px solid #334155; border-bottom: 1px solid #334155;">
                            📋 出走表・基本情報
                        </td>
                    </tr>
                    <?php
                        $basic_rows = [
                            ['label' => '選手名', 'key' => 'player_name', 'src' => 'entry'],
                            ['label' => '級別 / 支部', 'src' => 'custom', 'fn' => function($i) use ($map_entries) {
                                $e = $map_entries[$i] ?? [];
                                return htmlspecialchars(($e['class'] ?? '') . ' / ' . ($e['branch'] ?? ''));
                            }],
                            ['label' => '全国勝率', 'key' => 'national_win_rate', 'src' => 'entry'],
                            ['label' => '当地勝率', 'key' => 'local_win_rate', 'src' => 'entry'],
                            ['label' => 'モータ2連対率', 'key' => 'motor_exacta_rate', 'src' => 'entry'],
                            ['label' => 'ボート2連対率', 'key' => 'boat_exacta_rate', 'src' => 'entry'],
                            ['label' => '平均ST', 'key' => 'average_start', 'src' => 'entry'],
                            ['label' => '地力スコア', 'key' => 'jiryoku_score', 'src' => 'result'],
                            ['label' => '一次総合スコア', 'key' => 'total_score', 'src' => 'result', 'highlight' => true],
                            ['label' => '足スコア', 'key' => 'ashi_score', 'src' => 'result'],
                            ['label' => '一次評価', 'key' => 'ichiji_eval', 'src' => 'result'],
                        ];
                    ?>
                    <?php foreach ($basic_rows as $row): ?>
                        <tr>
                            <td style="position: sticky; left: 0; background-color: #0f172a; font-weight: bold; border-right: 2px solid #334155; z-index: 1; padding-left: 20px;">
                                <?= $row['label'] ?>
                            </td>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <?php
                                    $val = '-';
                                    if ($row['src'] === 'entry') $val = $map_entries[$i][$row['key']] ?? '-';
                                    elseif ($row['src'] === 'result') $val = $map_results[$i][$row['key']] ?? '-';
                                    elseif ($row['src'] === 'custom') $val = $row['fn']($i);
                                    $td_class = !empty($row['highlight']) ? 'score-highlight' : '';
                                ?>
                                <td class="<?= $td_class ?>" style="text-align: center; vertical-align: middle;">
                                    <?= htmlspecialchars($val !== '' && $val !== null ? $val : '-') ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>

                    <!-- 2. 展示・評価情報セクション -->
                    <tr style="background-color: #1e293b;">
                        <td colspan="7" style="text-align: left; padding: 8px 12px; font-weight: bold; color: #38bdf8; border-top: 2px solid #334155; border-bottom: 1px solid #334155;">
                            ⏱️ 展示・評価情報
                        </td>
                    </tr>
                    <?php
                        $tenji_rows = [
                            ['label' => '展示進入コース', 'key' => 'tenji_course', 'src' => 'tenji'],
                            ['label' => '展示タイム', 'key' => 'exhibition', 'src' => 'tenji'],
                            ['label' => '周回', 'key' => 'lap', 'src' => 'tenji'],
                            ['label' => '周り足', 'key' => 'mawari', 'src' => 'tenji'],
                            ['label' => '直線', 'key' => 'straight', 'src' => 'tenji'],
                            ['label' => 'J列', 'key' => 'tenji_J', 'src' => 'tenji', 'color' => '#c7d2fe'],
                            ['label' => 'K列', 'key' => 'tenji_K', 'src' => 'tenji', 'color' => '#c7d2fe'],
                            ['label' => 'L列(メイン評価)', 'key' => 'tenji_L', 'src' => 'tenji', 'highlight' => true, 'color' => '#38bdf8'],
                            ['label' => '展示ST', 'key' => 'st', 'src' => 'tenji'],
                            ['label' => '展示タイム場平均差', 'key' => 'ex_diff', 'src' => 'tenji'],
                            ['label' => '展示タイム評価', 'key' => 'ex_score', 'src' => 'tenji'],
                            ['label' => 'ST評価', 'key' => 'st_score', 'src' => 'tenji'],
                            ['label' => '周回評価', 'key' => 'lap_score', 'src' => 'tenji'],
                            ['label' => '周り足評価', 'key' => 'mawari_score', 'src' => 'tenji'],
                            ['label' => '直線評価', 'key' => 'straight_score', 'src' => 'tenji'],
                            ['label' => '展示足トータル', 'key' => 'ex_total', 'src' => 'tenji'],
                            ['label' => '展示攻めポテンシャル', 'key' => 'attack_potential', 'src' => 'tenji'],
                            ['label' => '展示安定感', 'key' => 'stable_score', 'src' => 'tenji'],
                            ['label' => '展示補正スコア', 'key' => 'ex_hosei', 'src' => 'tenji'],
                            ['label' => '展示総合スコア', 'key' => 'ex_sougou', 'src' => 'tenji'],
                            ['label' => '展示タイプ補正', 'key' => 'type_hosei', 'src' => 'tenji'],
                            ['label' => '展示タイプ名', 'src' => 'custom', 'fn' => function($i) use ($map_tenji) {
                                $t = $map_tenji[$i] ?? [];
                                $dtype = $t['dtype'] ?? '';
                                if (!$dtype) return '-';
                                $badge_class = 'type-badge';
                                if ($dtype === '超伸び型') $badge_class .= ' type-super';
                                elseif ($dtype === '攻め型') $badge_class .= ' type-attack';
                                elseif ($dtype === '差し型') $badge_class .= ' type-sashi';
                                return '<span class="' . $badge_class . '">' . htmlspecialchars($dtype) . '</span>';
                            }],
                            ['label' => '展開キー', 'key' => 'tenkai_key', 'src' => 'tenji'],
                            ['label' => '展開もらい補正', 'key' => 'tenkai_morai', 'src' => 'tenji'],
                            ['label' => '最終二次予想スコア', 'key' => 'final_2nd_score', 'src' => 'tenji', 'highlight' => true, 'style' => 'font-size: 14px;'],
                        ];
                    ?>
                    <?php foreach ($tenji_rows as $row): ?>
                        <tr>
                            <td style="position: sticky; left: 0; background-color: #0f172a; font-weight: bold; border-right: 2px solid #334155; z-index: 1; color: <?= $row['color'] ?? '#f8fafc' ?>; padding-left: 20px;">
                                <?= $row['label'] ?>
                            </td>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <?php
                                    $val = $map_tenji[$i][$row['key']] ?? '-';
                                    if ($row['src'] === 'custom') $val = $row['fn']($i);
                                    $td_style = $row['style'] ?? '';
                                    if (!empty($row['color']) && $row['src'] !== 'custom') $td_style .= " color: {$row['color']};";
                                    $td_class = !empty($row['highlight']) ? 'score-highlight' : '';
                                ?>
                                <td class="<?= $td_class ?>" style="text-align: center; vertical-align: middle; <?= $td_style ?>">
                                    <?php if ($row['src'] === 'custom' && (strpos($val, '<span') !== false)): ?>
                                        <?= $val ?>
                                    <?php else: ?>
                                        <?= htmlspecialchars($val !== '' && $val !== null ? $val : '-') ?>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        </tr>
                    <?php endforeach; ?>

                    <!-- 決まり手（直近1年） -->
                    <tr style="background-color:#1e293b;">
                        <td colspan="7" style="padding:8px 12px; font-weight:bold; color:#38bdf8;">
                            🎯 決まり手（直近1年）
                        </td>
                    </tr>

                    <?php
                    function get_kimarite_keys_by_course($course) {
                        if ($course == 1) {
                            return [
                                ['key' => 'nige', 'label' => '逃げ'],
                                ['key' => 'sasare', 'label' => '差され'],
                                ['key' => 'makurare', 'label' => '捲られ'],
                                ['key' => 'makurarezashi', 'label' => '捲られ差'],
                            ];
                        }

                        if ($course == 2) {
                            return [
                                ['key' => 'nige', 'label' => '逃げ'], // 逃がし
                                ['key' => 'sashi', 'label' => '差し'],
                                ['key' => 'makuri', 'label' => '捲り'],
                                ['key' => 'makurizashi', 'label' => '捲り差し'],
                            ];
                        }

                        return [
                            ['key' => 'sashi', 'label' => '差し'],
                            ['key' => 'makuri', 'label' => '捲り'],
                            ['key' => 'makurizashi', 'label' => '捲り差し'],
                        ];
                    }

                    function biyori_color($v) {
                        if ($v >= 40) return '#f87171';   // 赤
                        if ($v >= 25) return '#fb923c';   // オレンジ
                        if ($v >= 10) return '#facc15';   // 黄色
                        if ($v > 0)  return '#60a5fa';    // 青
                        return '#475569';                 // グレー
                    }
                    ?>

                    <!-- 決まり手（直近1年） -->
                    <tr style="background-color:#1e293b;">
                        <td colspan="7" style="padding:8px 12px; font-weight:bold; color:#38bdf8;">
                            🎯 決まり手（直近1年）
                        </td>
                    </tr>

                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <?php $cols = get_kimarite_keys_by_course($i); ?>

                        <tr style="background-color:#0f172a;">
                            <td colspan="7" style="padding:6px 12px; font-weight:bold; color:#38bdf8;">
                                <?= $i ?>コース
                            </td>
                        </tr>

                        <?php foreach ($cols as $col): ?>
                            <tr>
                                <td style="position:sticky; left:0; background:#0f172a; font-weight:bold; border-right:2px solid #334155; padding-left:20px;">
                                    <?= $col['label'] ?>
                                </td>

                                <?php
                                    $v = $kimarite_data[$i]['1year'][$col['key']] ?? 0;
                                    $pct = number_format($v, 1) . '%';
                                    $bg = biyori_color($v);
                                ?>
                                <td style="text-align:center; background:<?= $bg ?>; color:#0f172a; font-weight:bold;">
                                    <?= $pct ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endfor; ?>


                    <!-- 決まり手（直近6ヶ月） -->
                    <tr style="background-color:#1e293b;">
                        <td colspan="7" style="padding:8px 12px; font-weight:bold; color:#38bdf8;">
                            🎯 決まり手（直近6ヶ月）
                        </td>
                    </tr>

                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <?php $cols = get_kimarite_keys_by_course($i); ?>

                        <tr style="background-color:#0f172a;">
                            <td colspan="7" style="padding:6px 12px; font-weight:bold; color:#38bdf8;">
                                <?= $i ?>コース
                            </td>
                        </tr>

                        <?php foreach ($cols as $col): ?>
                            <tr>
                                <td style="position:sticky; left:0; background:#0f172a; font-weight:bold; border-right:2px solid #334155; padding-left:20px;">
                                    <?= $col['label'] ?>
                                </td>

                                <?php
                                    $v = $kimarite_data[$i]['6month'][$col['key']] ?? 0;
                                    $pct = number_format($v, 1) . '%';
                                    $bg = biyori_color($v);
                                ?>
                                <td style="text-align:center; background:<?= $bg ?>; color:#0f172a; font-weight:bold;">
                                    <?= $pct ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endfor; ?>

                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="no-data"><?= htmlspecialchars($api_error ?: 'データが存在しません。') ?></div>
    <?php endif; ?>
    
    <!-- ■ 最終予想（Excel完全一致） -->
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

    <!-- ■ 展示サム理論マスタデータ -->
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

    <h2>📐 サム理論（コース・区間別マスタ）</h2>
    <button id="toggle-sam" class="btn btn-primary">サム理論を非表示にする</button>
    <script>
    document.getElementById("toggle-sam").addEventListener("click", function() {
        const block = document.getElementById("sam-block");
        if (block.style.display === "none") {
            block.style.display = "block";
            this.textContent = "サム理論を非表示にする";
        } else {
            block.style.display = "none";
            this.textContent = "サム理論を表示する";
        }
    });
    </script>

    <!-- サム理論（コース・区間別マスタ）表示用テーブル -->
    <div id="sam-block">
        <?php if (!empty($sam_master_data)): ?>
            <h2 class="text-xl font-bold mb-3">サム理論（コース・区間別マスタデータ）</h2>
            <table class="table table-bordered text-center align-middle">
                <thead>
                    <tr>
                        <th>コース</th>
                        <th>指標</th>
                        <?php foreach ($sam_intervals as $interval): ?>
                            <th><?= htmlspecialchars($interval) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($course = 1; $course <= 6; $course++): ?>
                        <?php
                            $c_str = (string)$course;
                            $course_data = $sam_master_data[$c_str] ?? $sam_master_data[$course] ?? [];
                        ?>
                        <?php foreach ($sam_metrics as $m_idx => $metric): ?>
                            <tr>
                                <?php if ($m_idx === 0): ?>
                                    <td rowspan="<?= count($sam_metrics) ?>" class="align-middle font-bold"><?= $course ?>コース</td>
                                <?php endif; ?>
                                <td class="font-bold"><?= htmlspecialchars($metric) ?></td>
                                
                                <?php foreach ($sam_intervals as $interval): ?>
                                    <?php 
                                        $raw_val = $course_data[$interval][$metric] ?? '-';
                                        $val = is_numeric($raw_val) ? (float)$raw_val : null;

                                        $style = '';
                                        if ($val !== null) {
                                            if ($val > 0.05) {
                                                $style = 'background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; font-weight: bold;';
                                            } elseif ($val > 0.0) {
                                                $style = 'background-color: rgba(239, 68, 68, 0.1); color: #f87171;';
                                            } elseif ($val < -0.05) {
                                                $style = 'background-color: rgba(59, 130, 246, 0.2); color: #93c5fd; font-weight: bold;';
                                            } elseif ($val < 0.0) {
                                                $style = 'background-color: rgba(59, 130, 246, 0.1); color: #60a5fa;';
                                            }
                                        }
                                    ?>
                                    <td style="<?= $style ?>">
                                        <?= $val !== null ? sprintf('%.3f', $val) : '-' ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endfor; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

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