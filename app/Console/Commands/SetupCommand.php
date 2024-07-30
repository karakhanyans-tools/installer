<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

class SetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larafast:install {directory}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Larafast';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $directory = $this->argument('directory');

        $stack = select(
            label: 'Choose your Boilerplate',
            options: [
                'tall' => 'Larafast TALL Stack (Tailwind CSS, Alpine.js, Laravel, Livewire)',
                'vilt' => 'Larafast VILT Stack (Vue.js, Inertia.js, Laravel, Tailwind CSS)',
                'directory' => 'Larafast Directory Boilerplate',
                'api' => 'Larafast API Boilerplate',
            ],
            default: 'tall',
            hint: 'Make sure you have access in git repository to install Larafast',
            required: true,
        );

        $repo = match ($stack) {
            'vilt' => 'git@github.com:karakhanyans-tools/larafast.git',
            'directory' => 'git@github.com:karakhanyans-tools/larafast-directories.git',
            'api' => 'git@github.com:karakhanyans-tools/larafast-rest-api.git',
            default => 'git@github.com:karakhanyans-tools/larafast-tall.git',
        };

        $database = select(
            label: 'Choose your Database',
            options: [
                'sqlite' => 'SQLite',
                'mysql' => 'MySQL',
                'pgsql' => 'PostgreSQL',
                'sqlsrv' => 'SQL Server',
            ],
            default: 'sqlite',
            hint: 'Choose the database you want to use',
            required: true,
        );

        info('Installing Larafast ' . ucfirst($stack) . ' in ' . $directory . ' directory...');
        info('Cloning repository...');
        $this->processCommand('git clone ' . $repo . ' ' . $directory, $directory, true);
        info('Installing dependencies...');
        $this->processCommand('composer install', $directory);
        info('Installing NPM dependencies...');
        $this->processCommand('npm install', $directory);
        info('Building assets...');
        $this->processCommand('cp .env.example .env', $directory);

        $this->replaceInFile(
            'APP_NAME=Larafast',
            'APP_NAME=' . ucfirst($directory),
            '../' . $directory . '/.env'
        );

        $this->replaceInFile(
            'APP_URL=http://localhost',
            'APP_URL=http://' . $directory . '.test',
            '../' . $directory . '/.env'
        );

        $this->configureDefaultDatabaseConnection($directory, $database);

        $this->processCommand('php artisan key:generate', $directory);
        $this->processCommand('php artisan migrate:fresh', $directory);

        info('Larafast Installed Successfully');
    }

    protected function pregReplaceInFile(string $pattern, string $replace, string $file): void
    {
        file_put_contents(
            $file,
            preg_replace($pattern, $replace, file_get_contents($file))
        );
    }

    protected function replaceInFile(string|array $search, string|array $replace, string $file): void
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }

    protected function processCommand($command, $directory = null, $clone = false)
    {
        $command = $clone
            ? 'cd .. && ' . $command
            : 'cd ../' . $directory . ' && ' . $command;

        $process = Process::fromShellCommandline($command);

        try {
            $process->mustRun();
            info($process->getOutput());
        } catch (ProcessFailedException $exception) {
            warning($exception->getMessage());
            return 1;
        }
    }

    protected function commentDatabaseConfigurationForSqlite(string $directory): void
    {
        $defaults = [
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DATABASE=larafast',
            'DB_USERNAME=root',
            'DB_PASSWORD=',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn($default) => "# {$default}")->all(),
            '../' . $directory . '/.env'
        );
    }

    protected function uncommentDatabaseConfiguration(string $directory): void
    {
        $defaults = [
            '# DB_HOST=127.0.0.1',
            '# DB_PORT=3306',
            '# DB_DATABASE=larafast',
            '# DB_USERNAME=root',
            '# DB_PASSWORD=',
        ];

        $this->replaceInFile(
            $defaults,
            collect($defaults)->map(fn($default) => substr($default, 2))->all(),
            '../' . $directory . '/.env'
        );
    }

    protected function configureDefaultDatabaseConnection(string $directory, string $database): void
    {
        $this->pregReplaceInFile(
            '/DB_CONNECTION=.*/',
            'DB_CONNECTION=' . $database,
            '../' . $directory . '/.env'
        );

        if ($database === 'sqlite') {
            $environment = file_get_contents('../' . $directory . '/.env');

            // If database options aren't commented, comment them for SQLite...
            if (!str_contains($environment, '# DB_HOST=127.0.0.1')) {
                $this->commentDatabaseConfigurationForSqlite($directory);

                return;
            }

            return;
        }

        // Any commented database configuration options should be uncommented when not on SQLite...
        $this->uncommentDatabaseConfiguration($directory);

        $defaultPorts = [
            'pgsql' => '5432',
            'sqlsrv' => '1433',
        ];

        if (isset($defaultPorts[$database])) {
            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT=' . $defaultPorts[$database],
                '../' . $directory . '/.env'
            );
        }

        $this->replaceInFile(
            'DB_DATABASE=larafast',
            'DB_DATABASE=' . str_replace('-', '_', strtolower($directory)),
            '../' . $directory . '/.env'
        );
    }
}
