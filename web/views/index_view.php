<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>BoatRace Analytics</title>
    <link rel="stylesheet" href="./assets/css/style.css">
</head>
<body>
<div class="container">
    <h1>BoatRace Analytics</h1>

    <div class="code-box">
        <div class="code-label">RACE CODE</div>
        <div class="code-value"><?= htmlspecialchars($race_code) ?></div>
    </div>

    <!-- ここに出走表・展示・最終予想・サム理論・スリット表示を
         既存 index.php の HTML から順次移植していく -->
</div>
<script src="./assets/js/main.js"></script>
</body>
</html>
