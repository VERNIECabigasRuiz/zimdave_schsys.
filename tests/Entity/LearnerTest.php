<?php

namespace App\Tests\Entity;

use App\Entity\Learner;
use App\Entity\SexAtBirth;
use App\Entity\User;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class LearnerTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->validator = static::getContainer()->get(ValidatorInterface::class);
        $this->clearSchoolData();
    }

    private function learner(string $lrn = '123456789012', string $last = 'Dela Cruz', string $first = 'Juan'): Learner
    {
        return (new Learner(new User(), new \DateTimeImmutable()))
            ->setLrn($lrn)
            ->setLastName($last)
            ->setFirstName($first)
            ->setSex(SexAtBirth::Male)
            ->setBirthDate(new \DateTimeImmutable('2012-05-01'));
    }

    /** @return list<string> */
    private function messages(Learner $learner): array
    {
        return array_map(static fn ($v) => (string) $v->getMessage(), iterator_to_array($this->validator->validate($learner)));
    }

    public function testFullNameFormat(): void
    {
        $learner = $this->learner()->setMiddleName('Santos')->setExtension('Jr.');
        self::assertSame('Dela Cruz, Juan Santos Jr.', $learner->getFullName());
        self::assertSame('Dela Cruz, Juan Santos Jr.', $learner->getUser()->getFullName());

        $learner->setMiddleName(null)->setExtension(null);
        self::assertSame('Dela Cruz, Juan', $learner->getFullName());
        self::assertSame('Dela Cruz, Juan', $learner->getUser()->getFullName());
    }

    public function testLrnSetsUsername(): void
    {
        $learner = new Learner(new User(), new \DateTimeImmutable());
        $learner->setLrn('123456789012');
        self::assertSame('123456789012', $learner->getUser()->getUsername());
    }

    public function testLrnMustBe12Digits(): void
    {
        self::assertSame([], $this->messages($this->learner()));
        foreach (['12345678901', '12345678901A'] as $bad) {
            self::assertContains('The LRN must be exactly 12 digits.', $this->messages($this->learner($bad)));
        }
    }

    public function testLrnUnique(): void
    {
        $first = $this->learner('123456789012', 'Abad', 'Ana');
        $first->getUser()->setRole(User::ROLE_STUDENT)->setPassword('x');
        $this->em->persist($first->getUser());
        $this->em->persist($first);
        $this->em->flush();

        self::assertContains('LRN 123456789012 belongs to Abad, Ana.', $this->messages($this->learner('123456789012', 'Cruz', 'Ben')));
        // the saved learner itself is not a duplicate
        self::assertSame([], $this->messages($first));
    }

    public function testBirthDateBeforeToday(): void
    {
        $learner = $this->learner()->setBirthDate(new \DateTimeImmutable('today'));
        self::assertContains('Birthdate must be before today.', $this->messages($learner));
    }
}
