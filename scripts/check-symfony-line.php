<?php

declare(strict_types=1);

/*
 * Fails when a Symfony framework component in composer.lock is off the line set in
 * composer.json's extra.symfony.require (e.g. "7.4.*").
 *
 * Why: Flex applies that setting to a local `composer update`, but Dependabot doesn't.
 * In 24f2559 a grouped Dependabot bump moved 14 indirect components to 8.1, and the
 * next local security update then refused to resolve. CI runs this so a drift fails
 * on the Dependabot PR itself.
 *
 * Symfony packages with their own version numbers aren't framework components and are
 * skipped by the list below. A package that is neither on the line nor in that list
 * fails, so a new one gets a look instead of slipping through.
 *
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

$readJson = static function (string $path): array {
    $data = json_decode((string) @file_get_contents($path), true);
    if (!is_array($data)) {
        fwrite(STDERR, "Cannot read {$path}\n");
        exit(2);
    }

    return $data;
};

$composer = $readJson($root . '/composer.json');
$line = $composer['extra']['symfony']['require'] ?? null;
if (!is_string($line) || preg_match('/^(\d+)\.(\d+)\.\*$/', $line, $m) !== 1) {
    fwrite(STDERR, "composer.json extra.symfony.require must look like \"7.4.*\", found: "
        . var_export($line, true) . "\n");
    exit(2);
}
$prefix = $m[1] . '.' . $m[2] . '.';

$lock = $readJson($root . '/composer.lock');
$off = [];
$checked = 0;
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
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
    $version = ltrim($package['version'], 'v');
    if (!str_starts_with($version, $prefix)) {
        $off[] = "  {$name} {$package['version']}";
    }
}

if ($off !== []) {
    fwrite(STDERR, "These Symfony packages are off the {$line} line set in composer.json"
        . " (extra.symfony.require):\n" . implode("\n", $off) . "\n"
        . "Bring them back with a local `composer update <package>...` (Flex applies the line),"
        . " or, if the package has its own version numbers, add it to the list in "
        . basename(__FILE__) . ".\n");
    exit(1);
}

echo "OK: {$checked} Symfony components on {$line}\n";
