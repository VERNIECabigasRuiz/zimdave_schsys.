<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeChangeRequest;
use App\Entity\GradeLevel;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Grading\GradeChangeManager;
use App\Grading\GradeRecorder;
use App\Tests\Controller\Admin\AdminTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolGradeChangeControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $principal;
    private User $teacher;
    private Section $honest;
    private Subject $math;
    private TeachingLoad $load;
    private GradingPeriod $term1;
    /** @var array<string, Enrollment> by last name */
    private array $enrollments = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->term1 = $this->sy->getGradingPeriod(1);
        $this->em->flush();
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->openGrading($this->school, $this->term1);
        $this->principal = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($this->math);
        $this->em->persist($this->load = new TeachingLoad($this->honest, $this->math, $this->teacher));
        foreach (['Arnol' => SexAtBirth::Male, 'Bautista' => SexAtBirth::Female] as $last => $sex) {
            $learner = $this->learner('1200171700'.\count($this->enrollments), $last, 'Ana')->setSex($sex);
            $this->em->persist($this->enrollments[$last] = new Enrollment($learner, $this->sy, $this->honest, $this->teacher, new \DateTimeImmutable('2025-05-20')));
        }
        $this->em->persist(new Grade($this->enrollments['Arnol'], $this->math, $this->term1, 78, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        $this->em->flush();
        $this->client->loginUser($this->principal);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return '/schools/'.$this->school->getId().$path;
    }

    /** Locks Term 1 and sends Tess's request: Arnol 78 → 82. */
    private function sendRequest(): GradeChangeRequest
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(GradeRecorder::class)->lockPeriod(
            $em->find(School::class, $this->school->getId()),
            $em->find(GradingPeriod::class, $this->term1->getId()),
            $em->find(User::class, $this->principal->getId()),
        );

        return static::getContainer()->get(GradeChangeManager::class)->request(
            $em->find(TeachingLoad::class, $this->load->getId()),
            $em->find(GradingPeriod::class, $this->term1->getId()),
            [(int) $this->enrollments['Arnol']->getId() => 82],
            "Encoding error on Arnol's quiz total",
            $em->find(User::class, $this->teacher->getId()),
        );
    }

    private function arnolsGrade(): int
    {
        $this->em->clear();

        return $this->em->getRepository(Grade::class)->findOneBy(['enrollment' => $this->enrollments['Arnol']->getId()])->getValue();
    }

    private function isPending(GradeChangeRequest $request): bool
    {
        $this->em->clear();

        return $this->em->find(GradeChangeRequest::class, $request->getId())->isPending();
    }

    private function approveUrl(GradeChangeRequest $request): string
    {
        return $this->url('/grade-changes/'.$request->getId().'/approve');
    }

    public function testPendingList(): void
    {
        $this->sendRequest();

        $crawler = $this->client->request('GET', $this->url('/grade-changes'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Change requests');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Grades');
        $card = $crawler->filter('.change-requests__item');
        self::assertCount(1, $card);
        self::assertStringContainsString('Mathematics – Grade 8 – HONEST', $card->text());
        self::assertStringContainsString('Term 1', $card->text());
        self::assertStringContainsString('Tess Reyes', $card->text());
        self::assertStringContainsString("Encoding error on Arnol's quiz total", $card->text());
        self::assertStringContainsString('ARNOL', $card->text());
        self::assertStringContainsString('78 → 82', $card->text());
        self::assertStringContainsString('Pending', $card->filter('.badge')->text());
        self::assertCount(1, $card->selectButton('Approve'));
        self::assertCount(1, $card->selectButton('Decline'));
    }

    public function testApprove(): void
    {
        $request = $this->sendRequest();
        $crawler = $this->client->request('GET', $this->url('/grade-changes'));

        $this->client->submit($crawler->selectButton('Approve')->form());

        self::assertResponseRedirects($this->url('/grade-changes'));
        self::assertSame(82, $this->arnolsGrade());
        self::assertFalse($this->isPending($request));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Approved. The new grades are in.');
        self::assertCount(0, $crawler->filter('.change-requests__item'));

        $crawler = $this->client->request('GET', $this->url('/grade-changes?status=decided'));
        $card = $crawler->filter('.change-requests__item');
        self::assertCount(1, $card);
        self::assertStringContainsString('Approved', $card->filter('.badge')->text());
        self::assertStringContainsString('Rosa Cruz', $card->text());
        self::assertCount(0, $crawler->filter('main form'));
    }

    public function testApproveStale(): void
    {
        $request = $this->sendRequest();
        $this->em->clear();
        $this->em->getRepository(Grade::class)->findOneBy(['enrollment' => $this->enrollments['Arnol']->getId()])
            ->change(80, $this->em->find(User::class, $this->teacher->getId()), new \DateTimeImmutable('2025-08-22'));
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->url('/grade-changes'));

        $this->client->submit($crawler->selectButton('Approve')->form());

        self::assertResponseRedirects($this->url('/grade-changes'));
        self::assertSame(80, $this->arnolsGrade());
        self::assertTrue($this->isPending($request));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'Grades have changed since this request was sent. Decline it so the teacher can send a new one.');
    }

    public function testDecline(): void
    {
        $request = $this->sendRequest();
        $crawler = $this->client->request('GET', $this->url('/grade-changes'));
        $field = 'decline_'.$request->getId().'[note]';

        $crawler = $this->client->submit($crawler->selectButton('Decline')->form([$field => 'ok']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Write a note for the teacher (at least 5 characters).', $crawler->filter('.change-requests__item')->text());
        self::assertTrue($this->isPending($request));

        $this->client->submit($crawler->selectButton('Decline')->form([$field => 'Attach the class record']));

        self::assertResponseRedirects($this->url('/grade-changes'));
        self::assertFalse($this->isPending($request));
        self::assertSame(78, $this->arnolsGrade());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Declined. The teacher will see your note.');

        $crawler = $this->client->request('GET', $this->url('/grade-changes?status=decided'));
        $card = $crawler->filter('.change-requests__item');
        self::assertStringContainsString('Declined', $card->filter('.badge')->text());
        self::assertStringContainsString('Attach the class record', $card->text());
    }

    public function testAdminSeesButCannotDecide(): void
    {
        $request = $this->sendRequest();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', $this->url('/grade-changes'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.change-requests__item'));
        self::assertCount(0, $crawler->selectButton('Approve'));
        self::assertCount(0, $crawler->selectButton('Decline'));

        $this->client->request('POST', $this->approveUrl($request), ['_token' => $this->csrfToken('grade-change-approve-'.$request->getId())]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url('/grade-changes/'.$request->getId().'/decline'), ['decline_'.$request->getId() => ['note' => 'Attach the class record']]);
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->isPending($request));
        self::assertSame(78, $this->arnolsGrade());
    }

    public function testOtherSchoolsRequest404(): void
    {
        $other = $this->school('303197', 'Other NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $section = $this->newSection($other, $this->sy, GradeLevel::Grade8, 'Elsewhere');
        $subject = (new Subject($other, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->persist($request = new GradeChangeRequest($section, $subject, $this->term1, $this->teacher, 'Encoding error on a quiz', new \DateTimeImmutable('2025-08-22')));
        $this->em->flush();

        $this->client->request('GET', $this->url('/grade-changes'));
        $this->client->request('POST', $this->approveUrl($request), ['_token' => $this->csrfToken('grade-change-approve-'.$request->getId())]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url('/grade-changes/999999/approve'), ['_token' => $this->csrfToken('grade-change-approve-999999')]);
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($this->isPending($request));
    }

    public function testBadTokenChangesNothing(): void
    {
        $request = $this->sendRequest();

        $this->client->request('POST', $this->approveUrl($request), ['_token' => 'nope']);

        self::assertTrue($this->isPending($request));
        self::assertSame(78, $this->arnolsGrade());
    }

    public function testTeacherForbidden(): void
    {
        $this->client->loginUser($this->teacher);

        $this->client->request('GET', $this->url('/grade-changes'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testGradesPageButtonCount(): void
    {
        $this->sendRequest();

        $crawler = $this->client->request('GET', $this->url('/grades'));

        $link = $crawler->filter('a:contains("Change requests (1)")');
        self::assertCount(1, $link);
        self::assertSame($this->url('/grade-changes'), $link->attr('href'));
    }
}
