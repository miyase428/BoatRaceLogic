# 第2世代AI1着率 forward validation

- forward start: **2026-10-01**
- training cutoff: **2026-09-27**
- 対象場: ASY（芦屋）、AMG（尼崎）
- baseline: `v6_final`
- candidate A: `v6_final 75% + AutoGluon course-first 25%`
- candidate B: `v6_final 95% + EBM course-first 5%`
- weightはhistorical VALIDで固定済み。forward結果によるweight・場別・コース別変更は禁止。

## 保存条件

展示6艇分が揃った後、公式締切前に保存する。`prediction.csv` はrace_code単位で初回の6行だけを保存し、後続実行では上書きしない。2026-09-28〜09-30は正式forwardへ保存しない。

予測生成は対象レースの結果・払戻を参照しない。固定170特徴に必要な決まり手履歴は対象日の前日までに限定する。結果参照は採点scriptだけで行う。

## artifact

ホスト上（Git対象外）:

`analysis/artifacts/ai_winrate_forward/20260927/`

- AutoGluon: historical固定TRAIN/VALIDでモデル選択し、固定TEST期間を追加してcutoffまで `refit_full`
- EBM: cutoffまで全件を固定設定でfit
- `manifest.json`: version、SHA256、runtime、行数、特徴量、label、固定weight

バックアップはartifactディレクトリ全体をtar等で別媒体へ複製し、`analysis/ai_winrate_forward_config.json` のmanifest SHA256およびmanifest内artifact SHA256と照合する。

```bash
tar -C /var/www/html/boatrace/analysis/artifacts/ai_winrate_forward -czf /保存先/ai_winrate_forward_20260927.tar.gz 20260927
sha256sum /保存先/ai_winrate_forward_20260927.tar.gz
```

## 実行

```bash
cd /var/www/html/boatrace
/tmp/boatrace-autogluon-ngboost/bin/python analysis/save_ai_winrate_blend_forward.py --date "$(TZ=Asia/Tokyo date +%F)"
.venv-models/bin/python analysis/evaluate_ai_winrate_blend_forward.py
```

推奨cron（既存cronへは未登録）:

```cron
*/5 8-21 * * * flock -n /tmp/boatrace-ai-winrate-forward.lock /var/www/html/boatrace/analysis/run_ai_winrate_blend_forward_cron.sh >> /var/www/html/boatrace/logs/ai_winrate_forward.log 2>&1
```

5分間隔で展示取得後から締切までのどこかで初回保存する。既に保存済みのrace_codeはスキップする。

## 採点

`evaluate_ai_winrate_blend_forward.py` だけが `race_result_detail` を参照する。日別・月別・場別・勝者コース別・累積・paired race bootstrap（5,000回、seed固定）・100/300/500/1000R checkpointを出力する。100/300Rは参考、500R以降で傾向を確認し、1000Rを重要checkpointとする。
