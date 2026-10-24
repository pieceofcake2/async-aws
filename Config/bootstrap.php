<?php
/**
 * Bootstrap of the AsyncAws plugin, loaded with `CakePlugin::load('AsyncAws', ['bootstrap' => true])`.
 *
 * Registers the `s3://` stream wrapper with an S3 client created from the `AsyncAws` configuration
 * (or the `Aws` configuration of pieceofcake2/aws). When async-aws/simple-s3 is not installed, a warning is
 * logged and the wrapper is not registered.
 *
 * Registers the Symfony Mailer style DSNs of Amazon SES (`ses+api://`, `ses+https://`, `ses+smtp://`, ...) for the
 * `'dsn'` key of email profiles, e.g. `'dsn' => env('MAILER_DSN')`. Skipped on pieceofcake2/cakephp releases
 * without `CakeEmail::setDsnClassMap()`.
 */

S3StreamWrapper::bootstrap();
AsyncAwsSesDsn::register();
