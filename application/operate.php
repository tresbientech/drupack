<?php

declare(strict_types=1);

// One named operation per call, run by the launcher through php-cli:
//   operate.php check folder PROJECT DOCROOT
//   operate.php check site DATA
//   operate.php prepare DATA
//   operate.php login URL DRUSH DIRECTORY [PATH]
// An operation prints its step lines on stderr and one JSON record on stdout. A refusal prints
// its message on stderr and exits 1. A site operation reads the start's database, administrator
// and site name options from their DRUPACK_* variables, so no credential reaches argv.

require_once __DIR__ . '/process.php';

use Drupack\Support\Need;
use Drupack\Support\SiteContract;
use Drupack\Support\SiteData;
use Drupack\Support\Step;

// composer.lock is the reader's file. An extension it declares that this runtime
// lacks fails only once a request reaches the code needing it, so a start refuses.
// Composer's own platform_check.php tests the PHP version alone by default.
function checkPlatform(string $binary, string $project): void
{
    $check = "$project/vendor/composer/platform_check.php";
    if (is_file($check)) {
        $child = proc_open([$binary, 'php-cli', $check], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($child)) {
            throw new RuntimeException("Cannot run $check");
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        if (proc_close($child) !== 0) {
            throw new RuntimeException("This runtime cannot run $project.\n" . trim($output));
        }
    }
    $lock = json_decode((string) @file_get_contents("$project/composer.lock"), true);
    if (!is_array($lock)) {
        return;
    }
    $declared = [];
    foreach (array_keys($lock['platform'] ?? []) as $requirement) {
        $declared[$requirement][] = "$project/composer.json";
    }
    foreach ($lock['packages'] ?? [] as $package) {
        foreach (array_keys($package['require'] ?? []) as $requirement) {
            $declared[$requirement][] = $package['name'];
        }
    }
    $missing = [];
    foreach ($declared as $requirement => $declarers) {
        // Composer spells an extension's spaces as dashes: ext-zend-opcache.
        if (str_starts_with($requirement, 'ext-') && !extension_loaded(str_replace('-', ' ', substr($requirement, 4)))) {
            $missing[] = '  ' . substr($requirement, 4) . ': ' . implode(', ', $declarers);
        }
    }
    if ($missing !== []) {
        throw new RuntimeException("$project/composer.lock declares PHP extensions this runtime lacks:\n" . implode("\n", $missing));
    }
}

// Null when the probe cannot tell, such as for a database it cannot reach.
function siteInstalled(string $binary, string $project, string $docroot): ?bool
{
    $descriptors = [0 => ['file', nullDevice(), 'r'], 1 => ['pipe', 'w'], 2 => ['file', nullDevice(), 'w']];
    $child = proc_open([$binary, 'php-cli', __DIR__ . '/installed.php', $project, $docroot], $descriptors, $pipes, $project);
    if (!is_resource($child)) {
        throw new RuntimeException('Cannot run the installation probe');
    }
    $answer = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    $status = proc_close($child);
    return $status === 0 ? $answer === 'yes' : null;
}


// The platform check, then the installation probe. installed is null when the probe cannot tell.
function check(string $binary, string $project, string $docroot): array
{
    checkPlatform($binary, $project);
    return ['installed' => siteInstalled($binary, $project, $docroot)];
}

// The one-time login link for uid 1 at $url, opening $path when given, or the reason Drush gave
// when it cannot mint one. The launcher decides whether a start serves without the link.
function mintLoginLink(string $binary, string $url, string $drush, string $directory, array $path): array
{
    try {
        return ['link' => drushLoginLink($binary, $drush, $directory, ["--uri=$url", ...$path], $url)];
    } catch (LoginLinkFailure $failure) {
        return ['failure' => $failure->reason];
    }
}

// Whether the Packaged site at $data owes no step. Refuses, before the launcher writes any Site
// data, when the options lack what an owed step needs.
function checkSite(string $data, SiteContract $contract): array
{
    [$steps] = owedSteps(new SiteData($data), startOptions($contract), $contract);
    return ['installed' => $steps === []];
}

// Runs the steps $data owes, under the launcher's lease. They are read again here: another start
// may have changed them since `check site`, and the list can owe the administrator step with
// credentials generated now. credentials reports whether a step needed them, adopted whether the
// database already held a site.
function prepare(string $binary, string $data, SiteContract $contract): array
{
    $site = new SiteData($data);
    [$steps, $options] = owedSteps($site, startOptions($contract), $contract);
    // The administrator step's Drush reads them from the environment, never from argv.
    putenv("DRUPACK_ADMIN_USER={$options['admin-user']}");
    putenv("DRUPACK_ADMIN_PASSWORD={$options['admin-password']}");
    // A start refreshes the recorded settings from this release's template. A pending settings
    // step writes the file itself, so its contents are read once they are final. A resumed step
    // then names the recorded database, never one the command line names.
    if (file_exists($site->settings()) && !in_array(Step::Settings, $steps, true)) {
        $options = recordedOptions($options, $site);
        writeSettings($site->settings(), application() . '/settings.php', databaseConfiguration($options, $site), $contract->settings);
    }
    // The remaining steps reach the disk before the first one changes anything, and the
    // completion marker follows the last one.
    $number = 0;
    $adopted = $site->initialize($steps, function (Step $step, bool $adopted) use (&$number, $steps, $site, $options, $binary): bool {
        fwrite(STDERR, sprintf("[%d/%d] %s\n", ++$number, count($steps), $step->report()));
        return runStep($step, $site, $options, $binary, $adopted);
    });
    return ['adopted' => $adopted, 'credentials' => in_array(Need::Credentials, Step::owed($steps), true)];
}

function operate(string $binary, array $words): array
{
    return match (true) {
        $words[0] === 'check' && $words[1] === 'folder' => check($binary, $words[2], $words[3]),
        $words[0] === 'check' && $words[1] === 'site' => checkSite($words[2], siteContract()),
        $words[0] === 'prepare' => prepare($binary, $words[1], siteContract()),
        $words[0] === 'login' => mintLoginLink($binary, $words[1], $words[2], $words[3], array_slice($words, 4)),
    };
}

// application/tests/serve_test.php and operate_test.php load this file for its functions alone.
if (defined('DRUPACK_SERVE_LIBRARY')) {
    return;
}

try {
    // A Packaged site's operations run launch.php's preparation functions. The engine's payload
    // carries no launch.php and calls neither.
    if ($argv[1] === 'prepare' || $argv[1] === 'check' && $argv[2] === 'site') {
        require __DIR__ . '/launch.php';
    }
    $record = operate(getenv('DRUPACK_RUNTIME_BINARY'), array_slice($argv, 1));
    fwrite(STDOUT, json_encode($record, JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
