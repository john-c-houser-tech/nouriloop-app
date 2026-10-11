<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\TextExtension;
use PHPUnit\Framework\TestCase;

final class TextExtensionTest extends TestCase
{
    private TextExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new TextExtension();
    }

    public function testConvertsMarkdownBoldToStrong(): void
    {
        self::assertSame(
            'You hit <strong>20.1 g</strong> today.',
            $this->ext->inlineMd('You hit **20.1 g** today.'),
        );
    }

    public function testConvertsNewlinesToBr(): void
    {
        self::assertSame("line one<br>\nline two", $this->ext->inlineMd("line one\nline two"));
    }

    public function testEscapesHtmlBeforeConverting(): void
    {
        // Any markup the model emits is neutralised; only our <strong>/<br> are added.
        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt; <strong>safe</strong>',
            $this->ext->inlineMd('<script>alert(1)</script> **safe**'),
        );
    }

    public function testPlainProseIsUnchangedApartFromEscaping(): void
    {
        self::assertSame('Nice work today.', $this->ext->inlineMd('Nice work today.'));
    }

    public function testHandlesNull(): void
    {
        self::assertSame('', $this->ext->inlineMd(null));
    }
}
