<?php

namespace App\Tests\Service;

use App\LearnerId\IdCard;
use App\LearnerId\IdCardPdf;
use App\LearnerId\IdLayout;
use App\LearnerId\QrCode;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

class IdCardPdfTest extends KernelTestCase
{
    private function pdf(): IdCardPdf
    {
        self::bootKernel();

        return new IdCardPdf(static::getContainer()->get(Environment::class));
    }

    private static function card(string $lrn, string $name = 'GERALD JAMES M. ARNOL', bool $full = true): IdCard
    {
        return new IdCard(
            'Pagsabungan National High School', '303196', 'Region VII', 'Mandaue City',
            $full ? 'Pagsabungan, Mandaue City' : null, $full ? '(032) 123-4567' : null, null, null,
            $name, $lrn, 'Grade 8 – ARTISTIC', null,
            $full ? 'Mother' : null, $full ? 'MOLINA, ANGELLE' : null, $full ? '09171234567' : null,
            $full ? 'Pagsabungan, Mandaue City, Cebu' : null, (new QrCode())->png($lrn),
            $full ? 'RENE PETANCIO' : null, '2025–2026', $full ? new \DateTimeImmutable('2026-04-30') : null,
        );
    }

    /** @return list<IdCard> */
    private static function cards(int $count): array
    {
        return array_map(static fn (int $i) => self::card(str_pad((string) $i, 12, '0', \STR_PAD_LEFT)), range(0, $count - 1));
    }

    private static function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    /** @param list<?IdCard> $slots */
    private static function lrns(array $slots): array
    {
        return array_map(static fn (?IdCard $c) => null === $c ? null : (int) $c->lrn, $slots);
    }

    public function testNineCardsMakeOneSheet(): void
    {
        $pages = IdCardPdf::sheetPages(self::cards(9));

        self::assertCount(1, $pages);
        self::assertSame(range(0, 8), self::lrns($pages[0]['front']));
        self::assertSame([2, 1, 0, 5, 4, 3, 8, 7, 6], self::lrns($pages[0]['back']));
    }

    public function testTenCardsMirrorTheLoneCard(): void
    {
        $pages = IdCardPdf::sheetPages(self::cards(10));

        self::assertCount(2, $pages);
        self::assertSame([9, null, null, null, null, null, null, null, null], self::lrns($pages[1]['front']));
        self::assertSame([null, null, 9, null, null, null, null, null, null], self::lrns($pages[1]['back']));
    }

    public function testRenderSheetAndCard(): void
    {
        $pdf = $this->pdf();

        $card = $pdf->render(self::cards(3), IdLayout::Card);
        self::assertStringStartsWith('%PDF', $card);
        self::assertSame(6, self::pages($card));

        $sheet = $pdf->render(self::cards(3), IdLayout::Sheet);
        self::assertStringStartsWith('%PDF', $sheet);
        self::assertSame(2, self::pages($sheet));
    }

    public function testRenderEmpty(): void
    {
        $pdf = $this->pdf()->render([], IdLayout::Sheet);

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame(1, self::pages($pdf));
    }

    public function testRegionLineStaysBetweenSealAndLogo(): void
    {
        $html = $this->pdf()->html([self::card('120017170001')], IdLayout::Card);

        // the seal and logo take 2.2–12.2 mm from each side; the government lines must wrap between them
        self::assertMatchesRegularExpression('/\.gov\s*\{[^}]*left:\s*12\.8mm;[^}]*width:\s*28\.4mm;/', $html);
    }

    public function testLongNameAndMissingDataStayOnOnePage(): void
    {
        $card = self::card('120017170001', 'MA. KRISTINE JOY B. DELA CRUZ-VILLANUEVA', full: false);
        $pdf = $this->pdf();

        self::assertSame(2, self::pages($pdf->render([$card], IdLayout::Card)));
        $html = $pdf->html([$card], IdLayout::Card);
        self::assertStringNotContainsString('—', $html);
        self::assertStringNotContainsString('In case of emergency', $html);
        self::assertStringNotContainsString('Valid until', $html);
    }
}
