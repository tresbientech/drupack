<?php

declare(strict_types=1);

// Unit cases for the functions in composer/drupack-install. Run with:
//   ./dist/mercury-demo-linux-amd64 php-cli "$PWD/application/tests/drupack_install_test.php"
// The constant loads drupack-install for its functions alone. PHP prints the
// shebang line of an included file, so the buffer drops it.

define('DRUPACK_INSTALL_LIBRARY', true);
require __DIR__ . '/../vendor/autoload.php';
ob_start();
require __DIR__ . '/../../composer/drupack-install';
ob_end_clean();

require __DIR__ . '/cases.php';
$root = sys_get_temp_dir() . '/drupack-install-test-' . bin2hex(random_bytes(8));
mkdir($root);

// An empty directory of its own for one case.
function directory(string $name): string
{
    $directory = $GLOBALS['root'] . "/$name";
    mkdir($directory);
    return $directory;
}

test('a release tag is the version to install', function (): void {
    same('0.5.1', releaseVersion('0.5.1'));
    same('1.0.0-rc1', releaseVersion('1.0.0-rc1'));
});

test('a branch or an unreadable version is refused', function (): void {
    foreach (['dev-main', '0.5.x-dev', '0.5.1/../../other', 'not a version', null] as $installed) {
        throws('names no release', fn () => releaseVersion($installed));
    }
});

test('the version enters a release URL as one encoded path segment', function (): void {
    same('https://github.com/tresbientech/drupack/releases/download/0.5.1/install-drupack.sh', releaseUrl('0.5.1', 'install-drupack.sh'));
    same('https://github.com/tresbientech/drupack/releases/download/0.5.1%0A%2F..%2Fx/checksums.txt', releaseUrl("0.5.1\n/../x", 'checksums.txt'));
});

test('each system runs the fetched script as a file', function (): void {
    same(['sh', '/tmp/script.sh'], installCommand(false, '/tmp/script.sh'));
    same(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', 'C:/Temp/script.ps1'], installCommand(true, 'C:/Temp/script.ps1'));
    same('drupack.exe', executable(true));
    same('drupack', executable(false));
});

test('the fetched script runs in the project root with DRUPACK_LIBC', function (): void {
    $directory = directory('environment');
    $script = "$directory/install.sh";
    file_put_contents($script, "printf %s \"\$DRUPACK_LIBC\" > libc\necho discarded\n");
    putenv('DRUPACK_LIBC=musl');
    try {
        same(0, runScript(false, $script, $directory));
    } finally {
        putenv('DRUPACK_LIBC');
    }
    same('musl', file_get_contents("$directory/libc"));
});

test('a build is current when the release lists its SHA-256 for drupack', function (): void {
    $directory = directory('current');
    file_put_contents("$directory/drupack", 'a drupack build');
    $sha256 = hash('sha256', 'a drupack build');
    same(true, isRelease("$directory/drupack", "0000  drupack-0.5.1-macos-arm64\n$sha256  drupack-0.5.1-linux-amd64\n", '0.5.1'));
    same(false, isRelease("$directory/drupack", "$sha256  drupack-0.5.2-linux-amd64\n", '0.5.1'));
    same(false, isRelease("$directory/drupack", "$sha256  mercury-demo-0.5.1-linux-amd64\n", '0.5.1'));
    file_put_contents("$directory/drupack", "#!/bin/sh\necho 'drupack 0.5.1 (drupack 0.5.1, glibc)'\n");
    same(false, isRelease("$directory/drupack", "$sha256  drupack-0.5.1-linux-amd64\n", '0.5.1'));
});

test('the executable joins an existing .gitignore once', function (): void {
    $directory = directory('ignore');
    file_put_contents("$directory/.gitignore", "/vendor/");
    same(true, ignore($directory, 'drupack'));
    same(false, ignore($directory, 'drupack'));
    same("/vendor/\n/drupack\n", file_get_contents("$directory/.gitignore"));
});

test('a .gitignore naming the executable already is left alone', function (): void {
    $directory = directory('listed');
    file_put_contents("$directory/.gitignore", "drupack\n");
    same(false, ignore($directory, 'drupack'));
    same("drupack\n", file_get_contents("$directory/.gitignore"));
});

test('a project outside git without .gitignore gets none', function (): void {
    $directory = directory('absent');
    same(false, ignore($directory, 'drupack'));
    same(false, file_exists("$directory/.gitignore"));
});

test('a git project without .gitignore gets one', function (): void {
    $directory = directory('repository');
    mkdir("$directory/.git");
    same(true, ignore($directory, 'drupack'));
    same("/drupack\n", file_get_contents("$directory/.gitignore"));
});

test('a project inside a parent git repository gets a .gitignore', function (): void {
    $directory = directory('monorepo');
    // A worktree or submodule holds .git as a file.
    file_put_contents("$directory/.git", "gitdir: elsewhere\n");
    mkdir("$directory/site");
    same(true, ignore("$directory/site", 'drupack'));
    same("/drupack\n", file_get_contents("$directory/site/.gitignore"));
});

$status = runCases();
chdir(sys_get_temp_dir());
exec('rm -rf ' . escapeshellarg($root));

exit($status);
