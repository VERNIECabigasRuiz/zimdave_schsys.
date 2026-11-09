<?php

declare(strict_types=1);

namespace App\Staff\Domain\Service\ServiceRecord;

use App\Schools\Domain\Model\School;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\ServiceRecord;
use App\Staff\Domain\Model\ServiceRecordEntry;

/** What a service record's PDF shows: the PDS header, the BP number, the rows and the school's signatories. */
final readonly class ServiceRecordPrintout
{
    /**
     * @param list<ServiceRecordEntry> $rows oldest first
     */
    public function __construct(
        public School $school,
        public ?PersonalDataSheet $sheet,
        public ServiceRecord $record,
        public array $rows,
        public ?string $certifiedName,
        public ?string $certifiedDesignation,
        public \DateTimeImmutable $printedOn,
    ) {
    }

    /** "Service record - DELA CRUZ, JUAN.pdf"; "Service record.pdf" while the PDS has no name. */
    public function fileName(): string
    {
        $name = implode(', ', array_filter([
            mb_strtoupper(trim((string) $this->sheet?->surname)),
            mb_strtoupper(trim((string) $this->sheet?->firstName)),
        ]));
        $name = (string) preg_replace('/[\/\\\\%"]/', '-', $name);

        return '' === $name ? 'Service record.pdf' : 'Service record - ' . $name . '.pdf';
    }
}
