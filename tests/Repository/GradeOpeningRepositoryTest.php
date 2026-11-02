<?php

namespace App\Tests\Repository;

use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Repository\GradeOpeningRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GradeOpeningRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private GradeOpeningRepository $openings;
    private SchoolYear $sy;
    private School $a;
    private School $b;
    /** @var list<GradingPeriod> */
    private array $terms;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->openings = static::getContainer()->get(GradeOpeningRepository::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->terms = array_values($this->sy->getGradingPeriods()->toArray());
        $this->a = $this->school('303196', 'Pagsabungan National High School');
        $this->b = $this->school('303197', 'Other National High School');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testFindForAndOpenPeriodIds(): void
    {
        $this->openGrading($this->a, $this->terms[0]);
        $this->openGrading($this->a, $this->terms[2]);

        $ids = $this->openings->findOpenPeriodIds($this->a, $this->sy);
        sort($ids);
        $this->assertSame([$this->terms[0]->getId(), $this->terms[2]->getId()], $ids);
        $this->assertSame([], $this->openings->findOpenPeriodIds($this->b, $this->sy));
        $this->assertNull($this->openings->findFor($this->b, $this->terms[0]));
        $this->assertNotNull($this->openings->findFor($this->a, $this->terms[0]));
    }

    public function testExistsForSchoolYear(): void
    {
        $this->assertFalse($this->openings->existsForSchoolYear($this->sy));
        $this->openGrading($this->a, $this->terms[1]);
        $this->assertTrue($this->openings->existsForSchoolYear($this->sy));
    }

    public function testCountSchoolsOpen(): void
    {
        $this->openGrading($this->a, $this->terms[0]);
        $this->openGrading($this->b, $this->terms[0]);

        $this->assertSame(2, $this->openings->countSchoolsOpen($this->terms[0]));
        $this->assertSame(0, $this->openings->countSchoolsOpen($this->terms[1]));
    }
}
