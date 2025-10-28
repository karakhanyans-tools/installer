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
use function Laravel\Prompts\text;


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
                'tenancy' => 'Larafast Multi-Tenancy Starter Kit',
                'vilt' => 'Larafast VILT Stack (Vue.js, Inertia.js, Laravel, Tailwind CSS)',
                'directory' => 'Larafast Directory Boilerplate',
                'api' => 'Larafast API Boilerplate',
            ],
            default: 'tall',
            hint: 'Make sure you have access to git repository to install Larafast',
            required: true,
        );

        $sshRepoUrl = match ($stack) {
            'vilt' => 'git@github.com:karakhanyans-tools/larafast.git',
            'directory' => 'git@github.com:karakhanyans-tools/larafast-directories.git',
            'api' => 'git@github.com:karakhanyans-tools/larafast-rest-api.git',
            'tenancy' => 'git@github.com:karakhanyans-tools/larafast-multitenancy.git',
            default => 'git@github.com:karakhanyans-tools/larafast-tall.git',
        };

        $httpsRepoUrl = match ($stack) {
            'vilt' => 'https://github.com/karakhanyans-tools/larafast.git',
            'directory' => 'https://github.com/karakhanyans-tools/larafast-directories.git',
            'api' => 'https://github.com/karakhanyans-tools/larafast-rest-api.git',
            'tenancy' => 'https://github.com/karakhanyans-tools/larafast-multitenancy.git',
            default => 'https://github.com/karakhanyans-tools/larafast-tall.git',
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

        $git = text(
            label: 'Want to set up a new git repository? (Enter to skip)',
            placeholder: 'https://github.com/karakhanyans-tools/larafast-tall.git',
            default: '',
            required: false,
            hint: 'Enter the git repository URL (Enter to skip)',
        );

        info('Installing Larafast ' . ucfirst($stack) . ' in ' . $directory . ' directory...');
        info('Cloning repository...');

        $this->processCommand('git clone ' . $sshRepoUrl . ' ' . $directory, $directory, true);

        if (!File::exists('../' . $directory)) {
            $this->processCommand('git clone ' . $httpsRepoUrl . ' ' . $directory, $directory, true);
        }

        if (!File::exists('../' . $directory)) {
            $this->error('Failed to clone repository.');

            $this->error('If you haven\'t purchased Larafast yet, you can do so at https://larafast.com.');

            return;
        }

        info('Installing dependencies...');
        $this->processCommand('composer install', $directory);

        info('Installing NPM dependencies...');
        $this->processCommand('npm install', $directory);

        info('Setting up .env');
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

        info('Configuring database...');
        $this->configureDefaultDatabaseConnection($directory, $database);

        info('Generating application key...');
        $this->processCommand('php artisan key:generate', $directory);

        info('Migrating database...');
        $this->processCommand('php artisan migrate --force', $directory);

        $this->processCommand('rm -rf .git', $directory);
        $this->processCommand('git init', $directory);
        $this->processCommand('git add .', $directory);
        $this->processCommand('git commit -m "Initial commit"', $directory);

        if ($git) {
            info('Setting up new git repository...');
            $this->processCommand('git remote add origin ' . $git, $directory);
            $this->processCommand('git branch -M master', $directory);
            $this->processCommand('git push -u origin master', $directory);
        }

        info('Setting up upstream repository...');
        $this->processCommand('git remote add larafast ' . $httpsRepoUrl, $directory);

        info('Larafast ' . ucfirst($stack) . ' Installed Successfully');
        info('You can now run "npm run dev" to compile your assets and "php artisan serve" to start the server.');

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
            $process->run();

            $this->info($process->getOutput());
        } catch (ProcessFailedException $exception) {
            $this->error($exception->getMessage());
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
