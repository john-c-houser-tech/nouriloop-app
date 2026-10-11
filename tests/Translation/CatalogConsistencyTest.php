<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Mechanical guardrails for the translation catalogs (i18n Phase 3):
 *
 *  - FULL catalogs (es, de, fr, ru, hi) carry the exact same dotted-key set as
 *    the en source — a key added to en without every full catalog fails here,
 *    which is the point (the house rule is catalogs ship in the same PR).
 *  - OVERRIDE catalogs (es_MX, es_ES, en_GB) are strict subsets of their base.
 *  - Every message COMPILES as ICU MessageFormat in its own locale — a stray
 *    unescaped brace or malformed plural would otherwise 500 at render time.
 *  - Every message's placeholder-name set matches the en source's for that key
 *    (plural CATEGORIES may differ per language; argument NAMES may not).
 *  - Validators catalogs share the es file's key set and keep `{{ limit }}`
 *    style placeholders verbatim (that domain is not ICU).
 */
final class CatalogConsistencyTest extends TestCase
{
    private const DIR = __DIR__ . '/../../translations';

    /** locale => base locale whose key set it must be a subset of */
    private const OVERRIDES = ['es_MX' => 'es', 'es_ES' => 'es', 'en_GB' => 'en'];

    private const FULL = ['es', 'de', 'fr', 'ru', 'hi'];

    /** @var array<string, array<string, string>> */
    private static array $flat = [];

    /** @return array<string, string> dotted key => message */
    private static function catalog(string $locale): array
    {
        if (!isset(self::$flat[$locale])) {
            $file = self::DIR . "/messages+intl-icu.{$locale}.yaml";
            self::assertFileExists($file);
            $flat = [];
            self::flatten((array) Yaml::parseFile($file), '', $flat);
            self::$flat[$locale] = $flat;
        }

        return self::$flat[$locale];
    }

    /** @param array<string, mixed> $tree @param array<string, string> $out */
    private static function flatten(array $tree, string $prefix, array &$out): void
    {
        foreach ($tree as $key => $value) {
            $dotted = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (\is_array($value)) {
                self::flatten($value, $dotted, $out);
            } else {
                $out[$dotted] = (string) $value;
            }
        }
    }

    /**
     * ICU argument names used in a message. A brace-depth scanner, because a
     * naive regex can't tell `{points}` (an argument) from `{decrease}` (the
     * literal body of a select branch): real arguments always OPEN at odd
     * depth (top level = 1, nested inside a plural/select branch = 3, …),
     * branch bodies open at even depth. Quoted sections ('' = literal quote)
     * are skipped per ICU quoting rules.
     *
     * @return list<string>
     */
    private static function placeholders(string $message): array
    {
        $names = [];
        $depth = 0;
        $inQuote = false;
        $len = \strlen($message);

        for ($i = 0; $i < $len; $i++) {
            $c = $message[$i];
            if ($c === "'") {
                if ($inQuote) {
                    $inQuote = false; // any single quote ends a quoted section
                } elseif ($i + 1 < $len && $message[$i + 1] === "'") {
                    $i++; // '' = escaped literal apostrophe
                } elseif ($i + 1 < $len && \in_array($message[$i + 1], ['{', '}', '#'], true)) {
                    // ICU: an apostrophe starts quoting ONLY before a special
                    // char — "we've" is a literal apostrophe, not a quote.
                    $inQuote = true;
                }
                continue;
            }
            if ($inQuote) {
                continue;
            }
            if ($c === '{') {
                $depth++;
                if ($depth % 2 === 1 && preg_match('/\G\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*[,}]/', $message, $m, 0, $i)) {
                    $names[$m[1]] = true;
                }
            } elseif ($c === '}') {
                $depth = max(0, $depth - 1);
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    public function testFullCatalogsCarryTheExactEnglishKeySet(): void
    {
        $en = array_keys(self::catalog('en'));
        sort($en);

        foreach (self::FULL as $locale) {
            $keys = array_keys(self::catalog($locale));
            sort($keys);

            self::assertSame(
                [],
                array_values(array_diff($en, $keys)),
                "{$locale}: keys missing vs en",
            );
            self::assertSame(
                [],
                array_values(array_diff($keys, $en)),
                "{$locale}: orphan keys not present in en",
            );
        }
    }

    public function testOverrideCatalogsAreStrictSubsetsOfTheirBase(): void
    {
        foreach (self::OVERRIDES as $locale => $base) {
            $baseKeys = array_keys(self::catalog($base));
            $orphans = array_diff(array_keys(self::catalog($locale)), $baseKeys);

            self::assertSame([], array_values($orphans), "{$locale}: keys not present in base {$base}");
            self::assertNotSame([], self::catalog($locale), "{$locale}: override catalog is empty");
        }
    }

    public function testEveryMessageCompilesAsIcuInItsOwnLocale(): void
    {
        foreach ([...self::FULL, ...array_keys(self::OVERRIDES), 'en'] as $locale) {
            foreach (self::catalog($locale) as $key => $message) {
                $formatter = \MessageFormatter::create(str_replace('_', '-', $locale), $message);
                self::assertNotNull(
                    $formatter,
                    sprintf('%s: "%s" is not valid ICU: %s', $locale, $key, intl_get_error_message()),
                );
            }
        }
    }

    public function testPlaceholderNamesMatchTheEnglishSource(): void
    {
        $en = self::catalog('en');
        $es = self::catalog('es');

        foreach ([...self::FULL, ...array_keys(self::OVERRIDES)] as $locale) {
            $reference = str_starts_with($locale, 'es_') ? $es : $en;
            foreach (self::catalog($locale) as $key => $message) {
                if (!isset($reference[$key])) {
                    continue; // key-set tests report this separately
                }
                self::assertSame(
                    self::placeholders($reference[$key]),
                    self::placeholders($message),
                    "{$locale}: placeholder names drifted for \"{$key}\"",
                );
            }
        }
    }

    public function testValidatorsCatalogsShareKeysAndKeepTwigStylePlaceholders(): void
    {
        $reference = (array) Yaml::parseFile(self::DIR . '/validators.es.yaml');
        $files = glob(self::DIR . '/validators.*.yaml') ?: [];
        self::assertNotSame([], $files);

        foreach ($files as $file) {
            $catalog = (array) Yaml::parseFile($file);
            $name = basename($file);

            self::assertSame(
                [],
                array_values(array_diff(array_keys($reference), array_keys($catalog))),
                "{$name}: keys missing vs validators.es.yaml",
            );
            self::assertSame(
                [],
                array_values(array_diff(array_keys($catalog), array_keys($reference))),
                "{$name}: orphan keys not present in validators.es.yaml",
            );

            foreach ($catalog as $key => $message) {
                preg_match_all('/\{\{\s*\w+\s*\}\}/', (string) $key, $inKey);
                preg_match_all('/\{\{\s*\w+\s*\}\}/', (string) $message, $inMessage);
                $normalize = static fn (array $m): array => array_map(
                    static fn (string $p): string => preg_replace('/\s+/', ' ', $p),
                    $m,
                );
                $expected = $normalize($inKey[0]);
                $actual = $normalize($inMessage[0]);
                sort($expected);
                sort($actual);
                self::assertSame(
                    $expected,
                    $actual,
                    "{$name}: {{ placeholder }} set drifted for \"{$key}\"",
                );
            }
        }
    }
}
