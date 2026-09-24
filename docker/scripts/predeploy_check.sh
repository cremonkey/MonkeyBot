#!/usr/bin/env bash
# Pre-deploy check for MonkeyBot (see docs/plans/2026-09-24-remaining-work.md, task B4).
#
# Usage:
#   docker/scripts/predeploy_check.sh <ref> [<base>] [--db]
#
#   <ref>   Git ref to check (branch, tag or commit). Required.
#   <base>  Git ref to diff against for "changed files". Default: the production
#           branch `chore/commit-live-work-20260922` if it exists locally, else
#           `release/9.x-integration`.
#   --db    Opt-in: also apply new migrations twice against a throwaway MariaDB
#           container to check they are idempotent. OFF by default because this
#           host runs other clients' production containers and memory is tight
#           (see docs/plans/2026-09-24-remaining-work.md §2 rule 9). When you do
#           pass --db, run `free -g` first and require >= 3 GB available.
#
# Checks performed:
#   1. php -l on every *.php file that changed between <base> and <ref>.
#   2. All dependency-free unit suites (tests/run.php, tests/*/run.php, except
#      tests/conversations) via the monkeybot-app-ojzrhf-app:latest image.
#   3. Static migration safety check on every new migration file (added vs
#      <base>): starts with `DO 0;`, no `;` inside `--` comment lines, CREATE/
#      ALTER statements use IF NOT EXISTS, no DROP / TRUNCATE / DELETE FROM /
#      destructive MODIFY.
#   4. No file added under application/config/ (that dir is a Docker volume in
#      production; new files there never reach it).
#   5. CRLF-regression check: any changed file whose CR count went from >0 to 0
#      or from 0 to >0 (legacy CRLF files must keep their line endings).
#   6. (--db only) Each new migration applied twice on a throwaway DB.
#
# Exits non-zero if any check fails. Makes no deploy, push or merge changes.

set -u
IMAGE="monkeybot-app-ojzrhf-app:latest"

usage() {
  echo "Usage: $0 <ref> [<base>] [--db]" >&2
  exit 2
}

REF=""
BASE=""
USE_DB=0
for arg in "$@"; do
  case "$arg" in
    --db) USE_DB=1 ;;
    *)
      if [ -z "$REF" ]; then REF="$arg";
      elif [ -z "$BASE" ]; then BASE="$arg";
      else usage; fi
      ;;
  esac
done
[ -z "$REF" ] && usage

REPO_ROOT="$(git rev-parse --show-toplevel)"
cd "$REPO_ROOT" || exit 2

if [ -z "$BASE" ]; then
  if git rev-parse --verify --quiet chore/commit-live-work-20260922 >/dev/null; then
    BASE="chore/commit-live-work-20260922"
  else
    BASE="release/9.x-integration"
  fi
fi

if ! git rev-parse --verify --quiet "$REF" >/dev/null; then
  echo "predeploy_check: unknown ref '$REF'" >&2
  exit 2
fi
if ! git rev-parse --verify --quiet "$BASE" >/dev/null; then
  echo "predeploy_check: unknown base '$BASE'" >&2
  exit 2
fi

REF_SHA="$(git rev-parse "$REF")"
BASE_SHA="$(git rev-parse "$BASE")"

TMPDIR="$(mktemp -d /tmp/predeploy-check.XXXXXX)"
cleanup() {
  cd "$REPO_ROOT" 2>/dev/null || true
  git worktree remove --force "$TMPDIR" >/dev/null 2>&1
  rm -rf "$TMPDIR" 2>/dev/null
}
trap cleanup EXIT

echo "predeploy_check: ref=$REF ($REF_SHA)  base=$BASE ($BASE_SHA)"
echo "predeploy_check: temp worktree at $TMPDIR"
if ! git worktree add --detach --quiet "$TMPDIR" "$REF_SHA" >/dev/null 2>&1; then
  echo "predeploy_check: failed to create temp worktree" >&2
  exit 2
fi

declare -A RESULT
declare -A DETAIL
FAIL=0

record() {
  local name="$1" status="$2" detail="$3"
  RESULT["$name"]="$status"
  DETAIL["$name"]="$detail"
  [ "$status" = "FAIL" ] && FAIL=1
}

# List of files that changed between base and ref, in the ref's tree.
mapfile -t CHANGED_FILES < <(git diff --name-only "$BASE_SHA" "$REF_SHA" -- .)
mapfile -t ADDED_FILES < <(git diff --name-status "$BASE_SHA" "$REF_SHA" -- . | awk '$1=="A"{print $2}')

# --- 1. php -l on changed PHP files -----------------------------------------
PHP_CHANGED=()
for f in "${CHANGED_FILES[@]}"; do
  case "$f" in *.php) [ -f "$TMPDIR/$f" ] && PHP_CHANGED+=("$f") ;; esac
done

if [ "${#PHP_CHANGED[@]}" -eq 0 ]; then
  record "php-lint" "PASS" "no changed PHP files"
