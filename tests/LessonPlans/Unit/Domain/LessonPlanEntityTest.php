<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Unit\Domain;

use App\LessonPlans\Domain\Model\LessonPlan;
use App\Classes\Domain\Model\TeachingLoad;
use PHPUnit\Framework\TestCase;

class LessonPlanEntityTest extends TestCase
{
    public function testBulletsSplitLines(): void
    {
        self::assertSame(['a', 'b'], LessonPlan::bullets("a\n\n b \r\n"));
        self::assertSame([], LessonPlan::bullets(null));
    }

    public function testAPlanKeepsWhenItWasCreatedAndLastSaved(): void
    {
        // Initialize
        $load = (new \ReflectionClass(TeachingLoad::class))->newInstanceWithoutConstructor();
        $plan = new LessonPlan(
            $load,
            new \DateTimeImmutable('2026-11-30 13:00'),
            new \DateTimeImmutable('2026-11-27 08:00'),
        );

        // Execute
        $plan->touch(new \DateTimeImmutable('2026-11-28 09:30'));

        // Assert
        self::assertSame(
            ['2026-11-30 00:00', '2026-11-27 08:00', '2026-11-28 09:30'],
            [
                $plan->getWeekStart()->format('Y-m-d H:i'),
                $plan->getCreatedAt()->format('Y-m-d H:i'),
                $plan->getUpdatedAt()->format('Y-m-d H:i'),
            ],
        );
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
