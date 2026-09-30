<?php

use App\Support\DisposableDatabase;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

it('decides safety from both environment and resolved target without opening the database', function (string $environment, string $target, bool $allowed) {
    $original = app()->environment();
    app()->instance('env', $environment);
    config(['database.connections.safety_probe' => ['driver' => 'sqlite', 'database' => $target, 'prefix' => '']]);
    try {
        expect(DisposableDatabase::allowsDestructiveCommands('safety_probe'))->toBe($allowed);
    } finally {
        app()->instance('env', $original);
        DB::purge('safety_probe');
    }
})->with([
    'local development' => ['local', dirname(__DIR__, 3).'/database/database.sqlite', false],
    'testing memory' => ['testing', ':memory:', true],
    'testing absolute development' => ['testing', dirname(__DIR__, 3).'/database/database.sqlite', false],
    'testing relative development' => ['testing', 'database/database.sqlite', false],
    'testing dot segments' => ['testing', 'database/../database/database.sqlite', false],
    'local memory' => ['local', ':memory:', false],
    'unrecognized URI' => ['testing', 'file:database/database.sqlite?mode=rw', false],
]);

it('allows an explicitly disposable file but rejects a symlink to development', function () {
    $path = tempnam(sys_get_temp_dir(), 'career_toolkit_test_');
    config(['database.connections.safety_probe' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']]);
    try {
        expect(DisposableDatabase::allowsDestructiveCommands('safety_probe'))->toBeTrue();
        unlink($path);
        symlink(database_path('database.sqlite'), $path);
        expect(DisposableDatabase::allowsDestructiveCommands('safety_probe'))->toBeFalse();
    } finally {
        unlink($path);
        DB::purge('safety_probe');
    }
});

it('uses Laravel database URL resolution rather than the unparsed database setting', function () {
    config(['database.connections.safety_probe' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        'url' => 'sqlite://'.database_path('database.sqlite'),
    ]]);
    try {
        expect(DisposableDatabase::allowsDestructiveCommands('safety_probe'))->toBeFalse();
    } finally {
        DB::purge('safety_probe');
    }
});

it('prohibits an explicit unsafe command connection despite a safe default without executing the command', function () {
    config(['database.connections.safety_probe' => ['driver' => 'sqlite', 'database' => database_path('database.sqlite'), 'prefix' => '']]);
    $input = new ArrayInput(['--database' => 'safety_probe'], new InputDefinition([
        new InputOption('database', null, InputOption::VALUE_OPTIONAL),
    ]));
    try {
        Event::dispatch(new CommandStarting('migrate:fresh', $input, new NullOutput));
        $property = new ReflectionProperty(FreshCommand::class, 'prohibitedFromRunning');
        expect($property->getValue())->toBeTrue();
    } finally {
        DB::purge('safety_probe');
        DB::prohibitDestructiveCommands(false);
    }
});
