<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\StreamHandler;

beforeEach(function () {
    config(['testing.original_database' => config('database.default')]);
    $this->app->detectEnvironment(fn (): string => 'production');
    config([
        'app.debug' => false, 'app.demo_mode' => true, 'app.url' => 'https://deskops.example',
        'database.default' => 'pgsql',
        'database.connections.pgsql.url' => config('database.connections.pgsql.url') ?: 'postgresql://database.invalid/deskops_test?sslmode=disable',
        'cache.default' => 'database', 'session.driver' => 'database', 'session.secure' => true,
        'session.http_only' => true, 'session.same_site' => 'lax', 'queue.default' => 'sync',
        'logging.default' => 'stderr', 'mail.default' => 'array', 'app.locale' => 'fr',
        'trustedproxy.proxies' => 'REMOTE_ADDR', 'app.key' => null,
    ]);
});

afterEach(function () {
    config(['database.default' => config('testing.original_database')]);
});

test('production jobs pass configuration checks without an encryption key but web startup refuses it', function () {
    $this->artisan('deskops:production-check')->assertSuccessful();
    $this->artisan('deskops:production-check', ['--web' => true])->expectsOutput('Configuration de production invalide : APP_KEY.')->assertFailed();
    config(['app.key' => 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'))]);
    $this->artisan('deskops:production-check', ['--web' => true])->assertSuccessful();
});

test('production checks reject unsafe configuration without disclosing its value', function (string $key, mixed $value, string $label) {
    if ($key === 'app.env') {
        $this->app->detectEnvironment(fn (): string => 'local');
    } else {
        config([$key => $value]);
    }
    expect(Artisan::call('deskops:production-check'))->toBe(1);
    expect(Artisan::output())->toContain($label);
})->with([
    ['app.env', 'local', 'APP_ENV'], ['app.debug', true, 'APP_DEBUG'],
    ['app.demo_mode', false, 'APP_DEMO_MODE'], ['app.url', 'http://deskops.example', 'APP_URL'],
    ['database.default', 'sqlite', 'DB_CONNECTION'], ['database.connections.pgsql.url', null, 'DATABASE_URL'],
    ['cache.default', 'file', 'CACHE_STORE'], ['session.driver', 'file', 'SESSION_DRIVER'],
    ['session.secure', false, 'SESSION_SECURE_COOKIE'], ['session.http_only', false, 'SESSION_HTTP_ONLY'],
    ['session.same_site', 'none', 'SESSION_SAME_SITE'], ['queue.default', 'database', 'QUEUE_CONNECTION'],
    ['logging.default', 'stack', 'LOG_CHANNEL'], ['mail.default', 'smtp', 'MAIL_MAILER'],
    ['app.locale', 'en', 'APP_LOCALE'], ['trustedproxy.proxies', '*', 'TRUSTED_PROXIES'],
]);

test('health returns an empty public 204 without any database access or cookie even in production without a key', function (string $method) {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $response = $this->call($method, '/health');
    $response->assertNoContent()->assertHeaderMissing('Set-Cookie');
    expect($queries)->toBeEmpty();
    expect(Route::getRoutes()->getByName('health')->gatherMiddleware())->not->toContain('web', 'auth');
})->with(['GET', 'HEAD']);

test('only the actual ingress proxy is trusted and forwarded host is ignored', function () {
    Route::get('/proxy-check', fn () => response()->json([
        'secure' => request()->secure(), 'host' => request()->getHost(), 'port' => request()->getPort(), 'ip' => request()->ip(),
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.7'])->withHeaders([
        'Host' => 'deskops.example', 'X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443',
        'X-Forwarded-For' => '203.0.113.5', 'X-Forwarded-Host' => 'external.invalid',
    ])->get('http://deskops.example/proxy-check')->assertOk()->assertExactJson(['secure' => true, 'host' => 'deskops.example', 'port' => 443, 'ip' => '203.0.113.5']);
});

test('production session cookies are secure HTTP only and SameSite lax', function () {
    config(['session.driver' => 'array', 'app.key' => 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'))]);
    $response = $this->get('/login')->assertOk();
    $cookies = array_filter($response->headers->getCookies(), fn ($cookie): bool => $cookie->getName() === config('session.cookie'));
    expect($cookies)->toHaveCount(1);
    $cookie = array_values($cookies)[0];
    expect($cookie->isSecure())->toBeTrue();
    expect($cookie->isHttpOnly())->toBeTrue();
    expect($cookie->getSameSite())->toBe('lax');
});

test('stderr logging removes connection URLs from messages nested context and exceptions', function () {
    $stream = fopen('php://memory', 'w+');
    $logger = app('log')->channel('stderr')->getLogger();
    $logger->setHandlers([new StreamHandler($stream)]);
    $url = 'postgresql://fictitious:'.generatedTestPassword().'@database.invalid/demo';
    $logger->error('Connection failed {url}', ['url' => $url, 'nested' => ['url' => $url], 'exception' => new RuntimeException('Failure '.$url)]);
    rewind($stream);
    $output = stream_get_contents($stream);
    expect($output)->not->toContain($url)->not->toContain('postgresql://')->toContain('[connection URL redacted]');
    fclose($stream);
});
