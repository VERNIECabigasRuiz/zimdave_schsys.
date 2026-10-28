<?php

namespace App\Tests\Controller\Admin;

use App\Entity\GradeLevel;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;

class UserAccountControllerTest extends AdminTestCase
{
    public function testAdminCreatesATeacherWhoCanThenSignIn(): void
    {
        $this->client->request('GET', '/admin/accounts/new?role=teachers');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="user_account_form[role]"] option[value="ROLE_TEACHER"][selected]');

        $this->client->submitForm('Create account', [
            'user_account_form[fullName]' => 'Rosa M. Petancio',
            'user_account_form[username]' => 'RMPetancio',
            'user_account_form[temporaryPassword]' => 'temp-1234',
        ]);
        self::assertResponseRedirects('/admin/teachers');
        $this->client->followRedirect();

        // Sign-in details are shown once so the admin can hand them over
        self::assertSelectorTextContains('.credentials', 'rmpetancio');
        self::assertSelectorTextContains('.credentials', 'temp-1234');
        self::assertSelectorTextContains('.table', 'Rosa M. Petancio');
        self::assertSelectorTextContains('.table', 'Pending first sign-in');

        $teacher = $this->findUser('rmpetancio');
        self::assertSame(User::ROLE_TEACHER, $teacher->getRole());
        self::assertTrue($teacher->mustChangePassword());
        self::assertContains('Created account: rmpetancio', $this->activityActions());

