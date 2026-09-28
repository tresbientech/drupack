<?php

declare(strict_types=1);

// The runner every test file here shares. test() registers a case, same() and
// throws() fail it, and runCases() runs them all and returns the exit status.

$cases = [];

function test(string $name, callable $case): void
{
    $GLOBALS['cases'][$name] = $case;
}

function same(mixed $expected, mixed $actual, string $note = ''): void
{
    if ($expected === $actual) {
        return;
    }
    throw new RuntimeException(($note === '' ? '' : "$note: ")
        . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function throws(string $needle, callable $case): void
{
    try {
        $case();
    } catch (Throwable $error) {
        if (!str_contains($error->getMessage(), $needle)) {
            throw new RuntimeException("expected a message holding \"$needle\", got \"{$error->getMessage()}\"");
        }
        return;
    }
    throw new RuntimeException("expected a throw holding \"$needle\", nothing was thrown");
}

function runCases(): int
{
    $failed = 0;
    foreach ($GLOBALS['cases'] as $name => $case) {
        try {
            $case();
            fwrite(STDOUT, "ok   $name\n");
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDOUT, "FAIL $name\n       {$error->getMessage()}\n");
        }
    }
    $total = count($GLOBALS['cases']);
    fwrite(STDOUT, $failed === 0 ? "\n$total cases passed\n" : "\n$failed of $total cases failed\n");
    return $failed === 0 ? 0 : 1;
}
