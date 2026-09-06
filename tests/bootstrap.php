<?php
/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Bijstaan
 */

/**
 * Shared harness for the pure suites.
 *
 * These boot nothing: no GLPI, no Guzzle, no tenant. Everything that decides
 * what a resource *is* — the type map, the state ladder, paging, the cost
 * column mapping — is written to take a plain callable or a plain array, so it
 * can be exercised with `php file.php` and a fixture.
 *
 * tests/http.php is the exception and says so at the top: transport behaviour
 * needs a real client, so it runs in the container.
 */

const AZ_SRC = __DIR__ . '/../src';

require_once AZ_SRC . '/AzureException.php';
require_once AZ_SRC . '/Endpoints.php';
require_once AZ_SRC . '/Types.php';
require_once AZ_SRC . '/Rows.php';
require_once AZ_SRC . '/Graph.php';
require_once AZ_SRC . '/Cost.php';
require_once AZ_SRC . '/Subscriptions.php';

// GLPI's translation helper, for the one file that carries user-facing strings.
if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}

final class T
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var string[] */
    public static array $failures = [];

    public static function ok(bool $condition, string $what): void
    {
        if ($condition) {
            self::$passed++;

            return;
        }

        self::$failed++;
        self::$failures[] = $what;
    }

    public static function is(mixed $actual, mixed $expected, string $what): void
    {
        if ($actual === $expected) {
            self::$passed++;

            return;
        }

        self::$failed++;
        self::$failures[] = sprintf(
            "%s\n      expected: %s\n      actual:   %s",
            $what,
            self::show($expected),
            self::show($actual)
        );
    }

    /** @param callable():mixed $run */
    public static function throws(callable $run, string $kind, string $what): void
    {
        try {
            $result = $run();

            // A generator does nothing until it is read.
            if ($result instanceof Generator) {
                iterator_to_array($result);
            }
        } catch (GlpiPlugin\Glpicloudazure\AzureException $e) {
            self::is($e->kind, $kind, $what);

            return;
        } catch (Throwable $e) {
            self::$failed++;
            self::$failures[] = $what . ' (threw ' . $e::class . ': ' . $e->getMessage() . ')';

            return;
        }

        self::$failed++;
        self::$failures[] = $what . ' (did not throw)';
    }

    private static function show(mixed $value): string
    {
        if (is_string($value)) {
            return "'" . $value . "'";
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }

    public static function done(string $suite): int
    {
        if (self::$failed === 0) {
            printf("%-14s %d passed\n", $suite, self::$passed);

            return 0;
        }

        printf("%-14s %d passed, %d FAILED\n", $suite, self::$passed, self::$failed);

        foreach (self::$failures as $failure) {
            echo '  - ' . $failure . "\n";
        }

        return 1;
    }
}

/** @return array<mixed> */
function fixture(string $name): array
{
    return (array) json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name), true);
}
