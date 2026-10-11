#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Apple Health / Health Export → NouriLoop sync (standalone, runs on your Mac).
 *
 * Two input formats are auto-detected from --file:
 *
 *   • Apple Health `export.xml` — streamed locally and POSTed to the ingestion
 *     API in small batches, so a huge export never has to go through the
 *     size-capped web upload. Mirrors AppleHealthXmlImporter's type mapping.
 *
 *   • "Health Export" iPhone app `.json` — the file with a root `exportInfo`
 *     header and camelCase metric keys. The whole file is POSTed verbatim to
 *     `…/health-metrics/health-export`; the server owns the mapping. Ideal for a
 *     daily cron job pointed at the app's Dropbox/iCloud export folder.
 *
 * No app/framework dependencies; needs only PHP with the xmlreader (XML path)
 * and curl extensions. The server validates and de-duplicates, so re-running is
 * always safe.
 *
 * Usage:
 *   php scripts/apple-health-sync.php \
 *       --file=/path/to/export.xml        # or a .json file, or a directory of .json
 *       --url=https://nouriloop.com/api/health-metrics \
 *       --token=nutri_xxxxxxxx            # or set NOURILOOP_TOKEN (NUTRILOG_TOKEN still honored)
 *   Options: --batch=500 (max 1000, XML only)  --app=<key> (force a JSON source
 *            app instead of auto-detecting)  --dry-run
 *
 * Cron example (newest Health Export JSON in a Dropbox folder, nightly at 9 PM):
 *   0 21 * * * php /path/to/scripts/apple-health-sync.php \
 *       --file="$HOME/Library/CloudStorage/Dropbox/Health" \
 *       --url=https://nouriloop.com/api/health-metrics --token=nutri_xxx >> ~/health-sync.log 2>&1
 */

const MAX_BATCH = 1000;

const SLEEP_HK_TYPE = 'HKCategoryTypeIdentifierSleepAnalysis';

const SLEEP_STAGE_MAP = [
    'HKCategoryValueSleepAnalysisInBed'      => 'sleep_in_bed',
    'HKCategoryValueSleepAnalysisAsleep'     => 'sleep_asleep',
    'HKCategoryValueSleepAnalysisAsleepCore' => 'sleep_core',
    'HKCategoryValueSleepAnalysisAsleepDeep' => 'sleep_deep',
    'HKCategoryValueSleepAnalysisAsleepREM'  => 'sleep_rem',
];

const TYPE_MAP = [
    'HKQuantityTypeIdentifierBodyMass'               => 'weight',
    'HKQuantityTypeIdentifierBloodGlucose'           => 'blood_glucose',
    'HKQuantityTypeIdentifierBloodPressureSystolic'  => 'blood_pressure_systolic',
    'HKQuantityTypeIdentifierBloodPressureDiastolic' => 'blood_pressure_diastolic',
    'HKQuantityTypeIdentifierHeartRate'              => 'heart_rate',
    'HKQuantityTypeIdentifierBodyFatPercentage'      => 'body_fat_percentage',
];

/** @return never */
function fail(string $message): void
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

$opts  = getopt('', ['file:', 'url:', 'token::', 'batch::', 'app::', 'dry-run']);
$file  = $opts['file'] ?? null;
$url   = $opts['url'] ?? null;
// NOURILOOP_TOKEN is preferred; NUTRILOG_TOKEN stays as a fallback so existing
// crons/automations that set the old name keep working.
$token = $opts['token'] ?? getenv('NOURILOOP_TOKEN') ?: getenv('NUTRILOG_TOKEN') ?: null;
$app   = $opts['app'] ?? null; // optional source-app override (JSON only); else auto-detected
$batchSize = max(1, min(MAX_BATCH, (int) ($opts['batch'] ?? 500)));
$dryRun = array_key_exists('dry-run', $opts);

if (!is_string($file) || $file === '') {
    fail('--file=/path/to/export.(xml|json) is required (a directory of .json files is also accepted).');
}

// A directory means "the newest JSON export in it" — convenient for cron.
if (is_dir($file)) {
    $newest = newestJson($file);
    if ($newest === null) {
        fail("No .json files found in directory {$file}.");
    }
    $file = $newest;
}

if (!is_file($file)) {
    fail("File not found: {$file}.");
}

$format = detectFormat($file);

if (!$dryRun && (!is_string($url) || $url === '')) {
    fail('--url=https://…/api/health-metrics is required (unless --dry-run).');
}
if (!$dryRun && (!is_string($token) || $token === '')) {
    fail('--token=… is required (or set NOURILOOP_TOKEN) unless --dry-run.');
}

if ($format === 'json') {
    syncJson($file, $url, $token, $dryRun, is_string($app) ? $app : null);
} else {
    syncXml($file, $url, $token, $dryRun, $batchSize);
}

/**
 * Detects the input format by extension, falling back to a content peek.
 *
 * @return 'xml'|'json'
 */
function detectFormat(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext === 'xml') {
        return 'xml';
    }
    if ($ext === 'json') {
        return 'json';
    }

    $handle = @fopen($file, 'r');
    $peek   = $handle ? ltrim((string) fread($handle, 100)) : '';
    if ($handle) {
        fclose($handle);
    }

    if (str_starts_with($peek, '{') || str_starts_with($peek, '[')) {
        return 'json';
    }

    return 'xml';
}

/** Returns the most recently modified *.json file in a directory, or null. */
function newestJson(string $dir): ?string
{
    $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
    if ($files === []) {
        return null;
    }

    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return $files[0];
}

/**
 * Posts a Health Export JSON file to the server verbatim; the server maps and
 * de-duplicates. Daily exports are tiny, so the whole file goes in one request.
 */
