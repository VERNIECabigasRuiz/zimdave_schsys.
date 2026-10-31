<?php

namespace App\Tests\CardSlip;

use App\CardSlip\CardSlipSuggester;
use App\CardSlip\TermFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CardSlipSuggesterTest extends TestCase
{
    /** @return iterable<string, array{TermFacts, list<string>}> */
    public static function cases(): iterable
    {
        yield 'excellent perfect' => [new TermFacts(['Math' => 92], 92.0, null, 0, 0, 50), ['excellent', 'perfect_attendance', 'read_daily']];
        yield 'very good improved' => [new TermFacts(['Math' => 86], 86.5, 82.0, 1, 0, 50), ['very_good', 'improved', 'read_daily']];
        yield 'good absences adviser' => [new TermFacts(['Math' => 81], 81.0, 79.5, 6, 0, 50), ['good', 'absences', 'see_adviser']];
        yield 'needs improvement' => [new TermFacts(['Math' => 72, 'Sci' => 88], 80.0, null, 2, 6, 50), ['needs_improvement', 'tardiness', 'see_adviser']];
        yield 'incomplete wins' => [new TermFacts(['Math' => 70, 'Sci' => null], null, null, 1, 0, 50), ['incomplete', 'see_adviser', 'read_daily']];
        yield 'study home' => [new TermFacts(['Math' => 78], 78.0, null, 1, 0, 50), ['study_home', 'read_daily', 'see_adviser']];
        yield 'no previous no improved' => [new TermFacts(['Math' => 95], 95.0, null, 1, 0, 50), ['excellent', 'read_daily', 'study_home']];
        yield 'zero days no perfect' => [new TermFacts(['Math' => 95], 95.0, null, 0, 0, 0), ['excellent', 'read_daily', 'study_home']];
        yield 'boundary 90' => [new TermFacts(['Math' => 90], 90.0, null, 1, 0, 50), ['excellent', 'read_daily', 'study_home']];
        yield 'boundary 85' => [new TermFacts(['Math' => 85], 85.0, null, 1, 0, 50), ['very_good', 'read_daily', 'study_home']];
        yield 'boundary 80' => [new TermFacts(['Math' => 80], 80.0, null, 1, 0, 50), ['good', 'read_daily', 'study_home']];
        yield 'rise of exactly 3' => [new TermFacts(['Math' => 83], 83.0, 80.0, 1, 0, 50), ['good', 'improved', 'read_daily']];
        // 449/7 - 428/7 computes as 2.999999999999993 in floating point
        $seven = ['A' => 64, 'B' => 64, 'C' => 64, 'D' => 64, 'E' => 64, 'F' => 64, 'G' => 65];
        yield 'rise of 3 over 7 subjects' => [new TermFacts($seven, 449 / 7, 428 / 7, 1, 0, 50), ['needs_improvement', 'improved', 'see_adviser']];
        yield 'capped at 3' => [new TermFacts(['Math' => 70, 'Sci' => 70], 70.0, 60.0, 9, 0, 50), ['needs_improvement', 'improved', 'absences']];
    }

    /** @param list<string> $expected */
    #[DataProvider('cases')]
    public function testSuggest(TermFacts $facts, array $expected): void
    {
        $result = (new CardSlipSuggester())->suggest($facts);
        $this->assertSame($expected, $result);
        $this->assertLessThanOrEqual(3, \count($result));
    }

    public function testFailingSubjectsAndIncomplete(): void
    {
        $f = new TermFacts(['A' => 70, 'B' => 90, 'C' => 60], 73.0, null, 0, 0, 10);
        $this->assertSame(['A', 'C'], $f->failingSubjects());
        $this->assertFalse($f->isIncomplete());
        $this->assertTrue((new TermFacts(['A' => null], null, null, null, null, 10))->isIncomplete());
    }
}
