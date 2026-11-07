<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Application\Query\Command\GetOwnSectionFormCommand;
use App\Classes\Application\Query\Command\GetSectionFormCommand;
use App\Classes\Application\Query\Command\ListSectionsCommand;
use App\Classes\Application\UseCase\Command\BringBackSectionsCommand;
use App\Classes\Application\UseCase\Command\CreateOwnSectionCommand;
use App\Classes\Application\UseCase\Command\CreateSectionCommand;
use App\Classes\Application\UseCase\Command\DeleteSectionCommand;
use App\Classes\Application\UseCase\Command\UpdateSectionCommand;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Model\ActivityLog;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SectionHandlersTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Alpha NHS');
    }

    public function testCreatesEditsAndDeletesASectionWithItsLogLines(): void
    {
        // Initialize
        $ana = $this->plainUser('rt-ana', User::ROLE_TEACHER);
        $create = new CreateSectionCommand((int) $this->school->getId());
        $create->gradeLevel = GradeLevel::Grade7;
        $create->name = 'Rizal';
        $create->adviserId = $ana->getId();

        // Execute
        $created = $this->handle($create);
        $section = $this->em->getRepository(Section::class)->findOneBy(['name' => 'Rizal']);
        \assert(null !== $section);
        $update = new UpdateSectionCommand((int) $this->school->getId(), (int) $section->getId());
        $update->gradeLevel = GradeLevel::Grade8;
        $update->name = 'Mabini';
        $updated = $this->handle($update);
        $deleted = $this->handle(new DeleteSectionCommand((int) $this->school->getId(), (int) $section->getId()));

        // Assert
        self::assertSame(['label' => 'Grade 7 – Rizal'], $created->data());
        self::assertSame(['label' => 'Grade 8 – Mabini'], $updated->data());
        self::assertSame(['label' => 'Grade 8 – Mabini'], $deleted->data());
        self::assertSame(0, $this->em->getRepository(Section::class)->count([]));
        self::assertSame([
            'Created section Grade 7 – Rizal',
            'Assigned adviser rt-ana to Grade 7 – Rizal',
            'Updated section Grade 8 – Mabini',
            'Deleted section Grade 8 – Mabini',
        ], $this->actions());
    }

    public function testARefusedEditChangesNothing(): void
    {
        // Initialize
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($math);
        $this->em->persist(new TeachingLoad($section, $math, $this->plainUser('rt-ana', User::ROLE_TEACHER)));
        $this->em->flush();
        $update = new UpdateSectionCommand((int) $this->school->getId(), (int) $section->getId());
        $update->gradeLevel = GradeLevel::Grade8;
        $update->name = 'Mabini';

        // Execute
        $result = $this->handle($update);
        $this->em->clear();

        // Assert
        self::assertSame('IN_USE', $result->errorCode());
        self::assertSame(
            'Grade 7 – Rizal',
            $this->em->getRepository(Section::class)->find($section->getId())?->getLabel(),
        );
    }

    public function testBringBackNeedsAPreviousYearAndListingNeedsACurrentOne(): void
    {
        // Initialize
        $bringBack = new BringBackSectionsCommand((int) $this->school->getId());

        // Execute
        $refused = $this->handle($bringBack);
        $this->sy->setCurrent(false);
        $this->em->flush();
        $list = $this->query(new ListSectionsCommand((int) $this->school->getId()));
        $form = $this->query(new GetSectionFormCommand((int) $this->school->getId()));

        // Assert
        self::assertSame('There is no previous school year.', $refused->exception()?->getMessage());
        self::assertSame([
            'schoolYear' => null,
            'groups' => [],
            'enrolled' => [],
            'refusal' => null,
            'bringBackFrom' => null,
        ], $list->data());
        self::assertSame('No current school year.', $form->exception()?->getMessage());
    }

    public function testATeacherOnAPrincipalsPlanOrWithoutASchoolCannotCreateTheirSection(): void
    {
        // Initialize
        $ana = $this->plainUser('rt-ana', User::ROLE_TEACHER);
        $ben = $this->plainUser('rt-ben', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $ana, User::ROLE_TEACHER);
        $own = new CreateOwnSectionCommand((int) $this->school->getId(), (int) $ben->getId());
        $own->gradeLevel = GradeLevel::Grade7;
        $own->name = 'Rizal';

        // Execute
        $denied = $this->query(new GetOwnSectionFormCommand((int) $ana->getId()));
        $noSchool = $this->query(new GetOwnSectionFormCommand((int) $ben->getId()));
        $created = $this->handle($own);

        // Assert
        self::assertSame('CLASSES_ACCESS_DENIED', $denied->errorCode());
        self::assertSame(['school' => null, 'grades' => []], $noSchool->data());
        self::assertSame('SCHOOL_NOT_FOUND', $created->errorCode());
    }

    public function testWithoutACurrentYearATeacherHasNoOwnSectionPage(): void
    {
        // Initialize
        $ana = $this->plainUser('rt-ana', User::ROLE_TEACHER);
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $page = $this->query(new GetOwnSectionFormCommand((int) $ana->getId()));

        // Assert
        self::assertSame(['school' => null, 'grades' => []], $page->data());
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_map(
            static fn (ActivityLog $log) => $log->getAction(),
            $this->em->getRepository(ActivityLog::class)->findBy([], ['id' => 'ASC']),
        );
    }

    private function handle(UseCase $command): Result
    {
        return static::getContainer()->get(UseCaseBusInterface::class)->handle($command);
    }

    private function query(Query $command): Result
    {
        return static::getContainer()->get(QueryBusInterface::class)->handle($command);
    }
}
