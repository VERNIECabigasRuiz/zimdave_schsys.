<?php

declare(strict_types=1);

namespace App\Tests\Grading\Unit\Application;

use App\Grading\Application\Service\CardSlipComments;
use PHPUnit\Framework\TestCase;

final class CardSlipCommentsTest extends TestCase
{
    public function testEveryGroupedCodeHasText(): void
    {
        $count = 0;
        foreach (CardSlipComments::GROUPS as $codes) {
            foreach ($codes as $code) {
                ++$count;
                $this->assertTrue(CardSlipComments::isKnown($code));
                $this->assertNotSame('', CardSlipComments::text($code));
            }
        }
        $this->assertSame(16, $count);
        $this->assertFalse(CardSlipComments::isKnown('nope'));
    }

    public function testNeedsImprovementFillsSubjects(): void
    {
        $this->assertSame(
            'Needs to improve in Mathematics and Science. Please attend remedial sessions.',
            CardSlipComments::text('needs_improvement', ['Mathematics', 'Science']),
        );
    }

    public function testJoinSubjects(): void
    {
        $this->assertSame('A', CardSlipComments::joinSubjects(['A']));
        $this->assertSame('A and B', CardSlipComments::joinSubjects(['A', 'B']));
        $this->assertSame('A, B and C', CardSlipComments::joinSubjects(['A', 'B', 'C']));
    }
}
