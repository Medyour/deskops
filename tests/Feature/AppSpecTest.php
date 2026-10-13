<?php

use Symfony\Component\Yaml\Yaml;

/** @return array<string, mixed> */
function digitalOceanSpec(): array
{
    return Yaml::parseFile(base_path('.do/app.yaml'));
}

test('app spec targets a private GitHub source in London with one fixed web instance and a development database', function () {
    $spec = digitalOceanSpec();
    expect($spec['name'])->toBe('deskops');
    expect($spec['region'])->toBe('lon');
    expect($spec['services'])->toHaveCount(1);
    expect($spec['databases'])->toBe([['name' => 'deskops-db', 'engine' => 'PG', 'version' => '18', 'production' => false]]);
    expect(array_keys($spec))->not->toContain('domains', 'workers', 'functions', 'envs', 'vpc');
    $web = $spec['services'][0];
    expect($web['http_port'])->toBe(8080);
    expect($web['health_check']['http_path'])->toBe('/health');
    expect($web['run_command'])->toBe('sh .do/start-web.sh');
    expect(array_keys($web))->not->toContain('autoscaling');
    foreach ([$web, ...$spec['jobs']] as $component) {
        expect($component['github'])->toBe(['repo' => 'Medyour/deskops', 'branch' => 'main', 'deploy_on_push' => false]);
        expect($component['dockerfile_path'])->toBe('.do/Dockerfile');
        expect($component['instance_size_slug'])->toBe('apps-s-1vcpu-0.5gb');
        expect($component['instance_count'])->toBe(1);
    }
});

test('all component variables are runtime only and contain bindings rather than credentials', function () {
    $spec = digitalOceanSpec();
    foreach ([$spec['services'][0], ...$spec['jobs']] as $component) {
        $variables = array_column($component['envs'], null, 'key');
        expect($variables)->not->toHaveKey('APP_KEY')->not->toHaveKey('DB_PASSWORD')->not->toHaveKey('DB_SSLROOTCERT');
        expect($variables['DATABASE_URL'])->toBe(['key' => 'DATABASE_URL', 'value' => '${deskops-db.DATABASE_PRIVATE_URL}', 'scope' => 'RUN_TIME', 'type' => 'SECRET']);
        expect($variables['APP_URL']['value'])->toBe('${APP_URL}');
        foreach (['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_DEMO_MODE' => 'true', 'DB_CONNECTION' => 'pgsql', 'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr', 'MAIL_MAILER' => 'array', 'SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax', 'APP_LOCALE' => 'fr', 'TRUSTED_PROXIES' => 'REMOTE_ADDR'] as $key => $value) {
            expect($variables[$key]['value'])->toBe($value);
        }
        expect(array_unique(array_column($component['envs'], 'scope')))->toBe(['RUN_TIME']);
        expect(array_filter($component['envs'], fn (array $variable): bool => $variable['type'] === 'SECRET'))->toHaveCount(1);
    }
});

test('deployment and scheduled jobs fail closed and reset only the exclusive demo perimeter', function () {
    $spec = digitalOceanSpec();
    expect($spec['jobs'])->toHaveCount(2);
    $jobs = array_column($spec['jobs'], null, 'name');
    expect($jobs['prepare-demo']['kind'])->toBe('PRE_DEPLOY');
    expect($jobs['prepare-demo']['run_command'])->toBe('sh .do/pre-deploy.sh');
    expect($jobs['reset-demo']['kind'])->toBe('SCHEDULED');
    expect($jobs['reset-demo']['schedule'])->toBe(['cron' => '0 4 * * *', 'time_zone' => 'Europe/Paris']);
    expect($jobs['reset-demo']['run_command'])->toBe('sh .do/reset-demo.sh');
    foreach (['pre-deploy.sh', 'reset-demo.sh', 'start-web.sh'] as $script) {
        $source = file_get_contents(base_path('.do/'.$script));
        expect($source)->toContain('set -eu')->not->toContain('|| true', 'migrate:fresh', 'migrate:reset', 'key:generate');
    }
    $deployment = file_get_contents(base_path('.do/pre-deploy.sh'));
    expect($deployment)->toContain("production-check --database --no-interaction\nphp artisan migrate --force --no-interaction\nphp artisan deskops:demo-reset --force --exclusive --no-interaction");
    expect(file_get_contents(base_path('.do/reset-demo.sh')))->toContain('demo-reset --force --exclusive')->not->toContain('migrate ');
    expect(file_get_contents(base_path('.do/start-web.sh')))->toContain('production-check --web', 'config:cache', 'exec apache2-foreground');
});

test('production image excludes local artifacts pins official images and leaves configuration uncached at build time', function () {
    $docker = file_get_contents(base_path('.do/Dockerfile'));
    preg_match_all('/^FROM (\\S+)(?: AS \\S+)?$/m', $docker, $images);
    foreach ($images[1] as $image) {
        if ($image !== 'application') {
            expect($image)->toMatch('/@sha256:[a-f0-9]{64}$/');
        }
    }
    expect($docker)->toContain('docker-php-ext-install -j2 pdo_pgsql pdo_sqlite', 'EXPOSE 8080');
    expect($docker)->not->toMatch('/(?:ARG|ENV)\\s+(?:APP_KEY|DATABASE_URL)/');
    $build = file_get_contents(base_path('.do/build-production.sh'));
    expect($build)->toContain('npm ci --include=dev --ignore-scripts', 'composer install --no-dev', 'composer audit --locked', 'npm audit', 'route:cache', 'event:cache', 'view:cache');
    expect($build)->not->toContain('config:cache', 'key:generate', 'migrate ');
    $ignore = file_get_contents(base_path('.dockerignore'));
    expect($ignore)->toContain('.env', 'database/*.sqlite*', 'bootstrap/cache/*', 'vendor', 'node_modules');
    expect(file_get_contents(base_path('.do/apache.conf')))->toContain('DocumentRoot /var/www/html/public', 'Options -Indexes')->not->toContain('%r', '%q', '%U');
});
