<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\SymptomTypeRepository;
use App\Service\DemoDataSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Panther\Client;
use Symfony\Component\Panther\ProcessManager\WebServerManager;

/**
 * Captures one clean PNG per help topic into `public/images/help/`, driving the real
 * app in a headless browser against the seeded {@see DemoDataSeeder} demo user — so the
 * help-center screenshots show real, varied data and stay reproducible.
 *
 * Run it in the **test** env (Claude is mocked, and the dev web-debug toolbar is off, so
 * the captures are clean): the command seeds the demo user into `nutrition_test`, boots a
 * Panther web server + headless Chrome (chromedriver from ./drivers), logs in, and shoots
 * each page. Re-runnable; overwrites the PNGs in place.
 *
 *   APP_ENV=test php bin/console app:capture-help-screenshots
 *
 * Needs the test DB migrated (the e2e suite already does this) and a Chrome/chromedriver
 * pair (vendor/bin/bdi detect drivers), same as the Panther E2E suite.
 */
#[AsCommand(
    name: 'app:capture-help-screenshots',
    description: 'Capture help-center screenshots from the demo user (test env)',
)]
class CaptureHelpScreenshotsCommand extends Command
{
    private const HOST = '127.0.0.1';
    private const PORT = 9081;

    /** Settle time (ms) after a page loads, so Chart.js charts finish drawing. */
    private const SETTLE_MS = 1400;

    /**
     * Help topic slug => the app route to shoot. One entry per HelpController::TOPICS
     * topic; the inflammation topic points at Trends (where its chart lives).
     *
     * @var array<string, string>
     */
    private const SHOTS = [
        'dashboard'      => '/',
        'log-meal'       => '/meal/log',
        'favorites'      => '/meal/favorites',
        'import-recipe'  => '/meal/recipe/scan',
        'health-metrics' => '/health-metrics',
        'supplements'    => '/supplements',
        'inflammation'   => '/trends',
        'symptoms'       => '/symptoms',
        'glucose'        => '/insights/glucose',
        'connect-cgm'    => '/connect-cgm',
        'goals'          => '/goals',
        'settings'       => '/settings',
    ];

    /**
     * The symptom used for the Symptoms topic's second, supplementary screenshot — the
     * per-symptom Diet/Health small-multiples chart on Trends. Its `.insight-panel` is
     * `d-none` until a tile is tapped, so a plain `/trends` GET (the `inflammation` shot
     * above) never renders it; this one deep-links straight to the panel via the
     * `#panel=symptom-<id>` hash the page's own JS already honours on load.
     */
    private const CHART_SYMPTOM_KEY = 'joint_stiffness';

    public function __construct(
        private readonly DemoDataSeeder $seeder,
        private readonly KernelInterface $kernel,
        private readonly EntityManagerInterface $em,
        private readonly SymptomTypeRepository $symptomTypeRepo,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = $this->kernel->getEnvironment();

        // Test-env only: this reseeds the demo user and overwrites the committed PNGs, so
        // refuse to run anywhere a real DB or deployed assets could be touched. Test also
        // gives clean captures (Claude mocked, no dev web-debug toolbar).
        if ($env !== 'test') {
            $io->error(sprintf(
                'This command reseeds the demo user and overwrites committed screenshots — '
                . 'run it only in the test env (current: "%s"). Re-run with APP_ENV=test.',
                $env,
            ));

            return Command::FAILURE;
        }

        $io->section('Seeding the demo user');
        $counts = $this->seeder->seed();
        $io->writeln(sprintf(
            '  %d meals, %d health rows, %d goal check-ins',
            $counts['meals'],
            $counts['health'],
            $counts['goal_checkins'],
        ));

        $outDir = $this->kernel->getProjectDir() . '/public/images/help';
        (new Filesystem())->mkdir($outDir);

        $io->section('Starting web server + headless Chrome');
        $server = new WebServerManager(
            $this->kernel->getProjectDir() . '/public',
            self::HOST,
            self::PORT,
            env: ['APP_ENV' => $env],
        );
        $server->start();
        $baseUri = sprintf('http://%s:%d', self::HOST, self::PORT);

        // Everything past the server start runs under try/finally so the web server is
        // always torn down — including if createChromeClient() throws (bad chromedriver,
        // busy port, …).
        $client = null;
        try {
            $client = Client::createChromeClient(null, $this->chromeArguments(), [], $baseUri);

            $client->request('GET', '/login');
            $client->submitForm('Sign In', [
                '_username' => DemoDataSeeder::EMAIL,
                '_password' => DemoDataSeeder::PASSWORD,
            ]);
            $client->waitFor('a[href="/logout"]', 10);

            $io->section('Capturing');
            foreach (self::SHOTS as $slug => $path) {
                $client->request('GET', $path);
                $client->waitForVisibility('body', 10);
                usleep(self::SETTLE_MS * 1000);

                $file = $outDir . '/' . $slug . '.png';
                $client->takeScreenshot($file);
                $io->writeln(sprintf('  ✓ %-15s → %s', $slug, 'public/images/help/' . $slug . '.png'));
            }

            $this->captureSymptomChart($client, $outDir, $io);
            $this->captureRecipeReference($client, $outDir, $io);
        } finally {
            $client?->quit();
            $server->quit();
        }

        $io->success(sprintf('Captured %d help screenshots into public/images/help/.', count(self::SHOTS) + 3));

        return Command::SUCCESS;
    }