else
  LINT_FAILS=()
  for f in "${PHP_CHANGED[@]}"; do
    out="$(docker run --rm -v "$TMPDIR:/app" -w /app "$IMAGE" php -l "$f" 2>&1)"
    if [ $? -ne 0 ]; then
      LINT_FAILS+=("$f: $out")
    fi
  done
  if [ "${#LINT_FAILS[@]}" -eq 0 ]; then
    record "php-lint" "PASS" "${#PHP_CHANGED[@]} file(s) linted"
  else
    record "php-lint" "FAIL" "$(printf '%s; ' "${LINT_FAILS[@]}")"
  fi
fi

# --- 2. Unit suites ----------------------------------------------------------
SUITE_FAILS=()
SUITE_COUNT=0
while IFS= read -r -d '' suite; do
  case "$suite" in
    */tests/conversations/*) continue ;;
  esac
  SUITE_COUNT=$((SUITE_COUNT + 1))
  rel="${suite#$TMPDIR/}"
  out="$(docker run --rm -v "$TMPDIR:/app" -w /app "$IMAGE" php "$rel" 2>&1)"
  rc=$?
  last_line="$(echo "$out" | tail -1)"
  # A suite fails on non-zero exit, or a summary line reporting a non-zero
  # failed count (e.g. "12 passed, 2 failed").
  if [ $rc -ne 0 ] || echo "$out" | grep -qiE '[1-9][0-9]* failed'; then
    SUITE_FAILS+=("$rel: $last_line")
  fi
done < <(find "$TMPDIR/tests" -maxdepth 2 -name 'run.php' -print0 2>/dev/null | sort -z)

if [ "$SUITE_COUNT" -eq 0 ]; then
  record "unit-suites" "FAIL" "no test suites found"
elif [ "${#SUITE_FAILS[@]}" -eq 0 ]; then
  record "unit-suites" "PASS" "$SUITE_COUNT suite(s) passed"
else
  record "unit-suites" "FAIL" "$(printf '%s; ' "${SUITE_FAILS[@]}")"
fi

# --- 3. Static migration safety check ----------------------------------------
NEW_MIGRATIONS=()
for f in "${ADDED_FILES[@]}"; do
  case "$f" in
    assets/backup_db/migrations/*.sql) [ -f "$TMPDIR/$f" ] && NEW_MIGRATIONS+=("$f") ;;
  esac
done

if [ "${#NEW_MIGRATIONS[@]}" -eq 0 ]; then
  record "migration-safety" "PASS" "no new migrations"
else
  MIG_FAILS=()
  for f in "${NEW_MIGRATIONS[@]}"; do
    path="$TMPDIR/$f"
    issues=()

    # Must start with DO 0; (first non-blank, non-comment-only line ignored;
    # scan the first statement).
    first_stmt="$(grep -v '^\s*--' "$path" | grep -v '^\s*$' | head -1)"
    if ! echo "$first_stmt" | grep -qE '^\s*DO\s+0\s*;'; then
      issues+=("does not start with 'DO 0;'")
    fi

    # The migration runner (application/controllers/Cron_hub.php) splits
    # statements on `;` immediately followed by a newline, so only a ';' at
    # the end of a '--' comment line (optionally trailing whitespace) is
    # dangerous — a ';' in the middle of a comment sentence is harmless.
    if grep -nE '^\s*--.*;\s*$' "$path" >/dev/null; then
      issues+=("';' at end of a -- comment line (would split the migration)")
    fi

    # CREATE/ALTER must use IF NOT EXISTS.
    while IFS= read -r line; do
      stripped="$(echo "$line" | sed 's/--.*$//')"
      if echo "$stripped" | grep -qiE '\bCREATE\s+TABLE\b' && ! echo "$stripped" | grep -qiE 'IF\s+NOT\s+EXISTS'; then
        issues+=("CREATE TABLE without IF NOT EXISTS: $line")
      fi
      if echo "$stripped" | grep -qiE '\bALTER\s+TABLE\b.*\b(ADD\s+COLUMN|ADD\s+INDEX|ADD\s+KEY)\b' && ! echo "$stripped" | grep -qiE 'IF\s+NOT\s+EXISTS'; then
        issues+=("ALTER TABLE ADD without IF NOT EXISTS: $line")
      fi
    done < "$path"

    # No destructive statements.
    if grep -niE '\b(DROP\s+TABLE|DROP\s+COLUMN|TRUNCATE|DELETE\s+FROM)\b' "$path" | grep -v '^\s*--' >/dev/null; then
      issues+=("contains DROP/TRUNCATE/DELETE FROM")
    fi
    if grep -niE '\bMODIFY\b' "$path" | grep -v '^\s*--' >/dev/null; then
      issues+=("contains MODIFY (review for destructiveness)")
    fi

    if [ "${#issues[@]}" -gt 0 ]; then
      MIG_FAILS+=("$f: $(printf '%s | ' "${issues[@]}")")
    fi
  done
  if [ "${#MIG_FAILS[@]}" -eq 0 ]; then
    record "migration-safety" "PASS" "${#NEW_MIGRATIONS[@]} new migration(s) checked"
  else
    record "migration-safety" "FAIL" "$(printf '%s; ' "${MIG_FAILS[@]}")"
  fi
fi

# --- 4. No new files under application/config/ -------------------------------
CONFIG_ADDS=()
for f in "${ADDED_FILES[@]}"; do
  case "$f" in application/config/*) CONFIG_ADDS+=("$f") ;; esac
done
if [ "${#CONFIG_ADDS[@]}" -eq 0 ]; then
  record "config-volume" "PASS" "no files added under application/config/"
else
  record "config-volume" "FAIL" "$(printf '%s; ' "${CONFIG_ADDS[@]}")"
fi

# --- 5. CRLF regression check -------------------------------------------------
CRLF_REGRESSIONS=()
for f in "${CHANGED_FILES[@]}"; do
  [ -f "$TMPDIR/$f" ] || continue
  new_cr=$(tr -cd '\r' < "$TMPDIR/$f" 2>/dev/null | wc -c)
  old_content="$(git show "$BASE_SHA:$f" 2>/dev/null)"
  if [ $? -ne 0 ]; then
    continue # file didn't exist in base; nothing to regress from
  fi
  old_cr=$(git show "$BASE_SHA:$f" 2>/dev/null | tr -cd '\r' | wc -c)
  if { [ "$old_cr" -gt 0 ] && [ "$new_cr" -eq 0 ]; } || { [ "$old_cr" -eq 0 ] && [ "$new_cr" -gt 0 ]; }; then
    CRLF_REGRESSIONS+=("$f: CR count $old_cr -> $new_cr")
  fi
done
if [ "${#CRLF_REGRESSIONS[@]}" -eq 0 ]; then
  record "crlf-regression" "PASS" "no line-ending changes"
else
  record "crlf-regression" "FAIL" "$(printf '%s; ' "${CRLF_REGRESSIONS[@]}")"
fi

# --- 6. Optional: migrations run twice on a throwaway DB ---------------------
if [ "$USE_DB" -eq 1 ]; then
  if [ "${#NEW_MIGRATIONS[@]}" -eq 0 ]; then
    record "migration-db-idempotency" "PASS" "no new migrations to apply"
  else
    avail_gb=$(free -g | awk '/^Mem:/{print $7}')
    if [ -z "$avail_gb" ] || [ "$avail_gb" -lt 3 ]; then
      record "migration-db-idempotency" "FAIL" "< 3 GB available memory (free -g), refusing to start a DB container"
    else
      DB_NAME="predeploy-check-db-$$"
      DB_PASS="predeploy_check_$$"
      docker run -d --rm --name "$DB_NAME" \
        -e MYSQL_ROOT_PASSWORD="$DB_PASS" -e MYSQL_DATABASE=monkeybot \
        mariadb:10.11 >/dev/null 2>&1
      db_cleanup() { docker rm -f "$DB_NAME" >/dev/null 2>&1; }
      trap 'db_cleanup; cleanup' EXIT

      ready=0
      for _ in $(seq 1 30); do
        if docker exec "$DB_NAME" sh -c "mariadb -uroot -p\"$DB_PASS\" -e 'SELECT 1' monkeybot" >/dev/null 2>&1; then
          ready=1; break
        fi
        sleep 2
      done

      if [ "$ready" -ne 1 ]; then
        record "migration-db-idempotency" "FAIL" "throwaway DB did not become ready"
      else
        docker exec -i "$DB_NAME" sh -c "mariadb -uroot -p\"$DB_PASS\" monkeybot" < "$TMPDIR/assets/backup_db/initial_db.sql" >/dev/null 2>&1
        DB_FAILS=()
        for pass in 1 2; do
          for f in "${NEW_MIGRATIONS[@]}"; do
            if ! docker exec -i "$DB_NAME" sh -c "mariadb -uroot -p\"$DB_PASS\" monkeybot" < "$TMPDIR/$f" >/tmp/predeploy-mig-$$.log 2>&1; then
              DB_FAILS+=("$f (pass $pass): $(tail -3 /tmp/predeploy-mig-$$.log | tr '\n' ' ')")
            fi
            rm -f /tmp/predeploy-mig-$$.log
          done
        done
        if [ "${#DB_FAILS[@]}" -eq 0 ]; then
          record "migration-db-idempotency" "PASS" "${#NEW_MIGRATIONS[@]} migration(s) applied twice cleanly"
        else
          record "migration-db-idempotency" "FAIL" "$(printf '%s; ' "${DB_FAILS[@]}")"
        fi
      fi
      db_cleanup
      trap cleanup EXIT
    fi
  fi
else
  record "migration-db-idempotency" "SKIP" "pass --db to enable (off by default, host memory is shared)"
fi

# --- Summary -------------------------------------------------------------------
echo
printf '%-28s %-6s %s\n' "CHECK" "STATUS" "DETAIL"
printf '%-28s %-6s %s\n' "-----" "------" "------"
for name in php-lint unit-suites migration-safety config-volume crlf-regression migration-db-idempotency; do
  printf '%-28s %-6s %s\n' "$name" "${RESULT[$name]}" "${DETAIL[$name]}"
done
echo

if [ "$FAIL" -ne 0 ]; then
  echo "predeploy_check: FAIL"
  exit 1
else
  echo "predeploy_check: PASS"
  exit 0
fi
