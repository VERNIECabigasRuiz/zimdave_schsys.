<?php

declare(strict_types=1);

namespace App\Tests\Classes\Functional;

use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\TeachingLoad;
use App\Classes\Domain\Model\Weekday;
use App\Schools\Domain\Model\SchoolYear;
use Doctrine\ORM\Event\OnFlushEventArgs;

/**
 * Plays another request that saves a Monday class in the same slot just before this
 * request's change is written, once.
 */
final class SlotTakenOnFlush
{
    private bool $done = false;

    public function __construct(
        private readonly TeachingLoad $load,
        private readonly SchoolYear $schoolYear,
        private readonly ClassPeriod $period,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $args->getObjectManager()->getConnection()->insert('schedule_entry', [
            'load_id' => $this->load->getId(),
            'section_id' => $this->load->getSection()->getId(),
            'teacher_id' => $this->load->getTeacher()->getId(),
            'school_year_id' => $this->schoolYear->getId(),
            'day' => Weekday::Monday->value,
            'period_id' => $this->period->getId(),
            'term' => 0,
        ]);
    }
}
