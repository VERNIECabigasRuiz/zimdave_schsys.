<?php

namespace App\Tests\Controller\Admin;

use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\User;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private function createSchool(string $schoolId, string $name): void
    {
        $this->client->request('GET', '/admin/schools/new');
        $this->client->submitForm('Create school', [
            'school_form[schoolId]' => $schoolId,
            'school_form[name]' => $name,
            'school_form[type]' => 'junior_high',
            'school_form[region]' => 'Region VII',
            'school_form[division]' => 'Mandaue City',
        ]);
        self::assertResponseRedirects('/admin/schools');
    }

    private function findSchool(string $schoolId): ?School
    {
        $this->em->clear();

        return $this->em->getRepository(School::class)->findOneBy(['schoolId' => $schoolId]);
    }

    private function clickRowButton(string $rowText, string $button): void
    {
        $crawler = $this->client->request('GET', '/admin/schools');
        $form = $crawler->filter('tr:contains("'.$rowText.'")')->selectButton($button)->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/schools');
    }

    public function testAdminCreatesSchool(): void
    {
        $this->createSchool('303456', 'Pagsabungan National High School');

        $school = $this->findSchool('303456');
        self::assertNotNull($school);
        self::assertSame('Pagsabungan National High School', $school->getName());
        self::assertSame(SchoolType::JuniorHigh, $school->getType());
        self::assertSame('Region VII', $school->getRegion());
        self::assertSame('Mandaue City', $school->getDivision());
        self::assertTrue($school->isActive());

        $this->client->followRedirect();
        self::assertSelectorTextContains('table', 'Pagsabungan National High School');
        self::assertContains('Created school 303456 Pagsabungan National High School', $this->activityActions());
    }

    public function testSchoolIdKeepsLeadingZeros(): void
    {
        $this->createSchool('012345', 'Zero Elementary School');

        self::assertSame('012345', $this->findSchool('012345')?->getSchoolId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('table', '012345');
    }

    public function testDuplicateSchoolIdRejected(): void
    {
        $this->createSchool('303456', 'Pagsabungan National High School');

        $this->client->request('GET', '/admin/schools/new');
        $this->client->submitForm('Create school', [
            'school_form[schoolId]' => '303456',
            'school_form[name]' => 'Another School',
            'school_form[type]' => 'elementary',
            'school_form[region]' => 'Region VII',
            'school_form[division]' => 'Mandaue City',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'This School ID already exists.');
    }

    public function testInvalidSchoolIdRejected(): void
    {
        foreach (['12345', '30345A'] as $badId) {
            $this->client->request('GET', '/admin/schools/new');
            $this->client->submitForm('Create school', [
                'school_form[schoolId]' => $badId,
                'school_form[name]' => 'Bad School',
                'school_form[type]' => 'elementary',
                'school_form[region]' => 'Region VII',
                'school_form[division]' => 'Mandaue City',
            ]);

            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('.form-card', 'School ID must be exactly 6 digits.');
        }
    }

    public function testAdminEditsSchool(): void
    {
        $this->createSchool('303456', 'Pagsabungan NHS');
        $id = $this->findSchool('303456')->getId();

        $this->client->request('GET', '/admin/schools/'.$id.'/edit');
        $this->client->submitForm('Save school', [
            'school_form[name]' => 'Pagsabungan National High School',
        ]);
        self::assertResponseRedirects('/admin/schools');

        self::assertSame('Pagsabungan National High School', $this->findSchool('303456')->getName());
        self::assertContains('Updated school 303456 Pagsabungan National High School', $this->activityActions());
    }

    public function testToggleDeactivatesAndReactivates(): void
    {
        $this->createSchool('303456', 'Pagsabungan NHS');

        $this->clickRowButton('303456', 'Deactivate');
        self::assertFalse($this->findSchool('303456')->isActive());

        $this->clickRowButton('303456', 'Activate');
        self::assertTrue($this->findSchool('303456')->isActive());

        $actions = $this->activityActions();
        self::assertContains('Deactivated school 303456 Pagsabungan NHS', $actions);
        self::assertContains('Activated school 303456 Pagsabungan NHS', $actions);
    }

    public function testToggleRejectsForgedToken(): void
    {
        $this->createSchool('303456', 'Pagsabungan NHS');
        $id = $this->findSchool('303456')->getId();

        $this->client->request('POST', '/admin/schools/'.$id.'/toggle', ['_token' => 'forged']);

        // Rejected (sent back to sign-in) and nothing changes
        self::assertResponseRedirects();
        self::assertTrue($this->findSchool('303456')->isActive());
    }

    public function testSchoolPlanFieldsSaved(): void
    {
        $this->client->request('GET', '/admin/schools/new');
        $this->client->submitForm('Create school', [
            'school_form[schoolId]' => '303456',
            'school_form[name]' => 'Pagsabungan NHS',
            'school_form[type]' => 'junior_high',
            'school_form[region]' => 'Region VII',
            'school_form[division]' => 'Mandaue City',
            'school_form[plan]' => (string) $this->plan('Individual')->getId(),
            'school_form[seats]' => '2',
            'school_form[planValidUntil]' => '2027-04-30',
        ]);
        self::assertResponseRedirects('/admin/schools');

        $school = $this->findSchool('303456');
        self::assertSame('Individual', $school->getPlan()->getName());
        self::assertSame(2, $school->getSeats());
        self::assertSame('2027-04-30', $school->getPlanValidUntil()->format('Y-m-d'));

        $crawler = $this->client->followRedirect();
        $row = $crawler->filter('tr:contains("Pagsabungan NHS")')->text();
        self::assertStringContainsString('Individual × 2', $row);
        self::assertStringContainsString('Apr 30, 2027', $row);
    }

    public function testNewSchoolDefaultsToBasic(): void
    {
        $this->createSchool('303456', 'Pagsabungan NHS');

        self::assertSame('Basic', $this->findSchool('303456')->getPlan()->getName());
        self::assertSame(1, $this->findSchool('303456')->getSeats());
    }

    public function testExpiryBadges(): void
    {
        static::mockTime('2026-10-16');
        $this->school('300001', 'Expired School', planValidUntil: new \DateTimeImmutable('2026-10-15'));
        $this->school('300002', 'Expiring School', planValidUntil: new \DateTimeImmutable('2026-10-26'));
        $this->school('300003', 'Paid School', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->school('300004', 'Forever School');

        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertStringContainsString('Expired', $crawler->filter('tr:contains("Expired School")')->text());
        self::assertStringContainsString('Expires soon', $crawler->filter('tr:contains("Expiring School")')->text());
        $paid = $crawler->filter('tr:contains("Paid School")')->text();
        self::assertStringNotContainsString('Expire', $paid);
        self::assertStringContainsString('Apr 30, 2027', $paid);
        self::assertStringNotContainsString('Expire', $crawler->filter('tr:contains("Forever School")')->text());
    }

    public function testCannotSwitchToNoPrincipalPlanWhilePrincipalAssigned(): void
    {
        $sy = $this->schoolYear(2026, current: true);
        $school = $this->school('303456', 'Pagsabungan NHS');
        $this->assign($school, $sy, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));

        $this->client->request('GET', '/admin/schools/'.$school->getId().'/edit');
        $this->client->submitForm('Save school', [
            'school_form[plan]' => (string) $this->plan('Individual')->getId(),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Remove the principal of Pagsabungan NHS for 2026-2027 before choosing the Individual plan.');
        self::assertSame('Basic', $this->findSchool('303456')->getPlan()->getName());
    }

    public function testNonAdminsAreDenied(): void
    {
        foreach ([User::ROLE_PRINCIPAL, User::ROLE_TEACHER] as $i => $role) {
            $this->client->loginUser($this->createUser('user'.$i, $role));

            foreach (['/admin/schools', '/admin/schools/new'] as $url) {
                $this->client->request('GET', $url);
                self::assertResponseStatusCodeSame(403);
            }
        }
    }
}
