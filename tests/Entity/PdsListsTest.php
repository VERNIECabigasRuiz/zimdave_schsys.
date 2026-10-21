<?php

namespace App\Tests\Entity;

use App\Entity\EducationLevel;
use App\Entity\PdsChild;
use App\Entity\PdsEducation;
use App\Entity\PdsEligibility;
use App\Entity\PdsTraining;
use App\Entity\PdsVoluntaryWork;
use App\Entity\PdsWorkExperience;
use App\Entity\PersonalDataSheet;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PdsListsTest extends KernelTestCase
{
    private function sheet(): PersonalDataSheet
    {
        return new PersonalDataSheet(new User());
    }

    /** @return list<string> */
    private function violations(object $object): array
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);

        return array_map(
            static fn ($v) => $v->getPropertyPath().': '.$v->getMessage(),
            iterator_to_array($validator->validate($object, groups: [PersonalDataSheet::LISTS])),
        );
    }

    private static function d(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date);
    }

    private function education(EducationLevel $level, string $school, ?int $from = null): PdsEducation
    {
        $e = new PdsEducation();
        $e->level = $level;
        $e->schoolName = $school;
        $e->yearFrom = $from;

        return $e;
    }

    private function work(string $title, string $from, ?string $to = null, bool $present = false): PdsWorkExperience
    {
        $w = new PdsWorkExperience();
        $w->positionTitle = $title;
        $w->office = 'DepEd';
        $w->dateFrom = self::d($from);
        $w->dateTo = $to ? self::d($to) : null;
        $w->present = $present;

        return $w;
    }

    public function testEducationSortedByLevelThenYear(): void
    {
        $sheet = $this->sheet();
        $sheet->addEducation($this->education(EducationLevel::Graduate, 'CNU', 2015));
        $sheet->addEducation($this->education(EducationLevel::College, 'CNU', 2004));
        $sheet->addEducation($this->education(EducationLevel::Elementary, 'Pagsabungan ES', 1992));
        $sheet->addEducation($this->education(EducationLevel::Graduate, 'UC', 2012));

        $order = array_map(static fn (PdsEducation $e) => $e->level->name.' '.$e->schoolName, $sheet->sortedEducations());

        self::assertSame(['Elementary Pagsabungan ES', 'College CNU', 'Graduate UC', 'Graduate CNU'], $order);
    }

    public function testWorkSortedPresentFirstThenNewest(): void
    {
        $sheet = $this->sheet();
        $sheet->addWorkExperience($this->work('Teacher I', '2010-06-01', '2015-05-31'));
        $sheet->addWorkExperience($this->work('Principal I', '2022-01-03', present: true));
        $sheet->addWorkExperience($this->work('Master Teacher I', '2015-06-01', '2021-12-31'));

        $titles = array_map(static fn ($w) => $w->positionTitle, $sheet->sortedWorkExperiences());

        self::assertSame(['Principal I', 'Master Teacher I', 'Teacher I'], $titles);
    }

    public function testTrainingAndVoluntarySortedNewestFirstUndatedLast(): void
    {
        $sheet = $this->sheet();
        foreach (['2020-01-10' => 'Old', '2024-05-02' => 'New'] as $date => $title) {
            $t = new PdsTraining();
            $t->title = $title;
            $t->dateFrom = self::d($date);
            $t->hours = '8';
            $sheet->addTraining($t);
        }
        foreach ([[null, 'Undated'], ['2019-03-01', 'Older'], ['2023-03-01', 'Newer']] as [$date, $org]) {
            $v = new PdsVoluntaryWork();
            $v->organization = $org;
            $v->dateFrom = $date ? self::d($date) : null;
            $sheet->addVoluntaryWork($v);
        }

        self::assertSame(['New', 'Old'], array_map(static fn ($t) => $t->title, $sheet->sortedTrainings()));
        self::assertSame(['Newer', 'Older', 'Undated'], array_map(static fn ($v) => $v->organization, $sheet->sortedVoluntaryWorks()));
        self::assertSame(16.0, $sheet->trainingHours());
    }

    public function testRenumberListsWritesSortedPositions(): void
    {
        $sheet = $this->sheet();
        $old = $this->work('Teacher I', '2010-06-01', '2015-05-31');
        $new = $this->work('Principal I', '2022-01-03', present: true);
        $sheet->addWorkExperience($old);
        $sheet->addWorkExperience($new);

        $sheet->renumberLists();

        self::assertSame([0, 1], [$new->position, $old->position]);
    }

    public function testPresentJobHasNoEndDate(): void
    {
        $sheet = $this->sheet();
        $job = $this->work('Principal I', '2022-01-03', '2023-01-01', present: true);
        $sheet->addWorkExperience($job);

        $sheet->renumberLists();

        self::assertNull($job->dateTo);
    }

    public function testEligibilityKeepsUserOrder(): void
    {
        $sheet = $this->sheet();
        foreach (['Professional Teacher (RA 1080 / LET)', 'Career Service Professional'] as $name) {
            $e = new PdsEligibility();
            $e->name = $name;
            $sheet->addEligibility($e);
        }

        self::assertSame(['Professional Teacher (RA 1080 / LET)', 'Career Service Professional'], array_map(static fn ($e) => $e->name, $sheet->sortedEligibilities()));
    }

    public function testRowValidationRules(): void
    {
        $work = $this->work('Teacher I', '2015-06-01', '2010-01-01');
        $work->salaryGrade = '11';
        self::assertEqualsCanonicalizing(
            ['dateTo: The end date cannot be before the start date.', 'salaryGrade: Use the format 00-0, e.g. 11-1.'],
            $this->violations($work),
        );
        $work->dateTo = self::d('2016-01-01');
        $work->salaryGrade = '11-1';
        self::assertSame([], $this->violations($work));

        $edu = $this->education(EducationLevel::College, '', 2012);
        $edu->yearTo = 2010;
        self::assertEqualsCanonicalizing(
            ['schoolName: Enter the name of the school.', 'yearTo: The end year cannot be before the start year.'],
            $this->violations($edu),
        );
    }

    public function testNumberFieldsMustBeNumbers(): void
    {
        $training = new PdsTraining();
        $training->title = 'INSET';
        $training->dateFrom = self::d('2024-01-01');
        foreach (['abc' => true, '100000' => true, '0' => true, '24' => false, '7.5' => false] as $hours => $invalid) {
            $training->hours = (string) $hours;
            self::assertSame($invalid, [] !== $this->violations($training), "hours = $hours");
        }

        $work = $this->work('Teacher I', '2012-06-04');
        foreach (['abc' => true, '30,024.00' => true, '-1' => true, '30024' => false, '30024.50' => false] as $salary => $invalid) {
            $work->monthlySalary = (string) $salary;
            self::assertSame($invalid, [] !== $this->violations($work), "salary = $salary");
        }
    }

    /** The list tabs cap their own rows (ProfileControllerTest); children are capped on the sheet. */
    public function testChildrenAreCapped(): void
    {
        $sheet = $this->sheet();
        for ($i = 0; $i < 31; ++$i) {
            $c = new PdsChild();
            $c->fullName = 'Child '.$i;
            $sheet->addChild($c);
        }

        $validator = static::getContainer()->get(ValidatorInterface::class);
        $paths = array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($validator->validate($sheet, groups: [PersonalDataSheet::FAMILY])));

        self::assertContains('children', $paths);
    }
}
