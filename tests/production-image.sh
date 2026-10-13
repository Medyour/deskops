#!/bin/bash
set -Eeuo pipefail
trap 'echo "Production scenario failed at line $LINENO" >&2' ERR

docker run --rm deskops-production-test php -r 'exit(!file_exists(".env") && !file_exists("bootstrap/cache/config.php") && file_exists("public/build/manifest.json") && !is_dir("vendor/pestphp") && PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 ? 0 : 1);'
if docker run --rm deskops-production-test sh .do/start-web.sh; then
    echo 'Web startup accepted an incomplete runtime configuration' >&2
    exit 1
fi
docker exec "$POSTGRES_ID" psql -U deskops -d deskops_test -v ON_ERROR_STOP=1 -c 'CREATE ROLE deskops_runtime LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION'
docker exec "$POSTGRES_ID" psql -U deskops -d deskops_test -v ON_ERROR_STOP=1 -c 'CREATE DATABASE deskops_production_test OWNER deskops_runtime'
network=$(docker inspect --format '{{range $key, $value := .NetworkSettings.Networks}}{{$key}}{{end}}' "$POSTGRES_ID")
runtime="$RUNNER_TEMP/deskops-production.env"
cat > "$runtime" <<'ENV'
APP_NAME=DeskOps
APP_ENV=production
APP_DEBUG=false
APP_DEMO_MODE=true
APP_URL=https://deskops.example
DB_CONNECTION=pgsql
DATABASE_URL=postgresql://deskops_runtime@postgres:5432/deskops_production_test?sslmode=disable
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
QUEUE_CONNECTION=sync
LOG_CHANNEL=stderr
LOG_LEVEL=warning
MAIL_MAILER=array
APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
TRUSTED_PROXIES=REMOTE_ADDR
ENV
docker run --rm --network "$network" --env-file "$runtime" deskops-production-test sh .do/pre-deploy.sh
APP_KEY=$(docker run --rm deskops-production-test php -r 'echo "base64:".base64_encode(random_bytes(32));')
echo "::add-mask::$APP_KEY"
export APP_KEY
web=$(docker run -d --network "$network" --env-file "$runtime" --env APP_KEY -p 127.0.0.1:18080:8080 deskops-production-test)
trap 'docker rm -f "$web" >/dev/null; rm -f "$runtime"' EXIT
status=''
for attempt in $(seq 1 30); do
    status=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/health || true)
    if [ "$status" = 204 ]; then break; fi
    sleep 1
done
test "$status" = 204
headers=(-H 'Host: deskops.example' -H 'X-Forwarded-Proto: https' -H 'X-Forwarded-Port: 443')
for role in administrator agent; do
    cookie_headers="$RUNNER_TEMP/$role.headers"
    page="$RUNNER_TEMP/$role.html"
    curl -fsS "${headers[@]}" -D "$cookie_headers" http://127.0.0.1:18080/login -o "$page"
    token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$page" | head -n 1)
    session=$(sed -n 's/^Set-Cookie: deskops-session=\([^;]*\).*/\1/ip' "$cookie_headers" | tr -d '\r')
    test -n "$token"
    test -n "$session"
    code=$(curl -sS "${headers[@]}" -H "Cookie: deskops-session=$session" -D "$cookie_headers" --data-urlencode "_token=$token" --data-urlencode "role=$role" -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/demo/login)
    test "$code" = 302
    grep -qi '^set-cookie: deskops-session=.*secure.*httponly.*samesite=lax' "$cookie_headers"
    session=$(sed -n 's/^Set-Cookie: deskops-session=\([^;]*\).*/\1/ip' "$cookie_headers" | tr -d '\r')
    curl -fsS "${headers[@]}" -H "Cookie: deskops-session=$session" http://127.0.0.1:18080/dashboard -o "$page"
    grep -q 'Tableau de bord' "$page"
    if [ "$role" = administrator ]; then
        grep -q 'Camille Martin' "$page"
        curl -fsS "${headers[@]}" -H "Cookie: deskops-session=$session" http://127.0.0.1:18080/tickets/create -o "$page"
        token=$(sed -n 's/.*name="_token" value="\([^"]*\)".*/\1/p' "$page" | head -n 1)
        code=$(curl -sS "${headers[@]}" -H "Cookie: deskops-session=$session" --data-urlencode "_token=$token" --data-urlencode 'title=Ticket fictif de validation' --data-urlencode 'description=Demande fictive de validation du reset quotidien.' --data-urlencode 'requester_name=Contact fictif' --data-urlencode 'requester_email=contact@deskops.example' -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/tickets)
        test "$code" = 302
    else
        grep -q 'Alex Bernard' "$page"
    fi
done
docker run --rm --network "$network" --env-file "$runtime" deskops-production-test sh .do/reset-demo.sh
docker run --rm --network "$network" --env-file "$runtime" deskops-production-test sh .do/reset-demo.sh
code=$(curl -sS "${headers[@]}" -H "Cookie: deskops-session=$session" -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/dashboard)
test "$code" = 302
docker exec "$web" php artisan deskops:production-check --web --ready --no-interaction
docker exec "$web" apache2ctl -t
docker pause "$POSTGRES_ID"
test "$(curl -fsS -o /dev/null -w '%{http_code}' http://127.0.0.1:18080/health)" = 204
docker unpause "$POSTGRES_ID"
if docker exec --env APP_KEY= "$web" sh .do/start-web.sh; then
    echo 'Web restart used a cached key instead of the runtime configuration' >&2
    exit 1
fi
