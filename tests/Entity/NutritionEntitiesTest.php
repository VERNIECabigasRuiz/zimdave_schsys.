<?php

namespace App\Tests\Entity;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\NutritionAssessment;
use App\Entity\NutritionMeasurement;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf8Submission;
use App\Entity\User;
use App\Nutrition\NutritionPeriod;
use App\Repository\NutritionAssessmentRepository;
use App\Repository\Sf8SubmissionRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NutritionEntitiesTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private User $adviser;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->enrollment = $this->enroll($this->learner('120017170001', 'Arnol', 'Gerald'), $this->sy, $this->section, $this->adviser);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function assessment(NutritionPeriod $period): NutritionAssessment
    {
        $assessment = new NutritionAssessment($this->section, $period, new \DateTimeImmutable('2025-08-22 10:00'), $this->adviser, new \DateTimeImmutable('2025-08-22 10:00'));
        $this->em->persist($assessment);
        $this->em->flush();

        return $assessment;
    }

    public function testPersistAssessmentWithMeasurements(): void
    {
        $other = $this->enroll($this->learner('120017170002', 'Bello', 'Ana'), $this->sy, $this->section, $this->adviser);
        $assessment = new NutritionAssessment($this->section, NutritionPeriod::Baseline, new \DateTimeImmutable('2025-08-22 10:00'), $this->adviser, new \DateTimeImmutable('2025-08-22 10:00'));
        $assessment->addMeasurement((new NutritionMeasurement($assessment, $this->enrollment))->setWeightKg('31.50')->setHeightM('1.32'));
        $assessment->addMeasurement((new NutritionMeasurement($assessment, $other))->setRemarks('Absent'));
        $this->em->persist($assessment);
        $this->em->flush();
        $id = $assessment->getId();
        $this->em->clear();

        $loaded = $this->em->find(NutritionAssessment::class, $id);
        self::assertSame('2025-08-22', $loaded->getWeighedOn()->format('Y-m-d'));
        self::assertSame(NutritionPeriod::Baseline, $loaded->getPeriod());
        self::assertCount(2, $loaded->getMeasurements());

        $enrollment = $this->em->find(Enrollment::class, $this->enrollment->getId());
        $measurement = $loaded->measurementFor($enrollment);
        self::assertSame('31.50', $measurement->getWeightKg());
        self::assertSame('1.32', $measurement->getHeightM());
        self::assertTrue($measurement->isWeighed());

        $remarksOnly = $loaded->measurementFor($this->em->find(Enrollment::class, $other->getId()));
        self::assertSame('Absent', $remarksOnly->getRemarks());
        self::assertFalse($remarksOnly->isWeighed());

        $found = $this->em->getRepository(NutritionAssessment::class);
        self::assertSame($loaded, $found->findFor($this->em->find(Section::class, $this->section->getId()), NutritionPeriod::Baseline));
        self::assertNull($found->findFor($loaded->getSection(), NutritionPeriod::Endline));
    }

    public function testOneAssessmentPerSectionAndPeriod(): void
    {
        $this->assessment(NutritionPeriod::Baseline);
        $this->assessment(NutritionPeriod::Endline);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->assessment(NutritionPeriod::Baseline);
    }

    public function testSubmissionUnique(): void
    {
        $this->em->persist(new Sf8Submission($this->section, NutritionPeriod::Baseline, $this->adviser, new \DateTimeImmutable('2025-08-23')));
        $this->em->persist(new Sf8Submission($this->section, NutritionPeriod::Endline, $this->adviser, new \DateTimeImmutable('2025-08-23')));
        $this->em->flush();

        $repository = $this->em->getRepository(Sf8Submission::class);
        self::assertNotNull($repository->findFor($this->section, NutritionPeriod::Baseline));
        self::assertArrayHasKey($this->section->getId(), $repository->findForSections([$this->section], NutritionPeriod::Endline));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->persist(new Sf8Submission($this->section, NutritionPeriod::Baseline, $this->adviser, new \DateTimeImmutable('2025-08-24')));
        $this->em->flush();
    }

    public function testUnenrollDeletesMeasurements(): void
    {
        $assessment = $this->assessment(NutritionPeriod::Baseline);
        $assessment->addMeasurement((new NutritionMeasurement($assessment, $this->enrollment))->setWeightKg('31.50')->setHeightM('1.32'));
        $this->em->flush();
        $assessmentId = $assessment->getId();

        $this->em->remove($this->enrollment);
        $this->em->flush();
        $this->em->clear();

        self::assertCount(0, $this->em->find(NutritionAssessment::class, $assessmentId)->getMeasurements());
        self::assertSame(0, (int) $this->em->createQuery('SELECT COUNT(m) FROM '.NutritionMeasurement::class.' m')->getSingleScalarResult());
    }

    public function testCertifierFieldsBlankToNull(): void
    {
        $this->school->setSf8CertifierName('  ')->setSf8CertifierPosition(' Principal II ');

        self::assertNull($this->school->getSf8CertifierName());
        self::assertSame('Principal II', $this->school->getSf8CertifierPosition());
    }
}
