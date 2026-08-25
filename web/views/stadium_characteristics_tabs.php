<?php
$stadiumCharacteristicsMode = (string)($stadiumCharacteristicsMode ?? 'pc');
$stadiumCharacteristicsPlace = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($selected_place ?? 'unknown'));
$stadiumCharacteristicsRootId = 'stadium-characteristics-tabs-' . $stadiumCharacteristicsMode . '-' . $stadiumCharacteristicsPlace;
$isApp = $stadiumCharacteristicsMode === 'app';
?>

<div id="<?= htmlspecialchars($stadiumCharacteristicsRootId, ENT_QUOTES, 'UTF-8') ?>" style="<?= $isApp ? 'margin:0 0 8px;' : 'margin:14px 0 0;' ?>">
    <div style="display:flex; gap:6px; <?= $isApp ? 'overflow-x:auto; -webkit-overflow-scrolling:touch; padding-bottom:2px;' : 'flex-wrap:wrap;' ?>">
        <?php
        $tabs = [
            'basic' => '基本特性',
            'escape' => '逃げ時',
            'outer' => 'イン飛び・外枠',
            'exhibition' => '展示・ST',
            'web' => 'Web相性',
        ];
        foreach ($tabs as $key => $label):
        ?>
            <button
                type="button"
                data-stadium-char-tab="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                aria-selected="false"
                style="<?= $isApp ? 'flex:0 0 auto;' : 'flex:1 1 120px;' ?> min-height:34px; padding:7px 10px; border:1px solid <?= $isApp ? '#d8cdbc' : 'var(--border)' ?>; border-radius:8px; background:<?= $isApp ? '#fffaf2' : 'var(--surface-soft)' ?>; color:<?= $isApp ? '#475569' : 'var(--text-muted)' ?>; font-size:<?= $isApp ? '11px' : '12px' ?>; font-weight:700; cursor:pointer; white-space:nowrap;"
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
    const storageKey = <?= json_encode('br_stadium_characteristics_tab_' . $stadiumCharacteristicsMode, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const normalBg = <?= json_encode($isApp ? '#fffaf2' : 'var(--surface-soft)') ?>;
    const normalColor = <?= json_encode($isApp ? '#475569' : 'var(--text-muted)') ?>;
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
