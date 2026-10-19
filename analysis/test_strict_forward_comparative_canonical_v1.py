#!/usr/bin/env python3
"""Python half of the v1 PHP/Python canonical JSON fixture."""
import json
import math
import sys


def normalize(value):
    if isinstance(value, float):
        if not math.isfinite(value):
            raise ValueError("comparative_non_finite_number")
        return {"__scalar_type": "float", "value": format(value, ".17g").replace("E", "e")}
    if isinstance(value, list):
        return [normalize(item) for item in value]
    if isinstance(value, dict):
        return {key: normalize(value[key]) for key in sorted(value)}
    return value


payload = json.loads(sys.stdin.read())
print(json.dumps(normalize(payload), ensure_ascii=False, separators=(",", ":")))
