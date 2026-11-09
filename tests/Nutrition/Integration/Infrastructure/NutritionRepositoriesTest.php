<?php

declare(strict_types=1);

namespace App\Tests\Nutrition\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Nutrition\Domain\Model\NutritionAssessment;
use App\Nutrition\Domain\Model\NutritionMeasurement;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Nutrition\Domain\Model\Repository\NutritionAssessmentRepositoryInterface;
use App\Nutrition\Domain\Model\Repository\Sf8SubmissionRepositoryInterface;
use App\Nutrition\Domain\Model\Sf8Submission;
use App\Schools\Domain\Model\GradeLevel;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class NutritionRepositoriesTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private Section $section;
    private User $adviser;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $schoolYear = $this->schoolYear(2025, current: true);
        $school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->section = $this->newSection($school, $schoolYear, GradeLevel::Grade8, 'Artistic');
        $this->enrollment = $this->enroll(
            $this->learner('120017170001', 'Arnol', 'Gerald'),
            $schoolYear,
            $this->section,
            $this->adviser,
        );
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testFindFreshSeesAMeasurementSavedElsewhere(): void
    {
        // Initialize
        $repository = static::getContainer()->get(NutritionAssessmentRepositoryInterface::class);
        $assessment = new NutritionAssessment(
            $this->section,
            NutritionPeriod::Baseline,
            new \DateTimeImmutable('2025-08-22'),
            $this->adviser,
            new \DateTimeImmutable('2025-08-22 10:00'),
        );
        $repository->add($assessment);
        $this->em->flush();
        $loaded = $repository->findFor($this->section, NutritionPeriod::Baseline);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO nutrition_measurement (assessment_id, enrollment_id, weight_kg, height_m) VALUES (?, ?, ?, ?)',
            [$assessment->getId(), $this->enrollment->getId(), '31.50', '1.32'],
        );
        $this->em->getConnection()->executeStatement(
            'UPDATE nutrition_assessment SET weighed_on = ? WHERE id = ?',
            ['2025-08-20', $assessment->getId()],
        );

        // Execute
        $stale = $repository->findFor($this->section, NutritionPeriod::Baseline)?->getWeighedOn()->format('Y-m-d');
        $fresh = $repository->findFresh($this->section, NutritionPeriod::Baseline);

        // Assert
        self::assertNotNull($loaded);
        self::assertSame('2025-08-22', $stale);
        self::assertSame('2025-08-20', $fresh?->getWeighedOn()->format('Y-m-d'));
        self::assertCount(1, $fresh->getMeasurements());
        $measurement = $fresh->getMeasurements()->first();
        self::assertInstanceOf(NutritionMeasurement::class, $measurement);
        self::assertSame('31.50', $measurement->getWeightKg());
    }

    public function testFindFreshSeesASubmissionMadeAndRemovedElsewhere(): void
    {
        // Initialize
        $repository = static::getContainer()->get(Sf8SubmissionRepositoryInterface::class);
        $submission = new Sf8Submission(
            $this->section,
            NutritionPeriod::Endline,
            $this->adviser,
            new \DateTimeImmutable('2025-08-23'),
        );
        $repository->add($submission);
        $this->em->flush();
        $id = $submission->getId();
        $repository->remove($submission);
        $this->em->flush();
        $removedHere = $repository->findFor($this->section, NutritionPeriod::Endline);
        $this->em->getConnection()->executeStatement(
            'INSERT INTO sf8_submission (section_id, period, submitted_by_id, submitted_at) VALUES (?, ?, ?, ?)',
            [$this->section->getId(), 'endline', $this->adviser->getId(), '2025-08-24 08:00:00'],
        );

        // Execute
        $fresh = $repository->findFresh($this->section, NutritionPeriod::Endline);

        // Assert
        self::assertNotNull($id);
        self::assertNull($removedHere);
        self::assertSame('2025-08-24', $fresh?->getSubmittedAt()->format('Y-m-d'));
    }
}
