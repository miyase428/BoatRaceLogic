#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"
ENC_FILE="$ROOT_DIR/config/db.env.enc"
TMP_FILE="$(mktemp)"
trap 'rm -f "$TMP_FILE"' EXIT

if ! command -v openssl >/dev/null 2>&1; then
  echo "openssl が見つかりません" >&2
  exit 1
fi

if [[ ! -f "$ENC_FILE" ]]; then
  echo "暗号化設定が見つかりません: $ENC_FILE" >&2
  exit 1
fi

echo "GitHubから取得した暗号化DB設定を復号します。"
echo "暗号化時と同じパスフレーズを入力してください。"

openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
  -in "$ENC_FILE" \
  -out "$TMP_FILE"

for key in DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD; do
  if ! grep -q "^${key}=" "$TMP_FILE"; then
    echo "復号結果に ${key} がありません。配置を中止します。" >&2
    exit 1
  fi
done

mv "$TMP_FILE" "$ENV_FILE"
trap - EXIT
chmod 600 "$ENV_FILE"

echo "配置完了: $ENV_FILE"
echo "権限: $(stat -c '%a %U:%G' "$ENV_FILE" 2>/dev/null || true)"
