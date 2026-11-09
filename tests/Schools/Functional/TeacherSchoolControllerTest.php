<?php

namespace App\Tests\Schools\Functional;

use App\Tests\Support\AdminTestCase;
use App\Schools\Domain\Model\School;
use App\Schools\Presentation\Controller\SchoolTeacherController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;

class TeacherSchoolControllerTest extends AdminTestCase
{
    public function testAdminAssignsTeacherToSchool(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');

        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $school->getId()]);
        self::assertResponseRedirects('/admin/teachers');

        $assignment = $this->em->getRepository(SchoolAssignment::class)->findOneBy([
            'user' => $ana->getId(),
            'schoolYear' => $sy->getId(),
        ]);
        self::assertNotNull($assignment);
        self::assertSame('Pagsabungan NHS', $assignment->getSchool()->getName());
        self::assertSame(User::ROLE_TEACHER, $assignment->getRole());
        self::assertContains('Assigned ana to Pagsabungan NHS (' . $sy->getName() . ')', $this->activityActions());
    }

    public function testAdminMovesTeacherToAnotherSchool(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $from = $this->school('300001', 'Old School');
        $to = $this->school('300002', 'New School');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($from, $sy, $ana, User::ROLE_TEACHER);

        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $to->getId()]);
        self::assertResponseRedirects('/admin/teachers');

        $assignments = $this->em->getRepository(SchoolAssignment::class)->findBy(['user' => $ana->getId()]);
        self::assertCount(1, $assignments);
        self::assertSame('New School', $assignments[0]->getSchool()->getName());
        self::assertContains(
            'Moved ana from Old School to New School (' . $sy->getName() . ')',
            $this->activityActions(),
        );
    }

    public function testAdminAssignRefusedWhenSchoolIsFull(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $solo = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->assign($solo, $sy, $this->createUser('ben', User::ROLE_TEACHER, 'Ben Cruz'), User::ROLE_TEACHER);
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');

        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $solo->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'The Individual plan allows 1 teacher(s) (1 used).');
        self::assertSame(0, $this->em->getRepository(SchoolAssignment::class)->count(['user' => $ana->getId()]));
    }

    public function testAdminRemovesTeacherFromSchool(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($this->school('303456', 'Pagsabungan NHS'), $sy, $ana, User::ROLE_TEACHER);

        $crawler = $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $this->client->submit($crawler->selectButton('Remove from school')->form());
        self::assertResponseRedirects('/admin/teachers');

        self::assertSame(0, $this->em->getRepository(SchoolAssignment::class)->count(['user' => $ana->getId()]));
        self::assertContains('Removed ana from Pagsabungan NHS (' . $sy->getName() . ')', $this->activityActions());
    }

    /** A teacher with a teaching load in Pagsabungan NHS this school year. */
    private function loadedTeacher(SchoolYear $sy): User
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($school, $sy, $ana, User::ROLE_TEACHER);
        $section = (new Section($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setName('Rizal');
        $math = (new Subject(
            $school,
            $sy,
        ))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')->setName('Mathematics')->setMeetingsPerWeek(5);
        $this->em->persist($section);
        $this->em->persist($math);
        $this->em->persist(new TeachingLoad($section, $math, $ana));
        $this->em->flush();

        return $ana;
    }

    private function schoolOf(User $teacher): ?string
    {
        $this->em->clear();

        return $this->em->getRepository(SchoolAssignment::class)
            ->findOneBy(['user' => $teacher->getId()])?->getSchool()->getName();
    }

    public function testTeacherWithLoadsCannotBeRemovedFromSchool(): void
    {
        $ana = $this->loadedTeacher($this->schoolYear((int) date('Y'), current: true));

        $crawler = $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $this->client->submit($crawler->selectButton('Remove from school')->form());

        self::assertResponseRedirects('/admin/teachers');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Ana Reyes still has 1 load this school year.');
        self::assertSame('Pagsabungan NHS', $this->schoolOf($ana));
    }

    public function testTeacherWithLoadsCannotBeMoved(): void
    {
        $ana = $this->loadedTeacher($this->schoolYear((int) date('Y'), current: true));
        $to = $this->school('300002', 'New School');

        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $to->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Ana Reyes still has 1 load this school year.');
        self::assertSame('Pagsabungan NHS', $this->schoolOf($ana));
    }

    public function testOnlyTeachersCanBeAssignedToASchool(): void
    {
        $this->schoolYear((int) date('Y'), current: true);
        $pedro = $this->createUser('pedro', User::ROLE_STUDENT);

        $this->client->request('GET', '/admin/accounts/' . $pedro->getId() . '/school');

        self::assertResponseStatusCodeSame(404);
    }

    public function testATeacherWithAnotherRoleThisYearCannotBeMoved(): void
    {
        // Initialize
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($this->school('300001', 'Old School'), $sy, $ana, User::ROLE_PRINCIPAL);
        $to = $this->school('300002', 'New School');
        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');

        // Execute
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $to->getId()]);

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains(
            '.form-card',
            'Ana Reyes already has another role at Old School for ' . $sy->getName() . '.',
        );
        self::assertSame('Old School', $this->schoolOf($ana));
    }

    public function testSavingTheSameSchoolChangesNothing(): void
    {
        // Initialize
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($school, $sy, $ana, User::ROLE_TEACHER);
        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');

        // Execute
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $school->getId()]);

        // Assert
        self::assertResponseRedirects('/admin/teachers');
        self::assertSame('Pagsabungan NHS', $this->schoolOf($ana));
        self::assertSame([], $this->activityActions());
    }

    public function testRemovingATeacherWithoutASchoolDoesNothing(): void
    {
        // Initialize
        $this->schoolYear((int) date('Y'), current: true);
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $token = $this->csrfToken('account-school-' . $ana->getId());

        // Execute
        $this->client->request('POST', '/admin/accounts/' . $ana->getId() . '/school/remove', ['_token' => $token]);

        // Assert
        self::assertResponseRedirects('/admin/teachers');
        self::assertNull($this->schoolOf($ana));
        self::assertSame([], $this->activityActions());
    }

    public function testRemovingAnUnknownAccountOrWithoutACurrentYearIs404(): void
    {
        // Initialize
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $ana = $this->createUser('ana', User::ROLE_TEACHER);
        $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $unknownToken = $this->csrfToken('account-school-999999');
        $anaToken = $this->csrfToken('account-school-' . $ana->getId());
        $sy->setCurrent(false);
        $this->em->flush();

        // Execute
        $this->client->request('POST', '/admin/accounts/999999/school/remove', ['_token' => $unknownToken]);
        $unknownStatus = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/admin/accounts/' . $ana->getId() . '/school/remove', ['_token' => $anaToken]);

        // Assert
        self::assertSame(404, $unknownStatus);
        self::assertResponseStatusCodeSame(404);
    }

    public function testADoubleSubmittedMoveShowsTheAlreadySavedMessage(): void
    {
        // Initialize
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $ana = $this->createUser('ana', User::ROLE_TEACHER);
        $this->client->disableReboot();
        $crawler = $this->client->request('GET', '/admin/accounts/' . $ana->getId() . '/school');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // Another request assigns her to the school between this one's checks and its save
        $clash = new class ($school, $sy, $ana) {
            public bool $done = false;

            public function __construct(
                private readonly School $school,
                private readonly SchoolYear $sy,
                private readonly User $teacher,
            ) {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                if ($this->done) {
                    return;
                }
                $this->done = true;
                $manager = $args->getObjectManager();
                $school = $manager->getReference(School::class, $this->school->getId());
                $sy = $manager->getReference(SchoolYear::class, $this->sy->getId());
                $teacher = $manager->getReference(User::class, $this->teacher->getId());
                if (null === $school || null === $sy || null === $teacher) {
                    throw new \LogicException('The clashing assignment needs saved rows.');
                }
                $manager->persist(new SchoolAssignment($school, $sy, $teacher, User::ROLE_TEACHER));
            }
        };
        $em->getEventManager()->addEventListener(Events::preFlush, $clash);
        $form = $crawler->selectButton('Save school')
            ->form(['teacher_school_form[school]' => (string) $school->getId()]);

        // Execute
        $this->client->submit($form);
        $em->getEventManager()->removeEventListener(Events::preFlush, $clash);

        // Assert
        self::assertTrue($clash->done);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', SchoolTeacherController::ALREADY_SAVED);
    }
}
