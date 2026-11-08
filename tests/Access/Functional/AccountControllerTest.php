<?php

namespace App\Tests\Access\Functional;

use App\Tests\Controller\Admin\AdminTestCase;
use App\Access\Application\UseCase\Handler\ToggleAccountActiveHandler;
use App\Access\Application\UseCase\Handler\UpdateAccountHandler;
use App\Access\Domain\Model\User;
use App\Access\Presentation\Controller\AccountController;
use App\Schools\Presentation\Controller\TeacherSchoolController;
use App\Learners\Infrastructure\Access\LearnerRoleChangeGuard;
use App\Schools\Infrastructure\Access\PrincipalRoleChangeGuard;
use App\Schools\Infrastructure\Access\TeacherSeatReactivationGuard;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AccountControllerTest extends AdminTestCase
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

    public function testActiveFilter(): void
    {
        // Initialize
        $this->createUser('dgsosmena', User::ROLE_TEACHER, 'Dante Sosmena');
        $this->createUser('john', User::ROLE_STUDENT, 'John Cruz', active: false);

        // Execute
        $this->client->request('GET', '/admin/accounts?status=active');

        // Assert
        self::assertSelectorTextContains('.table', 'Dante Sosmena');
        self::assertSelectorTextNotContains('.table', 'John Cruz');
    }

    public function testCreateAndAddAnother(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/accounts/new?role=teachers');

        // Execute
        $this->client->submitForm('Create and add another', [
            'user_account_form[fullName]' => 'Rosa M. Petancio',
            'user_account_form[username]' => 'rmpetancio',
            'user_account_form[temporaryPassword]' => 'temp-1234',
        ]);

        // Assert
        self::assertResponseRedirects('/admin/accounts/new?role=teachers');
        self::assertSame(User::ROLE_TEACHER, $this->findUser('rmpetancio')->getRole());
    }

    public function testAdminCannotResetTheirOwnPassword(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/accounts');
        static::getContainer()->get('request_stack')->push($this->client->getRequest());
        $token = static::getContainer()->get('security.csrf.token_manager')
            ->getToken('account-' . $this->admin->getId())
            ->getValue();
        $this->client->getRequest()->getSession()->save();
        $passwordBefore = $this->findUser('zim')->getPassword();

        // Execute
        $url = '/admin/accounts/' . $this->admin->getId() . '/reset-password';
        $this->client->request('POST', $url, ['_token' => $token]);
        $this->client->followRedirect();

        // Assert
        self::assertSelectorTextContains('.alert--error', 'Use "Change password" to change your own password.');
        self::assertSame($passwordBefore, $this->findUser('zim')->getPassword());
    }

    public function testEditAccount(): void
    {
        $user = $this->createUser('maria', User::ROLE_STUDENT, 'Maria Clara');

        $this->client->request('GET', '/admin/accounts/' . $user->getId() . '/edit');
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

        $this->client->request('GET', '/admin/accounts/' . $user->getId() . '/edit');
        self::assertSelectorExists('select[name="user_account_form[role]"] option[value="ROLE_STUDENT"][selected]');
        $this->client->submitForm('Save changes', [
            'user_account_form[role]' => User::ROLE_TEACHER,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'This account belongs to learner 100000000001.',
            $this->client->getCrawler()->filter('.form-card')->text(),
        );
        self::assertSame(User::ROLE_STUDENT, $this->findUser($user->getUsername())->getRole());
    }

    public function testLearnerAccountKeepsUsernameAndName(): void
    {
        $learner = $this->learner('100000000001', 'Cruz', 'Ana');
        $user = $learner->getUser();
        $username = $user->getUsername();
        $fullName = $user->getFullName();

        $crawler = $this->client->request('GET', '/admin/accounts/' . $user->getId() . '/edit');
        self::assertSelectorExists('input[name="user_account_form[username]"][disabled]');
        self::assertSelectorExists('input[name="user_account_form[fullName]"][disabled]');
        self::assertStringContainsString(
            'This account belongs to learner 100000000001.',
            $crawler->filter('.form-card')->text(),
        );

        // a hand-made request must not change them either
        $token = $crawler->filter('input[name="user_account_form[_token]"]')->attr('value');
        $this->client->request('POST', '/admin/accounts/' . $user->getId() . '/edit', ['user_account_form' => [
            'role' => User::ROLE_STUDENT,
            'username' => 'hacked',
            'fullName' => 'Hacked Name',
            'active' => '1',
            '_token' => $token,
        ]]);

        $saved = $this->findUser($username);
        self::assertSame($username, $saved->getUsername());
        self::assertSame($fullName, $saved->getFullName());
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
        $roleField = $this->client->getCrawler()->selectButton("Create account")->form()["user_account_form[role]"];
        self::assertInstanceOf(FormField::class, $roleField);
        self::assertSame("", $roleField->getValue());
    }

    public function testAdminCannotChangeOwnRoleOrStatus(): void
    {
        $this->client->request('GET', '/admin/accounts/' . $this->admin->getId() . '/edit');

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

    public function testDuplicateUsernameIsRefused(): void
    {
        // Initialize
        $this->createUser('juan', User::ROLE_TEACHER, 'Juan Cruz');
        $maria = $this->createUser('maria', User::ROLE_TEACHER, 'Maria Clara');

        // Execute
        $this->client->request('GET', '/admin/accounts/new');
        $this->client->submitForm('Create account', [
            'user_account_form[role]' => User::ROLE_TEACHER,
            'user_account_form[fullName]' => 'Another Juan',
            'user_account_form[username]' => 'Juan',
            'user_account_form[temporaryPassword]' => 'temp-1234',
        ]);
        $createStatus = $this->client->getResponse()->getStatusCode();
        $createText = $this->client->getCrawler()->filter('.form-card')->text();
        $this->client->request('GET', '/admin/accounts/' . $maria->getId() . '/edit');
        $this->client->submitForm('Save changes', ['user_account_form[username]' => 'juan']);

        // Assert
        self::assertSame(422, $createStatus);
        self::assertStringContainsString('This username is already taken.', $createText);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'This username is already taken.');
        self::assertSame('maria', $this->findUser('maria')->getUsername(), 'The edit was not saved');
    }

    public function testUnknownAccountIs404(): void
    {
        // Initialize
        $pedro = $this->createUser('pedro', User::ROLE_STUDENT);
        $id = $pedro->getId();
        $crawler = $this->client->request('GET', '/admin/students');
        $toggleToken = $crawler->filter('form[action="/admin/accounts/' . $id . '/toggle-active"] input[name="_token"]')
            ->attr('value');
        $resetToken = $crawler->filter('form[action="/admin/accounts/' . $id . '/reset-password"] input[name="_token"]')
            ->attr('value');
        $this->em->remove($this->findUser('pedro'));
        $this->em->flush();

        // Execute
        $this->client->request('GET', '/admin/accounts/' . $id . '/edit');
        $editStatus = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/admin/accounts/' . $id . '/toggle-active', ['_token' => $toggleToken]);
        $toggleStatus = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/admin/accounts/' . $id . '/reset-password', ['_token' => $resetToken]);

        // Assert
        self::assertSame(404, $editStatus);
        self::assertSame(404, $toggleStatus);
        self::assertResponseStatusCodeSame(404);
    }

    public function testActionsRequireAValidCsrfToken(): void
    {
        $user = $this->createUser('pedro', User::ROLE_STUDENT);

        $this->client->request('POST', '/admin/accounts/' . $user->getId() . '/toggle-active', ['_token' => 'forged']);

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
        $token = static::getContainer()->get('security.csrf.token_manager')
            ->getToken('account-' . $this->admin->getId())
            ->getValue();
        $this->client->getRequest()->getSession()->save();

        $url = '/admin/accounts/' . $this->admin->getId() . '/toggle-active';
        $this->client->request('POST', $url, ['_token' => $token]);
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

        $this->client->request('GET', '/admin/accounts/' . $maria->getId() . '/edit');
        $this->client->submitForm('Save changes', ['user_account_form[role]' => User::ROLE_TEACHER]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains(
            '.form-card',
            'Remove Maria Santos as principal of Pagsabungan NHS (' . $sy->getName() . ') before changing the role.',
        );
        self::assertSame(User::ROLE_PRINCIPAL, $this->findUser('maria')->getRole());
    }

    public function testPrincipalWithOnlyPastAssignmentCanChangeRole(): void
    {
        $past = $this->schoolYear(2020, current: false);
        $this->schoolYear((int) date('Y'), current: true);
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school('303456', 'Pagsabungan NHS'), $past, $maria);

        $this->client->request('GET', '/admin/accounts/' . $maria->getId() . '/edit');
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
        self::assertSame(
            '/admin/accounts/' . $ana->getId() . '/school',
            $row->filter('a:contains("School")')->attr('href'),
        );
    }

    public function testPrefetchedPageDoesNotSwallowTheNewPassword(): void
    {
        $this->createUser('rene', User::ROLE_PRINCIPAL, 'Rene Cruz');

        $crawler = $this->client->request('GET', '/admin/principals');
        $this->client->submit($crawler->filter('tr:contains("Rene Cruz")')->selectButton('Reset password')->form());
        self::assertResponseRedirects();

        // Turbo prefetches a hovered link before the redirect is followed
        $this->client->request(
            'GET',
            '/admin/accounts/new?role=principals',
            server: ['HTTP_X_SEC_PURPOSE' => 'prefetch'],
        );
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
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes', active: false);
        $this->assign($solo, $sy, $ana, User::ROLE_TEACHER);

        $crawler = $this->client->request('GET', '/admin/teachers');
        $this->client->submit($crawler->filter('tr:contains("Ana Reyes")')->selectButton('Activate')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'The Individual plan allows 1 teacher(s) (1 used).');
        self::assertFalse($this->findUser('ana')->isActive());
    }

    public function testATeacherWithoutASchoolThisYearCanBeReactivated(): void
    {
        // Initialize
        $this->schoolYear((int) date('Y'), current: true);
        $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes', active: false);
        $crawler = $this->client->request('GET', '/admin/teachers');
        $activate = $crawler->filter('tr:contains("Ana Reyes")')->selectButton('Activate')->form();

        // Execute
        $this->client->submit($activate);

        // Assert
        self::assertResponseRedirects('/admin/teachers');
        self::assertTrue($this->findUser('ana')->isActive());
    }

    public function testReactivationWaitsForAnotherChangeToTheSchoolsTeachers(): void
    {
        // Initialize
        $sy = $this->schoolYear((int) date('Y'), current: true);
        $school = $this->school('300002', 'Busy School');
        $ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes', active: false);
        $this->assign($school, $sy, $ana, User::ROLE_TEACHER);
        $crawler = $this->client->request('GET', '/admin/teachers');
        $activate = $crawler->filter('tr:contains("Ana Reyes")')->selectButton('Activate')->form();
        $otherChange = static::getContainer()->get(LockFactory::class)
            ->createLock('school-teachers-' . $school->getId());
        self::assertTrue($otherChange->acquire());

        // Execute
        $this->client->submit($activate);
        $otherChange->release();
        $this->client->followRedirect();

        // Assert
        self::assertSelectorTextContains(
            '.alert--error',
            "Another change to this school's teachers is being saved. Please try again.",
        );
        self::assertFalse($this->findUser('ana')->isActive());
    }

    public function testAccountScreensAreForAdminsOnly(): void
    {
        // Execute
        $accounts = (new \ReflectionClass(AccountController::class))->getAttributes(IsGranted::class);
        $teacherSchools = (new \ReflectionClass(TeacherSchoolController::class))->getAttributes(IsGranted::class);

        // Assert
        self::assertSame(User::ROLE_ADMIN, $accounts[0]->getArguments()[0] ?? null);
        self::assertSame(User::ROLE_ADMIN, $teacherSchools[0]->getArguments()[0] ?? null);
    }

    public function testEveryModulesAccountRuleIsAsked(): void
    {
        // Execute
        $roleGuards = $this->guardClasses(UpdateAccountHandler::class, 'roleGuards');
        $reactivationGuards = $this->guardClasses(ToggleAccountActiveHandler::class, 'reactivationGuards');

        // Assert
        self::assertEqualsCanonicalizing([PrincipalRoleChangeGuard::class, LearnerRoleChangeGuard::class], $roleGuards);
        self::assertSame([TeacherSeatReactivationGuard::class], $reactivationGuards);
    }

    /**
     * The classes of the guards a handler was given.
     *
     * @param class-string $handlerClass
     *
     * @return list<string>
     */
    private function guardClasses(string $handlerClass, string $property): array
    {
        $handler = static::getContainer()->get($handlerClass);
        $guards = (new \ReflectionProperty($handler, $property))->getValue($handler);
        self::assertIsIterable($guards);
        $classes = [];
        foreach ($guards as $guard) {
            self::assertIsObject($guard);
            $classes[] = $guard::class;
        }

        return $classes;
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
