# CakePHP 2 AsyncAws Plugin

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/async-aws?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/async-aws?label=Packagist)](https://packagist.org/packages/pieceofcake2/async-aws)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/async-aws/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen?label=PHPStan)](https://github.com/pieceofcake2/async-aws/actions/workflows/CI.yml)
![CakePHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/async-aws/pieceofcake2/cakephp?logo=cakephp&logoColor=%23FFFFFF&label=CakePHP&labelColor=%23D33C43&color=%23FFFFFF)
[![CI](https://img.shields.io/github/actions/workflow/status/pieceofcake2/async-aws/CI.yml?label=CI)](https://github.com/pieceofcake2/async-aws/actions/workflows/CI.yml)
[![Codecov](https://img.shields.io/codecov/c/gh/pieceofcake2/async-aws?label=Coverage)](https://codecov.io/gh/pieceofcake2/async-aws)

The AsyncAws plugin integrates [AsyncAws](https://async-aws.com) with CakePHP 2 applications
running on [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp): an `AsyncAws`
component that creates AsyncAws clients, a DynamoDB session handler, a DynamoDB cache engine,
an Amazon SES email transport and a small S3 storage helper.

It is the AsyncAws counterpart of [pieceofcake2/aws](https://github.com/pieceofcake2/aws),
which uses the full AWS SDK for PHP. AsyncAws installs only the services you use, which keeps
deployments small, for example on AWS Lambda with [Bref](https://bref.sh).

## Requirements

- PHP 8.1+
- pieceofcake2/cakephp 2.12+

## Installation

```bash
composer require pieceofcake2/async-aws
```

Then load the plugin with `CakePlugin::load('AsyncAws')`.

## Documentation

Documentation: https://pieceofcake2.github.io/async-aws/

## Contributing

See the [contribution guidelines](https://github.com/pieceofcake2/.github/blob/master/CONTRIBUTING.md).

## License

Licensed under the MIT License. See [LICENSE](LICENSE).
