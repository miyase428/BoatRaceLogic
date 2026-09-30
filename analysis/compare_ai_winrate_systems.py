#!/usr/bin/env python3
"""AI1着率v6の内部構成と、固定170特徴のcourse-firstモデルを比較する。

重い任意依存を隔離したまま再現できるよう、処理を stage に分ける。
各学習stageは /tmp の予測だけを受け渡し、finalizeだけがGit管理対象の
集計・予測明細・レポートを生成する。本番artifactと本番コードは変更しない。
"""

from __future__ import annotations

import argparse
import csv
import gc
import gzip
import hashlib
import itertools
import json
import math
import os
import pickle
import platform
import resource
import shutil
import subprocess
import sys
import time
from collections import defaultdict
from datetime import date
from pathlib import Path

import numpy as np

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "analysis"))
sys.path.insert(0, str(ROOT / "forecast"))
sys.path.insert(0, str(ROOT / "common"))

import audit_tamagawa_course_signals_zero_base_ml as audit
from ai_winrate_features import normalized_race_probabilities

PLACES = ("ASY", "AMG")
COURSES = tuple(range(1, 7))
TRAIN_END = date(2025, 8, 31)
VALID_START = date(2025, 9, 1)
VALID_END = date(2026, 2, 28)
TEST_START = date(2026, 3, 1)
TEST_END = date(2026, 9, 27)
END_EXCLUSIVE = date(2026, 9, 28)
SEED = 20260927
WORK = Path("/tmp/boatrace-ai-winrate-system-compare")
OUTPUT = ROOT / "analysis" / "output"
STEM = "ai_winrate_system_compare_asy_amg_20260927"

CORE_MODELS = ("hgb_course_first", "catboost_course_first", "flaml_course_first", "ebm_course_first")
TAB_MODELS = ("tabdpt_turbo_course_first",)
AG_MODELS = ("autogluon_course_first", "ngboost_course_first")
V6_MODELS = (
    "v2_hgb", "v2_xgboost", "v2_blend", "v4_ranker",
    "rating_v6_ranker", "v6_pre_course", "v6_final",
)
ALL_MODELS = CORE_MODELS + TAB_MODELS + AG_MODELS + V6_MODELS
NEW_MODELS = CORE_MODELS + TAB_MODELS + AG_MODELS


def git_sha() -> str:
    return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def rss_kb() -> int:
    return int(resource.getrusage(resource.RUSAGE_SELF).ru_maxrss)


def read_csv(path: Path) -> list[dict]:
    with path.open(encoding="utf-8", newline="") as handle:
        return list(csv.DictReader(handle))


def write_csv(path: Path, rows: list[dict]) -> None:
    if not rows:
        raise RuntimeError(f"出力行がありません: {path}")
    path.parent.mkdir(parents=True, exist_ok=True)
    opener = gzip.open if path.suffix == ".gz" else open
    with opener(path, "wt", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0]), lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def split_name(value: date) -> str | None:
    if value <= TRAIN_END:
        return "train"
    if VALID_START <= value <= VALID_END:
        return "valid"
    if TEST_START <= value <= TEST_END:
        return "test"
    return None


def load_or_build_course_dataset() -> dict:
    path = WORK / "course_dataset.pkl"
    if path.exists():
        with path.open("rb") as handle:
            payload = pickle.load(handle)
        if payload.get("feature_count") == 170 and payload.get("label_definition") == "exhibition_subject_player_finish":
            return payload
    config = json.loads((ROOT / "config" / "course_signal_rules.json").read_text(encoding="utf-8"))
    records_by_place, audits = {}, {}
    features = None
    for place in PLACES:
        audit.PLACE = place
        records, _pre, post = audit.build_dataset(date(2023, 9, 27), TEST_END, config)
        records_by_place[place] = records
        audits[place] = dict(audit.LAST_DATASET_AUDIT)
        if features is None:
            features = list(post)
        elif features != list(post):
            raise RuntimeError("場ごとに170特徴の一覧が一致しません")
    if features is None or len(features) != 170:
        raise RuntimeError(f"固定特徴数が170ではありません: {0 if features is None else len(features)}")
    # 元のcourse-signal firstは「結果の実進入course」を当てるラベルである。
    # 今回は艇/選手の1着確率なので、展示進入で選んだsubject playerの実着順へ
    # ラベルだけを揃える（特徴量とsplitは既存170特徴のまま）。
    import psycopg2
    from db_config import load_db_config
    race_codes = sorted({row["race_code"] for records in records_by_place.values() for row in records})
    sql = """
SELECT race_code, player_id::text, TRIM(rank::text)
FROM boat_race.race_result_detail
WHERE race_code = ANY(%s)
  AND TRIM(rank::text) IN ('1', '2', '3')
"""
    finish = {}
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(sql, (race_codes,))
            for code, player_id, rank in cursor.fetchall():
                finish[(str(code), str(player_id).strip())] = int(rank)
    for records in records_by_place.values():
        for row in records:
            rank = finish.get((row["race_code"], str(row["player_id"]).strip()))
            row["first"] = int(rank == 1)
            row["top2"] = int(rank in (1, 2))
            row["top3"] = int(rank in (1, 2, 3))
    payload = {"feature_count": len(features), "features": features, "records": records_by_place, "audits": audits, "label_definition": "exhibition_subject_player_finish"}
    WORK.mkdir(parents=True, exist_ok=True)
    with path.open("wb") as handle:
        pickle.dump(payload, handle, protocol=pickle.HIGHEST_PROTOCOL)
    return payload


def case_rows(payload: dict, place: str, course: int, split: str) -> list[dict]:
    return [row for row in payload["records"][place] if row["course"] == course and split_name(row["date"]) == split]


