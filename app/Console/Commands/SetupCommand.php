<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class SetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'larafast:install
        {directory : The directory to install Larafast into}
        {--stack= : The boilerplate to install (tall, tenancy, vilt, directory, api)}
        {--database= : The database driver (sqlite, mysql, mariadb, pgsql, sqlsrv)}
        {--git= : A remote git repository URL to push the new project to}
        {--ai-provider= : The AI provider to configure (none, openai, anthropic, gemini, mistral, groq, deepseek, xai, openrouter)}
        {--ai-key= : The API key for the selected AI provider}
        {--boost : Set up Laravel Boost for AI coding agents}
        {--no-boost : Skip Laravel Boost setup}
        {--path= : The parent directory to install into (defaults to the installer\'s parent directory)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install Larafast';

    /**
     * @var array<string, string>
     */
    public const STACKS = [
        'tall' => 'Larafast TALL Stack (Tailwind CSS, Alpine.js, Laravel, Livewire)',
        'tenancy' => 'Larafast Multi-Tenancy Starter Kit',
        'vilt' => 'Larafast VILT Stack (Vue.js, Inertia.js, Laravel, Tailwind CSS)',
        'directory' => 'Larafast Directory Boilerplate',
        'api' => 'Larafast API Boilerplate',
    ];

    /**
     * @var array<string, string>
     */
    public const REPOSITORIES = [
        'tall' => 'karakhanyans-tools/larafast-tall',
        'tenancy' => 'karakhanyans-tools/larafast-multitenancy',
        'vilt' => 'karakhanyans-tools/larafast',
        'directory' => 'karakhanyans-tools/larafast-directories',
        'api' => 'karakhanyans-tools/larafast-rest-api',
    ];

    /**
     * @var array<string, string>
     */
    public const DATABASES = [
        'sqlite' => 'SQLite',
        'mysql' => 'MySQL',
        'mariadb' => 'MariaDB',
        'pgsql' => 'PostgreSQL',
        'sqlsrv' => 'SQL Server',
    ];

    /**
     * AI providers and the environment variable holding their API key.
     *
     * @var array<string, array{label: string, env: string|null}>
     */
    public const AI_PROVIDERS = [
        'none' => ['label' => 'None (skip)', 'env' => null],
        'openai' => ['label' => 'OpenAI', 'env' => 'OPENAI_API_KEY'],
        'anthropic' => ['label' => 'Anthropic (Claude)', 'env' => 'ANTHROPIC_API_KEY'],
        'gemini' => ['label' => 'Google Gemini', 'env' => 'GEMINI_API_KEY'],
        'mistral' => ['label' => 'Mistral', 'env' => 'MISTRAL_API_KEY'],
        'groq' => ['label' => 'Groq', 'env' => 'GROQ_API_KEY'],
        'deepseek' => ['label' => 'DeepSeek', 'env' => 'DEEPSEEK_API_KEY'],
        'xai' => ['label' => 'xAI (Grok)', 'env' => 'XAI_API_KEY'],
        'openrouter' => ['label' => 'OpenRouter', 'env' => 'OPENROUTER_API_KEY'],
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $directory = $this->argument('directory');

        $stack = $this->option('stack') ?: select(
            label: 'Choose your Boilerplate',
            options: self::STACKS,
            default: 'tall',
            hint: 'Make sure you have access to git repository to install Larafast',
            required: true,
        );

        if (! array_key_exists($stack, self::STACKS)) {
            $this->error("Unknown stack [{$stack}].");

            return self::FAILURE;
        }

        $database = $this->option('database') ?: select(
            label: 'Choose your Database',
            options: self::DATABASES,
            default: 'sqlite',
            hint: 'Choose the database you want to use',
            required: true,
        );

        if (! array_key_exists($database, self::DATABASES)) {
            $this->error("Unknown database [{$database}].");

            return self::FAILURE;
        }

        $aiProvider = $this->option('ai-provider') ?: select(
            label: 'Which AI provider do you want to use?',
            options: collect(self::AI_PROVIDERS)->map(fn (array $provider): string => $provider['label'])->all(),
            default: 'none',
            hint: 'Configures the API key in .env (and config/ai.php when present)',
        );

        if (! array_key_exists($aiProvider, self::AI_PROVIDERS)) {
            $this->error("Unknown AI provider [{$aiProvider}].");

            return self::FAILURE;
        }

        $aiKey = '';

        if ($aiProvider !== 'none') {
            $aiKey = $this->option('ai-key') ?? password(
                label: 'Enter your '.self::AI_PROVIDERS[$aiProvider]['label'].' API key (Enter to skip)',
                hint: 'Stored only in your local .env file',
            );
        }

        $installBoost = match (true) {
            (bool) $this->option('boost') => true,
            (bool) $this->option('no-boost') => false,
            ! $this->input->isInteractive() => false,
            default => confirm(
                label: 'Set up Laravel Boost for AI coding agents?',
                default: true,
                hint: 'Adds guidelines, skills and the Boost MCP server for Claude Code, Cursor, Copilot and others',
            ),
        };

        $git = $this->option('git') ?? text(
            label: 'Want to set up a new git repository? (Enter to skip)',
            placeholder: 'https://github.com/you/your-project.git',
            default: '',
            required: false,
            hint: 'Enter the git repository URL (Enter to skip)',
        );

        $parentPath = rtrim($this->option('path') ?: dirname(base_path()), DIRECTORY_SEPARATOR);
        $projectPath = $parentPath.DIRECTORY_SEPARATOR.$directory;
        $repository = self::REPOSITORIES[$stack];
        $httpsRepoUrl = "https://github.com/{$repository}.git";

        info('Installing Larafast '.ucfirst($stack).' in '.$directory.' directory...');
        info('Cloning repository...');

        $this->runInDirectory(['git', 'clone', "git@github.com:{$repository}.git", $directory], $parentPath);

        if (! File::exists($projectPath)) {
            $this->runInDirectory(['git', 'clone', $httpsRepoUrl, $directory], $parentPath);
        }

        if (! File::exists($projectPath)) {
            $this->error('Failed to clone repository.');
            $this->error('If you haven\'t purchased Larafast yet, you can do so at https://larafast.com.');

            return self::FAILURE;
        }

        $envPath = $projectPath.DIRECTORY_SEPARATOR.'.env';

        info('Installing dependencies...');
        $this->runInDirectory(['composer', 'install'], $projectPath);

        info('Installing NPM dependencies...');
        $this->runInDirectory(['npm', 'install'], $projectPath);

        info('Setting up .env');
        File::copy($projectPath.DIRECTORY_SEPARATOR.'.env.example', $envPath);

        $this->replaceInFile('APP_NAME=Larafast', 'APP_NAME='.ucfirst($directory), $envPath);
        $this->replaceInFile('APP_URL=http://localhost', 'APP_URL=http://'.$directory.'.test', $envPath);

        info('Configuring database...');
        $this->configureDefaultDatabaseConnection($envPath, $directory, $database);

        if ($aiProvider !== 'none') {
            info('Configuring AI provider...');
            $this->configureAiProvider($projectPath, $aiProvider, $aiKey);
        }

        info('Generating application key...');
        $this->runInDirectory(['php', 'artisan', 'key:generate'], $projectPath);

        info('Migrating database...');
        $this->runInDirectory(['php', 'artisan', 'migrate', '--force'], $projectPath);

        if ($installBoost) {
            $this->installBoost($projectPath);
        }

        File::deleteDirectory($projectPath.DIRECTORY_SEPARATOR.'.git');
        $this->runInDirectory(['git', 'init'], $projectPath);
        $this->runInDirectory(['git', 'add', '.'], $projectPath);
        $this->runInDirectory(['git', 'commit', '-m', 'Initial commit'], $projectPath);

        if ($git) {
            info('Setting up new git repository...');
            $this->runInDirectory(['git', 'remote', 'add', 'origin', $git], $projectPath);
            $this->runInDirectory(['git', 'branch', '-M', 'master'], $projectPath);
            $this->runInDirectory(['git', 'push', '-u', 'origin', 'master'], $projectPath);
        }

        info('Setting up upstream repository...');
        $this->runInDirectory(['git', 'remote', 'add', 'larafast', $httpsRepoUrl], $projectPath);

        info('Larafast '.ucfirst($stack).' Installed Successfully');
        info('You can now run "npm run dev" to compile your assets and "php artisan serve" to start the server.');

        return self::SUCCESS;
    }

    /**
     * Write the provider's API key to .env and make it the default provider in config/ai.php when present.
     */
    protected function configureAiProvider(string $projectPath, string $provider, string $apiKey): void
    {
        $envKey = self::AI_PROVIDERS[$provider]['env'];

        $this->setEnvironmentValue($projectPath.DIRECTORY_SEPARATOR.'.env', $envKey, $apiKey);

        $aiConfigPath = $projectPath.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'ai.php';

        if (File::exists($aiConfigPath)) {
            $this->pregReplaceInFile(
                "/'default' => '[^']*'/",
                "'default' => '{$provider}'",
                $aiConfigPath,
                limit: 1,
            );
        }

        if ($apiKey === '') {
            warning("No API key entered. Add {$envKey} to your .env file before using AI features.");
        }
    }

    /**
     * Install Laravel Boost (if missing) and run its interactive installer.
     */
    protected function installBoost(string $projectPath): void
    {
        info('Setting up Laravel Boost...');

        $composer = json_decode((string) File::get($projectPath.DIRECTORY_SEPARATOR.'composer.json'), true) ?? [];

        $hasBoost = isset($composer['require']['laravel/boost']) || isset($composer['require-dev']['laravel/boost']);

        if (! $hasBoost) {
            $this->runInDirectory(['composer', 'require', 'laravel/boost', '--dev'], $projectPath);
        }

        if ($this->input->isInteractive() && Process::supportsTty()) {
            Process::path($projectPath)->forever()->tty()->run(['php', 'artisan', 'boost:install']);

            return;
        }

        warning('Run "php artisan boost:install" in your project to choose your AI agents.');
    }

    protected function setEnvironmentValue(string $envPath, string $key, string $value): void
    {
        $contents = File::get($envPath);
        $line = $key.'='.$value;
        $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';

        $contents = preg_match($pattern, $contents)
            ? preg_replace($pattern, $line, $contents, 1)
            : rtrim($contents, PHP_EOL).PHP_EOL.PHP_EOL.$line.PHP_EOL;

        File::put($envPath, $contents);
    }

    protected function pregReplaceInFile(string $pattern, string $replace, string $file, int $limit = -1): void
    {
        File::put($file, preg_replace($pattern, $replace, File::get($file), $limit));
    }

    /**
     * @param  string|array<int, string>  $search
     * @param  string|array<int, string>  $replace
     */
    protected function replaceInFile(string|array $search, string|array $replace, string $file): void
    {
        File::put($file, str_replace($search, $replace, File::get($file)));
    }

    /**
     * @param  array<int, string>  $command
     */
    protected function runInDirectory(array $command, string $path): bool
    {
        $result = Process::path($path)->forever()->run($command);

        if ($result->failed()) {
            $this->error(trim($result->errorOutput()) ?: 'Command failed: '.implode(' ', $command));

            return false;
        }

        if ($output = trim($result->output())) {
            $this->line($output);
        }

        return true;
    }

    protected function commentDatabaseConfigurationForSqlite(string $envPath): void
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
            collect($defaults)->map(fn (string $default): string => "# {$default}")->all(),
            $envPath
        );
    }

    protected function uncommentDatabaseConfiguration(string $envPath): void
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
            collect($defaults)->map(fn (string $default): string => substr($default, 2))->all(),
            $envPath
        );
    }

    protected function configureDefaultDatabaseConnection(string $envPath, string $directory, string $database): void
    {
        $this->pregReplaceInFile('/DB_CONNECTION=.*/', 'DB_CONNECTION='.$database, $envPath);

        if ($database === 'sqlite') {
            if (! str_contains(File::get($envPath), '# DB_HOST=127.0.0.1')) {
                $this->commentDatabaseConfigurationForSqlite($envPath);
            }

            return;
        }

        $this->uncommentDatabaseConfiguration($envPath);

        $defaultPorts = [
            'pgsql' => '5432',
            'sqlsrv' => '1433',
        ];

        if (isset($defaultPorts[$database])) {
            $this->replaceInFile('DB_PORT=3306', 'DB_PORT='.$defaultPorts[$database], $envPath);
        }

        $this->replaceInFile(
            'DB_DATABASE=larafast',
            'DB_DATABASE='.str_replace('-', '_', strtolower($directory)),
            $envPath
        );
    }
}