    /**
     * Deep-links to the demo user's `{@see self::CHART_SYMPTOM_KEY}` panel on Trends and
     * shoots two supplementary PNGs the plain `inflammation`/`symptoms` shots above never
     * reveal (see the constant's docblock): `symptoms-chart.png` (top of the panel — the
     * small-multiples chart) and `symptoms-significance.png` (scrolled to the "Significant
     * diet correlations" callout below it). Looks the symptom type's id up fresh post-seed
     * since Doctrine auto-increment ids aren't stable across re-seeds (the seeder deletes
     * rows rather than truncating).
     */
    private function captureSymptomChart(Client $client, string $outDir, SymfonyStyle $io): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => DemoDataSeeder::EMAIL]);
        \assert($user instanceof User);
        $symptom = $this->symptomTypeRepo->findOneByUserAndKey($user, self::CHART_SYMPTOM_KEY);
        if ($symptom === null) {
            $io->warning(sprintf(
                'No "%s" symptom type on the demo user — skipping symptoms-chart.png '
                . 'and symptoms-significance.png.',
                self::CHART_SYMPTOM_KEY,
            ));

            return;
        }

        $client->request('GET', '/trends#panel=symptom-' . $symptom->getId());
        $client->waitForVisibility('body', 10);
        usleep(self::SETTLE_MS * 1000);

        $file = $outDir . '/symptoms-chart.png';
        $client->takeScreenshot($file);
        $io->writeln(sprintf('  ✓ %-15s → %s', 'symptoms-chart', 'public/images/help/symptoms-chart.png'));

        // The significant-correlations callout is the first `.bg-light` box in the open
        // panel — scroll it to the top of the viewport so the shot frames the list itself
        // rather than whatever the chart's height happened to push below the fold.
        $client->executeScript(<<<'JS'
            document.querySelector('.insight-panel:not(.d-none) .bg-light')
                ?.scrollIntoView({block: 'start'});
            JS);
        usleep(self::SETTLE_MS * 1000);

        $sigFile = $outDir . '/symptoms-significance.png';
        $client->takeScreenshot($sigFile);
        $io->writeln(sprintf(
            '  ✓ %-15s → %s',
            'symptoms-significance',
            'public/images/help/symptoms-significance.png',
        ));
    }

    /**
     * The import-recipe topic's supplementary shot: the favorites page with the
     * imported recipe's "Ingredients & steps" collapse open — the cook's
     * reference the topic's "Cooking from it later" section shows. The seeded
     * recipe favorite is the most recently used (see DemoDataSeeder), so the
     * default "recent" sort puts it first and the first steps trigger is its own.
     */
    private function captureRecipeReference(Client $client, string $outDir, SymfonyStyle $io): void
    {
        $client->request('GET', '/meal/favorites');
        $client->waitForVisibility('body', 10);
        $client->executeScript(
            'document.querySelector(\'button[data-bs-target^="#steps-"]\')?.click();'
        );
        usleep(self::SETTLE_MS * 1000);
        // Frame the whole card (name + "From a recipe" badge included), not
        // just the trigger row.
        $client->executeScript(
            'document.querySelector(\'button[data-bs-target^="#steps-"]\')'
            . '?.closest(".card")?.scrollIntoView({block: "start"});'
        );
        usleep(self::SETTLE_MS * 1000);

        $file = $outDir . '/import-recipe-reference.png';
        $client->takeScreenshot($file);
        $io->writeln(sprintf(
            '  ✓ %-15s → %s',
            'import-recipe-reference',
            'public/images/help/import-recipe-reference.png',
        ));
    }

    /**
     * Chrome flags for the capture run. Passing explicit arguments bypasses Panther's
     * defaults, so we re-declare headless **and** the container-safety flags the E2E
     * suite relies on (`--no-sandbox`, `--disable-dev-shm-usage`) instead of dropping
     * them, then fold in any `PANTHER_CHROME_ARGUMENTS` override (same env the suite +
     * CI set). 2× device scale keeps the PNGs crisp on retina; the help layout caps the
     * displayed width anyway.
     *
     * @return string[]
     */
    private function chromeArguments(): array
    {
        $args = [
            '--headless',
            '--disable-gpu',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--hide-scrollbars',
            '--window-size=1100,950',
            '--force-device-scale-factor=2',
        ];

        if ($extra = $_SERVER['PANTHER_CHROME_ARGUMENTS'] ?? null) {
            $args = array_merge($args, array_filter(explode(' ', (string) $extra)));
        }

        return $args;
    }
}
