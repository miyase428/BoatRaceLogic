# AI1着率 v6 model inventory

## 現行フロー

`v2 HGB/XGBoost → race内正規化・50/50 blend` + `v4 LightGBM LambdaRank` + `motor-reset rating LightGBM LambdaRank` → 15% / 21.25% / 63.75% blend → course factor → race内100%正規化。

- v4/rating rankerはVALIDだけでtemperatureを求める。
- v6は該当artifactがなければv5へfallback。v2/v4 artifact不足時はwaiting。
- 本番artifactはいずれも今回TEST期間を含むため、正式比較には使わず分析専用に再学習した。

## 本番artifact

|artifact|trained through exclusive|features|SHA256|
|---|---|---:|---|
|ai_winrate_v2.joblib|2026-09-23|13|`0a16dc3081c2765e5e3bfa6cb9250ff7407f004eda502ebf2c07e14f72265784`|
|ai_winrate_v4.joblib|2026-09-24|31|`164f4aeb877084ee966142c209c653ed53d968e3a486e9120c97305bd34d1f39`|
|ai_winrate_v5.joblib|2026-09-24|35|`1eaef1af079bdc857812df9d44308bc9773726884585a329703c6a1988d5f6b8`|
|ai_winrate_v6.joblib|2026-09-24|35|`3699630f2decbce874cca8e54e1e3fed5ad283ed9b0de8367c25bcad2b435f0e`|

## 比較用runtime

- core: Python 3.12.3 / NumPy 1.26.4 / max RSS 2,015,940KB / 293.12秒
- tabdpt: Python 3.12.3 / NumPy 1.26.4 / max RSS 1,862,604KB / 474.35秒
- agng: Python 3.12.3 / NumPy 1.26.4 / max RSS 1,363,144KB / 576.05秒
- v6: Python 3.12.3 / NumPy 1.26.4 / max RSS 3,557,436KB / 370.77秒
