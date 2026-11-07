<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Application\Query\Command\GetSubjectFormCommand;
use App\Classes\Application\Query\Command\GetSubjectScopeCommand;
use App\Classes\Application\Query\Command\ListSubjectsCommand;
use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Classes\Application\UseCase\Command\AddDepEdSubjectsCommand;
use App\Classes\Application\UseCase\Command\BringBackSubjectsCommand;
use App\Classes\Application\UseCase\Command\CreateSubjectCommand;
use App\Classes\Application\UseCase\Command\DeleteSubjectCommand;
use App\Classes\Application\UseCase\Command\UpdateSubjectCommand;
use App\Classes\Domain\Model\Subject;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SubjectHandlersTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private SubjectScopeKey $key;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Alpha NHS');
        $this->key = SubjectScopeKey::school((int) $this->school->getId());
    }

    public function testCreatesAComponentEditsAndDeletesIt(): void
    {
        // Initialize
        $mapeh = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MAPEH7')
            ->setName('MAPEH')->setPosition(1);
        $this->em->persist($mapeh);
        $this->em->flush();
        $create = new CreateSubjectCommand($this->key);
        $create->gradeLevel = GradeLevel::Grade7;
        $create->code = 'MA7';
        $create->name = 'Music and Arts';
        $create->meetingsPerWeek = 4;
        $create->parentId = $mapeh->getId();

        // Execute
        $created = $this->handle($create);
        $component = $this->em->getRepository(Subject::class)->findOneBy(['code' => 'MA7']);
        \assert(null !== $component);
        $update = new UpdateSubjectCommand($this->key, (int) $component->getId());
        $update->gradeLevel = GradeLevel::Grade7;
        $update->code = 'MA7';
        $update->name = 'Music';
        $update->meetingsPerWeek = 4;
        $update->parentId = $mapeh->getId();
        $updated = $this->handle($update);
        $deleted = $this->handle(new DeleteSubjectCommand($this->key, (int) $component->getId()));

        // Assert
        self::assertSame(['label' => 'MA7 – Music and Arts (MAPEH)'], $created->data());
        self::assertSame(['label' => 'MA7 – Music (MAPEH)'], $updated->data());
        self::assertSame(['label' => 'MA7 – Music (MAPEH)'], $deleted->data());
        self::assertSame(1, $this->em->getRepository(Subject::class)->count([]));
    }

    public function testRefusesAnUnknownDepEdGradeAndABringBackWithoutAPreviousYear(): void
    {
        // Execute
        $deped = $this->handle(new AddDepEdSubjectsCommand($this->key, 99));
        $bringBack = $this->handle(new BringBackSubjectsCommand($this->key));

        // Assert
        self::assertSame(
            'There is no DepEd subject list for this grade level here.',
            $deped->exception()?->getMessage(),
        );
        self::assertSame('There is no previous school year.', $bringBack->exception()?->getMessage());
    }

    public function testANewSubjectStartsInTheAskedGradeAfterItsSubjects(): void
    {
        // Initialize
        $math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(3)->setMeetingsPerWeek(5);
        $this->em->persist($math);
        $this->em->flush();

        // Execute
        $grade8 = $this->query(new GetSubjectFormCommand($this->key, null, 8));
        $noGrade = $this->query(new GetSubjectFormCommand($this->key));

        // Assert
        /** @var array{defaults: array{gradeLevel: ?GradeLevel, position: int}} $page */
        $page = $grade8->data();
        self::assertSame([GradeLevel::Grade8, 4], [$page['defaults']['gradeLevel'], $page['defaults']['position']]);
        /** @var array{defaults: array{gradeLevel: ?GradeLevel, position: int}} $empty */
        $empty = $noGrade->data();
        self::assertSame([null, 1], [$empty['defaults']['gradeLevel'], $empty['defaults']['position']]);
    }

    public function testASchoolPageWithoutACurrentYearIsEmpty(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $list = $this->query(new ListSubjectsCommand($this->key));

        // Assert
        self::assertSame([
            'school' => ['id' => $this->school->getId(), 'name' => 'Alpha NHS'],
            'schoolYear' => null,
            'groups' => [],
            'refusal' => null,
            'depedGrades' => [],
            'bringBackFrom' => null,
        ], $list->data());
    }

    public function testWithoutACurrentYearATeacherHasNoSubjectsToSetUp(): void
    {
        // Initialize
        $ana = $this->plainUser('rt-ana', User::ROLE_TEACHER);
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $scope = $this->query(new GetSubjectScopeCommand(SubjectScopeKey::teacher((int) $ana->getId())));

        // Assert
        self::assertSame('NO_ADVISORY', $scope->errorCode());
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
