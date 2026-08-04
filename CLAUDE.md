# CLAUDE.md

## Project Overview

**dynart-dpress-test** is the test suite for [dpress](../dynart-dpress). It follows the same pattern as `dynart-micro-test` and `dynart-micro-entities-test`: a separate repository that symlinks the package under test through a Composer path repository. `dynart/dpress`, `dynart/micro` and `dynart/micro-entities` are all symlinked, so library changes are picked up immediately.

## Running Tests

```bash
php vendor/bin/phpunit --stderr
php vendor/bin/phpunit --testsuite unit --stderr
php vendor/bin/phpunit --filter testMethodName --stderr
```

## Project Structure

```
src/
  StubConfig.php     ConfigInterface stub returning whatever it was handed
tests/
  Unit/              no database required
```

## Notes

- There is no integration suite yet. When one arrives it should follow `dynart-micro-entities-test`: a `configs/test.ini`, an `IntegrationTestCase` in `src/` rather than `tests/` so the autoloader finds it before PHPUnit loads its subclasses, and each test creating and dropping its own tables.
- `DpressCliAppTest` checks the command table structurally — that every callable exists and returns `int` or `string`. `CliApp::process()` passes a command's return value straight to `finish()`, which is typed `string|int`, so a command returning `null` is a runtime TypeError that only shows up when that command is run.
