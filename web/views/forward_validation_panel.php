<?php
require_once __DIR__ . '/../logic/ForwardValidationLogic.php';

$fvEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$fvMode = (string)($forwardValidationMode ?? 'pc');
$fvRaceCode = (string)($race_code ?? '');
$fvPlaceCode = (string)($selected_place ?? '');
$fvRaceDate = (string)($selected_date ?? '');
$fvRaceNo = (int)($selected_race ?? 0);
$fvVenueName = (string)($place_names[$fvPlaceCode] ?? $fvPlaceCode);

$fvCurrentSnapshot = [
    'honmei_head' => (int)($honmei_head ?? 0),
    'honmei_kai' => (string)($honmei_kai ?? ''),
    'taikou_head' => (int)($taikou_head ?? 0),
    'taikou_kai' => (string)($taikou_kai ?? ''),
    'kiru' => (string)($honmei_kiru_str ?? ($kiru_str ?? '')),
    'rank_boats' => is_array($rank_boats ?? null) ? array_values($rank_boats) : [],
    'prediction_entry_order' => (string)($prediction_entry_order ?? ''),
    'simulation_active' => !empty($simulation_active),
];

$fvReady = false;
$fvRecord = null;
$fvStats = ['total' => 0, 'completed' => 0, 'improved' => 0, 'same' => 0, 'worse' => 0, 'unclear' => 0];
$fvMessage = '';
$fvError = '';

try {
    $fvLogic = new ForwardValidationLogic();
    $fvReady = $fvLogic->isReady();

    if (
        $fvReady
        && isset($_POST['save_forward_validation'])
        && hash_equals($fvRaceCode, (string)($_POST['fv_race_code'] ?? ''))
    ) {
        $fvLogic->save([
            'race_code' => $fvRaceCode,
            'race_date' => $fvRaceDate,
            'place_code' => $fvPlaceCode,
            'race_no' => $fvRaceNo,
            'web_snapshot' => $fvCurrentSnapshot,
            'decision_action' => (string)($_POST['fv_action'] ?? 'as_is'),
            'factors' => is_array($_POST['fv_factors'] ?? null) ? $_POST['fv_factors'] : [],
            'final_head' => (string)($_POST['fv_final_head'] ?? ''),
            'final_bet' => (string)($_POST['fv_final_bet'] ?? ''),
            'decision_note' => (string)($_POST['fv_decision_note'] ?? ''),
            'actual_result' => (string)($_POST['fv_actual_result'] ?? ''),
            'effect' => (string)($_POST['fv_effect'] ?? 'pending'),
            'result_note' => (string)($_POST['fv_result_note'] ?? ''),
        ]);
        $fvMessage = '前向き検証の記録を保存しました。';
    }

    if ($fvReady) {
        $fvRecord = $fvLogic->load($fvRaceCode);
        $fvStats = $fvLogic->getPlaceStats($fvPlaceCode);
    }
} catch (Throwable $e) {
    $fvError = $e->getMessage();
}

$fvSnapshot = is_array($fvRecord['web_snapshot'] ?? null)
    ? $fvRecord['web_snapshot']
    : $fvCurrentSnapshot;
$fvSnapshotStored = is_array($fvRecord);

$fvAction = (string)($fvRecord['decision_action'] ?? 'as_is');
$fvFactors = is_array($fvRecord['factors'] ?? null) ? $fvRecord['factors'] : [];
$fvFinalHead = $fvRecord['final_head'] ?? ($fvCurrentSnapshot['honmei_head'] ?: '');
$fvFinalBet = (string)($fvRecord['final_bet'] ?? $fvCurrentSnapshot['honmei_kai']);
$fvDecisionNote = (string)($fvRecord['decision_note'] ?? '');
$fvActualResult = (string)($fvRecord['actual_result'] ?? '');
$fvEffect = (string)($fvRecord['effect'] ?? 'pending');
$fvResultNote = (string)($fvRecord['result_note'] ?? '');

$fvSnapshotHead = (int)($fvSnapshot['honmei_head'] ?? 0);
$fvSnapshotBet = (string)($fvSnapshot['honmei_kai'] ?? '');
$fvSnapshotTaikouHead = (int)($fvSnapshot['taikou_head'] ?? 0);
$fvSnapshotTaikouBet = (string)($fvSnapshot['taikou_kai'] ?? '');
$fvSnapshotKiru = (string)($fvSnapshot['kiru'] ?? '');

$fvCardStyle = $fvMode === 'app'
    ? 'margin:0 0 10px;padding:10px 11px;border:1px solid #d8cdbc;border-radius:10px;background:#fffaf2;color:#334155;'
    : 'margin:14px 0;padding:12px 14px;background:var(--surface-soft);border:1px solid var(--border);border-radius:8px;color:var(--text);';
