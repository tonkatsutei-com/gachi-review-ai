#!/bin/sh
# gachi-review-ai サーバーcron 実行スクリプト（2026-10-06 T33）。
#
# サーバーの crontab に登録して使う（例: 毎分実行）。
#   * * * * * /home/example/domains/example.com/public_html/wp-content/plugins/gachi-review-ai/cron/cron.sh >/dev/null 2>&1
#
# 実行方式（③サイト設定の「記事生成投稿設定」>「実行方式」）が
# 「WP-Cron」のときは何もしない（サイト訪問ベースのまま）。
#
# 注意:
#   - 本ファイルは LF（CRLF 不可）。CRLF だと行末の CR をコマンドの一部として解釈し、
#     /bin/sh での実行に失敗する（.gitattributes / .editorconfig で担保）。
#   - `php` が cron の PATH に無い環境では、PHP_BIN を絶対パス（例: /usr/bin/php8.2）にする。
#   - 詳細は README「cron の実行経路」節を参照。

# PHP の CLI バイナリ（環境変数 PHP_BIN で上書きできる）。
PHP_BIN="${PHP_BIN:-php}"

# 本スクリプトと同じディレクトリの index.php を実行する。
DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd) || exit 1

exec "$PHP_BIN" "$DIR/index.php"
