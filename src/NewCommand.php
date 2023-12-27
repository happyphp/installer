<?php

declare(strict_types=1);

namespace Happy\Installer\Console;

use RuntimeException;
use Illuminate\Support\Composer;
use Illuminate\Support\ProcessUtils;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\text;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;

class NewCommand extends Command
{
    use Concerns\ConfiguresPrompts;

    protected Composer $composer;

    protected function configure(): void
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Happy application')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Installs the latest "development" release')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('github', null, InputOption::VALUE_OPTIONAL, 'Create a new repository on GitHub', false)
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'The GitHub organization to create the new repository for')
            ->addOption('pest', null, InputOption::VALUE_NONE, 'Installs the Pest testing framework')
            ->addOption('phpunit', null, InputOption::VALUE_NONE, 'Installs the PHPUnit testing framework')
            ->addOption('prompt-breeze', null, InputOption::VALUE_NONE, 'Issues a prompt to determine if Breeze should be installed (Deprecated)')
            ->addOption('prompt-jetstream', null, InputOption::VALUE_NONE, 'Issues a prompt to determine if Jetstream should be installed (Deprecated)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Forces install even if the directory already exists');
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write(PHP_EOL.'  <fg=red>:)</>'.PHP_EOL.PHP_EOL);

        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label: 'What is the name of your project?',
                placeholder: 'E.g. example-app',
                required: 'The project name is required.',
                validate: fn ($value) => preg_match('/[^\pL\pN\-_.]/', $value) !== 0
                    ? 'The name may only contain letters, numbers, dashes, underscores, and periods.'
                    : null,
            ));
        }

        if (! $input->getOption('phpunit') && ! $input->getOption('pest')) {
            $input->setOption('pest', select(
                    label: 'Which testing framework do you prefer?',
                    options: ['PHPUnit', 'Pest'],
                ) === 'Pest');
        }

        if (! $input->getOption('git') && $input->getOption('github') === false && Process::fromShellCommandline('git --version')->run() === 0) {
            $input->setOption('git', confirm(label: 'Would you like to initialize a Git repository?', default: false));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');

        $directory = $name !== '.' ? getcwd().'/'.$name : '.';

        $this->composer = new Composer(new Filesystem(), $directory);

        $version = $this->getVersion($input);

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($input->getOption('force') && $directory === '.') {
            throw new RuntimeException('Cannot use --force option when using current directory for installation!');
        }

        $composer = $this->findComposer();

        $commands = [
            $composer." create-project happy/happy \"$directory\" $version --remove-vcs --prefer-dist",
        ];

        if ($directory != '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY == 'Windows') {
                array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (PHP_OS_FAMILY != 'Windows') {
            $commands[] = "chmod 755 \"$directory/happy\"";
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            if ($name !== '.') {
                $this->replaceInFile(
                    'APP_URL=http://localhost',
                    'APP_URL='.$this->generateAppUrl($name),
                    $directory.'/.env'
                );

                $database = $this->promptForDatabaseOptions($input);

                $this->configureDefaultDatabaseConnection($directory, $database, $name);
            }

            if ($input->getOption('git') || $input->getOption('github') !== false) {
                $this->createRepository($directory, $input, $output);
            }

            if ($input->getOption('github') !== false) {
                $this->pushToGitHub($name, $directory, $input, $output);
                $output->writeln('');
            }

            $output->writeln("  <bg=blue;fg=white> INFO </> Application ready in <options=bold>[{$name}]</>. Build something amazing.".PHP_EOL);
        }

        return $process->getExitCode();
    }

    protected function defaultBranch(): string
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

        $process->run();

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

    protected function configureDefaultDatabaseConnection(string $directory, string $database, string $name): void
    {
        if ($database === 'mariadb' && ! $this->hasMariaDBConfig($directory)) {
            $database = 'mysql';
        }

        $this->replaceInFile(
            'DB_CONNECTION=mysql',
            'DB_CONNECTION='.$database,
            $directory.'/.env'
        );

        if ($database != 'sqlite') {
            $this->replaceInFile(
                'DB_CONNECTION=mysql',
                'DB_CONNECTION='.$database,
                $directory.'/.env.example'
            );
        }

        $defaults = [
            'DB_HOST=127.0.0.1',
            'DB_PORT=3306',
            'DB_DATABASE=laravel',
            'DB_USERNAME=root',
            'DB_PASSWORD=',
        ];

        if ($database === 'sqlite') {
            $this->replaceInFile(
                $defaults,
                collect($defaults)->map(fn ($default) => "# {$default}")->all(),
                $directory.'/.env'
            );

            return;
        }

        $defaultPorts = [
            'pgsql' => '5432',
            'sqlsrv' => '1433',
        ];

        if (isset($defaultPorts[$database])) {
            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT='.$defaultPorts[$database],
                $directory.'/.env'
            );

            $this->replaceInFile(
                'DB_PORT=3306',
                'DB_PORT='.$defaultPorts[$database],
                $directory.'/.env.example'
            );
        }

        $this->replaceInFile(
            'DB_DATABASE=happy',
            'DB_DATABASE='.str_replace('-', '_', strtolower($name)),
            $directory.'/.env'
        );

        $this->replaceInFile(
            'DB_DATABASE=happy',
            'DB_DATABASE='.str_replace('-', '_', strtolower($name)),
            $directory.'/.env.example'
        );
    }

    protected function hasMariaDBConfig(string $directory): bool
    {
        if (! file_exists($directory.'/config/database.php')) {
            return true;
        }

        return str_contains(
            file_get_contents($directory.'/config/database.php'),
            "'mariadb' =>"
        );
    }

    protected function promptForDatabaseOptions(InputInterface $input): int|string
    {
        $database = 'mysql';

        if ($input->isInteractive()) {
            $database = select(
                label: 'Which database will your application use?',
                options: [
                    'mysql' => 'MySQL',
                    'mariadb' => 'MariaDB',
                    'pgsql' => 'PostgreSQL',
                    'sqlite' => 'SQLite',
                    'sqlsrv' => 'SQL Server',
                ],
                default: $database
            );
        }

        return $database;
    }

    protected function installPest(string $directory, InputInterface $input, OutputInterface $output): void
    {
        if ($this->removeComposerPackages(['phpunit/phpunit'], $output, true)
            && $this->requireComposerPackages(['pestphp/pest:^2.0', 'pestphp/pest-plugin-laravel:^2.0'], $output, true)) {
            $commands = array_filter([
                $this->phpBinary().' ./vendor/bin/pest --init',
            ]);

            $this->runCommands($commands, $input, $output, workingPath: $directory, env: [
                'PEST_NO_SUPPORT' => 'true',
            ]);

            $this->replaceFile(
                'pest/Feature.php',
                $directory.'/tests/Feature/ExampleTest.php',
            );

            $this->replaceFile(
                'pest/Unit.php',
                $directory.'/tests/Unit/ExampleTest.php',
            );

            $this->commitChanges('Install Pest', $directory, $input, $output);
        }
    }

    protected function createRepository(string $directory, InputInterface $input, OutputInterface $output): void
    {
        $branch = $input->getOption('branch') ?: $this->defaultBranch();

        $commands = [
            'git init -q',
            'git add .',
            'git commit -q -m "Set up a fresh Laravel app"',
            "git branch -M {$branch}",
        ];

        $this->runCommands($commands, $input, $output, workingPath: $directory);
    }

    protected function commitChanges(string $message, string $directory, InputInterface $input, OutputInterface $output): void
    {
        if (! $input->getOption('git') && $input->getOption('github') === false) {
            return;
        }

        $commands = [
            'git add .',
            "git commit -q -m \"$message\"",
        ];

        $this->runCommands($commands, $input, $output, workingPath: $directory);
    }

    protected function pushToGitHub(string $name, string $directory, InputInterface $input, OutputInterface $output): void
    {
        $process = new Process(['gh', 'auth', 'status']);
        $process->run();

        if (! $process->isSuccessful()) {
            $output->writeln('  <bg=yellow;fg=black> WARN </> Make sure the "gh" CLI tool is installed and that you\'re authenticated to GitHub. Skipping...'.PHP_EOL);

            return;
        }

        $name = $input->getOption('organization') ? $input->getOption('organization')."/$name" : $name;
        $flags = $input->getOption('github') ?: '--private';

        $commands = [
            "gh repo create {$name} --source=. --push {$flags}",
        ];

        $this->runCommands($commands, $input, $output, workingPath: $directory, env: ['GIT_TERMINAL_PROMPT' => 0]);
    }

    protected function verifyApplicationDoesntExist($directory): void
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('Application already exists!');
        }
    }

    protected function generateAppUrl($name): string
    {
        $hostname = mb_strtolower($name).'.test';

        return $this->canResolveHostname($hostname) ? 'http://'.$hostname : 'http://localhost';
    }

    protected function canResolveHostname($hostname): bool
    {
        return gethostbyname($hostname.'.') !== $hostname.'.';
    }

    protected function getVersion(InputInterface $input): string
    {
        if ($input->getOption('dev')) {
            return 'dev-master';
        }

        return '';
    }

    protected function findComposer(): string
    {
        return implode(' ', $this->composer->findComposer());
    }

    protected function phpBinary(): string
    {
        $phpBinary = (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }

    protected function requireComposerPackages(array $packages, OutputInterface $output, bool $asDev = false): bool
    {
        return $this->composer->requirePackages($packages, $asDev, $output);
    }

    protected function removeComposerPackages(array $packages, OutputInterface $output, bool $asDev = false): bool
    {
        return $this->composer->removePackages($packages, $asDev, $output);
    }

    protected function runCommands($commands, InputInterface $input, OutputInterface $output, string $workingPath = null, array $env = []): Process
    {
        if (! $output->isDecorated()) {
            $commands = array_map(function ($value) {
                if (str_starts_with($value, 'chmod')) {
                    return $value;
                }

                if (str_starts_with($value, 'git')) {
                    return $value;
                }

                return $value.' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                if (str_starts_with($value, 'chmod')) {
                    return $value;
                }

                if (str_starts_with($value, 'git')) {
                    return $value;
                }

                return $value.' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> WARN </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }

    protected function replaceFile(string $replace, string $file): void
    {
        $stubs = dirname(__DIR__).'/stubs';

        file_put_contents(
            $file,
            file_get_contents("$stubs/$replace"),
        );
    }

    protected function replaceInFile(string|array $search, string|array $replace, string $file): void
    {
        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }
}
