<?php

namespace App\ServiceRecord;

use App\Entity\School;
use App\Entity\ServiceRecordEntry;
use App\Entity\User;
use App\Repository\ServiceRecordEntryRepository;
use App\Service\SchoolClock;
use Doctrine\ORM\EntityManagerInterface;

/** Starts and saves service record rows the way a principal adds them: one change at a time after the last. */
class ServiceRecordKeeper
{
    public function __construct(
        private readonly ServiceRecordEntryRepository $entries,
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolClock $clock,
    ) {
    }

    /**
     * A new row like the last one (designation, status, station, branch), starting the day after it ends — or
     * today when it runs to the present or there is none. A first row gets the school's city and the National branch.
     */
    public function draft(User $employee, School $school, User $by): ServiceRecordEntry
    {
        $today = $this->clock->now()->setTime(0, 0);
        $rows = $this->entries->findForEmployee($employee);
        $last = [] === $rows ? null : end($rows);
        $entry = new ServiceRecordEntry($employee, $last?->getToDate()?->modify('+1 day') ?? $today, $by, $this->clock->now());

        if (null === $last) {
            return $entry->setStation(self::city($school))->setBranch('National');
        }

        return $entry
            ->setDesignation($last->getDesignation())
            ->setStatus($last->getStatus())
            ->setStation($last->getStation())
            ->setBranch($last->getBranch());
    }

    /** Saves the row; with closeOpen, first ends the person's open row on the day before it starts. */
    public function save(ServiceRecordEntry $entry, User $by): void
    {
        $now = $this->clock->now();
        if ($entry->closeOpen) {
            $open = $this->entries->findCurrent($entry->getEmployee());
            if (null !== $open && $open !== $entry && $open->getFromDate() < $entry->getFromDate()) {
                $open->setToDate($entry->getFromDate()->modify('-1 day'))->touch($by, $now);
            }
        }
        $entry->touch($by, $now);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    /** "Mandaue City" from "Z. Estreras, Pagsabungan, Mandaue City": the address's last part. */
    private static function city(School $school): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $school->getAddress())));

        return [] === $parts ? null : end($parts);
    }
}
