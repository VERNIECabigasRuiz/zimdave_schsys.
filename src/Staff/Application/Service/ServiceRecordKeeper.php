<?php

declare(strict_types=1);

namespace App\Staff\Application\Service;

use App\Access\Domain\Model\User;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Schools\Domain\Model\School;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Staff\Domain\Model\ServiceRecordEntry;

/** Starts and saves service record rows the way a principal adds them: one change at a time after the last. */
class ServiceRecordKeeper
{
    public function __construct(
        private readonly ServiceRecordEntryRepositoryInterface $entries,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /**
     * A new row like the last one (designation, status, station, branch), starting the day after it ends — with
     * no start when it runs to the present. A first row starts today, in the school's city, National branch.
     */
    public function draft(User $employee, School $school): SaveServiceRowCommand
    {
        $rows = $this->entries->findForEmployee($employee);
        $last = [] === $rows ? null : end($rows);
        $draft = new SaveServiceRowCommand();
        $draft->employeeId = (int) $employee->getId();
        // after a row up to the present the principal picks the date, so saving can't end that row by accident
        $draft->fromDate = null === $last
            ? $this->clock->now()->setTime(0, 0)
            : $last->getToDate()?->modify('+1 day');

        if (null === $last) {
            $draft->station = self::city($school);
            $draft->branch = 'National';

            return $draft;
        }

        $draft->designation = $last->getDesignation();
        $draft->status = $last->getStatus();
        $draft->station = $last->getStation();
        $draft->branch = $last->getBranch();

        return $draft;
    }

    /**
     * Saves the row onto $entry, or a new row when there is none; with closeOpen, first ends the person's open
     * row on the day before it starts.
     */
    public function save(SaveServiceRowCommand $row, User $employee, ?ServiceRecordEntry $entry, User $by): void
    {
        $from = $row->fromDate;
        \assert(null !== $from);
        $now = $this->clock->now();
        $entry ??= new ServiceRecordEntry($employee, $from, $by, $now);
        $row->applyTo($entry);
        if ($row->closeOpen) {
            $open = $this->entries->findCurrent($employee);
            if (null !== $open && $open !== $entry && $open->getFromDate() < $entry->getFromDate()) {
                $open->setToDate($from->modify('-1 day'))->touch($by, $now);
            }
        }
        $entry->touch($by, $now);
        $this->entries->add($entry);
    }

    /** "Mandaue City" from "Z. Estreras, Pagsabungan, Mandaue City": the address's last part. */
    private static function city(School $school): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $school->getAddress())));

        return [] === $parts ? null : end($parts);
    }
}
