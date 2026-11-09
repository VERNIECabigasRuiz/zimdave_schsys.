<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Pdf;

use App\Core\Infrastructure\Pdf\DompdfRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DompdfRendererTest extends TestCase
{
    public function testRendersAPdf(): void
    {
        // Execute
        $pdf = (new DompdfRenderer())->render('<p>Hello</p>');

        // Assert
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /** @return iterable<string, array{string|list<int>, string}> */
    public static function papers(): iterable
    {
        yield 'A4 (PDS)' => ['A4', '595.280 841.890'];
        yield 'long bond (service record, big staff ID)' => [[0, 0, 612, 936], '612.000 936.000'];
        yield 'letter landscape (lesson plan)' => [[0, 0, 792, 612], '792.000 612.000'];
        yield 'letter (small staff ID)' => [[0, 0, 612, 792], '612.000 792.000'];
    }

    /** @param string|list<int> $paper */
    #[DataProvider('papers')]
    public function testPaperSizes(string|array $paper, string $size): void
    {
        // Execute
        $pdf = (new DompdfRenderer())->render('<p>Hello</p>', $paper);

        // Assert
        self::assertStringContainsString('/MediaBox [0.000 0.000 ' . $size . ']', $pdf);
    }

    public function testPageRuleDecidesWithoutPaper(): void
    {
        // Initialize
        $html = '<style>@page { size: 612pt 936pt; }</style><p>Hello</p>';

        // Execute
        $pdf = (new DompdfRenderer())->render($html);

        // Assert
        self::assertStringContainsString('/MediaBox [0.000 0.000 612.000 936.000]', $pdf);
    }
}
