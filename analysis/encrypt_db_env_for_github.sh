#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"
ENC_FILE="$ROOT_DIR/config/db.env.enc"

if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl が見つかりません" >&2
  exit 1
fi

if [[ ! -f "$ENV_FILE" ]]; then
  echo ".env が見つかりません: $ENV_FILE" >&2
  exit 1
fi

for key in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD; do
  if ! grep -q "^${key}=" "$ENV_FILE"; then
    echo ".env に ${key} がありません" >&2
    exit 1
  fi
done

mkdir -p "$(dirname "$ENC_FILE")"

echo "GitHub配布用に .env を暗号化します。"
echo "このあと表示される暗号化パスフレーズは、Ubuntuとラズパイで同じものを使用してください。"
echo "パスフレーズ自体はGitHubへ保存しません。"

openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt \
  -in "$ENV_FILE" \
  -out "$ENC_FILE"

chmod 600 "$ENC_FILE"

echo "作成完了: $ENC_FILE"
echo "平文の .env はGit管理対象外のままです。"
