# Repository Guidelines

## Project Structure & Module Organization

The root Bash scripts implement the release process: `build.sh` orchestrates cloning, builds, tests, and packaging; `parse-version.sh` extracts version components; `update-versions.sh` updates and stages PHP source files. `config.default` documents release settings, and `Dockerfile` defines the build container. PHPUnit tests live in `tests/`, with shared fixtures in `ScriptTestCase.php` and configuration in `phpunit.xml`. `.github/workflows/tests.yml` runs CI. There are no application assets.

## Build, Test, and Development Commands

- `composer install`: install PHPUnit and its dependencies.
- `vendor/bin/phpunit`: run the complete script test suite.
- `vendor/bin/phpunit tests/BuildTest.php`: run release workflow tests only.
- `bash -n build.sh parse-version.sh update-versions.sh`: check shell syntax.
- `git diff --check`: check whitespace errors before review.
- `docker build -t php-release .`: build the release container.

For an actual release run, create a dedicated workspace containing `config` based on `config.default`, then run:

```sh
docker run --rm -ti -v"$PWD/workspace:/workspace" \
  -v"$HOME/.gnupg:/tmp/gnupg" php-release
```

Use Linux with Bash, Git, and GNU utilities. CI runs PHP 8.5.

## Coding Style & Naming Conventions

Match existing conventions: two-space Bash indentation, uppercase shell configuration variables, and quoted expansions where appropriate. PHP tests use four-space indentation, `declare(strict_types=1)`, PascalCase class names, and descriptive camelCase methods beginning with `test`. Use PHPUnit attributes for data providers. No formatter or lint configuration is currently provided.

## Testing Guidelines

Add tests for changed behavior and failure paths. Tests use temporary local Git repositories and stub compilation, packaging, and signing. Keep fixtures isolated, clean them up, and retain the wrapper's push and external-clone restrictions. Tests never commit to the project repository. No numeric coverage threshold is configured; run the full suite before submitting changes.

## Commit & Pull Request Guidelines

History uses short, descriptive subjects such as `Add experimental test framework`; no mandatory commit prefix is established. Keep changes focused. PR descriptions should explain the behaviour change, relevant issue links, and validation performed. Identify shell-script changes separately from test additions.

## Configuration & Release Safety

Never commit signing keys or private configuration. `vendor/`, `composer.lock`, and PHPUnit cache files are ignored. Actual release runs replace `/workspace/php-src` and create commits and tags there; use a disposable workspace. Do not commit or push project changes without explicit authorisation.
