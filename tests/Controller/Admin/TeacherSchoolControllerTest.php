<?php

namespace App\Tests\Controller\Admin;

use App\Entity\GradeLevel;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Access\Domain\Model\User;

class TeacherSchoolControllerTest extends AdminTestCase
{
    public function testAdminAssignsTeacherToSchool(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');

        $this->client->request('GET', '/admin/accounts/'.$ana->getId().'/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $school->getId()]);
        self::assertResponseRedirects('/admin/teachers');

        $assignment = $this->em->getRepository(SchoolAssignment::class)->findOneBy(['user' => $ana->getId(), 'schoolYear' => $sy->getId()]);
        self::assertNotNull($assignment);
        self::assertSame('Pagsabungan NHS', $assignment->getSchool()->getName());
        self::assertSame(User::ROLE_TEACHER, $assignment->getRole());
        self::assertContains('Assigned ana to Pagsabungan NHS ('.$sy->getName().')', $this->activityActions());
    }

    public function testAdminMovesTeacherToAnotherSchool(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $from = $this->school('300001', 'Old School');
        $to = $this->school('300002', 'New School');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($from, $sy, $ana, User::ROLE_TEACHER);

        $this->client->request('GET', '/admin/accounts/'.$ana->getId().'/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $to->getId()]);
        self::assertResponseRedirects('/admin/teachers');

        $assignments = $this->em->getRepository(SchoolAssignment::class)->findBy(['user' => $ana->getId()]);
        self::assertCount(1, $assignments);
        self::assertSame('New School', $assignments[0]->getSchool()->getName());
        self::assertContains('Moved ana from Old School to New School ('.$sy->getName().')', $this->activityActions());
    }

    public function testAdminAssignRefusedWhenSchoolIsFull(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $solo = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->assign($solo, $sy, $this->createUser('ben', User::ROLE_TEACHER, 'Ben Cruz'), User::ROLE_TEACHER);
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');

        $this->client->request('GET', '/admin/accounts/'.$ana->getId().'/school');
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

        $crawler = $this->client->request('GET', '/admin/accounts/'.$ana->getId().'/school');
        $this->client->submit($crawler->selectButton('Remove from school')->form());
        self::assertResponseRedirects('/admin/teachers');

        self::assertSame(0, $this->em->getRepository(SchoolAssignment::class)->count(['user' => $ana->getId()]));
        self::assertContains('Removed ana from Pagsabungan NHS ('.$sy->getName().')', $this->activityActions());
    }

    /** A teacher with a teaching load in Pagsabungan NHS this school year. */
    private function loadedTeacher(SchoolYear $sy): User
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($school, $sy, $ana, User::ROLE_TEACHER);
        $section = (new Section($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setName('Rizal');
        $math = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')->setName('Mathematics')->setMeetingsPerWeek(5);
        $this->em->persist($section);
        $this->em->persist($math);
        $this->em->persist(new TeachingLoad($section, $math, $ana));
        $this->em->flush();

        return $ana;
    }

    private function schoolOf(User $teacher): ?string
    {
        $this->em->clear();

        return $this->em->getRepository(SchoolAssignment::class)->findOneBy(['user' => $teacher->getId()])?->getSchool()->getName();
    }

    public function testTeacherWithLoadsCannotBeRemovedFromSchool(): void
    {
        $ana = $this->loadedTeacher($this->schoolYear((int) date('Y'), current: true));

        $crawler = $this->client->request('GET', '/admin/accounts/'.$ana->getId().'/school');
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

        $this->client->request('GET', '/admin/accounts/'.$ana->getId().'/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $to->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Ana Reyes still has 1 load this school year.');
        self::assertSame('Pagsabungan NHS', $this->schoolOf($ana));
    }

    public function testOnlyTeachersCanBeAssignedToASchool(): void
    {
        $this->schoolYear((int) date('Y'), current: true);
        $pedro = $this->createUser('pedro', User::ROLE_STUDENT);

        $this->client->request('GET', '/admin/accounts/'.$pedro->getId().'/school');

        self::assertResponseStatusCodeSame(404);
    }
}
