<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;

beforeEach(function () {
    $this->parentPath = sys_get_temp_dir().'/larafast-installer-'.uniqid();
    File::ensureDirectoryExists($this->parentPath);

    $this->projectPath = $this->parentPath.'/demo-app';
    $this->composerJson = ['require' => ['laravel/framework' => '^13.0'], 'require-dev' => []];
    $this->withAiConfig = false;

    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command) ? $process->command : [$process->command];

        if (($command[0] ?? null) === 'git' && ($command[1] ?? null) === 'clone') {
            File::ensureDirectoryExists($this->projectPath.'/.git');
            File::ensureDirectoryExists($this->projectPath.'/config');
            File::put($this->projectPath.'/.env.example', implode(PHP_EOL, [
                'APP_NAME=Larafast',
                'APP_URL=http://localhost',
                'DB_CONNECTION=sqlite',
                '# DB_HOST=127.0.0.1',
                '# DB_PORT=3306',
                '# DB_DATABASE=larafast',
                '# DB_USERNAME=root',
                '# DB_PASSWORD=',
                '# ANTHROPIC_API_KEY=',
            ]).PHP_EOL);
            File::put($this->projectPath.'/composer.json', json_encode($this->composerJson));

            if ($this->withAiConfig) {
                File::put($this->projectPath.'/config/ai.php', "<?php return ['default' => 'openai', 'default_for_images' => 'gemini'];");
            }
        }

        return Process::result();
    });
});

afterEach(function () {
    File::deleteDirectory($this->parentPath);
});

function runInstaller(array $options = []): PendingCommand
{
    return test()->artisan('larafast:install', array_merge([
        'directory' => 'demo-app',
        '--path' => test()->parentPath,
        '--stack' => 'tall',
        '--database' => 'sqlite',
        '--ai-provider' => 'none',
        '--git' => '',
        '--no-boost' => true,
        '--no-interaction' => true,
    ], $options));
}

it('installs the selected stack and configures the environment', function () {
    runInstaller()->assertSuccessful();

    $env = File::get($this->projectPath.'/.env');

    expect($env)
        ->toContain('APP_NAME=Demo-app')
        ->toContain('APP_URL=http://demo-app.test')
        ->toContain('DB_CONNECTION=sqlite');

    expect(File::exists($this->projectPath.'/.git'))->toBeFalse();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'clone', 'git@github.com:karakhanyans-tools/larafast-tall.git', 'demo-app']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['php', 'artisan', 'migrate', '--force']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'remote', 'add', 'larafast', 'https://github.com/karakhanyans-tools/larafast-tall.git']);
    Process::assertDidntRun(fn (PendingProcess $process) => $process->command === ['git', 'push', '-u', 'origin', 'master']);
});

it('clones the right repository for each stack', function (string $stack, string $repository) {
    runInstaller(['--stack' => $stack])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'clone', "git@github.com:{$repository}.git", 'demo-app']);
})->with([
    'tall' => ['tall', 'karakhanyans-tools/larafast-tall'],
    'tenancy' => ['tenancy', 'karakhanyans-tools/larafast-multitenancy'],
    'vilt' => ['vilt', 'karakhanyans-tools/larafast'],
    'directory' => ['directory', 'karakhanyans-tools/larafast-directories'],
    'api' => ['api', 'karakhanyans-tools/larafast-rest-api'],
]);

it('configures a non sqlite database', function () {
    runInstaller(['--database' => 'pgsql'])->assertSuccessful();

    expect(File::get($this->projectPath.'/.env'))
        ->toContain('DB_CONNECTION=pgsql')
        ->toContain("\nDB_HOST=127.0.0.1")
        ->toContain('DB_PORT=5432')
        ->toContain('DB_DATABASE=demo_app');
});

it('pushes to a new git remote when given', function () {
    runInstaller(['--git' => 'https://github.com/acme/app.git'])->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'remote', 'add', 'origin', 'https://github.com/acme/app.git']);
    Process::assertRan(fn (PendingProcess $process) => $process->command === ['git', 'push', '-u', 'origin', 'master']);
});

it('appends the AI provider key to the env file', function () {
    runInstaller(['--ai-provider' => 'openai', '--ai-key' => 'sk-test-123'])->assertSuccessful();

    expect(File::get($this->projectPath.'/.env'))->toContain("\nOPENAI_API_KEY=sk-test-123\n");
});

it('replaces an existing commented AI key and sets the default provider in config/ai.php', function () {
    $this->withAiConfig = true;

    runInstaller(['--ai-provider' => 'anthropic', '--ai-key' => 'sk-ant-xyz'])->assertSuccessful();

    $env = File::get($this->projectPath.'/.env');

    expect($env)
        ->toContain("\nANTHROPIC_API_KEY=sk-ant-xyz")
        ->not->toContain('# ANTHROPIC_API_KEY');

    expect(File::get($this->projectPath.'/config/ai.php'))
        ->toContain("'default' => 'anthropic'")
        ->toContain("'default_for_images' => 'gemini'");
});

it('warns when no AI key is entered', function () {
    runInstaller(['--ai-provider' => 'gemini', '--ai-key' => ''])
        ->expectsOutputToContain('Add GEMINI_API_KEY to your .env file')
        ->assertSuccessful();

    expect(File::get($this->projectPath.'/.env'))->toContain('GEMINI_API_KEY=');
});

it('does not touch AI settings when no provider is chosen', function () {
    $this->withAiConfig = true;

    runInstaller()->assertSuccessful();

    expect(File::get($this->projectPath.'/.env'))->not->toContain('OPENAI_API_KEY');
    expect(File::get($this->projectPath.'/config/ai.php'))->toContain("'default' => 'openai'");
});

it('requires laravel boost when the kit does not ship it', function () {
    runInstaller(['--no-boost' => false, '--boost' => true])
        ->expectsOutputToContain('php artisan boost:install')
        ->assertSuccessful();

    Process::assertRan(fn (PendingProcess $process) => $process->command === ['composer', 'require', 'laravel/boost', '--dev']);
});

it('skips requiring laravel boost when the kit already ships it', function () {
    $this->composerJson['require-dev']['laravel/boost'] = '^2.0';

    runInstaller(['--no-boost' => false, '--boost' => true])->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => $process->command === ['composer', 'require', 'laravel/boost', '--dev']);
});

it('skips laravel boost when declined', function () {
    runInstaller()->assertSuccessful();

    Process::assertDidntRun(fn (PendingProcess $process) => $process->command === ['composer', 'require', 'laravel/boost', '--dev']);
});

it('fails when the repository cannot be cloned', function () {
    Process::fake(fn () => Process::result(exitCode: 128, errorOutput: 'Permission denied'));

    runInstaller()
        ->expectsOutputToContain('Failed to clone repository.')
        ->assertFailed();
});

it('rejects unknown options', function (array $options, string $message) {
    runInstaller($options)
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'stack' => [['--stack' => 'rails'], 'Unknown stack [rails].'],
    'database' => [['--database' => 'mongo'], 'Unknown database [mongo].'],
    'ai provider' => [['--ai-provider' => 'skynet'], 'Unknown AI provider [skynet].'],
]);
