# TabDPT-Turbo / TabICLv2 実行可能性監査

## 判定

- **TabDPT-Turbo v1.2.0: 正式36ケース比較へ採用。** CPU-onlyで全ケースを完走し、正式実行の最大RSSは1.72GB、swapなし。
- **TabICLv2 v2: 今回は正式比較対象外。** ASY 3C top3のfull splitで、CPU・`n_estimators=1`・`batch_size=1`という最小設定でも最大RSSが5.55GBだった。disk offloadを明示しても5.31GBであり、8GBのWeb/DB同居サーバで36ケースを継続実行する安全余裕がない。

## Phase A: 公式実装と利用条件

|項目|TabDPT-Turbo|TabICLv2|
|---|---|---|
|公式実装|`layer6ai-labs/TabDPT-inference`|`soda-inria/tabicl`|
|今回のpackage|`tabdpt==1.2.0`|`tabicl==2.2.0`|
|Python|公式要件 >=3.10。Python 3.12.3でimport確認|Python 3.12.3でimport確認|
|主依存|PyTorch, FAISS CPU, Hugging Face Hub|PyTorch, einops, Hugging Face Hub|
|CPU/GPU|CPU対応。GPUは必須でない|CPU対応。大規模データでは公式もGPU推奨|
|確率出力|`TabDPTClassifier.predict_proba`|`TabICLClassifier.predict_proba`|
|分類|binaryを含むclassification対応|binaryを含むclassification対応|
|欠損|公式標準前処理|数値NaN平均補完、カテゴリ欠損専用カテゴリ|
|カテゴリ|数値入力前提の今回データでは未使用|自動検出・ordinal encoding対応|
|方式|pretrained ICL。今回v1.2がTurbo|pretrained ICL。fit/predictのforward pass|
|ライセンス|Apache-2.0（package/weight）|BSD-3-Clause（package/weight）|
|本番利用制約|確認できた範囲でnon-commercial/research-only制約なし|確認できた範囲でnon-commercial/research-only制約なし|

公式上、TabICLv2は2〜100列で事前学習されており、170列入力では性能低下の可能性がある。今回は170特徴をそのまま渡し、独自の特徴量削減はしていない。TabDPT-Turboは170列入力後に公式標準のPCA feature reductionを内部使用する。

## 環境とweight

- Ubuntu: 4 CPU core / RAM 7.7GiB / swap 4GiB / GPUなし。
- CPU PyTorch 2.7.1+cpuを隔離環境へ導入。隔離環境は約1.4GBで、既存正式比較環境は変更していない。
- TabDPT v1.2 checkpoint `tabdpt1_2.safetensors`: 約254MB。
- TabICLv2 classifier checkpoint `tabicl-classifier-v2-20260212.ckpt`: 約110MB。
- Hugging Face checkpoint cache合計: 約348MB。いずれもGit管理外。

## Phase B: smoke test

結果は `course_signal_tabdpt_tabicl_smoke.csv` に保存。代表ケースはASY 3C top3、170特徴量、seed 20260927。

- 500 / 1000行では両モデルともfit・`predict_proba`・Brier・Log Loss・AUCを正常算出し、NaN/Infなし、OOMなし。
- full split（TRAIN 3,500 / TEST 1,268）でも両者は正常終了。TabDPT-Turboのpredictは18.65秒、TabICLv2は50.37秒。
- TabICLv2はdisk offloadでも最大RSSが十分に下がらず、正式実行の安全性要件を満たさないと判断した。

## Phase C

TabDPT-Turboのみ、同一のASY/AMG・36ケース・170特徴・TRAIN/VALID/TESTで5モデル正式比較へ統合した。結果は `course_signal_model_compare_five_models_20260927_*` を参照。

TabICLv2のsmall/full smoke Brierは、sample/subset条件のため既存4モデルの正式順位表へ混ぜていない。

## 公式参照

- TabDPT-Turbo / v1.2: https://github.com/layer6ai-labs/TabDPT-inference
- TabDPT package metadata: https://pypi.org/project/tabdpt/1.2.0/
- TabDPT weights: https://huggingface.co/Layer6/TabDPT
- TabICLv2: https://github.com/soda-inria/tabicl
- TabICLv2 weights: https://huggingface.co/jingang/TabICL