$fvBorder = $fvMode === 'app' ? '#ded6c9' : 'var(--border)';
$fvSurface = $fvMode === 'app' ? '#faf6ef' : 'var(--surface)';
$fvMuted = $fvMode === 'app' ? '#6b7785' : 'var(--text-muted)';
$fvStrong = $fvMode === 'app' ? '#334155' : 'var(--text-strong)';
?>
<div style="<?= $fvCardStyle ?>">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;flex-wrap:wrap;">
        <div>
            <div style="font-size:<?= $fvMode === 'app' ? '13px' : '16px' ?>;font-weight:800;color:<?= $fvMode === 'app' ? '#334155' : 'var(--accent)' ?>;">📝 前向き実戦検証</div>
            <div style="margin-top:2px;font-size:<?= $fvMode === 'app' ? '10px' : '11px' ?>;color:<?= $fvMuted ?>;">
                <?= $fvEsc($fvVenueName) ?> <?= $fvRaceNo ?>R / <?= $fvEsc($fvRaceCode) ?>
            </div>
        </div>
        <?php if ($fvReady): ?>
            <div style="font-size:10px;color:<?= $fvMuted ?>;text-align:right;">
                この場: 記録 <?= number_format((int)$fvStats['total']) ?> / 結果済 <?= number_format((int)$fvStats['completed']) ?><br>
                改善 <?= number_format((int)$fvStats['improved']) ?> / 同等 <?= number_format((int)$fvStats['same']) ?> / 悪化 <?= number_format((int)$fvStats['worse']) ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$fvReady): ?>
        <div style="margin-top:8px;padding:8px 10px;border:1px solid #f59e0b;border-radius:6px;background:#fffbeb;font-size:11px;line-height:1.6;color:#92400e;">
            前向き検証テーブルが未作成です。<code>php analysis/setup_stadium_forward_validation.php</code> を一度実行すると記録できるようになります。
        </div>
    <?php else: ?>
        <?php if ($fvMessage !== ''): ?>
            <div style="margin-top:8px;padding:7px 9px;border:1px solid #86efac;border-radius:6px;background:#f0fdf4;color:#166534;font-size:11px;"><?= $fvEsc($fvMessage) ?></div>
        <?php endif; ?>
        <?php if ($fvError !== ''): ?>
            <div style="margin-top:8px;padding:7px 9px;border:1px solid #fca5a5;border-radius:6px;background:#fef2f2;color:#b91c1c;font-size:11px;"><?= $fvEsc($fvError) ?></div>
        <?php endif; ?>

        <div style="margin-top:9px;padding:8px 10px;border:1px solid <?= $fvBorder ?>;border-radius:6px;background:<?= $fvSurface ?>;font-size:<?= $fvMode === 'app' ? '10px' : '11px' ?>;line-height:1.65;">
            <strong><?= $fvSnapshotStored ? '保存時Web予想' : '現在のWeb予想' ?>:</strong>
            本命 <?= $fvSnapshotHead > 0 ? $fvSnapshotHead . '号艇' : '-' ?> / <?= $fvEsc($fvSnapshotBet !== '' ? $fvSnapshotBet : '-') ?>　
            対抗 <?= $fvSnapshotTaikouHead > 0 ? $fvSnapshotTaikouHead . '号艇' : '-' ?> / <?= $fvEsc($fvSnapshotTaikouBet !== '' ? $fvSnapshotTaikouBet : '-') ?>
            <?php if ($fvSnapshotKiru !== ''): ?>　切り <?= $fvEsc($fvSnapshotKiru) ?><?php endif; ?>
            <?php if ($fvSnapshotStored): ?>
                <div style="margin-top:2px;color:<?= $fvMuted ?>;">※Web予想は最初に保存した時点で固定。結果入力時には上書きしません。</div>
            <?php endif; ?>
        </div>

        <form method="POST" action="" style="margin-top:9px;">
            <input type="hidden" name="fv_race_code" value="<?= $fvEsc($fvRaceCode) ?>">

            <div style="display:grid;grid-template-columns:<?= $fvMode === 'app' ? '1fr' : 'minmax(0,1fr) minmax(0,1fr)' ?>;gap:9px;">
                <div style="padding:9px;border:1px solid <?= $fvBorder ?>;border-radius:6px;background:<?= $fvSurface ?>;">
                    <div style="font-size:11px;font-weight:800;color:<?= $fvStrong ?>;margin-bottom:7px;">レース前の判断</div>

                    <label style="display:block;font-size:10px;color:<?= $fvMuted ?>;margin-bottom:3px;">場特性を見てどうする？</label>
                    <select name="fv_action" style="width:100%;box-sizing:border-box;padding:6px;border:1px solid <?= $fvBorder ?>;border-radius:5px;background:white;font-size:11px;">
                        <?php foreach (ForwardValidationLogic::ACTIONS as $key => $label): ?>
                            <option value="<?= $fvEsc($key) ?>" <?= $fvAction === $key ? 'selected' : '' ?>><?= $fvEsc($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div style="margin-top:7px;font-size:10px;color:<?= $fvMuted ?>;">判断に使った場特性</div>
                    <div style="display:flex;gap:6px 10px;flex-wrap:wrap;margin-top:4px;font-size:10px;">
                        <?php foreach (ForwardValidationLogic::FACTORS as $key => $label): ?>
                            <label style="display:flex;align-items:center;gap:3px;white-space:nowrap;">
                                <input type="checkbox" name="fv_factors[]" value="<?= $fvEsc($key) ?>" <?= in_array($key, $fvFactors, true) ? 'checked' : '' ?>>
                                <?= $fvEsc($label) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:grid;grid-template-columns:90px minmax(0,1fr);gap:6px;margin-top:8px;">
                        <div>
                            <label style="display:block;font-size:10px;color:<?= $fvMuted ?>;margin-bottom:3px;">最終頭</label>
                            <select name="fv_final_head" style="width:100%;padding:6px;border:1px solid <?= $fvBorder ?>;border-radius:5px;background:white;font-size:11px;">
                                <option value="">-</option>
                                <?php for ($boat = 1; $boat <= 6; $boat++): ?>
                                    <option value="<?= $boat ?>" <?= (string)$fvFinalHead === (string)$boat ? 'selected' : '' ?>><?= $boat ?>号艇</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:10px;color:<?= $fvMuted ?>;margin-bottom:3px;">最終買い目・方針</label>
                            <input type="text" name="fv_final_bet" value="<?= $fvEsc($fvFinalBet) ?>" placeholder="例: 1-234-234 / 見送り" style="width:100%;box-sizing:border-box;padding:6px;border:1px solid <?= $fvBorder ?>;border-radius:5px;font-size:11px;">
                        </div>
                    </div>

                    <label style="display:block;font-size:10px;color:<?= $fvMuted ?>;margin:7px 0 3px;">判断メモ</label>
                    <textarea name="fv_decision_note" rows="2" placeholder="例: 下関は周り足重視。⑤を3着に残す。" style="width:100%;box-sizing:border-box;padding:6px;border:1px solid <?= $fvBorder ?>;border-radius:5px;font-size:11px;resize:vertical;"><?= $fvEsc($fvDecisionNote) ?></textarea>
                </div>

                <div style="padding:9px;border:1px solid <?= $fvBorder ?>;border-radius:6px;background:<?= $fvSurface ?>;">
                    <div style="font-size:11px;font-weight:800;color:<?= $fvStrong ?>;margin-bottom:7px;">結果後の確認</div>

                    <div style="display:grid;grid-template-columns:130px minmax(0,1fr);gap:6px;">
                        <div>
                            <label style="display:block;font-size:10px;color:<?= $fvMuted ?>;margin-bottom:3px;">実結果</label>
                            <input type="text" name="fv_actual_result" value="<?= $fvEsc($fvActualResult) ?>" placeholder="1-2-3" maxlength="5" style="width:100%;box-sizing:border-box;padding:6px;border:1px solid <?= $fvBorder ?>;border-radius:5px;font-size:11px;">
                        </div>
                        <div>
                            <label style="display:block;font-size:10px;color:<?= $fvMuted ?>;margin-bottom:3px;">場特性を見た効果</label>
                            <select name="fv_effect" style="width:100%;box-sizing:border-box;padding:6px;border:1px solid <?= $fvBorder ?>;border-radius:5px;background:white;font-size:11px;">
                                <?php foreach (ForwardValidationLogic::EFFECTS as $key => $label): ?>
                                    <option value="<?= $fvEsc($key) ?>" <?= $fvEffect === $key ? 'selected' : '' ?>><?= $fvEsc($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <label style="display:block;font-size:10px;color:<?= $fvMuted ?>;margin:7px 0 3px;">結果メモ</label>
                    <textarea name="fv_result_note" rows="4" placeholder="例: Webのままだと⑤を拾えなかったが、外枠到達率を見て残せた。" style="width:100%;box-sizing:border-box;padding:6px;border:1px solid <?= $fvBorder ?>;border-radius:5px;font-size:11px;resize:vertical;"><?= $fvEsc($fvResultNote) ?></textarea>
                </div>
            </div>

            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-top:8px;">
                <div style="font-size:9px;color:<?= $fvMuted ?>;line-height:1.5;">
                    レース前に一度保存 → 結果後に同じ欄を更新。改善/悪化は「場特性を見なかったWeb予想」と比べて判定する。
                </div>
                <button type="submit" name="save_forward_validation" value="1" style="border:0;border-radius:6px;padding:7px 14px;background:#1683bd;color:white;font-weight:800;font-size:11px;cursor:pointer;">記録を保存 / 更新</button>
            </div>
        </form>
    <?php endif; ?>
</div>
