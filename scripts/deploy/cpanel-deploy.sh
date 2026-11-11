#!/usr/bin/env bash
# Deploys a built zip of the app to Bluehost through the cPanel API (no SSH needed).
# Safe to re-run: each step checks what already exists.
#
# Needs: CPANEL_HOST, CPANEL_TOKEN, CPANEL_USER, SITE_DOMAIN (e.g. beta.axiom-maintenance.com),
#        APP_DIR (folder under the cPanel home, e.g. axiom-beta), BUILD_ZIP, LOGIN_FILE.
# Optional: ADMIN_EMAIL, DEMO_DATA (true|false).
set -euo pipefail

: "${CPANEL_HOST:?}" "${CPANEL_TOKEN:?}" "${CPANEL_USER:?}" "${SITE_DOMAIN:?}" "${APP_DIR:?}" "${BUILD_ZIP:?}" "${LOGIN_FILE:?}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@${SITE_DOMAIN#*.}}"
DEMO_DATA="${DEMO_DATA:-true}"

# Accept "box123.bluehost.com", "box123.bluehost.com:2083" or a full https:// URL.
host="${CPANEL_HOST#*://}"; host="${host%%/*}"
[[ "$host" == *:* ]] || host="$host:2083"
BASE="https://$host"
AUTH="Authorization: cpanel $CPANEL_USER:$CPANEL_TOKEN"
SUB="${SITE_DOMAIN%%.*}"
ROOT="${SITE_DOMAIN#*.}"

say() { echo "==> $*"; }
fail() { echo "::error::$*" >&2; exit 1; }
mask() { if [ -n "$1" ]; then echo "::add-mask::$1"; fi; }

# UAPI: uapi Module function [curl args...]; prints JSON, fails on API errors unless SOFT=1.
uapi() {
  local m=$1 f=$2; shift 2
  local out
  out=$(curl -sS --max-time 300 -H "$AUTH" "$BASE/execute/$m/$f" "$@") || fail "Could not reach cPanel at $host ($m::$f)."
  if [ "${SOFT:-0}" != 1 ] && [ "$(jq -r '.status // 0' <<<"$out" 2>/dev/null)" != 1 ]; then
    fail "cPanel $m::$f failed: $(jq -c '.errors // .' <<<"$out" 2>/dev/null || head -c 300 <<<"$out")"
  fi
  echo "$out"
}
# Older API 2 (used for extract, delete and cron, which UAPI lacks).
api2() {
  local m=$1 f=$2; shift 2
  local out
  out=$(curl -sS --max-time 300 -H "$AUTH" "$BASE/json-api/cpanel" \
    --data-urlencode "cpanel_jsonapi_user=$CPANEL_USER" -d cpanel_jsonapi_apiversion=2 \
    -d "cpanel_jsonapi_module=$m" -d "cpanel_jsonapi_func=$f" "$@") || fail "Could not reach cPanel ($m::$f)."
  if [ "$(jq -r '.cpanelresult.event.result // 0' <<<"$out" 2>/dev/null)" != 1 ]; then
    fail "cPanel $m::$f failed: $(jq -c '.cpanelresult.error // .' <<<"$out" 2>/dev/null || head -c 300 <<<"$out")"
  fi
  echo "$out"
}
envval() { sed -n "s/^$1=//p" <<<"$ENV_CONTENT" | tr -d '"\r' | head -n1; }
UA='AxiomMaintenanceDeploy/1.0 (+github-actions)'
rand() { openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c "${1:-32}"; }

say "Checking the cPanel login"
HOMEDIR=$(SOFT=1 uapi Variables get_user_information | jq -r '.data.home // empty')
HOMEDIR="${HOMEDIR:-/home/$CPANEL_USER}"
APP="$HOMEDIR/$APP_DIR"
domains=$(uapi DomainInfo list_domains)
jq -e --arg d "$ROOT" '[.data.main_domain] + .data.addon_domains + .data.parked_domains | index($d)' <<<"$domains" >/dev/null \
  || fail "$ROOT is not on this cPanel account. Add it under Domains in cPanel first."

if jq -e --arg d "$SITE_DOMAIN" '.data.sub_domains | index($d)' <<<"$domains" >/dev/null; then
  say "$SITE_DOMAIN already exists"