function syncJson(string $file, ?string $url, ?string $token, bool $dryRun, ?string $app): void
{
    $raw  = (string) file_get_contents($file);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fail("Not a valid JSON export: {$file}.");
    }

    if ($dryRun) {
        $keys = array_keys(array_filter($data, static fn($v): bool => is_array($v) && array_is_list($v)));
        printf(
            "[dry-run] %s looks like valid health-export JSON%s (%d metric arrays: %s).\n",
            basename($file),
            $app !== null ? " (app={$app})" : '',
            count($keys),
            implode(', ', $keys),
        );

        return;
    }

    $endpoint = healthExportUrl((string) $url);
    if ($app !== null && $app !== '') {
        $endpoint .= '?app=' . rawurlencode($app);
    }
    $decoded = postJson($endpoint, (string) $token, $raw);

    printf(
        "%d imported, %d skipped (duplicates/unmapped), %d errors.\n",
        (int) ($decoded['imported'] ?? 0),
        (int) ($decoded['skipped'] ?? 0),
        count($decoded['errors'] ?? []),
    );
}

/** Derives the health-export endpoint from a base `…/health-metrics` URL. */
function healthExportUrl(string $url): string
{
    $url = rtrim($url, '/');

    return str_ends_with($url, '/health-export') ? $url : $url . '/health-export';
}

/**
 * Streams an Apple Health export.xml and POSTs recognised records in batches.
 */
function syncXml(string $file, ?string $url, ?string $token, bool $dryRun, int $batchSize): void
{
    $reader = new XMLReader();
    if (!@$reader->open($file)) {
        fail("Cannot open {$file}.");
    }

    $batch  = [];
    $parsed = 0;
    $sent   = 0;
    $totals = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Record') {
            continue;
        }

        $record = recordFrom($reader);
        if ($record === null) {
            continue;
        }

        $batch[] = $record;
        $parsed++;

        if (count($batch) >= $batchSize) {
            flushBatch($batch, $url, $token, $dryRun, $totals);
            $sent += count($batch);
            $batch = [];
            fwrite(STDERR, "\rparsed {$parsed}, sent {$sent}, imported {$totals['imported']}, skipped {$totals['skipped']}   ");
        }
    }
    $reader->close();

    if (!empty($batch)) {
        flushBatch($batch, $url, $token, $dryRun, $totals);
        $sent += count($batch);
    }

    fwrite(STDERR, "\n");
    printf(
        "%s%d records parsed, %d imported, %d skipped (duplicates/unrecognized), %d batch errors.\n",
        $dryRun ? '[dry-run] ' : '',
        $parsed,
        $totals['imported'],
        $totals['skipped'],
        $totals['errors'],
    );
}

/**
 * Builds one API record from the reader's current <Record>, or null to skip.
 *
 * @return array{type: string, value: float, unit?: string, recordedAt: string, source: string}|null
 */
function recordFrom(XMLReader $reader): ?array
{
    $hkType = $reader->getAttribute('type') ?? '';
    $source = substr($reader->getAttribute('sourceName') ?? 'Apple Health', 0, 100);

    if ($hkType === SLEEP_HK_TYPE) {
        $metric = SLEEP_STAGE_MAP[$reader->getAttribute('value') ?? ''] ?? null;
        if ($metric === null) {
            return null; // Awake / unrecognized stage.
        }

        $start = $reader->getAttribute('startDate');
        $end   = $reader->getAttribute('endDate');
        if ($start === null || $end === null) {
            return null;
        }

        $minutes = (strtotime($end) - strtotime($start)) / 60;
        if ($minutes <= 0 || $minutes > 720) {
            return null;
        }

        return [
            'type'       => $metric,
            'value'      => round($minutes, 2),
            'recordedAt' => $start,
            'source'     => $source,
        ];
    }

    $metric = TYPE_MAP[$hkType] ?? null;
    if ($metric === null) {
        return null;
    }

    $value = filter_var($reader->getAttribute('value'), FILTER_VALIDATE_FLOAT);
    $start = $reader->getAttribute('startDate');
    if ($value === false || $start === null) {
        return null;
    }

    return [
        'type'       => $metric,
        'value'      => (float) $value,
        'unit'       => $reader->getAttribute('unit') ?? '',
        'recordedAt' => $start,
        'source'     => $source,
    ];
}

/**
 * POSTs a batch to the ingestion API and accumulates server-reported totals.
 *
 * @param array<array<string, mixed>> $batch
 * @param array{imported:int,skipped:int,errors:int} $totals
 */
function flushBatch(array $batch, ?string $url, ?string $token, bool $dryRun, array &$totals): void
{
    if ($dryRun) {
        return;
    }

    $decoded = postJson((string) $url, (string) $token, json_encode(['metrics' => $batch], JSON_THROW_ON_ERROR));
    $totals['imported'] += (int) ($decoded['imported'] ?? 0);
    $totals['skipped']  += (int) ($decoded['skipped'] ?? 0);
    $totals['errors']   += count($decoded['errors'] ?? []);
}

/**
 * POSTs a JSON body to the API and returns the decoded response, failing hard on
 * transport or HTTP errors.
 *
 * @return array<string, mixed>
 */
function postJson(string $url, string $token, string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err      = curl_error($ch);

    if ($response === false) {
        fail("Request failed: {$err}");
    }
    if ($status === 401) {
        fail('Unauthorized (401) — check your token.');
    }
    if ($status < 200 || $status >= 300) {
        fail("Server returned HTTP {$status}: {$response}");
    }

    $decoded = json_decode((string) $response, true);

    return is_array($decoded) ? $decoded : [];
}
