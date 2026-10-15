<?php
/**
 * Bootstrap of the AsyncAws plugin, loaded with `CakePlugin::load('AsyncAws', ['bootstrap' => true])`.
 *
 * Registers the `s3://` stream wrapper with an S3 client created from the `AsyncAws` configuration
 * (or the `Aws` configuration of pieceofcake2/aws). When async-aws/simple-s3 is not installed, a warning is
 * logged and the wrapper is not registered.
 */

App::uses('S3StreamWrapper', 'AsyncAws.Lib');

S3StreamWrapper::bootstrap();
