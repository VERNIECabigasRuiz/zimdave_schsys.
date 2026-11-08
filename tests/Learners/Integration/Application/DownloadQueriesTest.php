<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Learners\Application\Query\Command\GetLearnerIdCardCommand;
use App\Learners\Application\Query\Command\GetSectionIdCardsCommand;
use App\Learners\Application\Query\Command\GetSf1FileCommand;
use App\Learners\Domain\Exception\LearnerNotFoundException;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Model\Learner;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class DownloadQueriesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    protected EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $rizal;
    private Section $otherSection;
    private User $principal;
    private Learner $learner;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS');
        $other = $this->school('303457', 'Mandaue NHS');
        $this->rizal = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->otherSection = $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Bonifacio');
        $this->principal = $this->plainUser('maria', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->principal);
        $this->learner = $this->learner('100000000001', 'Cruz', 'Juan');
        $this->enroll($this->learner, $this->sy, $this->rizal, $this->principal);
    }

    public function testGivesTheSf1AndTheIdCardsWithTheirFileNames(): void
    {
        // Initialize
        $actorId = (int) $this->principal->getId();
        $schoolId = (int) $this->school->getId();
        $sectionId = (int) $this->rizal->getId();

        // Execute
        $sf1 = $this->file(new GetSf1FileCommand($actorId, $schoolId, null, $sectionId));
        $section = $this->file(new GetSectionIdCardsCommand($actorId, $schoolId, null, $sectionId, 'card'));
        $one = $this->file(
            new GetLearnerIdCardCommand($actorId, $schoolId, null, (int) $this->learner->getId(), 'unknown'),
        );

        // Assert
        self::assertSame('SF1_2026_Grade 7 - RIZAL.xls', $sf1['fileName']);
        self::assertStringStartsWith("\xD0\xCF\x11\xE0", $sf1['content']);
        self::assertSame('IDs_2026_Grade 7 - RIZAL.pdf', $section['fileName']);
        self::assertStringStartsWith('%PDF', $section['content']);
        self::assertSame('ID_100000000001.pdf', $one['fileName']);
        self::assertStringStartsWith('%PDF', $one['content']);
    }

    public function testRefusesWhatThePageMayNotReach(): void
    {
        // Initialize
        $outsider = $this->plainUser('otto', User::ROLE_PRINCIPAL);
        $schoolId = (int) $this->school->getId();
        $principalId = (int) $this->principal->getId();
        $stranger = $this->learner('100000000002', 'Reyes', 'Ana');
        $this->enroll($stranger, $this->sy, $this->otherSection, $this->principal);

        // Execute
        $results = [
            $this->query(new GetSf1FileCommand((int) $outsider->getId(), $schoolId, null, (int) $this->rizal->getId())),
            $this->query(
                new GetSectionIdCardsCommand(
                    $principalId,
                    $schoolId,
                    null,
                    (int) $this->otherSection->getId(),
                    'sheet',
                ),
            ),
            $this->query(new GetLearnerIdCardCommand($principalId, $schoolId, null, (int) $stranger->getId(), 'sheet')),
        ];

        // Assert
        self::assertSame(
            [LearnersAccessDeniedException::class, SectionNotFoundException::class, LearnerNotFoundException::class],
            array_map(static fn (Result $r) => get_debug_type($r->exception()), $results),
        );
    }

    /** @return array{fileName: string, content: string} */
    private function file(Query $command): array
    {
        $result = $this->query($command);
        self::assertTrue($result->isSuccess(), (string) $result->exception()?->getMessage());
        /** @var array{fileName: string, content: string} $file */
        $file = $result->data();

        return $file;
    }

    private function query(Query $command): Result
    {
        return static::getContainer()->get(QueryBusInterface::class)->handle($command);
    }
}
