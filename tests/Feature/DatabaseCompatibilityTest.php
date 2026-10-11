<?php

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('runtime PostgreSQL URLs resolve through Laravel without a connection or credentials in source', function (string $scheme) {
    config(['database.connections.runtime_postgresql' => [
        ...config('database.connections.pgsql'),
        'url' => $scheme.'://runtime_user@database.invalid:5432/deskops_test?sslmode=require',
    ]]);

    try {
        $configuration = DB::connection('runtime_postgresql')->getConfig();
        expect($configuration)->toMatchArray([
            'driver' => 'pgsql', 'host' => 'database.invalid', 'port' => 5432,
            'database' => 'deskops_test', 'username' => 'runtime_user', 'password' => '',
            'sslmode' => 'require', 'timezone' => 'UTC', 'isolation_level' => 'READ COMMITTED',
        ]);
    } finally {
        DB::purge('runtime_postgresql');
    }
})->with(['postgres', 'postgresql']);

test('PostgreSQL without an explicit TLS configuration does not impose certificate verification', function () {
    $variables = ['DB_SSLMODE', 'DB_SSLROOTCERT'];
    $environment = $_ENV;
    $server = $_SERVER;
    $process = [];
    foreach ($variables as $variable) {
        $process[$variable] = getenv($variable);
        putenv($variable);
        unset($_ENV[$variable], $_SERVER[$variable]);
    }

    try {
        $configuration = (require config_path('database.php'))['connections']['pgsql'];
        config(['database.connections.runtime_postgresql' => [
            ...$configuration, 'url' => 'postgresql://runtime_user@database.invalid/deskops_test',
        ]]);

        expect(DB::connection('runtime_postgresql')->getConfig('sslmode'))->toBeNull();
        expect(DB::connection('runtime_postgresql')->getConfig('sslrootcert'))->toBeNull();
    } finally {
        $_ENV = $environment;
        $_SERVER = $server;
        foreach ($process as $variable => $value) {
            putenv($value === false ? $variable : $variable.'='.$value);
        }
        DB::purge('runtime_postgresql');
    }
});

test('runtime URL parameters override TLS and session defaults without altering the supplied URL', function (string $sslMode, ?string $rootCertificate) {
    $url = 'postgresql://runtime_user@database.invalid:5432/deskops_test?sslmode='.$sslMode
        .'&timezone=UTC&isolation_level=READ%20COMMITTED&application_name=deskops-tests&search_path=public';
    if ($rootCertificate !== null) {
        $url .= '&sslrootcert='.$rootCertificate;
    }
    config(['database.connections.runtime_postgresql' => [
        ...config('database.connections.pgsql'), 'url' => $url,
        'sslmode' => 'verify-full', 'sslrootcert' => null,
        'timezone' => 'Europe/Paris', 'isolation_level' => 'SERIALIZABLE',
    ]]);

    try {
        expect(DB::connection('runtime_postgresql')->getConfig())->toMatchArray([
            'sslmode' => $sslMode, 'sslrootcert' => $rootCertificate,
            'timezone' => 'UTC', 'isolation_level' => 'READ COMMITTED',
            'application_name' => 'deskops-tests', 'search_path' => 'public',
        ]);
        expect(config('database.connections.runtime_postgresql.url'))->toBe($url);
    } finally {
        DB::purge('runtime_postgresql');
    }
})->with([
    'encrypted development connection' => ['require', null],
    'isolated CI without TLS' => ['disable', null],
    'managed connection with explicit system trust store' => ['verify-full', 'system'],
]);

test('the public environment example includes no database credentials key or certificate path', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toMatch('/^APP_KEY=\r?$/m')->not->toMatch('/^(?:DATABASE_URL|DB_URL|DB_PASSWORD|DB_SSLROOTCERT)=.+$/m');
});

test('database constraints reject invalid comments before any history is recorded', function (array $invalid) {
    $actor = User::factory()->create();
    $ticket = Ticket::factory()->create(['created_by' => $actor->id]);

    expect(fn () => DB::table('comments')->insert([
        'ticket_id' => $ticket->id, 'author_id' => $actor->id, 'body' => 'Commentaire fictif valide',
        'created_at' => '2026-01-15 12:00:00', ...$invalid,
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([
    'empty' => [['body' => '']], 'blank' => [['body' => '   ']],
    'too long' => [['body' => str_repeat('a', 5001)]],
    'missing author' => [['author_id' => 999999]], 'missing ticket' => [['ticket_id' => 999999]],
]);
