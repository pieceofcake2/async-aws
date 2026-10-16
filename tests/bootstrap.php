<?php
/**
 * Bootstrap for PHPUnit
 *
 * As in pieceofcake2/app, the tests run with APP_ENV=test by default (an APP_ENV from the environment wins), so
 * config/core.php of pieceofcake2/app loads its config/.env.test.
 */

use Composer\InstalledVersions;

if ((string)getenv('APP_ENV') === '' && empty($_SERVER['APP_ENV'])) {
    putenv('APP_ENV=test');
    $_ENV['APP_ENV'] = 'test';
    $_SERVER['APP_ENV'] = 'test';
}

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('VENDORS')) {
    define('VENDORS', dirname(__DIR__) . DS . 'vendor' . DS);
}
if (!defined('ROOT')) {
    define('ROOT', realpath(InstalledVersions::getInstallPath('pieceofcake2/app')));
}

require_once InstalledVersions::getInstallPath('pieceofcake2/cakephp') . DS . 'tests' . DS . 'bootstrap.php';

CakePlugin::load('AsyncAws', [
    'path' => dirname(__DIR__) . DS,
]);

require_once __DIR__ . DS . 'TestCase' . DS . 'MockAsyncAwsTrait.php';
