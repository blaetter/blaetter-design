#!/bin/sh
# Compares the copies the consumers keep of dist/ with dist/ (or updates them with --update).
# The consumers are looked for next to this repository; one that is not checked out is skipped.
#   bin/copies.sh [--update]      (or: ./design copies [--update])
# Each line: file in dist/, copy relative to the parent directory of this repository.
set -eu
cd "$(dirname "$0")/.."
update=0
[ "${1:-}" = "--update" ] && update=1

status=0
while read -r source copy; do
    [ -n "$source" ] || continue
    repo=../${copy%%/*}
    if [ ! -d "$repo" ]; then
        echo "skip  $copy (not checked out)"
        continue
    fi
    if [ -f "../$copy" ] && cmp -s "dist/$source" "../$copy"; then
        echo "ok    $copy"
    elif [ "$update" = 1 ]; then
        cp "dist/$source" "../$copy"
        echo "copy  $copy"
    else
        echo "DIFF  $copy differs from dist/$source (./design copies --update)"
        status=1
    fi
done <<'EOF'
archive.json    blaetter-archiv/config/design-tokens.json
tokens.css      status.blaetter.de/assets/css/tokens.css
tokens-dark.css status.blaetter.de/assets/css/tokens-dark.css
tokens.css      blaetter.web/web/themes/contrib/palm/css/blaetter-tokens.css
EOF
exit $status
