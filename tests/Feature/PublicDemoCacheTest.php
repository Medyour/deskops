<?php

use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Process;

test('demo entry uses the compiled configuration with compiled routes', function (bool $enabled) {
    $path = 'storage/framework/cache/deskops-cached-demo-'.bin2hex(random_bytes(8));
    $configurationCache = $path.'-config.php';
    $routesCache = $path.'-routes.php';
    $environment = [...$this->isolatedArtisanEnvironment($configurationCache, $routesCache), 'APP_DEMO_MODE' => $enabled ? 'true' : 'false'];
    try {
        foreach (['config:cache', 'route:cache'] as $command) {
            $result = Process::path(base_path())->env($environment)->timeout(60)->run([PHP_BINARY, 'artisan', $command, '--no-interaction']);
            expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
        }
        $cached = require base_path($configurationCache);
        expect($cached['app']['demo_mode'])->toBe($enabled);
        config(['app.demo_mode' => $cached['app']['demo_mode']]);
        require base_path($routesCache);
        $this->seed(DemoUserSeeder::class);
        if ($enabled) {
            $this->get(route('login'))->assertOk()->assertSee('Explorer comme administrateur');
            $this->post(route('demo.login'), ['role' => 'agent'])->assertRedirect(route('dashboard'));
            $this->assertAuthenticated();
        } else {
            $this->get(route('login'))->assertOk()->assertDontSee('Explorer comme');
            $this->post(route('demo.login'), ['role' => 'agent'])->assertNotFound();
            $this->get('/demo/login')->assertNotFound();
            $this->assertGuest();
        }
    } finally {
        foreach ([$configurationCache, $routesCache] as $file) {
            $file = base_path($file);
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
})->with([false, true]);
