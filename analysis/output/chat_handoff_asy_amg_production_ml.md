# 芦屋・尼崎 コースサインML 本番実装引き継ぎ

対象: `main` / 実装日: 2026-09-28

## 採用範囲

用途単位でのみMLへ置換し、それ以外は従来サインを維持する。

| 場 | コース | ML採用用途 | 従来維持 |
| --- | --- | --- | --- |
| 芦屋 ASY | 3C | top3（★） | first（★★★）、top2（★★） |
| 芦屋 ASY | 4C | top3（★、展示反映後のみ） | first、top2 |
| 尼崎 AMG | 2C | first（★★★）、top3（★、展示反映後のみ） | top2（★★） |
| 尼崎 AMG | 3C | first、top2、top3 | なし |
| 尼崎 AMG | 4C | top2（★★）、top3（★、展示反映後のみ） | first（★★★） |
| 尼崎 AMG | 5C | top2（★★）、top3（★、展示反映後のみ） | first（★★★） |

展示を必要とする用途は、展示前または展示欠損レースでは従来サインを返す。ML非採用用途も常に従来サインのままとする。買い目・本命・最終予想は変更していない。

## 再現性と固定条件

候補検証と同じ Python 3.12.3 / scikit-learn 1.4.1.post1 で ASY・AMG のみを学習・ライブ推論する。既存6場は従来の `.venv-models`（scikit-learn 1.9.1）を維持する。

期間は ASY・AMG とも、TRAIN: 2023-09-27〜2025-08-31、VALID: 2025-09-01〜2026-02-28、TEST: 2026-03-01〜2026-09-27。閾値はVALIDで固定し、TESTを見た再調整は行っていない。

## 保存モデル

| 場 | version | SHA256 | scikit-learn |
| --- | --- | --- | --- |
| ASY | `ashiya_course_signal_v1` | `40a12aac80b2016d33869f5ce81cfff5d2821d4fa2d1e079bdb87bf200d18c16` | 1.4.1.post1 |
| AMG | `amagasaki_course_signal_v1` | `b9bbec1c1627565464a65ff04843ed8c81706e5cb2410add9ee64553d365cc06` | 1.4.1.post1 |

各manifestには version、SHA256、period、runtime、`scikit_learn_version`、`production_enabled_from: 2026-09-28` を記録している。

## 候補検証との一致

joblibの各採用用途は候補検証JSONの model / 特徴量群 / VALID固定閾値 / TEST件数 / TEST率と一致した。

| 場 | 用途 | threshold | TEST N | TEST率 |
| --- | --- | ---: | ---: | ---: |
| ASY | 3C top3 | 0.591638550279 | 445 | 66.74% |
| ASY | 4C top3 | 0.805775598557 | 66 | 81.82% |
| AMG | 2C first | 0.143361985613 | 335 | 26.87% |
| AMG | 2C top3 | 0.677746480377 | 373 | 77.21% |
| AMG | 3C first/top2/top3 | 0.193602512013 / 0.490182495938 / 0.751363892433 | 213 / 242 / 237 | 28.64% / 57.44% / 76.79% |
| AMG | 4C top2/top3 | 0.526674944789 / 0.767449425753 | 104 / 141 | 50.96% / 85.11% |
| AMG | 5C top2/top3 | 0.255665383651 / 0.466569603743 | 343 / 364 | 32.36% / 54.40% |

## 本番経路確認

- ライブ推論: ASY 2026-09-27 は3C 4件・4C 1件、AMG 2026-09-26 は2C 8件・3C 2件・4C 1件・5C 2件を返した。
- PHP/API: 両場で `center_ml.applied=true`、versionはそれぞれ `ashiya_course_signal_v1` / `amagasaki_course_signal_v1`。フロント側も既存MLと同じv1表示経路に追加済み。
- 展示前API: ASY 4C、AMG 2C★・4C★・5C★/★★は従来詳細を返すことを確認。AMG 2C★★、4C★★、全てのML非採用用途も従来側を維持する。
- キャッシュ: コースサインキャッシュを v11 へ更新し、旧レスポンスを使用しない。

## 回帰・前方追跡

- 既存6場（TMG/TDA/OMR/SMS/SME/KRY）のモデルは再生成・変更していない。従来のscikit-learn 1.9.1ランタイムで全6joblibがロードできることを確認した。
- 既存の `PredictionForwardSnapshotStore::captureDisplayedCourseSignals()` の対象であり、ASY/AMGモデルもロジックversion算出に含めた。2026-09-28以降、表示されたサインが既存方式で前方保存される。
- 学習データは期間境界を固定したpoint-in-time検証のまま。本番ライブ推論は、その時点で取得済みの出走・展示情報だけを使用する。

## 運用上の確認事項

次回の芦屋・尼崎開催では、展示前→展示反映後に画面を確認し、展示依存用途だけがMLへ切り替わることを実画面でも確認する。前方追跡結果は蓄積後に再評価し、TESTを見た閾値再調整は行わない。
