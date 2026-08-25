#!/bin/sh
cd "$(dirname "$0")" || exit 1
"$1" update.php "$2" "$3" "$4" >/dev/null 2>&1 &
