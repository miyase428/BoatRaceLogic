<?php
$stadiumCharacteristicsMode = (string)($stadiumCharacteristicsMode ?? 'pc');
$stadiumCharacteristicsPlace = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($selected_place ?? 'unknown'));
$stadiumCharacteristicsRootId = 'stadium-characteristics-tabs-' . $stadiumCharacteristicsMode . '-' . $stadiumCharacteristicsPlace;
$isApp = $stadiumCharacteristicsMode === 'app';
$stadiumCharacteristicsStorageKey = 'br_stadium_characteristics_tab_' . $stadiumCharacteristicsMode;

// PC Webは棚卸し後の表示方針に合わせ、場特性の内側タブを使わず
// 「Web相性 → 基本特性」の順で常時表示する。
if (!$isApp):
?>
<div id="<?= htmlspecialchars($stadiumCharacteristicsRootId, ENT_QUOTES, 'UTF-8') ?>" style="margin:14px 0 0;">
    <div>
        <?php
        $stadiumAffinityMode = $stadiumCharacteristicsMode;
        include __DIR__ . '/stadium_affinity_panel.php';
        $raceNumberCompatibilityMode = $stadiumCharacteristicsMode;
        include __DIR__ . '/race_number_compatibility_panel.php';
        ?>
    </div>

    <div style="margin-top:12px;">
        <?php include __DIR__ . '/stadium_characteristics_basic_panel.php'; ?>
    </div>
</div>
<?php
return;
endif;

// APP版ではこのHTMLをinnerHTMLで後から差し込むため、内部の<script>は実行されない。
// ボタン自身にも切替処理を持たせ、iPhone/PWAでもタップだけで確実に切り替えられるようにする。
$stadiumCharacteristicsInlineHandler = <<<'JS'
(function(button){
    var root = button.parentElement ? button.parentElement.parentElement : null;
    if (!root) return;

    var target = button.getAttribute('data-stadium-char-tab') || 'basic';
    var buttons = root.querySelectorAll('[data-stadium-char-tab]');
    var panels = root.querySelectorAll('[data-stadium-char-panel]');
    var normalBg = button.getAttribute('data-normal-bg') || '#fffaf2';
    var normalColor = button.getAttribute('data-normal-color') || '#475569';
    var storageKey = button.getAttribute('data-storage-key') || '';

    Array.prototype.forEach.call(buttons, function(item) {
        var active = item.getAttribute('data-stadium-char-tab') === target;
        item.setAttribute('aria-selected', active ? 'true' : 'false');
        item.style.background = active ? '#334155' : normalBg;
        item.style.color = active ? '#ffffff' : normalColor;
        item.style.borderColor = active ? '#334155' : '';
    });

    Array.prototype.forEach.call(panels, function(panel) {
        panel.style.display = panel.getAttribute('data-stadium-char-panel') === target ? 'block' : 'none';
    });

    if (storageKey) {
        try {
            localStorage.setItem(storageKey, target);
        } catch (e) {}
    }
})(this);
JS;
?>

