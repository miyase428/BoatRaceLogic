# fetch_input.py
# ---------------------------------------------
# Raspberry Pi の get_input_data.php を叩いて
# entries[] を Python に返すモジュール
# ---------------------------------------------

import requests

def fetch_input_data(race_code: str) -> list:
    """
    Raspberry Pi の get_input_data.php を叩いて
    entries[] を Python の list[dict] として返す。

    race_code: "20260317SMS05" のような文字列
    return: entries[] の list
    """

    # Raspberry Pi の API URL
    url = f"http://192.168.0.208/api/get_input_data.php?race_code={race_code}"

    try:
        response = requests.get(url, timeout=5)
        response.raise_for_status()
    except Exception as e:
        raise RuntimeError(f"Failed to fetch data from Raspberry Pi: {e}")

    data = response.json()

    # entries が存在するかチェック
    if "entries" not in data or not isinstance(data["entries"], list):
        raise ValueError(f"Invalid response format: {data}")

    return data["entries"]
