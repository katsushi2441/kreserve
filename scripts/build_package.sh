#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・予約データは入れない。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/kreserve-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/kreserve.php public/kreserve_admin.php public/kreserve_lib.php \
  public/kreserve_config.php.example public/kres_data/.htaccess \
  scripts/make_password_hash.php scripts/check_kreserve.php \
  skills docs README.md LICENSE \
  -x '*.json' -x '*.log' >/dev/null
echo "built: $zip ($(du -h "$zip" | cut -f1))"
