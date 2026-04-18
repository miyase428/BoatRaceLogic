from utils.fetch_tenji import fetch_tenji_data
import json
import sys

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("race_code is required")
        sys.exit(1)

    race_code = sys.argv[1]

    try:
        data = fetch_tenji_data(race_code)
    except Exception as e:
        print(f"Error: {e}")
        sys.exit(1)

    print(json.dumps(data, ensure_ascii=False, indent=2))
