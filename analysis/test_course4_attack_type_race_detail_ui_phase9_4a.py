#!/usr/bin/env python3
"""Static contract checks for the read-only 4C Attack Type detail panel."""
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
JS = (ROOT / "web/assets/js/course4_attack_type_race_detail.js").read_text()
CSS = (ROOT / "web/assets/css/course4_attack_type_race_detail.css").read_text()
PC = (ROOT / "web/views/index_view.php").read_text()
APP = (ROOT / "web/views/app_view.php").read_text()


def require(condition: bool, name: str, checks: dict[str, bool]) -> None:
    checks[name] = condition
    if not condition:
        raise AssertionError(name)


def main() -> None:
    checks: dict[str, bool] = {}
    require("/web/course4_attack_type_api.php" in JS, "api_single_source", checks)
    require("course4_attack_type_live.py" not in JS and "python" not in JS.lower(), "no_direct_core", checks)
    require(".app-code, .code-value" in JS, "canonical_race_code", checks)
    require("まくり成功見込み" in JS and "まくり差し成功見込み" in JS and "攻撃成功なし" in JS,
            "three_probability_labels", checks)
    require("(number * 100).toFixed(1) + '%'" in JS, "one_decimal_rounding", checks)
    require("NON_WAKUNARI" in JS and "進入変化のため対象外" in JS, "non_wakunari_mapping", checks)
    require("EXHIBITION_NOT_COMPLETE" in JS and "ENTRY_MAPPING_INCOMPLETE" in JS
            and "展示データ待ち" in JS, "waiting_reason_mapping", checks)
    require("展開予測を取得できませんでした" in JS and "catch (error)" in JS, "error_fallback", checks)
    require("AbortController" in JS and "17000" in JS, "ui_timeout_guard", checks)
    require("course4_attack_type_race_detail.js" in PC and "course4_attack_type_race_detail.css" in PC,
            "desktop_included", checks)
    require("course4_attack_type_race_detail.js" in APP and "course4_attack_type_race_detail.css" in APP,
            "mobile_included", checks)
    require("@media (max-width: 420px)" in CSS and ".course4-attack-type-row" in CSS,
            "mobile_layout", checks)
    require("logic_version" not in JS and "artifact_sha256" not in JS and "input_sha256" not in JS,
            "identity_not_displayed", checks)
    print({"pass": True, "checks": checks})


if __name__ == "__main__":
    main()
