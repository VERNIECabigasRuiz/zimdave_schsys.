<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolType;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchoolRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolRepositoryInterface $schools;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->schools = static::getContainer()->get(SchoolRepositoryInterface::class);
        $this->clearSchoolData();
    }

    public function testFindsByIdAndBySchoolId(): void
    {
        // Initialize
        $school = $this->school('300001', 'Alpha NHS');

        // Execute
        $byId = $this->schools->findById((int) $school->getId());
        $bySchoolId = $this->schools->findBySchoolId('300001');
        $unknown = $this->schools->findBySchoolId('399999');

        // Assert
        self::assertSame($school, $byId);
        self::assertSame($school, $bySchoolId);
        self::assertNull($unknown);
    }

    public function testFindAllOrderedIsByName(): void
    {
        // Initialize
        $this->school('300002', 'Zeta NHS');
        $this->school('300001', 'Alpha NHS');

        // Execute
        $names = array_map(static fn (School $school) => $school->getName(), $this->schools->findAllOrdered());

        // Assert
        self::assertSame(['Alpha NHS', 'Zeta NHS'], $names);
    }

    public function testCountsAllAndActive(): void
    {
        // Initialize
        $this->school('300001', 'Alpha NHS');
        $this->school('300002', 'Beta NHS', active: false);

        // Execute
        $all = $this->schools->countAll();
        $active = $this->schools->countActive();

        // Assert
        self::assertSame(2, $all);
        self::assertSame(1, $active);
    }

    public function testCountExpiringByIncludesExpiredAndSkipsInactive(): void
    {
        // Initialize
        $this->school('300001', 'Expired', planValidUntil: new \DateTimeImmutable('2026-01-01'));
        $this->school('300002', 'Soon', planValidUntil: new \DateTimeImmutable('2026-03-01'));
        $this->school('300003', 'Later', planValidUntil: new \DateTimeImmutable('2026-12-01'));
        $this->school('300004', 'Inactive', active: false, planValidUntil: new \DateTimeImmutable('2026-01-01'));
        $this->school('300005', 'Never');

        // Execute
        $count = $this->schools->countExpiringBy(new \DateTimeImmutable('2026-03-01'));

        // Assert
        self::assertSame(2, $count);
    }

    public function testCountActiveWithoutPrincipal(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $headed = $this->school('300001', 'Headed');
        $this->school('300002', 'Open');
        $this->school('300003', 'Inactive', active: false);
        $this->assign($headed, $sy, $this->plainUser('rt-principal', User::ROLE_PRINCIPAL));

        // Execute
        $count = $this->schools->countActiveWithoutPrincipal($sy);

        // Assert
        self::assertSame(1, $count);
    }

    public function testCountByPlanSkipsPlansWithoutSchools(): void
    {
        // Initialize
        $this->school('300001', 'Alpha NHS');
        $this->school('300002', 'Beta NHS');
        $individual = $this->plan('Individual');

        // Execute
        $counts = $this->schools->countByPlan();

        // Assert
        self::assertSame([(int) $this->plan()->getId() => 2], $counts);
        self::assertArrayNotHasKey((int) $individual->getId(), $counts);
    }

    public function testAddWaitsForFlush(): void
    {
        // Initialize
        $school = (new School())->setSchoolId('300009')->setName('New NHS')->setType(SchoolType::Elementary)
            ->setRegion('Region VII')->setDivision('Cebu Province')->setPlan($this->plan());

        // Execute
        $this->schools->add($school);
        $beforeFlush = $this->schools->countAll();
        $this->em->flush();

        // Assert
        self::assertSame(0, $beforeFlush);
        self::assertSame(1, $this->schools->countAll());
    }
}
