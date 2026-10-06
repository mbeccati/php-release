# PHP Release Builder

This is an attempt to standardize and automate most of the build process for PHP releases starting from PHP 8.6.0. It is an evolution of `sgolemon/php-release`, which was itself based heavily on `dshafik/php-build`.

This container is currently based on `ubuntu:resolute`.

It will do the following:

- Clone PHP from the github
- Create the release branch from the correct branch
- Update the version number/date/credits in various files
- Build PHP for nts, debug-nts, zts, and debug-zts and run tests in each version
- Compare the build `-v` version against the intended version
- Create the packages
- Provide instructions for finalizing the release

## Features

- Repeatable, system independent builds
- Configuration file for unattended builds
- Ability to rebuild using release branch

## Running the Container

You need to create a `workspace` directory to mount into the container.
This is where the build script will place artifacts.
This directory must contain a `config` file (see `config.default` for settings).
The build process will create/overwrite the following folders and files within `/workspace`:

1. `php-src/`: The checkout from php-src. This checkout is performed fresh with every invocation of php-release.
2. `log/{config,make,test}.{debug-,}{nts,zts}.log`: Results of ./configure, make, and make test across zts/nts

For example, assuming you created your workspace in your home directory, you could run the following:

```sh
docker run --rm -v/home/$USER/workspace:/workspace -v/home/$USER/.gnupg:/tmp/gnupg -ti mbeccati/php-release
```

or

```sh
podman run --rm -v/home/$USER/workspace:/workspace -v/home/$USER/.gnupg:/tmp/gnupg -ti mbeccati/php-release
```

This will pull the image from hub.docker.com and run it,
mounting your local `~/workspace` directory as `/workspace` within the container.

## Testing the build scripts

Install the development dependencies with Composer, then run PHPUnit:

```sh
composer install
vendor/bin/phpunit
```

The tests require PHP compatible with the PHPUnit version in `composer.json`,
Git, Bash, and GNU command-line tools (including `date`, `sed`, and `nproc`).
Run them on Linux. To run just one group:

```sh
vendor/bin/phpunit tests/ParseVersionTest.php
vendor/bin/phpunit tests/UpdateVersionsTest.php
vendor/bin/phpunit tests/BuildTest.php
```

The suite checks version parsing, generated version files and Git staging,
release commits and tags, NEWS and API updates, the build matrix, packaging,
and failure handling. Each test uses its own temporary directory and local
Git repositories, which are removed afterward. Temporary copies of the shell
scripts have their container paths relocated into that directory.

Build commands, PHP test execution, archive generation, and signing are stubbed;
the suite does not compile PHP or verify real archives or signatures. The test
Git wrapper disables signing, permits clones only from the local fixture, and
rejects pushes. No Docker container, network access, or real signing keys are
needed, and the suite never commits to the project repository.
