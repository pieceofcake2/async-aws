<?php

App::uses('CakeException', 'Error');

/**
 * Thrown when a feature of the plugin is used but the AsyncAws package of its service is not installed.
 *
 * The plugin requires only `async-aws/core`; each feature needs the package of its service, e.g.
 * `async-aws/dynamo-db` for the DynamoDB session handler.
 */
class MissingPackageException extends CakeException
{
    /**
     * Throws an exception when `$class` does not exist.
     *
     * @param string $class Client class of the package, e.g. `AsyncAws\DynamoDb\DynamoDbClient`.
     * @param string $package Composer package, e.g. `async-aws/dynamo-db`.
     * @param string $feature Feature that needs the package, e.g. `The DynamoDB session handler`.
     * @return void
     * @throws \MissingPackageException When the class does not exist.
     */
    public static function check(string $class, string $package, string $feature): void
    {
        if (!class_exists($class)) {
            throw new MissingPackageException(static::message($package, $feature));
        }
    }

    /**
     * Returns the message for a missing package.
     *
     * @param string $package Composer package, e.g. `async-aws/dynamo-db`.
     * @param string $feature Feature that needs the package, e.g. `The DynamoDB session handler`.
     * @return string
     */
    public static function message(string $package, string $feature): string
    {
        return sprintf('%s requires %s. Install it with: composer require %s', $feature, $package, $package);
    }
}
