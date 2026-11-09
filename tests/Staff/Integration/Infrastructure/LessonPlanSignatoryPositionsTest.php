<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\LessonPlans\Domain\Service\Signatory\SignatoryPositionsInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LessonPlanSignatoryPositionsTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testASignatorySignsWithTheirPresentDesignation(): void
    {
        // Initialize
        $tess = $this->plainUser('tess', User::ROLE_TEACHER);
        $rene = $this->plainUser('rene', User::ROLE_PRINCIPAL);
        $now = new \DateTimeImmutable();
        $earlier = (new ServiceRecordEntry($tess, new \DateTimeImmutable('2019-06-01'), $tess, $now))
            ->setToDate(new \DateTimeImmutable('2022-05-31'))
            ->setDesignation('Teacher I')
            ->setStatus('Regular/Permanent')
            ->setAnnualSalary('300000')
            ->setStation('Mandaue City')
            ->setBranch('National');
        $present = (new ServiceRecordEntry($tess, new \DateTimeImmutable('2022-06-01'), $tess, $now))
            ->setDesignation('Teacher III')
            ->setStatus('Regular/Permanent')
            ->setAnnualSalary('400000')
            ->setStation('Mandaue City')
            ->setBranch('National');
        $this->em->persist($earlier);
        $this->em->persist($present);
        $this->em->flush();
        $positions = static::getContainer()->get(SignatoryPositionsInterface::class);

        // Execute
        $tessSigns = $positions->positionOf($tess);
        $reneSigns = $positions->positionOf($rene);

        // Assert
        self::assertSame('Teacher III', $tessSigns);
        self::assertNull($reneSigns);
    }
}
