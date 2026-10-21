<?php

namespace App\Tests\Entity;

use App\Entity\PdsReference;
use App\Entity\PersonalDataSheet;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PdsQuestionsTest extends KernelTestCase
{
    /** @return list<string> */
    private function violations(PersonalDataSheet $sheet, string $group): array
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);

        return array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($validator->validate($sheet, groups: [$group])));
    }

    public function testYesNeedsDetails(): void
    {
        $sheet = new PersonalDataSheet(new User());
        $sheet->q36 = true;
        $sheet->q34b = true;
        $sheet->q35b = true;
        $sheet->q35bDetails = 'Libel';

        self::assertEqualsCanonicalizing(
            ['q36Details', 'q34Details', 'q35bDateFiled', 'q35bStatus'],
            $this->violations($sheet, PersonalDataSheet::QUESTIONS),
        );
    }

    public function testNoAndUnansweredAreValid(): void
    {
        $sheet = new PersonalDataSheet(new User());
        $sheet->q36 = false;

        self::assertSame([], $this->violations($sheet, PersonalDataSheet::QUESTIONS));
        self::assertNull($sheet->q37);
    }

    public function testNoClearsOldDetails(): void
    {
        $sheet = new PersonalDataSheet(new User());
        $sheet->q35b = false;
        $sheet->q35bDetails = 'Old case';
        $sheet->q35bDateFiled = new \DateTimeImmutable('2020-01-01');
        $sheet->q35bStatus = 'Dismissed';
        $sheet->q34a = false;
        $sheet->q34b = false;
        $sheet->q34Details = 'Cousin';
        $sheet->q40b = true;
        $sheet->q40bDetails = 'PWD-123';

        $sheet->clearUnusedAnswerDetails();

        self::assertSame([null, null, null, null], [$sheet->q35bDetails, $sheet->q35bDateFiled, $sheet->q35bStatus, $sheet->q34Details]);
        self::assertSame('PWD-123', $sheet->q40bDetails);
    }

    public function testReferencesAllOrNothing(): void
    {
        $sheet = new PersonalDataSheet(new User());
        self::assertSame([], $this->violations($sheet, PersonalDataSheet::REFERENCES));

        $ref = new PdsReference();
        $ref->name = 'Dr. Ana Reyes';
        $ref->address = 'DepEd Division Office, Mandaue City';
        $ref->contact = '09171234567';
        $sheet->addReference($ref);

        self::assertContains('references', $this->violations($sheet, PersonalDataSheet::REFERENCES));
    }
}