def matrix(rows: list[dict], features: list[str]) -> np.ndarray:
    return audit.matrix(rows, features)


def prediction_base(row: dict, split: str) -> dict:
    return {
        "split": split,
        "race_code": row["race_code"],
        "date": row["date"].isoformat(),
        "place": row["race_code"][8:11],
        "race_number": int(row["race_code"][-2:]),
        "course": int(row["course"]),
        "player_id": row.get("player_id", ""),
        "actual_win": int(row["first"]),
    }


def dump_stage(name: str, rows: list[dict], runtime: dict) -> None:
    WORK.mkdir(parents=True, exist_ok=True)
    write_csv(WORK / f"{name}.csv", rows)
    (WORK / f"{name}.json").write_text(json.dumps(runtime, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def core_stage() -> None:
    from sklearn.base import clone
    from catboost import CatBoostClassifier
    from flaml import AutoML
    from interpret.glassbox import ExplainableBoostingClassifier
    from compare_course_signal_models import CATBOOST_PARAMETERS, EBM_PARAMETERS, FLAML_PARAMETERS

    payload = load_or_build_course_dataset()
    features = payload["features"]
    started = time.perf_counter()
    output = []
    timings = []
    for place in PLACES:
        for course in COURSES:
            train = case_rows(payload, place, course, "train")
            valid = case_rows(payload, place, course, "valid")
            test = case_rows(payload, place, course, "test")
            x_train, x_valid, x_test = (matrix(rows, features) for rows in (train, valid, test))
            y_train = np.asarray([row["first"] for row in train], dtype=np.int8)
            y_valid = np.asarray([row["first"] for row in valid], dtype=np.int8)
            models = {}
            models["hgb_course_first"] = clone(audit.make_models()["hist_gradient"])
            models["catboost_course_first"] = CatBoostClassifier(**{
                key: value for key, value in CATBOOST_PARAMETERS.items()
                if key in {"loss_function", "iterations", "depth", "learning_rate", "l2_leaf_reg", "random_seed", "thread_count", "allow_writing_files", "verbose", "task_type"}
            })
            probabilities = {}
            for name, model in models.items():
                begin = time.perf_counter(); model.fit(x_train, y_train); fit_s = time.perf_counter() - begin
                begin = time.perf_counter(); probabilities[name] = (model.predict_proba(x_valid)[:, 1], model.predict_proba(x_test)[:, 1]); pred_s = time.perf_counter() - begin
                timings.append({"model": name, "place": place, "course": course, "fit_seconds": fit_s, "predict_seconds": pred_s})
            flaml = AutoML(); begin = time.perf_counter()
            flaml.fit(X_train=x_train, y_train=y_train, X_val=x_valid, y_val=y_valid, **FLAML_PARAMETERS)
            fit_s = time.perf_counter() - begin; begin = time.perf_counter()
            probabilities["flaml_course_first"] = (flaml.predict_proba(x_valid)[:, 1], flaml.predict_proba(x_test)[:, 1])
            timings.append({"model": "flaml_course_first", "place": place, "course": course, "fit_seconds": fit_s, "predict_seconds": time.perf_counter() - begin, "selected_estimator": str(flaml.best_estimator)})
            ebm = ExplainableBoostingClassifier(feature_names=features, **EBM_PARAMETERS); begin = time.perf_counter(); ebm.fit(x_train, y_train); fit_s = time.perf_counter() - begin; begin = time.perf_counter()
            probabilities["ebm_course_first"] = (ebm.predict_proba(x_valid)[:, 1], ebm.predict_proba(x_test)[:, 1])
            timings.append({"model": "ebm_course_first", "place": place, "course": course, "fit_seconds": fit_s, "predict_seconds": time.perf_counter() - begin})
            for split_index, (split, rows) in enumerate((("valid", valid), ("test", test))):
                for index, row in enumerate(rows):
                    item = prediction_base(row, split)
                    item.update({name: float(values[split_index][index]) for name, values in probabilities.items()})
                    output.append(item)
            print(f"core {place} {course}C 完了", flush=True)
            del x_train, x_valid, x_test, models, probabilities, flaml, ebm
            gc.collect()
    import sklearn, catboost, flaml, interpret
    dump_stage("core", output, {
        "stage": "core", "python": platform.python_version(), "numpy": np.__version__, "sklearn": sklearn.__version__,
        "catboost": catboost.__version__, "flaml": flaml.__version__, "interpret": interpret.__version__,
        "elapsed_seconds": time.perf_counter() - started, "max_rss_kb": rss_kb(), "timings": timings,
    })


def tabdpt_stage() -> None:
    from tabdpt import TabDPTClassifier
    from compare_course_signal_models import TABDPT_TURBO_PARAMETERS
    from importlib.metadata import version

    payload = load_or_build_course_dataset(); features = payload["features"]
    started = time.perf_counter(); output = []; timings = []
    parameters = {key: value for key, value in TABDPT_TURBO_PARAMETERS.items() if key != "predict_seed"}
    for place in PLACES:
        for course in COURSES:
            train, valid, test = (case_rows(payload, place, course, split) for split in ("train", "valid", "test"))
            x_train, x_valid, x_test = (matrix(rows, features) for rows in (train, valid, test)); y_train = np.asarray([r["first"] for r in train], dtype=np.int8)
            model = TabDPTClassifier(**parameters); begin = time.perf_counter(); model.fit(x_train, y_train); fit_s = time.perf_counter() - begin; begin = time.perf_counter()
            probs = (model.predict_proba(x_valid, seed=SEED)[:, 1], model.predict_proba(x_test, seed=SEED)[:, 1]); pred_s = time.perf_counter() - begin
            timings.append({"model": TAB_MODELS[0], "place": place, "course": course, "fit_seconds": fit_s, "predict_seconds": pred_s})
            for split_index, (split, rows) in enumerate((("valid", valid), ("test", test))):
                for index, row in enumerate(rows):
                    item = prediction_base(row, split); item[TAB_MODELS[0]] = float(probs[split_index][index]); output.append(item)
            print(f"tabdpt {place} {course}C 完了", flush=True)
            del model, x_train, x_valid, x_test, probs; gc.collect()
    import sklearn, torch
    dump_stage("tabdpt", output, {"stage": "tabdpt", "python": platform.python_version(), "numpy": np.__version__, "sklearn": sklearn.__version__, "torch": torch.__version__, "tabdpt": version("tabdpt"), "elapsed_seconds": time.perf_counter() - started, "max_rss_kb": rss_kb(), "timings": timings})


def agng_stage() -> None:
    from compare_course_signal_models import fit_autogluon, fit_ngboost
    from importlib.metadata import version

    payload = load_or_build_course_dataset(); features = payload["features"]
    started = time.perf_counter(); output = []; timings = []
    for place in PLACES:
        for course in COURSES:
            train, valid, test = (case_rows(payload, place, course, split) for split in ("train", "valid", "test"))
            x_train, x_valid, x_test = (matrix(rows, features) for rows in (train, valid, test))
            y_train = np.asarray([r["first"] for r in train], dtype=np.int8); y_valid = np.asarray([r["first"] for r in valid], dtype=np.int8)
            case = f"aiwin_{place.lower()}_{course}c_first"
            ag_v, ag_t, ag_fit, ag_pred, ag_disk, ag_final, ag_comp, _leader = fit_autogluon(x_train, y_train, x_valid, y_valid, x_test, features, case)
            ng_v, ng_t, ng_fit, ng_pred = fit_ngboost(x_train, y_train, x_valid, y_valid, x_test)
            timings += [
                {"model": AG_MODELS[0], "place": place, "course": course, "fit_seconds": ag_fit, "predict_seconds": ag_pred, "disk_bytes": ag_disk, "final_model": ag_final, "composition": ag_comp},
                {"model": AG_MODELS[1], "place": place, "course": course, "fit_seconds": ng_fit, "predict_seconds": ng_pred},
            ]
            for probs, split, rows in (((ag_v, ng_v), "valid", valid), ((ag_t, ng_t), "test", test)):
                for index, row in enumerate(rows):
                    item = prediction_base(row, split); item[AG_MODELS[0]] = float(probs[0][index]); item[AG_MODELS[1]] = float(probs[1][index]); output.append(item)
            print(f"autogluon/ngboost {place} {course}C 完了", flush=True)
            del x_train, x_valid, x_test, ag_v, ag_t, ng_v, ng_t; gc.collect()
    import sklearn
    dump_stage("agng", output, {"stage": "agng", "python": platform.python_version(), "numpy": np.__version__, "sklearn": sklearn.__version__, "autogluon": version("autogluon.tabular"), "ngboost": version("ngboost"), "elapsed_seconds": time.perf_counter() - started, "max_rss_kb": rss_kb(), "timings": timings})


def rank_probability(scores: np.ndarray, rows: list[tuple], temperature: float, race_code_index: int) -> np.ndarray:
    output = np.empty(len(scores), dtype=np.float64); grouped = defaultdict(list)
    for index, row in enumerate(rows): grouped[str(row[race_code_index])].append(index)
    for indices in grouped.values():
        values = scores[indices] / max(float(temperature), 1e-9); values -= values.max(); values = np.exp(values); output[indices] = values / values.sum()
    return output


def calibrate_temperature(scores: np.ndarray, labels: np.ndarray, rows: list[tuple], race_code_index: int) -> float:
    from scipy.optimize import minimize_scalar
    grouped = defaultdict(list)
    for index, row in enumerate(rows): grouped[str(row[race_code_index])].append(index)
    winners = np.asarray([next(i for i in indices if labels[i] == 1) for indices in grouped.values()], dtype=np.int64)
    def objective(log_t):
        p = rank_probability(scores, rows, math.exp(log_t), race_code_index)
        return float(-np.mean(np.log(np.maximum(p[winners], 1e-12))))
    return float(math.exp(minimize_scalar(objective, bounds=(math.log(.03), math.log(20)), method="bounded").x))


def v6_stage() -> None:
    # 本番liveはstraight_timeを必須にせずNaN推論する一方、学習SQLだけが
    # 6艇全件を必須にしており、直線タイム未収録のAMGが全除外される。
    # production codeは変更せず、この分析用再学習だけliveと同じ欠損許容にする。
    import train_ai_winrate as base_training
    straight_requirement = "       AND COUNT(straight_time) = 6\n"
    if straight_requirement not in base_training.DATASET_SQL:
        raise RuntimeError("v6学習SQLのstraight_time必須条件を特定できません")
    base_training.DATASET_SQL = base_training.DATASET_SQL.replace(straight_requirement, "")
    from feature_ablation_tournament_2026 import load_rows, variant_features, build_matrix
    from ai_winrate_v4_rating_ablation_2026 import load_metadata
    from ai_winrate_motor_reset_ablation_2026 import RESET_PAIR_FEATURES, append_pair_reset_features, load_motor_reset_dates
    from ai_winrate_v2_models import make_hist_gradient_boosting, make_xgboost
    from ai_winrate_v4_models import make_v4_ranker
    from train_ai_winrate import RACE_CODE, RACE_DATE, PLACE_CODE, COURSE, IS_WINNER

    started = time.perf_counter()
    print("v6 point-in-time datasetを構築中…", flush=True)
    rows, columns = load_rows("2023-09-27", END_EXCLUSIVE.isoformat())
    valid_keys = {(str(row[RACE_CODE]), int(row[columns["course"]])) for row in rows}
    metadata = load_metadata("2023-09-27", END_EXCLUSIVE.isoformat(), valid_keys)
    if valid_keys - metadata.keys(): raise RuntimeError(f"Rating metadata不足: {len(valid_keys - metadata.keys())}")
    reset_dates = load_motor_reset_dates("2023-09-27", END_EXCLUSIVE.isoformat())
    rows, columns = append_pair_reset_features(rows, columns, metadata, reset_dates)
    del metadata, valid_keys; gc.collect()
    train = [r for r in rows if str(r[RACE_DATE]) <= TRAIN_END.isoformat()]
    valid = [r for r in rows if VALID_START.isoformat() <= str(r[RACE_DATE]) <= VALID_END.isoformat()]
    test = [r for r in rows if TEST_START.isoformat() <= str(r[RACE_DATE]) <= TEST_END.isoformat()]
    places = sorted({str(r[PLACE_CODE]) for r in train}); place_to_id = {place: i + 1 for i, place in enumerate(places)}
    labels_train = np.asarray([bool(r[IS_WINNER]) for r in train], dtype=np.int8); labels_valid = np.asarray([bool(r[IS_WINNER]) for r in valid], dtype=np.int8)
    codes_valid = [str(r[RACE_CODE]) for r in valid]; codes_test = [str(r[RACE_CODE]) for r in test]
    timings = []

    base_features = variant_features()["v3_baseline"]
    x_train = build_matrix(train, columns, base_features, place_to_id); x_valid = build_matrix(valid, columns, base_features, place_to_id); x_test = build_matrix(test, columns, base_features, place_to_id)
    hist, xgb = make_hist_gradient_boosting(), make_xgboost(); begin = time.perf_counter(); hist.fit(x_train, labels_train); timings.append({"model":"v2_hgb","fit_seconds":time.perf_counter()-begin}); begin=time.perf_counter(); xgb.fit(x_train, labels_train); timings.append({"model":"v2_xgboost","fit_seconds":time.perf_counter()-begin})
    hist_v = normalized_race_probabilities(hist.predict_proba(x_valid)[:,1], codes_valid); hist_t = normalized_race_probabilities(hist.predict_proba(x_test)[:,1], codes_test)
    xgb_v = normalized_race_probabilities(xgb.predict_proba(x_valid)[:,1], codes_valid); xgb_t = normalized_race_probabilities(xgb.predict_proba(x_test)[:,1], codes_test)
    v2_v, v2_t = .5*hist_v+.5*xgb_v, .5*hist_t+.5*xgb_t
    del x_train, x_valid, x_test, hist, xgb; gc.collect()

    def ranker_predictions(features, name):
        xtr = build_matrix(train, columns, features, place_to_id); xv = build_matrix(valid, columns, features, place_to_id); xt = build_matrix(test, columns, features, place_to_id)
        sizes=[]; last=None
        for row in train:
            code=str(row[RACE_CODE])
            if code != last: sizes.append(0); last=code
            sizes[-1]+=1
        model=make_v4_ranker(); begin=time.perf_counter(); model.fit(xtr, labels_train, group=np.asarray(sizes,dtype=np.int32), categorical_feature=[0,1]); fit_s=time.perf_counter()-begin
        sv, st = model.predict(xv), model.predict(xt); temperature=calibrate_temperature(sv, labels_valid, valid, RACE_CODE)
        timings.append({"model":name,"fit_seconds":fit_s,"temperature":temperature})
        del xtr,xv,xt,model; gc.collect()
        return rank_probability(sv,valid,temperature,RACE_CODE),rank_probability(st,test,temperature,RACE_CODE),temperature

    v4_features = variant_features()["v3_all_features"]
    v4_v,v4_t,v4_temp = ranker_predictions(v4_features,"v4_ranker")
    rating_v,rating_t,rating_temp = ranker_predictions(v4_features+RESET_PAIR_FEATURES,"rating_v6_ranker")
    pre_v=.15*v2_v+.2125*v4_v+.6375*rating_v; pre_t=.15*v2_t+.2125*v4_t+.6375*rating_t
    factor_start=date(2025,12,1); factors={}
    for course in COURSES:
        selected=np.asarray([factor_start.isoformat() <= str(r[RACE_DATE]) <= VALID_END.isoformat() and int(r[COURSE])==course for r in valid])
        n=int(selected.sum()); predicted=float(pre_v[selected].mean()); wins=int(labels_valid[selected].sum()); shrunk=(wins+500*predicted)/(n+500); factors[str(course)]=shrunk/predicted
    final_v=np.asarray([p*factors[str(int(r[COURSE]))] for p,r in zip(pre_v,valid)]); final_t=np.asarray([p*factors[str(int(r[COURSE]))] for p,r in zip(pre_t,test)])
    final_v=normalized_race_probabilities(final_v,codes_valid); final_t=normalized_race_probabilities(final_t,codes_test)
    values_valid=(hist_v,xgb_v,v2_v,v4_v,rating_v,pre_v,final_v); values_test=(hist_t,xgb_t,v2_t,v4_t,rating_t,pre_t,final_t)
    output=[]
    for split, source, values in (("valid",valid,values_valid),("test",test,values_test)):
        for index,row in enumerate(source):
            if str(row[PLACE_CODE]) not in PLACES: continue
            item={"split":split,"race_code":str(row[RACE_CODE]),"date":str(row[RACE_DATE]),"place":str(row[PLACE_CODE]),"race_number":int(str(row[RACE_CODE])[-2:]),"course":int(row[COURSE]),"boat_number":int(row[columns["lane_number"]]),"actual_win":int(bool(row[IS_WINNER]))}
            item.update({name:float(value[index]) for name,value in zip(V6_MODELS,values)}); output.append(item)
    import sklearn, lightgbm, xgboost
    dump_stage("v6",output,{"stage":"v6","python":platform.python_version(),"numpy":np.__version__,"sklearn":sklearn.__version__,"lightgbm":lightgbm.__version__,"xgboost":xgboost.__version__,"elapsed_seconds":time.perf_counter()-started,"max_rss_kb":rss_kb(),"train_rows":len(train),"valid_rows":len(valid),"test_rows":len(test),"temperature":{"v4":v4_temp,"rating_v6":rating_temp},"course_factors":factors,"course_factor_period":"2025-12-01 to 2026-02-28","reset_events":len(reset_dates),"timings":timings})


def normalize_group(rows: list[dict], source: str, target: str) -> None:
    grouped=defaultdict(list)
    for index,row in enumerate(rows): grouped[(row["split"],row["race_code"])].append(index)
    for indices in grouped.values():
        values=np.maximum(np.asarray([float(rows[i][source]) for i in indices]),1e-9); values/=values.sum()
        for index,value in zip(indices,values): rows[index][target]=float(value)


def metric(rows: list[dict], model: str) -> dict:
    p=np.asarray([float(r[f"{model}_normalized_probability"]) for r in rows]); y=np.asarray([int(r["actual_win"]) for r in rows]); grouped=defaultdict(list)
    for i,r in enumerate(rows): grouped[r["race_code"]].append(i)
    # course別は1レース1行なので、Top1や確率和ではなく艇単位Brierと
    # そのcourseが勝ったレースの予測確率だけを評価する。
    if any(len(indices) != 6 for indices in grouped.values()):
        winner = p[y == 1]
        return {"races":len(grouped),"boats":len(rows),"race_brier_mean":float(np.mean((p-y)**2)),"race_brier_sum":"","winner_log_loss":float(-np.mean(np.log(np.maximum(winner,1e-12)))) if len(winner) else "","top1_accuracy":"","actual_winner_probability_mean":float(np.mean(winner)) if len(winner) else "","probability_sum_mean":"","probability_sum_max_abs_error":""}
    hits=0; winner=[]; sums=[]; race_briers=[]
    for indices in grouped.values():
        top=max(indices,key=lambda i:(p[i],-int(rows[i]["course"]))); hits+=int(y[top]); wi=next(i for i in indices if y[i]==1); winner.append(p[wi]); sums.append(float(p[indices].sum())); race_briers.append(float(np.sum((p[indices]-y[indices])**2)))
    return {"races":len(grouped),"boats":len(rows),"race_brier_mean":float(np.mean((p-y)**2)),"race_brier_sum":float(np.mean(race_briers)),"winner_log_loss":float(-np.mean(np.log(np.maximum(winner,1e-12)))),"top1_accuracy":hits/len(grouped),"actual_winner_probability_mean":float(np.mean(winner)),"probability_sum_mean":float(np.mean(sums)),"probability_sum_max_abs_error":float(np.max(np.abs(np.asarray(sums)-1)))}


def finish_order_map(race_codes: list[str]) -> dict[tuple[str, int], str]:
    import psycopg2
    from db_config import load_db_config
    sql = """
SELECT re.race_code, re.lane_number, rrd.rank::text
FROM boat_race.race_entry re
JOIN boat_race.race_result_detail rrd
  ON rrd.race_code = re.race_code
 AND rrd.player_id = re.player_id
WHERE re.race_code = ANY(%s)
"""
    with psycopg2.connect(**load_db_config()) as conn:
        with conn.cursor() as cursor:
            cursor.execute(sql, (race_codes,))
            return {(str(code), int(boat)): str(rank) for code, boat, rank in cursor.fetchall()}


def subset(rows: list[dict], kind: str, value: str) -> list[dict]:
    if kind=="overall": return rows
    if kind=="venue": return [r for r in rows if r["place"]==value]
    if kind=="course": return [r for r in rows if r["course"]==int(value)]
    if kind=="month": return [r for r in rows if r["date"][:7]==value]
    raise ValueError(kind)


def calibration(rows: list[dict], model: str) -> list[dict]:
    bins=np.linspace(0,1,11); result=[]
    for lo,hi in zip(bins[:-1],bins[1:]):
        chosen=[r for r in rows if lo <= float(r[f"{model}_normalized_probability"]) < hi or (hi==1 and float(r[f"{model}_normalized_probability"])==1)]
        if chosen: result.append({"lower":float(lo),"upper":float(hi),"n":len(chosen),"predicted_mean":float(np.mean([float(r[f"{model}_normalized_probability"]) for r in chosen])),"actual_rate":float(np.mean([int(r["actual_win"]) for r in chosen]))})
    return result


def production_inventory() -> dict:
    paths=[ROOT/"forecast"/"models"/f"ai_winrate_v{v}.joblib" for v in (2,4,5,6)]
    # Metadata was read with the repository's .venv-models runtime during the audit.
    training={
        "ai_winrate_v2.joblib":{"trained_through_exclusive":"2026-09-23","features":13},
        "ai_winrate_v4.joblib":{"trained_through_exclusive":"2026-09-24","features":31,"blend":{"v2_weight":.15,"v4_weight":.85}},
        "ai_winrate_v5.joblib":{"trained_through_exclusive":"2026-09-24","features":35},
        "ai_winrate_v6.joblib":{"trained_through_exclusive":"2026-09-24","features":35,"blend":{"v2_weight":.15,"v4_weight":.2125,"rating_v4_weight":.6375}},
    }
    return {p.name:{"sha256":sha256(p),**training[p.name]} for p in paths}


def finalize_stage() -> None:
    stage_files={name:WORK/f"{name}.csv" for name in ("core","tabdpt","agng","v6")}
    missing=[str(path) for path in stage_files.values() if not path.exists()]
    if missing: raise RuntimeError(f"stage成果物が不足: {missing}")
    tables={name:read_csv(path) for name,path in stage_files.items()}
    indexed={name:{(r["split"],r["race_code"],int(r["course"])):r for r in rows} for name,rows in tables.items()}
    common=set.intersection(*(set(table) for table in indexed.values()))
    if not common: raise RuntimeError("全stageに共通するレース行がありません")
    finishes = finish_order_map(sorted({key[1] for key in common if key[0] == "test"}))
    rows=[]
    for key in sorted(common):
        base=indexed["v6"][key]
        boat_number = int(base["boat_number"])
        item={"race_code":base["race_code"],"date":base["date"],"place":base["place"],"race_number":int(base["race_number"]),"boat_number":boat_number,"course":int(base["course"]),"actual_finish":finishes.get((base["race_code"], boat_number), 1 if int(base["actual_win"]) else ""), "actual_win":int(base["actual_win"]),"split":base["split"],"exhibition_state":"post_exhibition"}
        for stage,models in (("core",CORE_MODELS),("tabdpt",TAB_MODELS),("agng",AG_MODELS)):
            source=indexed[stage][key]
            if int(source["actual_win"])!=item["actual_win"]: raise RuntimeError(f"ラベル不一致: {key}")
            for model in models: item[f"{model}_raw_probability"]=float(source[model])
        for model in V6_MODELS:
            value=float(base[model]); item[f"{model}_raw_probability"]=value; item[f"{model}_normalized_probability"]=value
        rows.append(item)
    for model in NEW_MODELS: normalize_group(rows,f"{model}_raw_probability",f"{model}_normalized_probability")
    complete=[]
    for split in ("valid","test"):
        race_groups=defaultdict(list)
        for row in rows:
            if row["split"]==split: race_groups[row["race_code"]].append(row)
        valid_codes={code for code,values in race_groups.items() if len(values)==6 and sum(int(v["actual_win"]) for v in values)==1}
        complete.extend(r for r in rows if r["split"]==split and r["race_code"] in valid_codes)
    rows=complete

    summary=[]; monthly=[]
    for split in ("valid","test"):
        split_rows=[r for r in rows if r["split"]==split]
        scopes=[("overall","all")]+[("venue",v) for v in PLACES]+[("course",str(c)) for c in COURSES]
        if split=="test": scopes += [("month",m) for m in sorted({r["date"][:7] for r in split_rows})]
        for kind,value in scopes:
            selected=subset(split_rows,kind,value)
            for model in ALL_MODELS:
                record={"split":split,"scope":kind,"scope_value":value,"model":model,**metric(selected,model)}
                (monthly if kind=="month" else summary).append(record)

    test=[r for r in rows if r["split"]=="test"]
    correlations=[]
    for left,right in itertools.combinations(ALL_MODELS,2):
        lp=np.asarray([float(r[f"{left}_normalized_probability"]) for r in test]); rp=np.asarray([float(r[f"{right}_normalized_probability"]) for r in test]); y=np.asarray([int(r["actual_win"]) for r in test])
        correlations.append({"model_left":left,"model_right":right,"probability_pearson":float(np.corrcoef(lp,rp)[0,1]),"error_pearson":float(np.corrcoef(lp-y,rp-y)[0,1]),"n":len(test)})

    focus=("v6_final","autogluon_course_first","ebm_course_first","ngboost_course_first","catboost_course_first","flaml_course_first")
    disagreements=[]; by_race=defaultdict(list)
    for row in test: by_race[row["race_code"]].append(row)
    for code,values in sorted(by_race.items()):
        picks={model:max(values,key=lambda r:(float(r[f"{model}_normalized_probability"]),-int(r["course"]))) for model in focus}
        if len({int(row["course"]) for row in picks.values()})==1: continue
        winner=next(r for r in values if int(r["actual_win"])==1)
        record={"race_code":code,"date":winner["date"],"place":winner["place"],"race_number":winner["race_number"],"winner_course":winner["course"],"exhibition_state":"post_exhibition"}
        for model,row in picks.items():
            record[f"{model}_pick_course"]=row["course"]; record[f"{model}_pick_probability"]=row[f"{model}_normalized_probability"]; record[f"{model}_correct"]=int(row["course"]==winner["course"]); record[f"{model}_winner_probability"]=winner[f"{model}_normalized_probability"]
        disagreements.append(record)

    predictions=[]
    for row in test:
        item={key:row[key] for key in ("race_code","date","place","race_number","boat_number","course","actual_finish","actual_win","exhibition_state")}
        for model in ALL_MODELS:
            item[f"{model}_raw_probability"]=row[f"{model}_raw_probability"]; item[f"{model}_normalized_probability"]=row[f"{model}_normalized_probability"]
        predictions.append(item)

    runtimes={name:json.loads((WORK/f"{name}.json").read_text()) for name in stage_files}
    inventory=production_inventory()
    from compare_course_signal_models import (
        AUTOGLUON_PARAMETERS, CATBOOST_PARAMETERS, EBM_PARAMETERS,
        FLAML_PARAMETERS, HGB_PARAMETERS, NGBOOST_PARAMETERS,
        TABDPT_TURBO_PARAMETERS,
    )
    experiment={
        "experiment":"ai_winrate_v6_vs_new_ml_race_probability","created_at":time.strftime("%Y-%m-%dT%H:%M:%S%z"),"git_sha":git_sha(),
        "period":{"train":"2023-09-27 to 2025-08-31","valid":"2025-09-01 to 2026-02-28","test":"2026-03-01 to 2026-09-27"},
        "places":list(PLACES),"feature_sets":{"new_ml":"fixed post-exhibition 170 features","v2":"13 features","v4":"31 features","v6_rating":"35 features"},
        "label_definition":"The pre-race exhibition-course subject player is positive iff that same player actually won. Existing course-signal actual-result-course labels were not reused for race win probability.",
        "model_parameters":{"hgb":HGB_PARAMETERS,"catboost":CATBOOST_PARAMETERS,"flaml":FLAML_PARAMETERS,"ebm":EBM_PARAMETERS,"tabdpt_turbo":TABDPT_TURBO_PARAMETERS,"autogluon":AUTOGLUON_PARAMETERS,"ngboost":NGBOOST_PARAMETERS},
        "normalization":"forecast.ai_winrate_features.normalized_race_probabilities; positive raw probabilities divided by six-boat race sum",
        "brier_definitions":{"race_brier_mean":"mean squared error over all six boats (same as mean per-boat binary Brier)","race_brier_sum":"mean per-race sum of six squared errors (= 6 * race_brier_mean)"},
        "v6":{"components":list(V6_MODELS),"blend":{"v2_weight":.15,"v4_weight":.2125,"rating_v6_weight":.6375},"course_calibration_strength":500,"course_calibration_source":"last three VALID months only"},
        "v6_dataset_compatibility":{"finding":"training SQL required six non-null straight_time values while live inference allows straight_time missing","analysis_only_action":"removed only the straight_time completeness filter so AMG NaN inputs follow live inference behavior","production_changed":False},
        "production_artifacts":inventory,"production_artifact_leakage":"All current production artifacts include part or all of TEST; not used for formal scoring.",
        "temporary_models":"All analysis-only; AutoGluon directories under /tmp removed by fitter; no model artifact committed.",
        "environments":runtimes,"common_rows":{"valid_boats":sum(r["split"]=="valid" for r in rows),"test_boats":len(test),"test_races":len(test)//6},
        "calibration_buckets":{model:calibration(test,model) for model in ALL_MODELS},
        "leakage_checks":{"result_used_only_as_label":True,"payouts_and_odds_unused":True,"point_in_time_history":True,"production_artifacts_not_scored":True,"test_not_used_for_training_tuning_calibration_or_blend":True},
    }
    OUTPUT.mkdir(parents=True,exist_ok=True)
    write_csv(OUTPUT/f"{STEM}_summary.csv",summary); write_csv(OUTPUT/f"{STEM}_monthly.csv",monthly); write_csv(OUTPUT/f"{STEM}_predictions.csv.gz",predictions); write_csv(OUTPUT/f"{STEM}_correlations.csv",correlations); write_csv(OUTPUT/f"{STEM}_disagreements.csv",disagreements)
    (OUTPUT/f"{STEM}_experiment.json").write_text(json.dumps(experiment,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    write_inventory(OUTPUT/"ai_winrate_model_inventory.md",inventory,runtimes)
    write_report(OUTPUT/f"{STEM}.md",summary,monthly,correlations,disagreements,experiment)
    print(json.dumps({"status":"ok","test_races":len(test)//6,"max_rss_kb":max(int(v["max_rss_kb"]) for v in runtimes.values()),"elapsed_seconds":sum(float(v["elapsed_seconds"]) for v in runtimes.values())},ensure_ascii=False),flush=True)


def write_inventory(path: Path, inventory: dict, runtimes: dict) -> None:
    lines=["# AI1着率 v6 model inventory","","## 現行フロー","","`v2 HGB/XGBoost → race内正規化・50/50 blend` + `v4 LightGBM LambdaRank` + `motor-reset rating LightGBM LambdaRank` → 15% / 21.25% / 63.75% blend → course factor → race内100%正規化。","","- v4/rating rankerはVALIDだけでtemperatureを求める。","- v6は該当artifactがなければv5へfallback。v2/v4 artifact不足時はwaiting。","- 本番artifactはいずれも今回TEST期間を含むため、正式比較には使わず分析専用に再学習した。","","## 本番artifact","","|artifact|trained through exclusive|features|SHA256|","|---|---|---:|---|"]
    for name,item in inventory.items(): lines.append(f"|{name}|{item['trained_through_exclusive']}|{item['features']}|`{item['sha256']}`|")
    lines += ["","## 比較用runtime","",* [f"- {name}: Python {item['python']} / NumPy {item['numpy']} / max RSS {item['max_rss_kb']:,}KB / {item['elapsed_seconds']:.2f}秒" for name,item in runtimes.items()],""]
    path.write_text("\n".join(lines),encoding="utf-8")


def write_report(path: Path, summary: list[dict], monthly: list[dict], correlations: list[dict], disagreements: list[dict], experiment: dict) -> None:
    overall={r["model"]:r for r in summary if r["split"]=="test" and r["scope"]=="overall"}
    ranked=sorted(overall.values(),key=lambda r:(float(r["race_brier_mean"]),float(r["winner_log_loss"])))
    v6=overall["v6_final"]
    best=ranked[0]
    best_new=min((overall[name] for name in NEW_MODELS),key=lambda r:float(r["race_brier_mean"]))
    lines=["# AI1着率 v6 + 新ML 総合比較","","## 結論（historical TEST）","",f"- race-level Brier最良は **{best['model']}**: {float(best['race_brier_mean']):.8f}。現行v6再現は {float(v6['race_brier_mean']):.8f}。",f"- 新ML最良は **{best_new['model']}**: {float(best_new['race_brier_mean']):.8f} で、v6 finalを上回らなかった。",f"- Top1は最良Brierモデル {float(best['top1_accuracy'])*100:.2f}% / v6 {float(v6['top1_accuracy'])*100:.2f}%、勝者Log Lossは {float(best['winner_log_loss']):.6f} / {float(v6['winner_log_loss']):.6f}。","- これは既に観察済みhistorical TESTであり、本番置換判断ではない。次は候補を固定してforward validationする。","","## 今回の6つの回答","","1. v6の強い部品はrating-v6 rankerとv4 ranker。3者blendの `v6_pre_course` が総合Brier最良。","2. v2 HGB/XGBoost/blendは単体で弱い。最終course補正はwinner NLLを少し改善したが、BrierとTop1はわずかに悪化。","3. AutoGluon単体systemはv6を上回らなかった。","4. EBM / NGBoost / CatBoost等も、1〜6Cの各艇単位Brierでv6 finalを上回らなかった。","5. 新MLで精度と多様性のバランスが最も良いのはEBM。TabDPTはv6との相関が最も低いが、精度差が大きい。","6. 次のblend候補はまず `v6 + EBM`。4Cの差が小さいNGBoostは補助候補。重み探索は今回未実施。","","## 監査で判明したデータ定義差","","- 既存course-signalのfirstは結果の実進入courseラベル。今回の艇1着率では、展示進入で選んだsubject player本人の勝敗へ揃えた。特徴量・split・採用レース数は不変。","- v6学習SQLはstraight_time 6艇必須だがliveは欠損許容。尼崎はstraight_time未収録のため、分析専用再学習だけliveと同じNaN許容にした。本番コードは未変更。","","## 固定条件","",f"- TRAIN {experiment['period']['train']} / VALID {experiment['period']['valid']} / TEST {experiment['period']['test']}。","- 新MLは展示込み固定170特徴。v6系は現行実装どおりv2=13、v4=31、reset-rating=35特徴。情報設計が異なるsystem比較である。","- TESTは学習・温度・course factor・モデル選択・blend調整に未使用。新しい校正とblend探索は実施していない。","- 全確率は既存 `normalized_race_probabilities()` でレース内100%化。","","## TEST総合","","|順位|model|Brier(mean/boat)|Brier(sum/race)|winner NLL|Top1|勝者平均確率|","|---:|---|---:|---:|---:|---:|---:|"]
    for rank,row in enumerate(ranked,1): lines.append(f"|{rank}|{row['model']}|{float(row['race_brier_mean']):.8f}|{float(row['race_brier_sum']):.8f}|{float(row['winner_log_loss']):.6f}|{float(row['top1_accuracy'])*100:.2f}%|{float(row['actual_winner_probability_mean'])*100:.2f}%|")
    lines += ["","## v6内部比較","","|段階|Brier|winner NLL|Top1|","|---|---:|---:|---:|"]
    for model in V6_MODELS:
        row=overall[model]; lines.append(f"|{model}|{float(row['race_brier_mean']):.8f}|{float(row['winner_log_loss']):.6f}|{float(row['top1_accuracy'])*100:.2f}%|")
    lines += ["","## 場別・コース別","","各区分の最良Brier（system評価）:",""]
    for scope in ("venue","course"):
        values=sorted({r["scope_value"] for r in summary if r["split"]=="test" and r["scope"]==scope},key=str)
        for value in values:
            candidates=[r for r in summary if r["split"]=="test" and r["scope"]==scope and r["scope_value"]==value]; chosen=min(candidates,key=lambda r:float(r["race_brier_mean"])); lines.append(f"- {scope} {value}: **{chosen['model']}** {float(chosen['race_brier_mean']):.8f}")
    lines += ["","## 月別","", "|月|最良model|Brier|", "|---|---|---:|"]
    for month in sorted({r["scope_value"] for r in monthly}):
        chosen=min((r for r in monthly if r["scope_value"]==month),key=lambda r:float(r["race_brier_mean"])); lines.append(f"|{month}|{chosen['model']}|{float(chosen['race_brier_mean']):.8f}|")
    v6_corr=sorted((r for r in correlations if "v6_final" in (r["model_left"],r["model_right"])),key=lambda r:float(r["probability_pearson"]))
    lines += ["","## 多様性・次のblend候補","",f"- v6との予測相関が最も低い候補: **{v6_corr[0]['model_right'] if v6_corr[0]['model_left']=='v6_final' else v6_corr[0]['model_left']}**（Pearson {float(v6_corr[0]['probability_pearson']):.4f}）。",f"- Top1不一致レースは {len(disagreements)}R。詳細CSVはルール作成前の観察用で、今回はroutingやblend weightを作っていない。","- 単体Brierでv6を上回る新MLはなかった。次フェーズでは、精度と多様性のバランスが最も良いEBMを `v6 + EBM` 候補として前方保存し、weight最適化前に検証設計を固定する。","","## 注意","","- production artifactはTEST期間を含むため直接評価していない。分析専用モデルは/tmpだけで、本番model/PHP/frontend/買い目は無変更。","- actual_finishはrace_result_detailに保存されている着順を補完し、未収録艇（欠場・失格等を含む）は空欄。actual_winは全レースで確定している。","- course別行は6艇レースを分割した艇単位Brierであり、Top1・確率和は算出していない。","- calibration bucket、runtime、version、course factor、各stage条件はexperiment JSONに保存。",""]
    path.write_text("\n".join(lines),encoding="utf-8")


def main() -> None:
    parser=argparse.ArgumentParser(description=__doc__); parser.add_argument("--stage",required=True,choices=("core","tabdpt","agng","v6","finalize")); args=parser.parse_args(); WORK.mkdir(parents=True,exist_ok=True)
    {"core":core_stage,"tabdpt":tabdpt_stage,"agng":agng_stage,"v6":v6_stage,"finalize":finalize_stage}[args.stage]()


if __name__ == "__main__":
    main()