else
  say "Creating $SITE_DOMAIN (site folder $APP_DIR/public)"
  uapi SubDomain addsubdomain -G --data-urlencode "domain=$SUB" --data-urlencode "rootdomain=$ROOT" \
    --data-urlencode "dir=$APP_DIR/public" >/dev/null
fi

say "Choosing PHP"
versions=$(SOFT=1 uapi LangPHP php_get_installed_versions | jq -r '.data.versions[]?' || true)
PHPV=""
for v in ea-php84 ea-php83; do grep -qx "$v" <<<"$versions" && { PHPV=$v; break; }; done
if [ -n "$PHPV" ]; then
  SOFT=1 uapi LangPHP php_set_vhost_versions -G --data-urlencode "version=$PHPV" --data-urlencode "vhost=$SITE_DOMAIN" >/dev/null || true
  PHPBIN="/opt/cpanel/$PHPV/root/usr/bin/php"
  say "Using $PHPV"
else
  echo "::warning::Could not read PHP versions (found: ${versions:-none}). Set PHP 8.3+ for $SITE_DOMAIN in cPanel MultiPHP Manager."
  PHPBIN="/usr/local/bin/php"
fi

say "Uploading the build"
uapi Fileman upload_files -F "dir=$APP" -F overwrite=1 -F "file-1=@$BUILD_ZIP;filename=release.zip" >/dev/null
api2 Fileman fileop -d op=extract --data-urlencode "sourcefiles=$APP/release.zip" --data-urlencode "destfiles=$APP" >/dev/null
api2 Fileman fileop -d op=unlink --data-urlencode "sourcefiles=$APP/release.zip" >/dev/null

env_json=$(SOFT=1 uapi Fileman get_file_content -G --data-urlencode "dir=$APP" --data-urlencode "file=.env")
if [ "$(jq -r '.status' <<<"$env_json")" = 1 ]; then
  say "Settings file already there; keeping it"
  ENV_CONTENT=$(jq -r '.data.content' <<<"$env_json")
else
  say "First deploy: creating the database and settings file"
  prefix=$(SOFT=1 uapi Mysql get_restrictions | jq -r '.data.prefix // empty')
  prefix="${prefix:-${CPANEL_USER:0:8}_}"
  DB="${prefix}${APP_DIR//-/}"; DB="${DB:0:64}"
  DB_PASS="$(rand 28)Aa9"; mask "$DB_PASS"
  if uapi Mysql list_databases | jq -e --arg d "$DB" '.data[] | select(.database == $d)' >/dev/null; then
    say "Database $DB already exists"
  else
    uapi Mysql create_database -G --data-urlencode "name=$DB" >/dev/null
  fi
  if uapi Mysql list_users | jq -e --arg u "$DB" '.data[] | select(.user == $u)' >/dev/null; then
    uapi Mysql set_password -G --data-urlencode "user=$DB" --data-urlencode "password=$DB_PASS" >/dev/null
  else
    uapi Mysql create_user -G --data-urlencode "name=$DB" --data-urlencode "password=$DB_PASS" >/dev/null
  fi
  uapi Mysql set_privileges_on_database -G --data-urlencode "user=$DB" --data-urlencode "database=$DB" \
    --data-urlencode "privileges=ALL PRIVILEGES" >/dev/null

  APP_KEY="base64:$(openssl rand -base64 32)"; mask "$APP_KEY"
  DEPLOY_KEY=$(rand 48); mask "$DEPLOY_KEY"
  ADMIN_PASSWORD=$(rand 16); mask "$ADMIN_PASSWORD"
  INTAKE_KEY=$(rand 40); mask "$INTAKE_KEY"
  envfile=$(mktemp)
  sed -e "s|^APP_ENV=.*|APP_ENV=production|" -e "s|^APP_KEY=.*|APP_KEY=$APP_KEY|" -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
      -e "s|^APP_URL=.*|APP_URL=https://$SITE_DOMAIN|" -e "s|^LOG_STACK=.*|LOG_STACK=daily|" -e "s|^LOG_LEVEL=.*|LOG_LEVEL=info|" \
      -e "s|^DB_CONNECTION=.*|DB_CONNECTION=mysql|" -e "s|^# DB_HOST=.*|DB_HOST=localhost|" -e "s|^# DB_PORT=.*|DB_PORT=3306|" \
      -e "s|^# DB_DATABASE=.*|DB_DATABASE=$DB|" -e "s|^# DB_USERNAME=.*|DB_USERNAME=$DB|" -e "s|^# DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" \
      -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"no-reply@$ROOT\"|" \
      -e "s|^ADMIN_NAME=.*|ADMIN_NAME=Admin|" -e "s|^ADMIN_EMAIL=.*|ADMIN_EMAIL=$ADMIN_EMAIL|" -e "s|^ADMIN_PASSWORD=.*|ADMIN_PASSWORD=$ADMIN_PASSWORD|" \
      -e "s|^INTAKE_API_KEY=.*|INTAKE_API_KEY=$INTAKE_KEY|" \
      .env.example > "$envfile"
  printf '\n# --- Deploy ---\nDEPLOY_KEY=%s\nDEMO_DATA=%s\nDEMO_PASSWORD=%s\n' "$DEPLOY_KEY" "$DEMO_DATA" "$ADMIN_PASSWORD" >> "$envfile"
  uapi Fileman save_file_content --data-urlencode "dir=$APP" --data-urlencode "file=.env" --data-urlencode "content@$envfile" >/dev/null
  ENV_CONTENT=$(cat "$envfile")
  rm -f "$envfile"
