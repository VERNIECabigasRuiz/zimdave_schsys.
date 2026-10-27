<?php

namespace App\Tests\Controller;

use App\Entity\ClassPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolClassPeriodControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $maria);
        $this->client->loginUser($maria);
    }

    private function url(School $school, string $suffix = ''): string
    {
        return '/schools/'.$school->getId().'/class-periods'.$suffix;
    }

    public function testPrincipalAddsPeriodAndBreak(): void
    {
        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Save', ['class_period_form[startsAt]' => '08:30', 'class_period_form[endsAt]' => '08:50', 'class_period_form[isBreak]' => '1', 'class_period_form[breakLabel]' => 'Recess']);
        self::assertResponseRedirects($this->url($this->school));

        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Save', ['class_period_form[startsAt]' => '07:30', 'class_period_form[endsAt]' => '08:30']);
        self::assertResponseRedirects($this->url($this->school));

        $crawler = $this->client->followRedirect();
        $rows = $crawler->filter('tbody tr')->each(static fn ($tr) => preg_replace('/\s+/', ' ', trim($tr->text())));
        self::assertCount(2, $rows);
        self::assertStringContainsString('Period 1', $rows[0]);
        self::assertStringContainsString('07:30–08:30', $rows[0]);
        self::assertStringContainsString('Recess', $rows[1]);
        self::assertContains('Added class period Period 1', $this->activityActions());
        self::assertContains('Added class period Recess', $this->activityActions());
    }

    public function testEditAndDelete(): void
    {
        $id = $this->classPeriod($this->school, $this->sy, '07:30', '08:30')->getId();

        $this->client->request('GET', $this->url($this->school, '/'.$id.'/edit'));
        $this->client->submitForm('Save', ['class_period_form[endsAt]' => '08:45']);
        self::assertResponseRedirects($this->url($this->school));
        self::assertContains('Updated class period Period 1', $this->activityActions());

        $crawler = $this->client->request('GET', $this->url($this->school));
        $this->client->submit($crawler->filter('tbody form')->form());
        self::assertResponseRedirects($this->url($this->school));
        self::assertContains('Deleted class period Period 1', $this->activityActions());
        self::assertNull($this->em->getRepository(ClassPeriod::class)->find($id));
    }

    public function testOtherSchoolPrincipalDenied(): void
    {
        $other = $this->school('303457', 'Other NHS');

        foreach (['', '/new'] as $suffix) {
            $this->client->request('GET', $this->url($other, $suffix));
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testPeriodOfOtherSchoolIs404(): void
    {
        $theirs = $this->classPeriod($this->school('303457', 'Other NHS'), $this->sy, '07:30', '08:30');

        $this->client->request('GET', $this->url($this->school, '/'.$theirs->getId().'/edit'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testBlockedPlanIsViewOnly(): void
    {
        $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $this->em->getRepository(School::class)->find($this->school->getId())->setPlanValidUntil(new \DateTimeImmutable('2026-10-15'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertSelectorTextContains('.plan-notice', 'expired on Oct 15, 2026');
        self::assertCount(0, $crawler->filter('a:contains("New period"), button:contains("Delete")'));

        $this->client->request('GET', $this->url($this->school, '/new'));
        self::assertResponseRedirects($this->url($this->school));
        $this->client->followRedirect();
        self::assertSelectorExists('.alert');
        self::assertCount(1, $this->em->getRepository(ClassPeriod::class)->findAll());
    }
}
