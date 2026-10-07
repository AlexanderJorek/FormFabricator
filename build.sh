#!/usr/bin/env bash
# =============================================================================
#  build.sh - macOS/Linux launcher for the release build (tools/build.php)
#
#  The build itself is PHP, the same on every system; build.cmd is this
#  launcher's Windows twin. Without arguments it shows a menu.
#
#  On a fresh clone there is nothing to install first: vendor/ is gitignored,
#  and the build runs `composer install` itself when the dev tools are absent.
#  The integration suite needs a MySQL or MariaDB server whose database it may
#  wipe: set WP_TESTS_DB_HOST (and _NAME, _USER, _PASSWORD), for example to a
#  container started with
#    docker run -d -p 3306:3306 -e MARIADB_ROOT_PASSWORD=root \
#      -e MARIADB_DATABASE=wordpress_test mariadb:11.4
#  and WP_TESTS_DB_HOST=127.0.0.1:3306.
#
#  Usage
#    ./build.sh                menu: full build / offline build / dev setup / quit
#    ./build.sh -y             full release build, no menu
#    ./build.sh --setup        dev dependencies, then stop
#    ./build.sh --skip-audit   offline build (any argument skips the menu)
#
#  Dev tooling, never shipped: tools/build-config.php leaves this file out of
#  the package, and the archive check rejects any .sh that reaches it.
# =============================================================================

set -u

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
build="$here/tools/build.php"

if [ ! -f "$build" ]; then
    echo "ERROR: tools/build.php was not found next to this launcher." >&2
    exit 1
fi
if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php is not on PATH. Install PHP 8.1 or newer (e.g. brew install php, apt install php-cli)." >&2
    exit 1
fi

usage() {
    echo "Usage: ./build.sh [-y] [--setup] [--skip-audit]"
    echo "  -y            run the full build immediately, without the menu"
    echo "  --setup       install this working tree's dev dependencies, then stop"
    echo "  --skip-audit  offline build: no composer audit, no test database download"
}

args=()
if [ $# -gt 0 ]; then
    for arg in "$@"; do
        case "$arg" in
            -h|--help|-\?) usage; exit 0 ;;
            -y) ;;
            -Setup|-setup) args+=(--setup) ;;
            -SkipAudit|-skipaudit) args+=(--skip-audit) ;;
            *) args+=("$arg") ;;
        esac
    done
else
    echo
    echo "  FormFabricator - release build"
    echo "  =============================="
    echo "    1  Full release build   (all gates, including composer audit)"
    echo "    2  Offline build        (no network advisory check)"
    echo "    3  Set up dev tools     (composer install - first run)"
    echo "    Q  Quit"
    echo
    # One key, and only these keys: anything else, or a closed stdin, quits.
    read -r -n 1 -p "Choice (1, 2, 3 or Q): " choice || choice=q
    echo
    case "$choice" in
        1) ;;
        2) args+=(--skip-audit) ;;
        3) args+=(--setup) ;;
        *) exit 0 ;;
    esac
fi

echo
php "$build" ${args[@]+"${args[@]}"}
rc=$?
if [ "$rc" -ne 0 ]; then
    echo
    echo "Build FAILED - exit code $rc. No shippable package was produced."
fi
exit "$rc"
