<?php

declare(strict_types=1);

namespace App\Tests\Draft;

use App\Draft\DraftSession;
use App\Draft\SupplementDraft;
use App\Dto\StagedPhoto;
use App\Service\HeicConverterService;
use App\Service\PhotoStaging;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\Validation;

final class DraftSessionTest extends TestCase
{
    private string $publicDir;
    private Session $session;
    private DraftSession $drafts;

    protected function setUp(): void
    {
        $this->publicDir = sys_get_temp_dir() . '/draft-session-' . bin2hex(random_bytes(4));
        mkdir($this->publicDir . '/uploads/meals', 0777, true);
        $this->session = new Session(new MockArraySessionStorage());
        $this->drafts = new DraftSession(new PhotoStaging(
            $this->publicDir,
            new HeicConverterService(),
            new AsciiSlugger(),
            Validation::createValidator(),
        ));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->publicDir));
    }

    public function testAStoredDraftLoadsBackEqual(): void
    {
        $draft = $this->draft('a');
        $this->drafts->store($this->session, $draft);

        self::assertEquals($draft, $this->drafts->load($this->session, SupplementDraft::class));
    }

    public function testNothingStoredLoadsAsNull(): void
    {
        self::assertNull($this->drafts->load($this->session, SupplementDraft::class));
    }

    public function testAMalformedEntryLoadsAsNullAndIsDropped(): void
    {
        $this->session->set(SupplementDraft::sessionKey(), ['nonsense' => true]);

        self::assertNull($this->drafts->load($this->session, SupplementDraft::class));
        self::assertFalse($this->session->has(SupplementDraft::sessionKey()));
    }

    public function testReplacingADraftDeletesThePhotosItNoLongerHolds(): void
    {
        $first = $this->draft('first');
        $this->drafts->store($this->session, $first);
        $this->drafts->store($this->session, $this->draft('second'));

        self::assertFileDoesNotExist($first->photo->path);
    }

    public function testClearDeletesPhotosExceptTheOnesKept(): void
    {
        $draft = $this->draft('kept');
        $this->drafts->store($this->session, $draft);

        $this->drafts->clear($this->session, SupplementDraft::class, keep: [$draft->photo->path]);

        self::assertFileExists($draft->photo->path);
        self::assertNull($this->drafts->load($this->session, SupplementDraft::class));

        $other = $this->draft('dropped');
        $this->drafts->store($this->session, $other);
        $this->drafts->clear($this->session, SupplementDraft::class);
        self::assertFileDoesNotExist($other->photo->path);
    }

    public function testASupplementDraftStoredBeforeTypedDraftsStillLoads(): void
    {
        // The pre-2026-10-06 shape: no image_path, only the web path.
        $this->session->set('supplement_scan', [
            'name' => 'Fish oil', 'serving_size' => '2 softgels', 'confidence' => 0.9,
            'nutrients' => ['vitaminD' => 10.0], 'image_web' => 'uploads/supplements/supp-old.jpg',
        ]);

        $draft = $this->drafts->load($this->session, SupplementDraft::class);

        self::assertNotNull($draft);
        self::assertSame('Fish oil', $draft->name);
        self::assertSame('uploads/supplements/supp-old.jpg', $draft->photo->webPath);
        self::assertSame([], $draft->stagedPaths(), 'an untracked photo is left for the sweep');
    }

    private function draft(string $name): SupplementDraft
    {
        $path = $this->publicDir . '/uploads/meals/supp-' . $name . '.jpg';
        file_put_contents($path, 'bytes');

        return new SupplementDraft($name, '1 capsule', 0.8, ['vitaminC' => 500.0], new StagedPhoto(
            $path,
            'uploads/meals/supp-' . $name . '.jpg',
        ));
    }
}
