<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeLevel;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Subject;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** Grade 8 – Honest of Pagsabungan NHS with Mathematics and two learners; the principal and admin download its slips. */
class SchoolCardSlipTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private School $other;
    private User $principal;
    private User $teacher;
    private Section $honest;
    private GradingPeriod $term1;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1);
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->other = $this->school('303197', 'Other NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->principal = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest')->setAdviser($this->teacher);
        $math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($math);
        foreach (['Abad' => [SexAtBirth::Female, 72], 'Bautista' => [SexAtBirth::Male, 90]] as $last => [$sex, $value]) {
            $learner = $this->learner('12001717'.\strlen($last).$value, $last, 'Ana')->setSex($sex);
            $this->em->persist($enrollment = new Enrollment($learner, $this->sy, $this->honest, $this->teacher, new \DateTimeImmutable('2025-05-20')));
            $this->em->persist(new Grade($enrollment, $math, $this->term1, $value, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        }
        $this->em->flush();
        $this->client->loginUser($this->principal);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function pdfUrl(?Section $section = null, ?GradingPeriod $period = null, ?School $school = null): string
    {
        return \sprintf('/schools/%d/sections/%d/card-slips/%d.pdf', ($school ?? $this->school)->getId(), ($section ?? $this->honest)->getId(), ($period ?? $this->term1)->getId());
    }

    public function testPrincipalDownloadsThePdf(): void
    {
        $this->client->request('GET', $this->pdfUrl());

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('filename="Card slips - Grade 8 HONEST - Term 1.pdf"', (string) $response->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());
    }

    public function testAdminDownloadsThePdf(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->pdfUrl());

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testTeacherIs403(): void
    {
        $this->client->loginUser($this->teacher);

        $this->client->request('GET', $this->pdfUrl());

        self::assertResponseStatusCodeSame(403);
    }

    public function testOtherSchoolsSectionIs404(): void
    {
        $elsewhere = $this->newSection($this->other, $this->sy, GradeLevel::Grade8, 'Elsewhere');

        $this->client->request('GET', $this->pdfUrl($elsewhere));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPeriodOfAnotherSchoolYearIs404(): void
    {
        $old = $this->schoolYear(2024, current: false);
        $old->rebuildGradingPeriods();
        $this->em->flush();

        $this->client->request('GET', $this->pdfUrl(null, $old->getGradingPeriod(1)));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/schools/'.$this->school->getId().'/sections/'.$this->honest->getId().'/card-slips/999999.pdf');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSectionWithoutLearnersRedirectsWithAFlash(): void
    {
        $empty = $this->newSection($this->school, $this->sy, GradeLevel::Grade9, 'Empty');

        $this->client->request('GET', $this->pdfUrl($empty));

        self::assertResponseRedirects('/schools/'.$this->school->getId().'/sections/'.$empty->getId().'/grades?period='.$this->term1->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'No learners to print slips for.');
    }

    public function testButtonOnTheSectionGradesPage(): void
    {
        $crawler = $this->client->request('GET', '/schools/'.$this->school->getId().'/sections/'.$this->honest->getId().'/grades?period='.$this->term1->getId());

        self::assertResponseIsSuccessful();
        $link = $crawler->selectLink('Card slips (PDF)');
        self::assertCount(1, $link);
        self::assertSame($this->pdfUrl(), $link->attr('href'));
    }
}
