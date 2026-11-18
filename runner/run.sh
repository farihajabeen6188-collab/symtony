#!/bin/sh
# SYMTONY isolated test runner.
#
# Runs inside a container with NO network, a read-only root filesystem and tmpfs scratch space.
# It only talks to SYMTONY through the jobs directory:
#   /jobs/<id>/job.json   {"variant": "buggy"|"fixed", "patch": "fixes/x.patch", "test_file": "tests/Symtony/X.php", "timeout": 60}
#   /jobs/<id>/Test.php   the reviewed test
#   /jobs/<id>/result.json  written here when done (exit code, duration, junit, output)
# The command it runs is fixed. Nothing in a job can choose what is executed besides the test file itself.
set -u

JOBS=/jobs
SRC=/src
VENDOR=/src-vendor

log() { echo "[runner] $*"; }

json_escape() { php -r 'echo json_encode(stream_get_contents(STDIN), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);'; }

run_job() {
    dir=$1
    id=$(basename "$dir")
    variant=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo in_array($j["variant"]??"", ["buggy","fixed"], true) ? $j["variant"] : "buggy";' "$dir/job.json")
    patch=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); $p=(string)($j["patch"]??""); echo preg_match("#^fixes/[A-Za-z0-9._-]+\.patch$#", $p) ? $p : "";' "$dir/job.json")
    timeout_s=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); echo max(5, min(120, (int)($j["timeout"]??60)));' "$dir/job.json")
    log "job $id: variant=$variant"

    work=/tmp/w-$id
    rm -rf "$work" && mkdir -p "$work"
    started=$(date +%s%3N)
    status=ok
    # Fresh copy of the application source (no var/, no vendor/) and a dereferenced vendor copy, so the
    # autoloader resolves App\ to THIS copy — never to the original (symlinked vendor would load the original src/).
    (cd "$SRC" && tar --exclude=./var --exclude=./vendor --exclude=./.env.local -cf - .) | (cd "$work" && tar -xf -) || status=setup
    cp -aL "$VENDOR" "$work/vendor" 2>/dev/null || status=setup
    mkdir -p "$work/var/data" "$work/tests/Symtony"

    if [ "$status" = ok ] && [ "$variant" = fixed ]; then
        if [ -z "$patch" ] || ! (cd "$work" && patch -p1 -s < "$SRC/$patch"); then
            status=patch
        fi
    fi

    class=$(php -r '$j=json_decode(file_get_contents($argv[1]),true); $c=(string)($j["class_name"]??""); echo preg_match("/^[A-Za-z][A-Za-z0-9_]{0,100}$/", $c) ? $c : "RegressionTest";' "$dir/job.json")
    test_file=tests/Symtony/$class.php
    cp "$dir/Test.php" "$work/$test_file" 2>/dev/null || status=setup

    output=""
    code=99
    if [ "$status" = ok ]; then
        output=$(cd "$work" && APP_ENV=test SYMFONY_DEPRECATIONS_HELPER=disabled timeout "$timeout_s" php -d memory_limit=384M vendor/bin/phpunit --no-progress --colors=never --log-junit /tmp/junit-$id.xml "$test_file" 2>&1)
        code=$?
    elif [ "$status" = patch ]; then
        output="The reference fix could not be applied."
    else
        output="The isolated workspace could not be prepared."
    fi
    ended=$(date +%s%3N)

    junit=""
    [ -f "/tmp/junit-$id.xml" ] && junit=$(cat "/tmp/junit-$id.xml")
    printf '{"job":"%s","variant":"%s","setup":"%s","exit_code":%d,"timed_out":%s,"duration_ms":%d,"output":%s,"junit":%s}\n' \
        "$id" "$variant" "$status" "$code" "$([ "$code" = 124 ] && echo true || echo false)" "$((ended - started))" \
        "$(printf '%s' "$output" | tail -c 60000 | json_escape)" "$(printf '%s' "$junit" | json_escape)" > "$dir/result.json.tmp"
    mv "$dir/result.json.tmp" "$dir/result.json"
    rm -rf "$work" "/tmp/junit-$id.xml"
    log "job $id: exit=$code ($status)"
}

log "ready; watching $JOBS"
while true; do
    date +%s > "$JOBS/.heartbeat" 2>/dev/null
    for dir in "$JOBS"/*/; do
        [ -f "$dir/job.json" ] || continue
        [ -f "$dir/result.json" ] && continue
        mkdir "$dir/.claimed" 2>/dev/null || continue
        run_job "${dir%/}"
    done
    sleep 1
done
