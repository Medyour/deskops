<?php

namespace App\Actions\Demo;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

class ProductionReadiness
{
    public function check(bool $web = false, bool $database = false, bool $ready = false): void
    {
        $url = config('database.connections.pgsql.url');
        $checks = [
            'APP_ENV' => app()->environment('production'),
            'APP_DEBUG' => config('app.debug') === false,
            'APP_DEMO_MODE' => config('app.demo_mode') === true,
            'APP_URL' => filter_var(config('app.url'), FILTER_VALIDATE_URL) !== false && parse_url(config('app.url'), PHP_URL_SCHEME) === 'https',
            'DB_CONNECTION' => config('database.default') === 'pgsql',
            'DATABASE_URL' => is_string($url) && in_array(parse_url($url, PHP_URL_SCHEME), ['postgres', 'postgresql'], true) && parse_url($url, PHP_URL_HOST) !== null,
            'CACHE_STORE' => config('cache.default') === 'database',
            'SESSION_DRIVER' => config('session.driver') === 'database',
            'SESSION_SECURE_COOKIE' => config('session.secure') === true,
            'SESSION_HTTP_ONLY' => config('session.http_only') === true,
            'SESSION_SAME_SITE' => config('session.same_site') === 'lax',
            'QUEUE_CONNECTION' => config('queue.default') === 'sync',
            'LOG_CHANNEL' => config('logging.default') === 'stderr',
            'MAIL_MAILER' => config('mail.default') === 'array',
            'APP_LOCALE' => config('app.locale') === 'fr',
            'TRUSTED_PROXIES' => config('trustedproxy.proxies') === 'REMOTE_ADDR',
        ];
        if ($web) {
            $key = config('app.key');
            $key = is_string($key) && str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
            $checks['APP_KEY'] = is_string($key) && Encrypter::supported($key, config('app.cipher'));
        }
        $invalid = array_keys(array_filter($checks, fn (bool $valid): bool => ! $valid));
        if ($invalid !== []) {
            throw new LogicException('Configuration de production invalide : '.implode(', ', $invalid).'.');
        }
        if ($database || $ready) {
            $this->checkDatabase($ready);
        }
    }

    private function checkDatabase(bool $ready): void
    {
        foreach (['users' => 'demo_identity', 'tickets' => 'is_demo'] as $table => $marker) {
            if (! Schema::hasTable($table)) {
                if ($ready) {
                    throw new LogicException('La base de démonstration n’est pas initialisée.');
                }

                continue;
            }
            $records = DB::table($table);
            if (Schema::hasColumn($table, $marker)) {
                $records = $table === 'users' ? $records->whereNull($marker) : $records->where($marker, false);
            }
            if ($records->exists()) {
                throw new LogicException('Cette opération exige une base exclusivement dédiée à la démonstration.');
            }
        }
        if (! $ready) {
            return;
        }
        foreach (['sessions' => ['user_id', 'payload', 'last_activity'], 'cache' => ['key', 'value', 'expiration'], 'cache_locks' => ['key', 'owner', 'expiration'], 'demo_state' => ['generation', 'fixture']] as $table => $columns) {
            if (! Schema::hasColumns($table, $columns)) {
                throw new LogicException('Les tables de fonctionnement de la démonstration sont incomplètes.');
            }
        }
        if (DB::table('users')->count() !== 3 || DB::table('tickets')->count() !== 12
            || DB::table('comments')->count() !== 24 || DB::table('ticket_activities')->count() !== 60
            || DB::table('demo_state')->where('id', 1)->value('fixture') === null) {
            throw new LogicException('La fixture de démonstration est incomplète.');
        }
    }
}