fi

for k in APP_KEY DB_PASSWORD ADMIN_PASSWORD INTAKE_API_KEY; do mask "$(envval $k)"; done
DEPLOY_KEY=$(envval DEPLOY_KEY); mask "$DEPLOY_KEY"
[ -n "$DEPLOY_KEY" ] || fail "The .env on the server has no DEPLOY_KEY line."

say "Turning on HTTPS"
SOFT=1 uapi SSL start_autossl_check >/dev/null || true

say "Running database setup on the site"
ok=""
for i in $(seq 1 10); do
  for tls in "" "-k"; do
    res=$(curl -sS $tls --max-time 120 -X POST -A "$UA" -H "X-Deploy-Key: $DEPLOY_KEY" -H 'Accept: application/json' \
      -H 'Content-Type: application/json' -d '{"run":true}' \
      "https://$SITE_DOMAIN/_deploy/run" 2>/dev/null || true)
    if jq -e '.ok == true' <<<"$res" >/dev/null 2>&1; then
      ok=1; [ -n "$tls" ] && echo "::warning::HTTPS certificate for $SITE_DOMAIN isn't ready yet; it usually appears within an hour."
      echo "$res" | jq -c 'del(.migrate)'; break 2
    fi
  done
  echo "  site not answering yet (try $i/10): $(head -c 200 <<<"${res:-no response}")"
  sleep 30
done
[ -n "$ok" ] || fail "The site didn't answer the setup call. DNS for $SITE_DOMAIN may still be spreading; re-run this job in a few minutes."

if jq -e '.fresh_install == true' <<<"$res" >/dev/null; then
  {
    echo "Beta site: https://$SITE_DOMAIN/login"
    echo "Admin login: $(envval ADMIN_EMAIL)"
    echo "Password: $(envval ADMIN_PASSWORD)"
    [ "$(envval DEMO_DATA)" = true ] && echo "Demo logins (same password): scheduler@example.test, mike@example.test, dana@example.test, sam@example.test"
    echo "Intake key for the axiom-properties.com form: $(envval INTAKE_API_KEY)"
  } > "$LOGIN_FILE"
  say "Saved the login details as this run's beta-login download"
fi

say "Checking the every-minute cron job"
CRON_CMD="cd $APP && $PHPBIN artisan schedule:run >> /dev/null 2>&1"
if api2 Cron listcron | jq -e --arg a "cd $APP &&" '.cpanelresult.data[]? | select((.command // "") | startswith($a))' >/dev/null; then
  say "Cron job already set"
else
  api2 Cron add_line --data-urlencode "command=$CRON_CMD" -d minute='*' -d hour='*' -d day='*' -d month='*' -d weekday='*' >/dev/null
  say "Added cron job"
fi

code=$(curl -s -A "$UA" -o /dev/null -w '%{http_code}' --max-time 30 "https://$SITE_DOMAIN/up" || true)
say "Health check: https://$SITE_DOMAIN/up answered $code"
say "Done: https://$SITE_DOMAIN"
