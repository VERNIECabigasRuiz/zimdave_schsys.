<?php

namespace App\Tests\LessonPlan;

use App\Entity\LessonPlan;
use App\Classes\Domain\Model\TeachingLoad;
use PHPUnit\Framework\TestCase;

class LessonPlanEntityTest extends TestCase
{
    public function testBulletsSplitLines(): void
    {
        self::assertSame(['a', 'b'], LessonPlan::bullets("a\n\n b \r\n"));
        self::assertSame([], LessonPlan::bullets(null));
    }

    public function testSessionCountSkipsHolidays(): void
    {
        $load = (new \ReflectionClass(TeachingLoad::class))->newInstanceWithoutConstructor();
        $plan = new LessonPlan($load, new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable());
        $plan->setDays([
            LessonPlan::emptyDay('2026-11-30', 'Bonifacio Day'),
            LessonPlan::emptyDay('2026-12-01'),
            LessonPlan::emptyDay('2026-12-02'),
        ]);

        self::assertSame(2, $plan->sessionCount());
        self::assertSame('Bonifacio Day', $plan->getDays()[0]['holiday']);
        foreach (LessonPlan::DAY_FIELDS as $field) {
            self::assertSame('', $plan->getDays()[1][$field]);
        }
    }
}
