<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Application;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Schools\Application\Query\Command\GetSchoolTeacherCommand;
use App\Schools\Application\Query\Command\GetTeacherSeatCommand;
use App\Schools\Application\Query\Command\ListSchoolTeachersCommand;
use App\Schools\Application\UseCase\Command\AddExistingTeacherCommand;
use App\Schools\Application\UseCase\Command\AssignTeacherSchoolCommand;
use App\Schools\Application\UseCase\Command\BringBackTeachersCommand;
use App\Schools\Application\UseCase\Command\CreateTeacherCommand;
use App\Schools\Application\UseCase\Command\ResetTeacherPasswordCommand;
use App\Schools\Application\UseCase\Command\ToggleTeacherActiveCommand;
use App\Schools\Application\UseCase\Command\UpdateTeacherCommand;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;

final class TeacherHandlersTest extends KernelTestCase
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

    public function testCreatesATeacherWithATemporaryPassword(): void
    {
        // Initialize
        $command = new CreateTeacherCommand((int) $this->school->getId());
        $command->fullName = ' Ana Reyes ';
        $command->username = 'Ana.Reyes';

        // Execute
        $result = $this->handle($command);

        // Assert
        self::assertTrue($result->isSuccess(), (string) $result->exception()?->getMessage());
        /** @var array{fullName: string, username: string, password: string} $data */
        $data = $result->data();
        self::assertSame('Ana Reyes', $data['fullName']);
        self::assertSame('ana.reyes', $data['username']);
        self::assertNotSame('', $data['password']);
        self::assertSame(['schoolId' => $this->school->getId(), 'username' => 'Ana.Reyes'], $command->toLogArray());
    }

    public function testABusySchoolIsRefused(): void
    {
        // Initialize
        $lock = static::getContainer()->get(LockFactory::class)
            ->createLock('school-teachers-' . $this->school->getId());
        self::assertTrue($lock->acquire());
        $command = new CreateTeacherCommand((int) $this->school->getId());
        $command->fullName = 'Ana Reyes';
        $command->username = 'ana.reyes';

        // Execute
        $result = $this->handle($command);
        $lock->release();

        // Assert
        self::assertSame('RESOURCE_BUSY', $result->errorCode());
        self::assertSame(
            "Another change to this school's teachers is being saved. Please try again.",
            $result->exception()?->getMessage(),
        );
    }

    public function testSeatChangesTakeTheLockBeforeReadingAnything(): void
    {
        // Initialize
        $lock = static::getContainer()->get(LockFactory::class)->createLock('school-teachers-999999');
        self::assertTrue($lock->acquire());
        $create = new CreateTeacherCommand(999999);
        $create->fullName = 'Ana Reyes';
        $create->username = 'ana.reyes';
        $addExisting = new AddExistingTeacherCommand(999999);
        $addExisting->username = 'ana.reyes';
        $assign = new AssignTeacherSchoolCommand(999998);
        $assign->schoolId = 999999;
        $commands = [
            $create,
            $addExisting,
            new ToggleTeacherActiveCommand(999999, 1),
            new BringBackTeachersCommand(999999),
            $assign,
        ];

        // Execute
        $codes = array_map(fn (UseCase $command) => $this->handle($command)->errorCode(), $commands);
        $lock->release();

        // Assert
        self::assertSame(array_fill(0, 5, 'RESOURCE_BUSY'), $codes);
    }

    public function testAddsAnExistingTeacherOrRefusesAnUnknownUsername(): void
    {
        // Initialize
        $ana = $this->plainUser('ana.reyes', User::ROLE_TEACHER);
        $known = new AddExistingTeacherCommand((int) $this->school->getId());
        $known->username = ' ANA.REYES ';
        $unknown = new AddExistingTeacherCommand((int) $this->school->getId());
        $unknown->username = 'nobody';

        // Execute
        $added = $this->handle($known);
        $refused = $this->handle($unknown);

        // Assert
        self::assertSame(['fullName' => $ana->getFullName(), 'school' => 'Alpha NHS'], $added->data());
        self::assertSame('No teacher account with that username.', $refused->exception()?->getMessage());
        self::assertSame(['schoolId' => $this->school->getId(), 'username' => 'nobody'], $unknown->toLogArray());
    }

    public function testEditsTogglesAndResetsATeacher(): void
    {
        // Initialize
        $ana = $this->plainUser('ana.reyes', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $ana, User::ROLE_TEACHER);
        $schoolId = (int) $this->school->getId();
        $anaId = (int) $ana->getId();
        $update = new UpdateTeacherCommand($schoolId, $anaId);
        $update->fullName = 'Ana M. Reyes';
        $update->username = 'ana.m.reyes';
        $toggle = new ToggleTeacherActiveCommand($schoolId, $anaId);

        // Execute
        $edited = $this->handle($update);
        $off = $this->handle($toggle);
        $on = $this->handle(new ToggleTeacherActiveCommand($schoolId, $anaId));
        $reset = $this->handle(new ResetTeacherPasswordCommand($schoolId, $anaId));

        // Assert
        self::assertSame(['fullName' => 'Ana M. Reyes'], $edited->data());
        self::assertSame(['fullName' => 'Ana M. Reyes', 'active' => false], $off->data());
        self::assertSame(['fullName' => 'Ana M. Reyes', 'active' => true], $on->data());
        /** @var array{username: string} $resetData */
        $resetData = $reset->data();
        self::assertSame('ana.m.reyes', $resetData['username']);
        self::assertSame($anaId, $update->toLogArray()['teacherId']);
        self::assertSame(['schoolId' => $schoolId, 'teacherId' => $anaId], $toggle->toLogArray());
    }

    public function testAnotherSchoolsTeacherIsNotFound(): void
    {
        // Initialize
        $ben = $this->plainUser('ben.cruz', User::ROLE_TEACHER);
        $this->assign($this->school('303457', 'Beta NHS'), $this->sy, $ben, User::ROLE_TEACHER);

        // Execute
        $result = $this->handle(new ResetTeacherPasswordCommand((int) $this->school->getId(), (int) $ben->getId()));

        // Assert
        self::assertSame('TEACHER_NOT_FOUND', $result->errorCode());
    }

    public function testBringBackCountsSeatsAsItAdds(): void
    {
        // Initialize
        $previous = $this->schoolYear(2025, current: false);
        $individual = $this->school('303458', 'Solo School', plan: 'Individual');
        foreach (['ana.reyes', 'ben.cruz'] as $username) {
            $this->assign($individual, $previous, $this->plainUser($username, User::ROLE_TEACHER), User::ROLE_TEACHER);
        }
        $command = new BringBackTeachersCommand((int) $individual->getId());

        // Execute
        $result = $this->handle($command);

        // Assert
        /** @var array{added: int, skipped: list<string>} $data */
        $data = $result->data();
        self::assertSame(1, $data['added']);
        self::assertCount(1, $data['skipped']);
        self::assertStringEndsWith(': limit reached', $data['skipped'][0]);
        self::assertSame(['schoolId' => $individual->getId()], $command->toLogArray());
    }

    public function testListsTheTeachersAndTheSeat(): void
    {
        // Initialize
        $ana = $this->plainUser('ana.reyes', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $ana, User::ROLE_TEACHER);
        $schoolId = (int) $this->school->getId();
        $list = new ListSchoolTeachersCommand($schoolId);
        $seat = new GetTeacherSeatCommand($schoolId);
        $one = new GetSchoolTeacherCommand($schoolId, (int) $ana->getId());

        // Execute
        $listed = $this->query($list);
        $seated = $this->query($seat);
        $found = $this->query($one);

        // Assert
        /** @var array{assignments: list<array{user: array{username: string}}>, used: int, addRefusal: ?string} $listData */
        $listData = $listed->data();
        self::assertSame('ana.reyes', $listData['assignments'][0]['user']['username']);
        self::assertSame(1, $listData['used']);
        self::assertNull($listData['addRefusal']);
        /** @var array{refusal: ?string} $seatData */
        $seatData = $seated->data();
        self::assertNull($seatData['refusal']);
        /** @var array{teacher: array{username: string}} $foundData */
        $foundData = $found->data();
        self::assertSame('ana.reyes', $foundData['teacher']['username']);
        self::assertSame(['schoolId' => $schoolId], $list->toLogArray());
        self::assertSame(['schoolId' => $schoolId], $seat->toLogArray());
        self::assertSame(['schoolId' => $schoolId, 'teacherId' => $ana->getId()], $one->toLogArray());
    }

    public function testWithoutACurrentYearTheListIsEmpty(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $result = $this->query(new ListSchoolTeachersCommand((int) $this->school->getId()));

        // Assert
        /** @var array{schoolYear: mixed, assignments: list<mixed>, used: int} $data */
        $data = $result->data();
        self::assertNull($data['schoolYear']);
        self::assertSame([], $data['assignments']);
        self::assertSame(0, $data['used']);
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
