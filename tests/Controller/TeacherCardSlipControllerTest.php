<?php

namespace App\Tests\Controller;

use App\CardSlip\CardSlipBuilder;
use App\CardSlip\CardSlipComments;
use App\CardSlip\CardSlipPdf;
use App\Entity\CardSlipComment;
use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeLevel;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\Subject;
use App\Entity\User;
use App\Grading\GradeRecorder;
use App\Tests\Controller\Admin\AdminTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Grade 8 – Honest, advised by Tess, with Mathematics only. S.Y. 2025-2026 in terms; it is Aug 22, 2025.
 * Abad (female) has Math 72, Bautista (male) has Math 90.
 */
class TeacherCardSlipControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private const NOT_LOCKED = "Term 1 isn't locked yet. Slips will print as DRAFT until the principal locks the grades.";

    private SchoolYear $sy;
    private School $school;
    private User $teacher;
    private Section $honest;
    private Subject $math;
    private GradingPeriod $term1;
    /** @var array<string, Enrollment> by last name */
    private array $enrollments = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1);
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest')->setAdviser($this->teacher);
        $this->math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($this->math);
        foreach (['Abad' => [SexAtBirth::Female, 72], 'Bautista' => [SexAtBirth::Male, 90]] as $last => [$sex, $grade]) {
            $learner = $this->learner('1200171700'.\count($this->enrollments), $last, 'Ana')->setSex($sex);
            $this->em->persist($enrollment = $this->enrollments[$last] = new Enrollment($learner, $this->sy, $this->honest, $this->teacher, new \DateTimeImmutable('2025-05-20')));
            $this->em->persist(new Grade($enrollment, $this->math, $this->term1, $grade, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        }
        $this->em->flush();
        $this->client->loginUser($this->teacher);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function pageUrl(?GradingPeriod $period = null): string
    {
        return '/teacher/class/card-slips?period='.($period ?? $this->term1)->getId();
    }

    private static function codesName(Enrollment $enrollment): string
    {
        return 'card_slip_comments_form[entries]['.$enrollment->getId().'][codes][]';
    }

    /** @return array<string, mixed> the page's form values, as PHP arrays */
    private function formValues(Crawler $page): array
    {
        return $page->selectButton('Save comments')->form()->getPhpValues();
    }

    /** @param array<string, mixed> $values */
    private function postForm(Crawler $page, array $values): Crawler
    {
        return $this->client->request('POST', $page->selectButton('Save comments')->form()->getUri(), $values);
    }

    /** Opens a term; the entities are looked up again, as a request resets the entity manager. */
    private function openTerm(int $number): void
    {
        $school = $this->em->find(School::class, $this->school->getId());
        $this->openGrading($school, $this->em->find(SchoolYear::class, $this->sy->getId())->getGradingPeriod($number));
    }

    private function lockTerm1(): void
    {
        $this->openTerm(1);
        $this->createUser('rosa', User::ROLE_PRINCIPAL);
        // the recorder's entity manager, which is a new one once the client has made a request
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(GradeRecorder::class)->lockPeriod(
            $em->find(School::class, $this->school->getId()),
            $em->find(GradingPeriod::class, $this->term1->getId()),
            $em->getRepository(User::class)->findOneBy(['username' => 'rosa']),
        );
    }

    /** @return array<string, CardSlipComment> saved rows by learner last name */
    private function saved(): array
    {
        $this->em->clear();
        $rows = [];
        foreach ($this->em->getRepository(CardSlipComment::class)->findAll() as $comment) {
            $rows[$comment->getEnrollment()->getLearner()->getLastName()] = $comment;
        }

        return $rows;
    }

    public function testPageShowsSuggestionsTicked(): void
    {
        $crawler = $this->client->request('GET', $this->pageUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Card slips');
        $abad = $this->enrollments['Abad'];
        $box = $crawler->filter(\sprintf('input[type=checkbox][name="%s"][value="needs_improvement"]', self::codesName($abad)));
        self::assertCount(1, $box);
        self::assertSame('checked', $box->attr('checked'));
        $label = $crawler->filter('label[for="'.$box->attr('id').'"]');
        self::assertStringContainsString('Needs to improve in Mathematics.', $label->text());
        self::assertStringContainsString('suggested', $label->closest('.card-slip-choice')->text());
        // not suggested: neither ticked nor marked
        $other = $crawler->filter(\sprintf('input[type=checkbox][name="%s"][value="participates"]', self::codesName($abad)));
        self::assertNull($other->attr('checked'));
        self::assertStringNotContainsString('suggested', $other->closest('.card-slip-choice')->text());
        self::assertSelectorTextContains('.card-slips', '72.00');
        // My Class has the button
        $this->client->request('GET', '/teacher/class');
        self::assertSelectorExists('a[href="/teacher/class/card-slips"]');
    }

    public function testSaveComments(): void
    {
        $crawler = $this->client->request('GET', $this->pageUrl());
        $abad = $this->enrollments['Abad'];
        $values = $this->formValues($crawler);
        $entry = &$values['card_slip_comments_form']['entries'][$abad->getId()];
        self::assertSame(['needs_improvement', 'perfect_attendance', 'see_adviser'], array_values($entry['codes']));
        $entry['codes'] = ['needs_improvement', 'perfect_attendance', 'participates'];
        $entry['text'] = 'Active in class.';
        unset($entry);

        $this->postForm($crawler, $values);

        self::assertResponseRedirects($this->pageUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Comments saved for 2 learners.');
        $saved = $this->saved();
        self::assertSame(['needs_improvement', 'participates', 'perfect_attendance'], self::sorted($saved['Abad']->getCodes()));
        self::assertSame('Active in class.', $saved['Abad']->getCustomText());
        self::assertSame('tess', $saved['Abad']->getUpdatedBy()?->getUsername());
        // the untouched learner gets their suggestions saved as they were shown
        self::assertNull($saved['Bautista']->getCustomText());

        $section = $this->em->find(Section::class, $this->honest->getId());
        $period = $this->em->find(GradingPeriod::class, $this->term1->getId());
        $slip = static::getContainer()->get(CardSlipBuilder::class)->forEnrollment($section, $period, (int) $abad->getId());
        $html = static::getContainer()->get(CardSlipPdf::class)->html([$slip]);
        self::assertStringContainsString('Needs to improve in Mathematics. Please attend remedial sessions.', $html);
        self::assertStringContainsString(CardSlipComments::text('participates'), $html);
        self::assertStringContainsString(CardSlipComments::text('perfect_attendance'), $html);
        self::assertStringContainsString('Active in class.', $html);
        self::assertStringNotContainsString(CardSlipComments::text('see_adviser'), $html);
    }

    public function testMoreThanThreeRefused(): void
    {
        $crawler = $this->client->request('GET', $this->pageUrl());
        $abad = $this->enrollments['Abad'];
        $values = $this->formValues($crawler);
        $values['card_slip_comments_form']['entries'][$abad->getId()]['codes'] = ['needs_improvement', 'perfect_attendance', 'see_adviser', 'participates'];

        $this->postForm($crawler, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.card-slips', 'Choose at most 3 comments for ABAD, ANA, -.');
        self::assertSame([], $this->saved());
    }

    public function testTypedTooLong(): void
    {
        $crawler = $this->client->request('GET', $this->pageUrl());
        $values = $this->formValues($crawler);
        $values['card_slip_comments_form']['entries'][$this->enrollments['Abad']->getId()]['text'] = str_repeat('a', 301);

        $this->postForm($crawler, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.card-slips', 'Keep the comment under 300 characters.');
        self::assertSame([], $this->saved());
    }

    public function testReset(): void
    {
        $abad = $this->enrollments['Abad'];
        $comment = new CardSlipComment($abad, $this->term1);
        $comment->update(['participates'], 'Active in class.', $this->teacher, new \DateTimeImmutable('2025-08-21'));
        $this->em->persist($comment);
        $this->em->flush();
        $crawler = $this->client->request('GET', $this->pageUrl());
        self::assertSame(['participates'], array_values($this->formValues($crawler)['card_slip_comments_form']['entries'][$abad->getId()]['codes']));

        $url = \sprintf('/teacher/class/card-slips/%d/%d/reset', $this->term1->getId(), $abad->getId());
        // a bad token changes nothing
        $this->client->request('POST', $url, ['_token' => 'wrong']);
        self::assertCount(1, $this->saved());

        $this->client->request('POST', $url, ['_token' => $this->csrfToken('card-slip-reset-'.$abad->getId())]);

        self::assertResponseRedirects($this->pageUrl());
        self::assertSame([], $this->saved());
        $crawler = $this->client->followRedirect();
        self::assertSame(['needs_improvement', 'perfect_attendance', 'see_adviser'], array_values($this->formValues($crawler)['card_slip_comments_form']['entries'][$abad->getId()]['codes']));

        // a learner not on this class's sheet
        $this->client->request('POST', \sprintf('/teacher/class/card-slips/%d/%d/reset', $this->term1->getId(), 999999), ['_token' => $this->csrfToken('card-slip-reset-999999')]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDraftNotice(): void
    {
        $this->client->request('GET', $this->pageUrl());
        self::assertSelectorTextContains('.card-slips-notice', self::NOT_LOCKED);

        $this->lockTerm1();
        $this->client->loginUser($this->teacher);
        $crawler = $this->client->request('GET', $this->pageUrl());
        self::assertStringNotContainsString("isn't locked yet", $crawler->text());
    }

    public function testClassListChangedRefused(): void
    {
        $crawler = $this->client->request('GET', $this->pageUrl());
        $values = $this->formValues($crawler);
        $abad = $this->em->find(Enrollment::class, $this->enrollments['Abad']->getId());
        $abad->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('b.1 Illness')->setRemarkDate(new \DateTimeImmutable('2025-08-20'));
        $this->em->flush();

        $this->postForm($crawler, $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.card-slips', 'The class list changed while you were editing. Check the rows and save again.');
        self::assertSame([], $this->saved());
    }

    public function testPdfAll(): void
    {
        $this->client->request('GET', \sprintf('/teacher/class/card-slips/%d.pdf', $this->term1->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
        self::assertStringContainsString('filename="Card slips - Grade 8 HONEST - Term 1.pdf"', (string) $response->headers->get('Content-Disposition'));
    }

    public function testPdfOne(): void
    {
        $abad = $this->enrollments['Abad'];
        $this->client->request('GET', \sprintf('/teacher/class/card-slips/%d/%d.pdf', $this->term1->getId(), $abad->getId()));

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $response->getContent());
        self::assertStringContainsString('filename="Card slip - ABAD, ANA, - - Term 1.pdf"', (string) $response->headers->get('Content-Disposition'));

        $this->client->request('GET', \sprintf('/teacher/class/card-slips/%d/%d.pdf', $this->term1->getId(), 999999));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPeriodOfAnotherYearIs404(): void
    {
        $other = $this->schoolYear(2024, current: false);
        $other->rebuildGradingPeriods();
        $this->em->flush();

        $this->client->request('GET', $this->pageUrl($other->getGradingPeriod(1)));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', \sprintf('/teacher/class/card-slips/%d.pdf', $other->getGradingPeriod(1)->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDefaultPeriodIsLatestLocked(): void
    {
        // nothing locked or open: the last term
        $this->client->request('GET', '/teacher/class/card-slips');
        self::assertSelectorTextContains('.grade-tabs [aria-current=page]', 'Term 3');

        // open term 2 only: the open one
        $this->openTerm(2);
        $this->client->request('GET', '/teacher/class/card-slips');
        self::assertSelectorTextContains('.grade-tabs [aria-current=page]', 'Term 2');

        // term 1 locked: the latest locked one wins over the open one
        $this->lockTerm1();
        $this->client->loginUser($this->teacher);
        $this->client->request('GET', '/teacher/class/card-slips');
        self::assertSelectorTextContains('.grade-tabs [aria-current=page]', 'Term 1');
    }

    public function testNotAdviser403(): void
    {
        $other = $this->createUser('olga', User::ROLE_TEACHER, 'Olga Lim');
        $this->assign($this->school, $this->sy, $other, User::ROLE_TEACHER);
        $this->client->loginUser($other);

        $this->client->request('GET', '/teacher/class/card-slips');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', \sprintf('/teacher/class/card-slips/%d.pdf', $this->term1->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', \sprintf('/teacher/class/card-slips/%d/%d.pdf', $this->term1->getId(), $this->enrollments['Abad']->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testNoLearners(): void
    {
        foreach ($this->enrollments as $enrollment) {
            $this->em->find(Enrollment::class, $enrollment->getId())->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('b.1 Illness')->setRemarkDate(new \DateTimeImmutable('2025-08-20'));
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->pageUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card-slips', 'No learners to print slips for.');
        self::assertCount(0, $crawler->selectButton('Save comments'));

        $this->client->request('GET', \sprintf('/teacher/class/card-slips/%d.pdf', $this->term1->getId()));
        self::assertResponseRedirects($this->pageUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'No learners to print slips for.');
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private static function sorted(array $codes): array
    {
        sort($codes);

        return $codes;
    }
}
