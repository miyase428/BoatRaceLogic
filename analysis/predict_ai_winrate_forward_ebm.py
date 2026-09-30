#!/usr/bin/env python3
"""EBM inference helper kept in the frozen sklearn 1.4 environment."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import joblib
import numpy as np


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", required=True)
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    artifact = joblib.load(args.model)
    payload = json.loads(Path(args.input).read_text(encoding="utf-8"))
    predictions = {}
    for race_code, rows in payload["races"].items():
        values = []
        for row in rows:
            model = artifact["models"][row["place"]][str(int(row["entry_course"]))]
            probability = float(model.predict_proba(np.asarray([row["features"]], dtype=np.float64))[0, 1])
            values.append(probability)
        total = sum(max(value, 1e-12) for value in values)
        predictions[race_code] = [max(value, 1e-12) / total for value in values]
    Path(args.output).write_text(json.dumps({"status": "ok", "predictions": predictions}, ensure_ascii=False) + "\n", encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
