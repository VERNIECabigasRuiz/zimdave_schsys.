<?php

declare(strict_types=1);

namespace App\Tests\Staff\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PersonalDataSheetRepositoryTest extends KernelTestCase
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

    public function testFindsOnlyTheSheetAddedForTheUser(): void
    {
        // Initialize
        $maria = $this->plainUser('maria', User::ROLE_TEACHER);
        $ben = $this->plainUser('ben', User::ROLE_TEACHER);
        $repository = static::getContainer()->get(PersonalDataSheetRepositoryInterface::class);
        $sheet = new PersonalDataSheet($maria);

        // Execute
        $repository->add($sheet);
        $this->em->flush();
        $this->em->clear();
        $found = $repository->findFor($this->em->find(User::class, $maria->getId()) ?? new User());
        $none = $repository->findFor($this->em->find(User::class, $ben->getId()) ?? new User());

        // Assert
        self::assertSame($sheet->getId(), $found?->getId());
        self::assertNull($none);
    }
}
