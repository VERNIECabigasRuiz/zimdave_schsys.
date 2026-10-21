<?php

namespace App\Tests\Controller;

use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class SchoolTeacherControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS');
    }

    private function url(School $school, string $suffix = ''): string
    {
        return '/schools/'.$school->getId().'/teachers'.$suffix;
    }

    private function teacher(string $username, School $school, ?SchoolYear $sy = null, bool $active = true): User
    {
        $user = $this->createUser($username, User::ROLE_TEACHER, ucfirst($username).' Teacher', $active);
        $this->assign($school, $sy ?? $this->sy, $user, User::ROLE_TEACHER);

        return $user;
    }

    private function createTeacher(School $school, string $fullName, string $username): void
    {
        $this->client->request('GET', $this->url($school, '/new'));
        $this->client->submitForm('Create teacher', [
            'teacher_form[fullName]' => $fullName,
            'teacher_form[username]' => $username,
        ]);
    }

    private function teacherCount(School $school): int
    {
        $this->em->clear();

        return $this->em->getRepository(SchoolAssignment::class)->count(['school' => $school->getId(), 'role' => User::ROLE_TEACHER]);
    }

    private function clickRow(string $rowText, string $button): void
    {
        $crawler = $this->client->request('GET', $this->url($this->school));
        $this->client->submit($crawler->filter('tr:contains("'.$rowText.'")')->selectButton($button)->form());
    }

    public function testAdminCreatesTeacher(): void
    {
        $this->createTeacher($this->school, 'Ana Reyes', 'ana.reyes');
        self::assertResponseRedirects($this->url($this->school));
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.credentials', 'ana.reyes');
        self::assertNotEmpty($crawler->filter('.credentials code')->last()->text());
        $ana = $this->findUser('ana.reyes');
        self::assertSame(User::ROLE_TEACHER, $ana->getRole());
        self::assertTrue($ana->mustChangePassword());
        self::assertSame(1, $this->teacherCount($this->school));
        self::assertSelectorTextContains('.page-head', 'Teachers 1 / 15');
        self::assertContains('Created teacher ana.reyes for Pagsabungan NHS', $this->activityActions());
    }

    public function testCreateBlockedAtLimit(): void
    {
        $solo = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->teacher('first', $solo);

        $crawler = $this->client->request('GET', $this->url($solo));
        self::assertCount(0, $crawler->filter('a:contains("New teacher")'));
        self::assertSelectorTextContains('.plan-notice', 'The Individual plan allows 1 teacher(s) (1 used).');

        $this->client->request('GET', $this->url($solo, '/new'));
        self::assertResponseRedirects($this->url($solo));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'The Individual plan allows 1 teacher(s) (1 used).');
        self::assertSame(1, $this->teacherCount($solo));
    }

    public function testCreateBlockedWhenExpired(): void
    {
        $expired = $this->school('300001', 'Old School', planValidUntil: new \DateTimeImmutable('2026-10-15'));

        $this->client->request('GET', $this->url($expired, '/new'));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'The plan of Old School expired on Oct 15, 2026.');
    }

    public function testLoweredLimitKeepsTeachers(): void
    {
        foreach (['ana', 'ben', 'cora'] as $username) {
            $this->teacher($username, $this->school);
        }
        $this->em->getRepository(School::class)->find($this->school->getId())->setPlan($this->plan('Individual'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->school));

        self::assertCount(3, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('.page-head', 'Teachers 3 / 1');
        $this->client->request('GET', $this->url($this->school, '/new'));
        self::assertResponseRedirects($this->url($this->school));
    }

    public function testDeactivateFreesSlotAndActivateChecksLimit(): void
    {
        $solo = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->school = $solo;
        $this->teacher('ana', $solo);

        $this->clickRow('Ana Teacher', 'Deactivate');
        self::assertFalse($this->findUser('ana')->isActive());

        $this->createTeacher($solo, 'Ben Cruz', 'ben');
        self::assertResponseRedirects($this->url($solo));

        $this->clickRow('Ana Teacher', 'Activate');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'The Individual plan allows 1 teacher(s) (1 used).');
        self::assertFalse($this->findUser('ana')->isActive());
    }

    public function testResetPassword(): void
    {
        $this->teacher('ana', $this->school);

        $this->clickRow('Ana Teacher', 'Reset password');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.credentials', 'Password reset');
        self::assertTrue($this->findUser('ana')->mustChangePassword());
    }

    public function testEditTeacher(): void
    {
        $ana = $this->teacher('ana', $this->school);
        $this->createUser('taken', User::ROLE_STUDENT);

        $this->client->request('GET', $this->url($this->school, '/'.$ana->getId().'/edit'));
        $this->client->submitForm('Save teacher', ['teacher_form[fullName]' => 'Ana Marie Reyes']);
        self::assertResponseRedirects($this->url($this->school));
        self::assertSame('Ana Marie Reyes', $this->findUser('ana')->getFullName());

        $this->client->request('GET', $this->url($this->school, '/'.$ana->getId().'/edit'));
        $this->client->submitForm('Save teacher', ['teacher_form[username]' => 'taken']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'This username is already taken.');
    }

    public function testBringBack(): void
    {
        $previous = $this->schoolYear(2025, current: false);
        $other = $this->school('300009', 'Other School');
        $this->teacher('ana', $this->school, $previous);
        $this->teacher('ben', $this->school, $previous, active: false);
        $cora = $this->teacher('cora', $this->school, $previous);
        $this->assign($other, $this->sy, $cora, User::ROLE_TEACHER);

        $crawler = $this->client->request('GET', $this->url($this->school));
        $this->client->submit($crawler->selectButton('Bring back teachers from S.Y. 2025-2026')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', 'Brought back 1 teacher(s).');
        self::assertSelectorTextContains('.alert--warning', 'Ben Teacher: account is inactive');
        self::assertSelectorTextContains('.alert--warning', 'Cora Teacher: already assigned to Other School');
        self::assertSame(['ana'], array_map(
            static fn (SchoolAssignment $a) => $a->getUser()->getUsername(),
            $this->em->getRepository(SchoolAssignment::class)->findBy(['school' => $this->school->getId(), 'schoolYear' => $this->sy->getId()]),
        ));
    }

    public function testBringBackStopsAtLimit(): void
    {
        $previous = $this->schoolYear(2025, current: false);
        $solo = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->teacher('ana', $solo, $previous);
        $this->teacher('ben', $solo, $previous);

        $crawler = $this->client->request('GET', $this->url($solo));
        $this->client->submit($crawler->selectButton('Bring back teachers from S.Y. 2025-2026')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', 'Brought back 1 teacher(s).');
        self::assertSelectorTextContains('.alert--warning', 'Ben Teacher: limit reached');
    }

    private function addExisting(School $school, string $username): void
    {
        $this->client->request('GET', $this->url($school, '/add-existing'));
        $this->client->submitForm('Add teacher', ['existing_teacher_form[username]' => $username]);
    }

    public function testPrincipalAddsExistingTeacherByUsername(): void
    {
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $maria);
        $this->createUser('ana.reyes', User::ROLE_TEACHER, 'Ana Reyes');
        $this->client->loginUser($maria);

        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertCount(1, $crawler->filter('a:contains("Add existing teacher")'));

        $this->addExisting($this->school, '  Ana.Reyes ');
        self::assertResponseRedirects($this->url($this->school));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', 'Ana Reyes was added to the teachers of Pagsabungan NHS.');
        self::assertSelectorNotExists('.credentials');
        self::assertSame(1, $this->teacherCount($this->school));
        self::assertContains('Added existing teacher ana.reyes to Pagsabungan NHS', $this->activityActions());
    }

    public function testAddExistingRefusals(): void
    {
        $this->createUser('pedro', User::ROLE_STUDENT, 'Pedro Student');
        $this->createUser('gone', User::ROLE_TEACHER, 'Gone Teacher', active: false);
        $this->teacher('here', $this->school);
        $this->teacher('busy', $this->school('300009', 'Other School'));

        $cases = [
            'nobody' => 'No teacher account with that username.',
            'pedro' => 'No teacher account with that username.',
            'gone' => 'This account is inactive. Ask the administrator.',
            'here' => 'Here Teacher is already a teacher of this school.',
            'busy' => 'Busy Teacher is assigned to another school for 2026-2027. Ask the administrator to move them.',
        ];
        foreach ($cases as $username => $message) {
            $this->addExisting($this->school, $username);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('.form-card', $message);
            self::assertSelectorTextNotContains('.form-card', 'Other School');
        }
        self::assertSame(1, $this->em->getRepository(SchoolAssignment::class)->count(['school' => $this->school->getId()]));
    }

    public function testAddExistingRespectsLimit(): void
    {
        $solo = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->teacher('ben', $solo);
        $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');

        $this->client->request('GET', $this->url($solo, '/add-existing'));
        self::assertResponseRedirects($this->url($solo));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'The Individual plan allows 1 teacher(s) (1 used).');
    }

    public function testCreateRefusedWhileAnotherChangeIsBeingSaved(): void
    {
        // Another request of this school is in the middle of adding a teacher
        $lock = static::getContainer()->get(LockFactory::class)->createLock('school-teachers-'.$this->school->getId());
        self::assertTrue($lock->acquire());

        try {
            $this->createTeacher($this->school, 'Ana Reyes', 'ana.reyes');
            self::assertResponseRedirects($this->url($this->school));
            $this->client->followRedirect();

            self::assertSelectorTextContains('.alert--error', "Another change to this school's teachers is being saved. Please try again.");
            self::assertSame(0, $this->teacherCount($this->school));
        } finally {
            $lock->release();
        }
    }

    public function testUserWhoIsNoLongerATeacherIsNotManagedHere(): void
    {
        $ana = $this->teacher('ana', $this->school);
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $maria);
        // Promoted on the admin Accounts page; the teacher assignment is still there
        $ana->setRole(User::ROLE_ADMIN);
        $this->em->flush();
        $this->client->loginUser($maria);

        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertCount(0, $crawler->filter('tr:contains("Ana Teacher")'));
        self::assertSelectorTextContains('.page-head', 'Teachers 0 / 15');

        // Edit, reset password and deactivate all resolve the teacher the same way
        $this->client->request('GET', $this->url($this->school, '/'.$ana->getId().'/edit'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTeacherOfAnotherSchoolIs404(): void
    {
        $stranger = $this->teacher('stranger', $this->school('300009', 'Other School'));

        $this->client->request('GET', $this->url($this->school, '/'.$stranger->getId().'/edit'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPrincipalManagesOwnSchool(): void
    {
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $maria);
        $this->client->loginUser($maria);

        $this->createTeacher($this->school, 'Ana Reyes', 'ana.reyes');

        self::assertResponseRedirects($this->url($this->school));
        self::assertSame(1, $this->teacherCount($this->school));
    }

    public function testPrincipalCannotOpenOtherSchool(): void
    {
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school('300009', 'Other School'), $this->sy, $maria);
        $this->client->loginUser($maria);

        $this->client->request('GET', $this->url($this->school));

        self::assertResponseStatusCodeSame(403);
    }

    public function testNoCurrentSchoolYear(): void
    {
        $this->em->getRepository(SchoolYear::class)->find($this->sy->getId())->setCurrent(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->school));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No current school year.');
        self::assertCount(0, $crawler->filter('a:contains("New teacher")'));
    }

    public function testSchoolsListLinksToTeachers(): void
    {
        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertSame($this->url($this->school), $crawler->filter('tr:contains("Pagsabungan NHS") a:contains("Teachers")')->attr('href'));
    }

    public function testPrincipalDownloadsOwnTeachersPds(): void
    {
        $principal = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $principal);
        $ana = $this->teacher('ana', $this->school);
        $this->client->loginUser($principal);

        $crawler = $this->client->request('GET', $this->url($this->school));
        $link = $crawler->filter('tr:contains("Ana Teacher") a:contains("PDS")');
        self::assertCount(1, $link);
        $this->client->request('GET', $link->attr('href'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', $this->client->getInternalResponse()->getContent());
        self::assertSame(0, $this->em->getRepository(\App\Entity\PersonalDataSheet::class)->count([]), 'Downloading must not create the teacher\'s PDS');
        self::assertContains('Downloaded PDS of ana', $this->activityActions());
    }

    public function testPrincipalCannotDownloadOtherSchoolsOrFormerTeachers(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $principal = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $principal);
        $ben = $this->teacher('ben', $other);
        $old = $this->teacher('old', $this->school, $this->schoolYear(2025, current: false));
        $this->client->loginUser($principal);

        $this->client->request('GET', $this->url($other, '/'.$ben->getId().'/pds.pdf'));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', $this->url($this->school, '/'.$ben->getId().'/pds.pdf'));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->url($this->school, '/'.$old->getId().'/pds.pdf'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTeacherCannotDownloadColleaguesPds(): void
    {
        $ana = $this->teacher('ana', $this->school);
        $ben = $this->teacher('ben', $this->school);
        $this->client->loginUser($ana);

        $this->client->request('GET', $this->url($this->school, '/'.$ben->getId().'/pds.pdf'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanDownloadTeachersPds(): void
    {
        $ana = $this->teacher('ana', $this->school);

        $this->client->request('GET', $this->url($this->school, '/'.$ana->getId().'/pds.pdf'));

        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }
}
