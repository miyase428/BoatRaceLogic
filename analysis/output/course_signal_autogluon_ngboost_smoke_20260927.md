# AutoGluon / NGBoost feasibility smoke

- 対象: ASY 3C top3、固定170特徴・同一split。
- AutoGluon: fit 21.39s / predict 0.33s / disk 69,996,350 bytes / RSS 851988KB / TEST Brier 0.22796230
- NGBoost: fit 15.83s / predict 0.31s / RSS 851988KB / TEST Brier 0.22865129
- AutoGluon final model: `WeightedEnsemble_L2` / composition: `RandomForest+CatBoost`
- AutoGluon artifactは/tmpから削除済み。