        // The new teacher can sign in with the temporary password and must change it
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'rmpetancio', '_password' => 'temp-1234']);
        $this->client->followRedirect();
        self::assertResponseRedirects('/change-password');
    }

    public function testCreateFormIsValidated(): void
    {
        $this->createUser('juan', User::ROLE_STUDENT);

        $this->client->request('GET', '/admin/accounts/new');
        $this->client->submitForm('Create account', [
            'user_account_form[role]' => '',
            'user_account_form[fullName]' => '',
            'user_account_form[username]' => 'Juan',
            'user_account_form[temporaryPassword]' => '123',
        ]);

        self::assertResponseStatusCodeSame(422);
        $form = $this->client->getCrawler()->filter('.form-card')->text();
        self::assertStringContainsString('Please choose a role', $form);
        self::assertStringContainsString('This value should not be blank', $form);
        self::assertStringContainsString('This username is already taken', $form);
        self::assertStringContainsString('at least 6 characters', $form);
    }

    public function testRoleMenusListOnlyThatRole(): void
    {
        $this->createUser('teacher1', User::ROLE_TEACHER, 'Tina Teacher');
        $this->createUser('student1', User::ROLE_STUDENT, 'Sam Student');
        $this->createUser('principal1', User::ROLE_PRINCIPAL, 'Paul Principal');

        $this->client->request('GET', '/admin/teachers');
        self::assertSelectorTextContains('h1', 'Teachers');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'TEACHER');
        self::assertSelectorTextContains('.table', 'Tina Teacher');
        self::assertSelectorTextNotContains('.table', 'Sam Student');

        $this->client->request('GET', '/admin/students');
        self::assertSelectorTextContains('.table', 'Sam Student');
        self::assertSelectorTextNotContains('.table', 'Tina Teacher');

        $this->client->request('GET', '/admin/principals');
        self::assertSelectorTextContains('.table', 'Paul Principal');
        self::assertSelectorTextNotContains('.table', 'Sam Student');

        $this->client->request('GET', '/admin/accounts');
        foreach (['Tina Teacher', 'Sam Student', 'Paul Principal', 'Admin'] as $name) {
            self::assertSelectorTextContains('.table', $name);
        }
    }

    public function testSearchAndStatusFilter(): void
    {
        $this->createUser('dgsosmena', User::ROLE_TEACHER, 'Dante Sosmena');
        $this->createUser('john', User::ROLE_STUDENT, 'John Cruz', active: false);

        $this->client->request('GET', '/admin/accounts?q=sosm');
        self::assertSelectorTextContains('.table', 'Dante Sosmena');
        self::assertSelectorTextNotContains('.table', 'John Cruz');

        $this->client->request('GET', '/admin/accounts?status=inactive');
        self::assertSelectorTextContains('.table', 'John Cruz');
        self::assertSelectorTextNotContains('.table', 'Dante Sosmena');
    }

    public function testEditAccount(): void
    {
        $user = $this->createUser('maria', User::ROLE_STUDENT, 'Maria Clara');

        $this->client->request('GET', '/admin/accounts/'.$user->getId().'/edit');
        $this->client->submitForm('Save changes', [
            'user_account_form[role]' => User::ROLE_TEACHER,
            'user_account_form[fullName]' => 'Maria Clara Santos',
            'user_account_form[username]' => 'mcsantos',
            'user_account_form[active]' => false,
        ]);
        self::assertResponseRedirects('/admin/teachers');

        $user = $this->findUser('mcsantos');
        self::assertSame('Maria Clara Santos', $user->getFullName());
        self::assertSame(User::ROLE_TEACHER, $user->getRole());
        self::assertFalse($user->isActive(), 'Unchecked "active" box deactivates the account');
    }

    public function testLearnerAccountKeepsItsRole(): void
    {
        $learner = $this->learner('100000000001', 'Cruz', 'Ana');
        $user = $learner->getUser();

        $this->client->request('GET', '/admin/accounts/'.$user->getId().'/edit');
        self::assertSelectorExists('select[name="user_account_form[role]"] option[value="ROLE_STUDENT"][selected]');
        $this->client->submitForm('Save changes', [
            'user_account_form[role]' => User::ROLE_TEACHER,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('This account belongs to learner 100000000001.', $this->client->getCrawler()->filter('.form-card')->text());
        self::assertSame(User::ROLE_STUDENT, $this->findUser($user->getUsername())->getRole());
    }

    public function testNewAccountCannotBeAStudent(): void
    {
        $this->client->request('GET', '/admin/accounts/new');

        self::assertSelectorExists('select[name="user_account_form[role]"] option[value="ROLE_TEACHER"]');
        self::assertSelectorNotExists('select[name="user_account_form[role]"] option[value="ROLE_STUDENT"]');
    }

    public function testStudentListHasNoCreateAccountButton(): void
    {
        $this->client->request('GET', '/admin/students');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/admin/accounts/new?role=students"]');
        self::assertSelectorNotExists('a[href^="/admin/accounts/new"]');

        $this->client->request('GET', '/admin/accounts/new?role=students');
        self::assertResponseIsSuccessful();
        self::assertSame("", $this->client->getCrawler()->selectButton("Create account")->form()["user_account_form[role]"]->getValue());
    }

    public function testAdminCannotChangeOwnRoleOrStatus(): void
    {
        $this->client->request('GET', '/admin/accounts/'.$this->admin->getId().'/edit');

        self::assertSelectorExists('select[name="user_account_form[role]"][disabled]');
        self::assertSelectorExists('input[name="user_account_form[active]"][disabled]');
    }

    public function testDeactivateAndReactivate(): void
    {
        $user = $this->createUser('pedro', User::ROLE_STUDENT);
        $crawler = $this->client->request('GET', '/admin/students');

        $this->client->submit($crawler->selectButton('Deactivate')->form());
        self::assertResponseRedirects('/admin/students');
        self::assertFalse($this->findUser('pedro')->isActive());

        $crawler = $this->client->request('GET', '/admin/students');
        $this->client->submit($crawler->selectButton('Activate')->form());
        self::assertTrue($this->findUser('pedro')->isActive());

        self::assertContains('Deactivated account: pedro', $this->activityActions());
        self::assertContains('Activated account: pedro', $this->activityActions());
    }

    public function testActionsRequireAValidCsrfToken(): void
    {
        $user = $this->createUser('pedro', User::ROLE_STUDENT);

        $this->client->request('POST', '/admin/accounts/'.$user->getId().'/toggle-active', ['_token' => 'forged']);

        // Rejected (sent back to sign-in) and nothing changes
        self::assertResponseRedirects();
        self::assertTrue($this->findUser('pedro')->isActive());
    }

    public function testAdminCannotDeactivateThemself(): void
    {
        // Your own row has no Deactivate button...
        $crawler = $this->client->request('GET', '/admin/accounts');
        self::assertCount(0, $crawler->filter('tr:contains("(you)") form'));

        // ...and the server refuses even if the request is sent anyway
        static::getContainer()->get('request_stack')->push($this->client->getRequest());
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken('account-'.$this->admin->getId())->getValue();
        $this->client->getRequest()->getSession()->save();

        $this->client->request('POST', '/admin/accounts/'.$this->admin->getId().'/toggle-active', ['_token' => $token]);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'cannot deactivate your own account');
        self::assertTrue($this->findUser('zim')->isActive());
    }

    public function testResetPasswordGivesANewTemporaryPassword(): void
    {
        $this->createUser('pedro', User::ROLE_STUDENT);
        $crawler = $this->client->request('GET', '/admin/students');

        $this->client->submit($crawler->selectButton('Reset password')->form());
        $crawler = $this->client->followRedirect();

        $newPassword = $crawler->filter('.credentials code')->last()->text();
        self::assertMatchesRegularExpression('/^[a-z]{4}-\d{4}$/', $newPassword);
        self::assertTrue($this->findUser('pedro')->mustChangePassword());

        // Old password no longer works, the new one does
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'pedro', '_password' => 'Secret123']);
        $this->client->followRedirect();
        self::assertSelectorExists('.alert--error');

        $this->client->submitForm('Sign in', ['_username' => 'pedro', '_password' => $newPassword]);
        $this->client->followRedirect();
        self::assertResponseRedirects('/change-password');
    }

    public function testAssignedPrincipalRoleCannotBeChanged(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($school, $sy, $maria);

        $this->client->request('GET', '/admin/accounts/'.$maria->getId().'/edit');
        $this->client->submitForm('Save changes', ['user_account_form[role]' => User::ROLE_TEACHER]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Remove Maria Santos as principal of Pagsabungan NHS ('.$sy->getName().') before changing the role.');
        self::assertSame(User::ROLE_PRINCIPAL, $this->findUser('maria')->getRole());
    }

    public function testPrincipalWithOnlyPastAssignmentCanChangeRole(): void
    {
        $past = $this->schoolYear(2020, current: false);
        $this->schoolYear((int) date('Y'), current: true);
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school('303456', 'Pagsabungan NHS'), $past, $maria);

        $this->client->request('GET', '/admin/accounts/'.$maria->getId().'/edit');
        $this->client->submitForm('Save changes', ['user_account_form[role]' => User::ROLE_TEACHER]);

        self::assertResponseRedirects('/admin/teachers');
        self::assertSame(User::ROLE_TEACHER, $this->findUser('maria')->getRole());
    }

    public function testTeacherRowShowsSchoolAndAssignButton(): void
    {
        $this->schoolYear((int) date('Y'), current: true);
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');

        $crawler = $this->client->request('GET', '/admin/teachers');
        $row = $crawler->filter('tr:contains("Ana Reyes")');

        self::assertStringContainsString('Unassigned', $row->text());
        self::assertSame('/admin/accounts/'.$ana->getId().'/school', $row->filter('a:contains("School")')->attr('href'));
    }

    public function testAdminAssignsTeacherToSchool(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');

        $this->client->request('GET', '/admin/accounts/'.$ana->getId().'/school');
        $this->client->submitForm('Save school', ['teacher_school_form[school]' => (string) $school->getId()]);
        self::assertResponseRedirects('/admin/teachers');

        $assignment = $this->em->getRepository(SchoolAssignment::class)->findOneBy(['user' => $ana->getId(), 'schoolYear' => $sy->getId()]);
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

    public function testPrefetchedPageDoesNotSwallowTheNewPassword(): void
    {
        $this->createUser('rene', User::ROLE_PRINCIPAL, 'Rene Cruz');

        $crawler = $this->client->request('GET', '/admin/principals');
        $this->client->submit($crawler->filter('tr:contains("Rene Cruz")')->selectButton('Reset password')->form());
        self::assertResponseRedirects();

        // Turbo prefetches a hovered link before the redirect is followed
        $this->client->request('GET', '/admin/accounts/new?role=principals', server: ['HTTP_X_SEC_PURPOSE' => 'prefetch']);
        self::assertSelectorNotExists('.credentials');

        $this->client->request('GET', '/admin/principals');
        self::assertSelectorTextContains('.credentials', 'rene');
    }

    public function testLinkPrefetchingIsTurnedOff(): void
    {
        $this->client->request('GET', '/admin/accounts');

        self::assertSelectorExists('meta[name="turbo-prefetch"][content="false"]');
    }

    public function testActivatingTeacherRespectsSchoolPlanLimit(): void
    {
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $solo = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->assign($solo, $sy, $this->createUser('ben', User::ROLE_TEACHER, 'Ben Cruz'), User::ROLE_TEACHER);
        $this->assign($solo, $sy, $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes', active: false), User::ROLE_TEACHER);

        $crawler = $this->client->request('GET', '/admin/teachers');
        $this->client->submit($crawler->filter('tr:contains("Ana Reyes")')->selectButton('Activate')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'The Individual plan allows 1 teacher(s) (1 used).');
        self::assertFalse($this->findUser('ana')->isActive());
    }

    public function testPrincipalRowShowsCurrentSchool(): void
    {
        $sy = $this->schoolYear(2026, current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->assign($school, $sy, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));
        $this->createUser('juan', User::ROLE_PRINCIPAL, 'Juan Cruz');
        $this->createUser('pedro', User::ROLE_STUDENT, 'Pedro Student');

        $crawler = $this->client->request('GET', '/admin/principals');
        self::assertStringContainsString('Pagsabungan NHS', $crawler->filter('tr:contains("Maria Santos")')->text());
        self::assertStringContainsString('Unassigned', $crawler->filter('tr:contains("Juan Cruz")')->text());

        // Principals and teachers show their school; students don't have one yet
        $crawler = $this->client->request('GET', '/admin/accounts');
        $studentRow = $crawler->filter('tr:contains("Pedro Student")')->text();
        self::assertStringNotContainsString('Unassigned', $studentRow);
        self::assertStringNotContainsString('Pagsabungan NHS', $studentRow);
    }
}
