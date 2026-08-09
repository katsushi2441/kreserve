#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/kreserve/
# 本体(public/*.php)をそのまま上げるので、常に最新と一致する。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . /home/kojima/work/aixec/.env; set +a
remote="/web/proto_exbridge_jp/kreserve"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
up public/kreserve.php kreserve.php
up public/kreserve_admin.php kreserve_admin.php
up public/kreserve_lib.php kreserve_lib.php
up demo/kreserve_config.php kreserve_config.php
up demo/index.php index.php
up demo/.htaccess .htaccess
up public/kres_data/.htaccess kres_data/.htaccess
echo "published: https://proto.exbridge.jp/kreserve/"
