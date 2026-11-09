<?php

declare(strict_types=1);

/*
 * Fails when a Symfony framework component in composer.lock is off the line set in
 * composer.json's extra.symfony.require (e.g. "7.4.*").
 *
 * Why: Flex applies that setting to a local `composer update`, but Dependabot doesn't.
 * In 24f2559 a grouped Dependabot bump moved 14 indirect components to 8.1, and the
 * next local security update then refused to resolve. composer.json's `conflict`
 * entries now keep the indirect components off 8.x for Composer and Dependabot alike;
 * this check, run in CI, is the backstop for anything those entries don't name.
 *
 * Symfony packages with their own version numbers aren't framework components and are
 * skipped by the list below. A package that is neither on the line nor in that list
 * fails, so a new one gets a look instead of slipping through.
 *
 * Exit codes: 0 on the line, 1 off it, 2 when the files can't be checked.
 * Usage: php scripts/check-symfony-line.php [path/to/project]
 */

$root = rtrim($argv[1] ?? dirname(__DIR__), '/');

/** Symfony packages versioned on their own, outside the framework's release line. */
$ownVersioning = [
    '#^symfony/flex$#',
    '#^symfony/([a-z-]+-)?contracts$#',
    '#^symfony/polyfill-#',
    '#^symfony/ux-#',
    '#^symfony/stimulus-bundle$#',
    '#^symfony/monolog-bundle$#',
    '#^symfony/maker-bundle$#',
    '#^symfony/panther$#',
];

$fail = static function (string $message): never {
    fwrite(STDERR, $message . "\n");
    exit(2);
};

$readJson = static function (string $path) use ($fail): array {
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) {
        $fail("Cannot read {$path} as JSON.");
    }

    return $data;
};

$composer = $readJson($root . '/composer.json');
$line = $composer['extra']['symfony']['require'] ?? null;
if (!is_string($line) || preg_match('/^(\d+)\.(\d+)\.\*\z/', $line, $m) !== 1) {
    $fail('composer.json extra.symfony.require must look like "7.4.*", found: ' . var_export($line, true));
}
$prefix = $m[1] . '.' . $m[2] . '.';

$lock = $readJson($root . '/composer.lock');
$packages = [];
foreach (['packages', 'packages-dev'] as $section) {
    $list = $lock[$section] ?? [];
    if (!is_array($list)) {
        $fail("composer.lock: \"{$section}\" is not a list.");
    }
    foreach ($list as $package) {
        if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
            $fail("composer.lock: an entry in \"{$section}\" has no name or version.");
        }
        $packages[] = $package;
    }
}

$off = [];
$checked = 0;
foreach ($packages as $package) {
    $name = $package['name'];
    if (!str_starts_with($name, 'symfony/')) {
        continue;
    }
    foreach ($ownVersioning as $pattern) {
        if (preg_match($pattern, $name) === 1) {
            continue 2;
        }
    }
    ++$checked;
    $version = preg_replace('/^v/', '', $package['version']);
    if (!str_starts_with($version, $prefix)) {
        $off[] = "  {$name} {$package['version']}";
    }
}

if ($checked === 0) {
    $fail("No Symfony framework components found in {$root}/composer.lock; nothing was checked.");
}

if ($off !== []) {
    fwrite(STDERR, "These Symfony packages are off the {$line} line set in composer.json"
        . " (extra.symfony.require):\n" . implode("\n", $off) . "\n"
        . "Bring them back with a local `composer update <package>...` (Flex applies the line) and"
        . " add a `conflict` entry for any that composer.json doesn't name, or, if the package has"
        . " its own version numbers, add it to the list in " . basename(__FILE__) . ".\n");
    exit(1);
}

echo "OK: {$checked} Symfony components on {$line}\n";
