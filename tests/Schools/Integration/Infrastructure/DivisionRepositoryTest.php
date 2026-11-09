<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Infrastructure;

use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DivisionRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private DivisionRepositoryInterface $divisions;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->divisions = static::getContainer()->get(DivisionRepositoryInterface::class);
        $this->clearSchoolData();
    }

    public function testFindsByIdAndForASchool(): void
    {
        // Initialize
        $mandaue = $this->division('Region VII', 'Mandaue City');
        $this->division('Region VII', 'Cebu Province');
        $this->em->flush();
        $school = $this->school('300001', 'Alpha NHS');

        // Execute
        $byId = $this->divisions->findById((int) $mandaue->getId());
        $forSchool = $this->divisions->findForSchool($school);
        $byPair = $this->divisions->findByRegionAndName('Region VII', 'Mandaue City');
        $wrongRegion = $this->divisions->findByRegionAndName('Region VI', 'Mandaue City');

        // Assert
        self::assertSame($mandaue, $byId);
        self::assertSame($mandaue, $forSchool);
        self::assertSame($mandaue, $byPair);
        self::assertNull($wrongRegion);
    }

    public function testFindAllOrderedIsByRegionThenName(): void
    {
        // Initialize
        $this->division('Region VII', 'Mandaue City');
        $this->division('NCR', 'Manila');
        $this->division('Region VII', 'Cebu Province');
        $this->em->flush();

        // Execute
        $pairs = array_map(
            static fn (Division $d) => $d->getRegion() . '/' . $d->getName(),
            $this->divisions->findAllOrdered(),
        );

        // Assert
        self::assertSame(['NCR/Manila', 'Region VII/Cebu Province', 'Region VII/Mandaue City'], $pairs);
    }

    private function division(string $region, string $name): Division
    {
        $division = (new Division())->setRegion($region)->setName($name)->setSdsName('Dr. Tester');
        $this->divisions->add($division);

        return $division;
    }
}
