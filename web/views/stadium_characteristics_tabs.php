<?php
$stadiumCharacteristicsMode = (string)($stadiumCharacteristicsMode ?? 'pc');
$stadiumCharacteristicsPlace = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($selected_place ?? 'unknown'));
$stadiumCharacteristicsRootId = 'stadium-characteristics-tabs-' . $stadiumCharacteristicsMode . '-' . $stadiumCharacteristicsPlace;
$isApp = $stadiumCharacteristicsMode === 'app';

// Web / APP とも棚卸し後の表示方針に合わせ、場特性の内側タブは使わない。
// 「Web相性 → 基本特性」の順で常時表示し、逃げ時・外枠・展示STは画面上から外す。
?>
<div id="<?= htmlspecialchars($stadiumCharacteristicsRootId, ENT_QUOTES, 'UTF-8') ?>" style="margin:<?= $isApp ? '0 0 8px' : '14px 0 0' ?>;">
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
