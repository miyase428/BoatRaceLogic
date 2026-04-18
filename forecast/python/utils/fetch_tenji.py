import requests

def fetch_tenji_data(race_code: str) -> dict:
    url = f"http://192.168.0.208/tenji_api.php?race_code={race_code}"

    try:
        response = requests.get(url, timeout=5)
        response.raise_for_status()
    except Exception as e:
        raise RuntimeError(f"Failed to fetch tenji data: {e}")

    data = response.json()

    if not isinstance(data, dict):
        raise ValueError(f"Invalid tenji_api response: {data}")

    return data
