<?php

namespace App\Tests\Entity;

use App\Entity\Learner;
use App\Entity\SexAtBirth;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LearnerSf1Test extends KernelTestCase
{
    private function learner(string $last = 'Dela Cruz', string $first = 'Juan', ?string $middle = 'Santos'): Learner
    {
        return (new Learner(new User(), new \DateTimeImmutable()))
            ->setLrn('123456789012')
            ->setLastName($last)
            ->setFirstName($first)
            ->setMiddleName($middle)
            ->setSex(SexAtBirth::Male)
            ->setBirthDate(new \DateTimeImmutable('2012-05-01'));
    }

    public function testSf1NameUpperCasesWithExtensionAndMiddle(): void
    {
        $learner = $this->learner('Bañez', 'Remuel', 'Bravo')->setExtension('Jr');

        self::assertSame('BAÑEZ, REMUEL JR, BRAVO', $learner->getSf1Name());
    }

    public function testSf1NameWithoutMiddlePrintsDash(): void
    {
        self::assertSame('BORJA, JUSTINE, -', $this->learner('Borja', 'Justine', null)->getSf1Name());
    }

    public function testParentNames(): void
    {
        $learner = $this->learner()
            ->setFatherLastName('Arnol')->setFatherFirstName('Gerald')->setFatherMiddleName('Abuhat')
            ->setMotherLastName('Molina')->setMotherFirstName('Angelle');

        self::assertSame('ARNOL, GERALD ABUHAT', $learner->getFatherSf1Name());
        self::assertSame('MOLINA, ANGELLE', $learner->getMotherSf1Name());
        self::assertNull($this->learner()->getFatherSf1Name());
    }

    public function testAgeOnFirstFridayOfJune(): void
    {
        // the first Friday of June 2025 is June 6
        self::assertSame(13, $this->learner()->setBirthDate(new \DateTimeImmutable('2012-06-06'))->getAgeOnFirstFridayOfJune(2025));
        self::assertSame(12, $this->learner()->setBirthDate(new \DateTimeImmutable('2012-06-07'))->getAgeOnFirstFridayOfJune(2025));
    }

    public function testBlankStringsBecomeNull(): void
    {
        self::assertNull($this->learner()->setReligion('  ')->getReligion());
        self::assertSame('Christianity', $this->learner()->setReligion(' Christianity ')->getReligion());
    }

    public function testIncompleteness(): void
    {
        $learner = $this->learner();
        self::assertTrue($learner->isSf1Incomplete());

        $learner->setAddressBarangay('Pagsabungan')->setAddressCity('Mandaue City')->setAddressProvince('Cebu')
            ->setMotherLastName('Molina')->setMotherFirstName('Angelle')->setContactNumber('09171234567');
        self::assertFalse($learner->isSf1Incomplete());

        $learner->setMotherFirstName(null)->setGuardianName('Lola Nena');
        self::assertFalse($learner->isSf1Incomplete(), 'a guardian stands in for the parents');

        $learner->setContactNumber(null);
        self::assertTrue($learner->isSf1Incomplete());
    }

    public function testTooLongStreetIsInvalid(): void
    {
        self::bootKernel();
        $validator = static::getContainer()->get(ValidatorInterface::class);

        self::assertCount(1, $validator->validate($this->learner()->setAddressStreet(str_repeat('x', 101))));
        self::assertCount(0, $validator->validate($this->learner()->setAddressStreet(str_repeat('x', 100))));
    }
}