<div id="<?= htmlspecialchars($stadiumCharacteristicsRootId, ENT_QUOTES, 'UTF-8') ?>" style="margin:0 0 8px;">
    <div style="display:flex; gap:6px; overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:2px;">
        <?php
        $tabs = [
            'basic' => '基本特性',
            'escape' => '逃げ時',
            'outer' => 'イン飛び・外枠',
            'exhibition' => '展示・ST',
            'web' => 'Web相性',
        ];
        foreach ($tabs as $key => $label):
            $isDefaultActive = $key === 'basic';
            $normalBg = '#fffaf2';
            $normalColor = '#475569';
            $buttonBg = $isDefaultActive ? '#334155' : $normalBg;
            $buttonColor = $isDefaultActive ? '#ffffff' : $normalColor;
            $buttonBorder = $isDefaultActive ? '#334155' : '#d8cdbc';
        ?>
            <button
                type="button"
                data-stadium-char-tab="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                data-normal-bg="<?= htmlspecialchars($normalBg, ENT_QUOTES, 'UTF-8') ?>"
                data-normal-color="<?= htmlspecialchars($normalColor, ENT_QUOTES, 'UTF-8') ?>"
                data-storage-key="<?= htmlspecialchars($stadiumCharacteristicsStorageKey, ENT_QUOTES, 'UTF-8') ?>"
                aria-selected="<?= $isDefaultActive ? 'true' : 'false' ?>"
                onclick="<?= htmlspecialchars($stadiumCharacteristicsInlineHandler, ENT_QUOTES, 'UTF-8') ?>"
                style="flex:0 0 auto; min-height:34px; padding:7px 10px; border:1px solid <?= htmlspecialchars($buttonBorder, ENT_QUOTES, 'UTF-8') ?>; border-radius:8px; background:<?= htmlspecialchars($buttonBg, ENT_QUOTES, 'UTF-8') ?>; color:<?= htmlspecialchars($buttonColor, ENT_QUOTES, 'UTF-8') ?>; font-size:11px; font-weight:700; cursor:pointer; white-space:nowrap;"
            ><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></button>
        <?php endforeach; ?>
    </div>

    <div data-stadium-char-panel="basic">
        <?php include __DIR__ . '/stadium_characteristics_basic_panel.php'; ?>
    </div>

    <div data-stadium-char-panel="escape" style="display:none;">
        <?php include __DIR__ . '/stadium_characteristics_escape_panel.php'; ?>
    </div>

    <div data-stadium-char-panel="outer" style="display:none;">
        <?php
        $stadiumNonLane1Mode = $stadiumCharacteristicsMode;
        include __DIR__ . '/stadium_non_lane1_practical_panel.php';
        $stadiumOuterMode = $stadiumCharacteristicsMode;
        include __DIR__ . '/stadium_outer_reach_panel.php';
        ?>
    </div>

    <div data-stadium-char-panel="exhibition" style="display:none;">
        <?php
        $stadiumExEffectMode = $stadiumCharacteristicsMode;
        include __DIR__ . '/stadium_exhibition_effectiveness_panel.php';
        ?>
    </div>

    <div data-stadium-char-panel="web" style="display:none;">
        <?php
        $stadiumAffinityMode = $stadiumCharacteristicsMode;
        include __DIR__ . '/stadium_affinity_panel.php';
        $raceNumberCompatibilityMode = $stadiumCharacteristicsMode;
        include __DIR__ . '/race_number_compatibility_panel.php';
        ?>
    </div>
</div>

<script>
(function () {
    const root = document.getElementById(<?= json_encode($stadiumCharacteristicsRootId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>);
    if (!root) return;

    const buttons = Array.from(root.querySelectorAll('[data-stadium-char-tab]'));
    const panels = Array.from(root.querySelectorAll('[data-stadium-char-panel]'));
    const storageKey = <?= json_encode($stadiumCharacteristicsStorageKey, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const normalBg = '#fffaf2';
    const normalColor = '#475569';
    const activeBg = '#334155';
    const activeColor = '#ffffff';

    function hasTab(name) {
        return buttons.some((button) => button.dataset.stadiumCharTab === name);
    }

    function showTab(name, save) {
        const target = hasTab(name) ? name : 'basic';

        buttons.forEach((button) => {
            const active = button.dataset.stadiumCharTab === target;
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.style.background = active ? activeBg : normalBg;
            button.style.color = active ? activeColor : normalColor;
            button.style.borderColor = active ? activeBg : '';
        });

        panels.forEach((panel) => {
            panel.style.display = panel.dataset.stadiumCharPanel === target ? 'block' : 'none';
        });

        if (save) {
            try {
                localStorage.setItem(storageKey, target);
            } catch (_) {}
        }
    }

    buttons.forEach((button) => {
        button.addEventListener('click', () => showTab(button.dataset.stadiumCharTab || 'basic', true));
    });

    let initial = 'basic';
    try {
        const saved = localStorage.getItem(storageKey);
        if (saved && hasTab(saved)) initial = saved;
    } catch (_) {}

    showTab(initial, false);
})();
</script>
