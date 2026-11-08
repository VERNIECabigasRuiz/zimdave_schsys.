<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Domain;

use App\Learners\Application\Transformer\LearnerTransformer;
use App\Learners\Application\UseCase\Command\LearnerDetails;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\SexAtBirth;
use App\Learners\Presentation\Validation\UniqueLrn;
use App\Access\Domain\Model\User;
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

    /**
     * The messages the learner details form gives for this learner's details.
     *
     * @return list<string>
     */
    private function messages(Learner $learner): array
    {
        $details = LearnerDetails::fromLearner(LearnerTransformer::toArray($learner));
        $messages = [];
        $lists = [$this->validator->validate($details), $this->validator->validate($details, new UniqueLrn())];
        foreach ($lists as $list) {
            foreach ($list as $violation) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
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

        $duplicate = $this->learner('123456789012', 'Cruz', 'Ben');

        self::assertContains('LRN 123456789012 belongs to Abad, Ana.', $this->messages($duplicate));
        // the saved learner itself is not a duplicate
        self::assertSame([], $this->messages($first));
    }

    public function testBirthDateBeforeToday(): void
    {
        $learner = $this->learner()->setBirthDate(new \DateTimeImmutable('today'));
        self::assertContains('Birthdate must be before today.', $this->messages($learner));
    }
}
