<?php

declare(strict_types=1);

use Happy\Installer\Console\NewCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

it('can scaffold a new Happy application', function (): void {
    $scaffoldDirectoryName = 'tests-output/my-app';
    $scaffoldDirectory = __DIR__.'/../'.$scaffoldDirectoryName;

    if (file_exists($scaffoldDirectory)) {
        if (PHP_OS_FAMILY === 'Windows') {
            exec("rd /s /q \"{$scaffoldDirectory}\"");
        } else {
            exec("rm -rf \"{$scaffoldDirectory}\"");
        }
    }

    $app = new Application('Happy PHP Installer');
    $app->add(new NewCommand());

    $tester = new CommandTester($app->find('new'));

    $statusCode = $tester->execute(['name' => $scaffoldDirectoryName], ['interactive' => false]);

    $this->assertSame(0, $statusCode);
    $this->assertDirectoryExists($scaffoldDirectory.'/vendor');
    $this->assertFileExists($scaffoldDirectory.'/.env');
});
