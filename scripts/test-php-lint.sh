#!/bin/sh
# Regression: PHP method names are case-insensitive; getID()+getId() in
# Notifier.php was a fatal "Cannot redeclare" that crash-looped the worker.
# php -l catches this class of compile error for every file under lib/.
set -e
cd "$(dirname "$0")/.."
command -v php >/dev/null || { echo "php not found" >&2; exit 1; }
fail=0
for f in $(find lib appinfo -name '*.php'); do
	php -l "$f" >/dev/null 2>/tmp/hv-lint.$$ || { cat /tmp/hv-lint.$$; fail=1; }
done
rm -f /tmp/hv-lint.$$
[ $fail -eq 0 ] && echo "php lint ok"
exit $fail
