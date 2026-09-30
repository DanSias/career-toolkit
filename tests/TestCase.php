<?php

namespace Tests;

use App\Support\DisposableDatabase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        // Never load local credentials for automated tests.
        $app->useEnvironmentPath(__DIR__.'/Support');
        $app->loadEnvironmentFrom('.env.testing');
        if ($app->configurationIsCached()) {
            throw new RuntimeException('Tests require uncached, isolated database configuration.');
        }
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();
        if (! DisposableDatabase::allowsDestructiveCommands()) {
            throw new RuntimeException('Refusing tests against a non-disposable database.');
        }
        Http::preventStrayRequests();
        // UI feature tests assert Laravel payloads, never call a running SSR server.
        config(['inertia.ssr.enabled' => false]);

        return $app;
    }
}
