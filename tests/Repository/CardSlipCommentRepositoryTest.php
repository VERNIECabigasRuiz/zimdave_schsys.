<?php

namespace App\Tests\Repository;

use App\Entity\CardSlipComment;
use App\Entity\GradeLevel;
use App\Entity\GradingPeriod;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Repository\CardSlipCommentRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CardSlipCommentRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private CardSlipCommentRepository $comments;
    private SchoolYear $sy;
    private Section $section;
    private User $adviser;
    /** @var list<GradingPeriod> */
    private array $terms;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->comments = static::getContainer()->get(CardSlipCommentRepository::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->terms = array_values($this->sy->getGradingPeriods()->toArray());
        $school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('adviser1', User::ROLE_TEACHER);
        $this->section = $this->newSection($school, $this->sy, GradeLevel::Grade7, 'Rizal');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testFindForEnrollmentsKeysByEnrollment(): void
    {
        $e1 = $this->enroll($this->learner('100000000001', 'Cruz', 'Ana'), $this->sy, $this->section, $this->adviser);
        $e2 = $this->enroll($this->learner('100000000002', 'Reyes', 'Ben'), $this->sy, $this->section, $this->adviser);
        $now = new \DateTimeImmutable();
        foreach ([$e1, $e2] as $e) {
            $row = new CardSlipComment($e, $this->terms[0]);
            $row->update(['excellent'], null, $this->adviser, $now);
            $this->em->persist($row);
        }
        $other = new CardSlipComment($e1, $this->terms[1]);
        $other->update(['see_adviser'], 'Hi', $this->adviser, $now);
        $this->em->persist($other);
        $this->em->flush();
        $this->em->clear();

        $found = $this->comments->findForEnrollments([$e1->getId(), $e2->getId()], $this->terms[0]);
        $this->assertCount(2, $found);
        $this->assertEqualsCanonicalizing([$e1->getId(), $e2->getId()], array_keys($found));
        $this->assertSame(['excellent'], $found[$e1->getId()]->getCodes());
        $this->assertSame([], $this->comments->findForEnrollments([$e2->getId()], $this->terms[1]));
        $this->assertSame([], $this->comments->findForEnrollments([], $this->terms[0]));
    }

    public function testUpdateStoresCodesAndText(): void
    {
        $e = $this->enroll($this->learner('100000000003', 'Lim', 'Cy'), $this->sy, $this->section, $this->adviser);
        $row = new CardSlipComment($e, $this->terms[0]);
        $row->update(['excellent', 'see_adviser'], 'Active in class.', $this->adviser, new \DateTimeImmutable('2026-10-31 08:00'));
        $this->em->persist($row);
        $this->em->flush();
        $id = $row->getId();
        $this->em->clear();

        $loaded = $this->em->find(CardSlipComment::class, $id);
        $this->assertSame(['excellent', 'see_adviser'], $loaded->getCodes());
        $this->assertSame('Active in class.', $loaded->getCustomText());
        $this->assertSame($this->adviser->getId(), $loaded->getUpdatedBy()->getId());
        $this->assertSame('2026-10-31 08:00', $loaded->getUpdatedAt()->format('Y-m-d H:i'));
    }
}
