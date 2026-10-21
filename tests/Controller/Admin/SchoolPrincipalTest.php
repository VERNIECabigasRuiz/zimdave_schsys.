<?php

namespace App\Tests\Controller\Admin;

use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\User;

class SchoolPrincipalTest extends AdminTestCase
{
    private SchoolYear $previousYear;
    private SchoolYear $currentYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousYear = $this->schoolYear(2025, current: false);
        $this->currentYear = $this->schoolYear(2026, current: true);
    }

    private function principalOf(School $school, SchoolYear $sy): ?User
    {
        $this->em->clear();
        $assignment = $this->em->getRepository(SchoolAssignment::class)->findOneBy([
            'school' => $school->getId(),
            'schoolYear' => $sy->getId(),
        ]);

        return $assignment?->getUser();
    }

    private function listUrl(?SchoolYear $sy = null): string
    {
        return '/admin/schools'.($sy ? '?sy='.$sy->getId() : '');
    }

    private function assignUrl(School $school, SchoolYear $sy): string
    {
        return '/admin/schools/'.$school->getId().'/principal?sy='.$sy->getId();
    }

    private function submitPrincipal(School $school, User $principal): void
    {
        $this->client->request('GET', $this->assignUrl($school, $this->currentYear));
        $this->client->submitForm('Save principal', [
            'principal_assignment_form[principal]' => (string) $principal->getId(),
        ]);
        self::assertResponseRedirects($this->listUrl($this->currentYear));
    }

    public function testListShowsAssignButtonAndPrincipalName(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');

        $crawler = $this->client->request('GET', '/admin/schools');
        self::assertCount(1, $crawler->filter('tr:contains("Pagsabungan NHS") a:contains("Assign principal")'));

        $this->assign($school, $this->currentYear, $maria);

        $crawler = $this->client->request('GET', '/admin/schools');
        $row = $crawler->filter('tr:contains("Pagsabungan NHS")');
        self::assertStringContainsString('Maria Santos', $row->text());
        self::assertCount(1, $row->filter('a:contains("Change")'));
    }

    public function testAssignViaForm(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $other = $this->school('303457', 'Mandaue City CES');
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $busy = $this->createUser('busy', User::ROLE_PRINCIPAL, 'Busy Principal');
        $this->createUser('gone', User::ROLE_PRINCIPAL, 'Inactive Principal', active: false);
        $this->createUser('ana', User::ROLE_TEACHER, 'Ana Teacher');
        $this->assign($other, $this->currentYear, $busy);

        $crawler = $this->client->request('GET', $this->assignUrl($school, $this->currentYear));
        $options = $crawler->filter('select[name="principal_assignment_form[principal]"] option')
            ->each(static fn ($o) => trim($o->text()));
        self::assertSame(['Select a principal', 'Maria Santos'], $options);

        $this->submitPrincipal($school, $maria);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', 'Maria Santos is now the principal of Pagsabungan NHS for 2026-2027.');
        self::assertSame('maria', $this->principalOf($school, $this->currentYear)?->getUsername());
    }

    public function testChangePrincipal(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->assign($school, $this->currentYear, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));
        $juan = $this->createUser('juan', User::ROLE_PRINCIPAL, 'Juan Cruz');

        $this->submitPrincipal($school, $juan);

        self::assertSame('juan', $this->principalOf($school, $this->currentYear)?->getUsername());
        self::assertSame(1, $this->em->getRepository(SchoolAssignment::class)->count([]));
    }

    public function testRemovePrincipal(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->assign($school, $this->currentYear, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));

        $crawler = $this->client->request('GET', '/admin/schools');
        $form = $crawler->filter('tr:contains("Pagsabungan NHS")')->selectButton('Remove')->form();
        $this->client->submit($form);
        self::assertResponseRedirects($this->listUrl($this->currentYear));
        $this->client->followRedirect();

        self::assertSelectorExists('.alert--success');
        self::assertNull($this->principalOf($school, $this->currentYear));
    }

    public function testSchoolYearSelector(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->assign($school, $this->previousYear, $this->createUser('old', User::ROLE_PRINCIPAL, 'Old Principal'));
        $this->assign($school, $this->currentYear, $this->createUser('new', User::ROLE_PRINCIPAL, 'New Principal'));

        $this->client->request('GET', $this->listUrl($this->previousYear));
        self::assertSelectorTextContains('table', 'Old Principal');
        self::assertSelectorTextNotContains('table', 'New Principal');

        $this->client->request('GET', '/admin/schools');
        self::assertSelectorTextContains('table', 'New Principal');
    }

    public function testUnknownSchoolYearIs404(): void
    {
        $this->client->request('GET', '/admin/schools?sy=999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNoSchoolYearShowsEmptyState(): void
    {
        $this->em->createQuery('DELETE FROM '.SchoolYear::class)->execute();
        $this->school('303456', 'Pagsabungan NHS');

        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Create a school year first');
        self::assertCount(1, $crawler->filter('a[href="/admin/school-years/new"]'));
        self::assertSelectorTextContains('table', 'Pagsabungan NHS');
    }

    public function testAssignToInactiveSchoolShowsError(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');

        $this->client->request('GET', $this->assignUrl($school, $this->currentYear));
        $this->em->getRepository(School::class)->find($school->getId())->setActive(false);
        $this->em->flush();

        $this->client->submitForm('Save principal', [
            'principal_assignment_form[principal]' => (string) $maria->getId(),
        ]);
        self::assertResponseRedirects($this->listUrl($this->currentYear));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'This school is inactive.');
        self::assertNull($this->principalOf($school, $this->currentYear));
    }

    public function testPrincipalOfInactiveSchoolCanBeRemoved(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS', active: false);
        $this->assign($school, $this->currentYear, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));

        $crawler = $this->client->request('GET', '/admin/schools');
        $row = $crawler->filter('tr:contains("Pagsabungan NHS")');
        self::assertCount(0, $row->filter('a:contains("Change")'));

        $this->client->submit($row->selectButton('Remove')->form());

        self::assertResponseRedirects($this->listUrl($this->currentYear));
        self::assertNull($this->principalOf($school, $this->currentYear));
    }

    public function testInactivePrincipalShownAsInactive(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($school, $this->currentYear, $maria);
        $maria->setActive(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertStringContainsString('Maria Santos (inactive)', $crawler->filter('tr:contains("Pagsabungan NHS")')->text());
    }

    public function testCopyButtonAndCopy(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->assign($school, $this->previousYear, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));

        $crawler = $this->client->request('GET', '/admin/schools');
        $button = $crawler->selectButton('Copy principals from SY 2025-2026');
        self::assertCount(1, $button);

        $this->client->submit($button->form());
        self::assertResponseRedirects($this->listUrl($this->currentYear));
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', 'Copied 1 principal assignment(s).');
        self::assertSame('maria', $this->principalOf($school, $this->currentYear)?->getUsername());
        self::assertCount(0, $crawler->selectButton('Copy principals from SY 2025-2026'));
    }

    public function testCopyReportsSkipped(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($school, $this->previousYear, $maria);
        $maria->setActive(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/schools');
        $this->client->submit($crawler->selectButton('Copy principals from SY 2025-2026')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', 'Copied 0 principal assignment(s).');
        self::assertSelectorTextContains('.alert--warning', 'Pagsabungan NHS: This account is inactive.');
    }

    public function testCopyButtonHiddenWithoutPreviousYear(): void
    {
        $crawler = $this->client->request('GET', $this->listUrl($this->previousYear));

        self::assertCount(0, $crawler->filter('button:contains("Copy principals")'));
    }

    public function testTeacherUsageColumn(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        foreach (['ana', 'ben'] as $username) {
            $this->em->persist(new SchoolAssignment($school, $this->currentYear, $this->createUser($username, User::ROLE_TEACHER), User::ROLE_TEACHER));
        }
        $this->em->persist(new SchoolAssignment($school, $this->currentYear, $this->createUser('gone', User::ROLE_TEACHER, active: false), User::ROLE_TEACHER));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertStringContainsString('2 / 15', $crawler->filter('tr:contains("Pagsabungan NHS")')->text());
    }

    public function testNoAssignButtonOnIndividualPlan(): void
    {
        $this->school('303456', 'Solo Teacher School', plan: 'Individual');

        $crawler = $this->client->request('GET', '/admin/schools');
        $row = $crawler->filter('tr:contains("Solo Teacher School")');

        self::assertCount(0, $row->filter('a:contains("Assign principal")'));
        self::assertStringContainsString('No principal on this plan', $row->text());
    }

    public function testPrincipalCannotAccessAssignRoutes(): void
    {
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->client->loginUser($this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));

        $this->client->request('GET', $this->assignUrl($school, $this->currentYear));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/admin/schools/copy-principals?sy='.$this->currentYear->getId());
        self::assertResponseStatusCodeSame(403);
    }
}
