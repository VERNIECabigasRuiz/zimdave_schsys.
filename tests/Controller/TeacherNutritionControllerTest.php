<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\NutritionMeasurement;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf1Remark;
use App\Entity\Sf8Submission;
use App\Entity\User;
use App\Form\NutritionSheetFormType;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

class TeacherNutritionControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $teacher;
    private Section $artistic;
    /** @var array<string, Enrollment> by last name */
    private array $enrollments = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->teacher);
        foreach (['Abad', 'Bautista', 'Cruz'] as $i => $last) {
            $this->em->persist($this->enrollments[$last] = new Enrollment($this->learner('12001717000'.$i, $last, 'Ana'), $this->sy, $this->artistic, $this->teacher, new \DateTimeImmutable('2025-05-20')));
        }
        $this->em->flush();
        $this->client->loginUser($this->teacher);
    }

    /** @param array<int, array{0: ?string, 1: ?string}> $measures weight and height by row */
    private function sheet(array $measures, string $date = '2025-08-20'): Crawler
    {
        $crawler = $this->client->request('GET', '/teacher/class/nutrition/baseline');

        return $this->client->submit($crawler->selectButton('Save weights')->form($this->values($crawler, $measures, $date)));
    }

    /**
     * The sheet's form values for weight and height by row, as the page names its inputs.
     *
     * @param array<int, array{0: ?string, 1: ?string}> $measures
     *
     * @return array<string, string>
     */
    private function values(Crawler $page, array $measures, string $date = '2025-08-20'): array
    {
        $keys = self::rowKeys($page);
        $values = ['nutrition_sheet_form[weighedOn]' => $date];
        foreach ($measures as $i => [$weight, $height]) {
            $values["nutrition_sheet_form[entries][$keys[$i]][weightKg]"] = $weight ?? '';
            $values["nutrition_sheet_form[entries][$keys[$i]][heightM]"] = $height ?? '';
        }

        return $values;
    }

    /** @return list<string> the sheet's entry keys, row by row */
    private static function rowKeys(Crawler $page): array
    {
        return $page->filter('input[name^="nutrition_sheet_form[entries]"][name$="[weightKg]"]')
            ->each(static fn (Crawler $input) => (string) preg_replace('/^nutrition_sheet_form\[entries\]\[([^\]]+)\]\[weightKg\]$/', '$1', (string) $input->attr('name')));
    }

    private static function weightName(Enrollment $enrollment): string
    {
        return 'nutrition_sheet_form[entries]['.$enrollment->getId().'][weightKg]';
    }

    private static function weightInput(Enrollment $enrollment): string
    {
        return 'input[name="'.self::weightName($enrollment).'"]';
    }

    /** @return list<NutritionMeasurement> */
    private function measurements(): array
    {
        $this->em->clear();

        return $this->em->getRepository(NutritionMeasurement::class)->findAll();
    }

    public function testIndexShowsBothPeriods(): void
    {
        $crawler = $this->client->request('GET', '/teacher/class/nutrition');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Baseline');
        self::assertSelectorTextContains('main', 'Endline');
        self::assertSelectorTextContains('main', 'Not started');
        self::assertSelectorTextContains('main', '0 of 3 weighed');
        self::assertCount(1, $crawler->filter('a[href="/teacher/class/nutrition/baseline"]'));
        self::assertCount(1, $crawler->filter('a[href="/teacher/class/nutrition/endline"]'));
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Nutrition');
    }

    public function testClassListLinksToNutrition(): void
    {
        $crawler = $this->client->request('GET', '/teacher/class');

        self::assertCount(1, $crawler->filter('a[href="/teacher/class/nutrition"]:contains("Nutrition · SF8")'));
    }

    public function testSaveSheet(): void
    {
        $this->sheet([0 => ['40', '1.50'], 1 => ['42.5', '1.55']]);

        self::assertResponseRedirects('/teacher/class/nutrition/baseline');
        self::assertCount(2, $this->measurements());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Saved Baseline weights for 2 learners.');
        self::assertSelectorTextContains('.nutrition-sheet', 'Normal');
        self::assertSame('2025-08-20', $crawler->filter('input[name="nutrition_sheet_form[weighedOn]"]')->attr('value'));
        self::assertSame('40.00', $crawler->filter(self::weightInput($this->enrollments['Abad']))->attr('value'));

        $this->client->request('GET', '/teacher/class/nutrition');
        self::assertSelectorTextContains('main', 'Draft');
        self::assertSelectorTextContains('main', '2 of 3 weighed');
    }

    public function testValidationMessages(): void
    {
        $this->sheet([0 => ['40', '152']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.nutrition-sheet', 'Enter height in metres, e.g. 1.32.');

        $this->sheet([0 => ['40', null]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.nutrition-sheet', 'Enter both weight and height, or neither.');

        // weight typed in grams
        $this->sheet([0 => ['40000', '1.50']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.nutrition-sheet', 'Enter weight in kilograms, between 5 and 200.');

        self::assertSame([], $this->measurements());
    }

    public function testFutureDateRefused(): void
    {
        $this->sheet([0 => ['40', '1.50']], '2025-08-25');

        self::assertSelectorTextContains('main', "The date of weighing can't be in the future.");
        self::assertSame([], $this->measurements());
    }

    public function testSubmitWithConfirm(): void
    {
        $this->sheet([0 => ['40', '1.50'], 1 => ['42.5', '1.55']]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('form[name="sf8_submit_form"]', '1 learner has no weight and height.');
        self::assertSelectorTextContains('.sf2-submit', 'Save your changes before submitting.');

        $this->client->submit($crawler->selectButton('Submit Baseline')->form());
        self::assertResponseRedirects('/teacher/class/nutrition/baseline');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', '1 learner has no weight and height.');
        self::assertSame(0, $this->em->getRepository(Sf8Submission::class)->count([]));

        $crawler = $this->client->getCrawler();
        $this->client->submit($crawler->selectButton('Submit Baseline')->form(['sf8_submit_form[confirm_unweighed]' => true]));
        self::assertResponseRedirects('/teacher/class/nutrition/baseline');
        self::assertSame(1, $this->em->getRepository(Sf8Submission::class)->count([]));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Baseline submitted to the principal.');
        self::assertSelectorTextContains('main', 'Submitted Aug 22, 2025 by Tess Reyes · locked');
        self::assertCount(0, $crawler->filter('input[name^="nutrition_sheet_form"]'));
        self::assertSelectorNotExists('form[name="sf8_submit_form"]');
        self::assertSelectorTextContains('.nutrition-sheet', '40.00');
    }

    public function testLockedPostRefused(): void
    {
        $this->sheet([0 => ['40', '1.50'], 1 => ['42.5', '1.55'], 2 => ['39', '1.48']]);
        $crawler = $this->client->followRedirect();
        $stale = $crawler->selectButton('Save weights')->form([self::weightName($this->enrollments['Abad']) => '55']);
        $this->client->submit($crawler->selectButton('Submit Baseline')->form());
        self::assertSame(1, $this->em->getRepository(Sf8Submission::class)->count([]));

        $this->client->submit($stale);

        self::assertSelectorTextContains('main', 'Baseline is submitted and locked.');
        $weights = array_map(static fn (NutritionMeasurement $m) => $m->getWeightKg(), $this->measurements());
        sort($weights);
        self::assertSame(['39.00', '40.00', '42.50'], $weights);
    }

    public function testStaleSheetAfterALearnerLeftSavesNothing(): void
    {
        $crawler = $this->client->request('GET', '/teacher/class/nutrition/baseline');
        $stale = $crawler->selectButton('Save weights')->form($this->values($crawler, [0 => ['40', '1.50'], 1 => ['42.5', '1.55'], 2 => ['39', '1.48']]));
        // Abad drops out while the adviser is typing
        $this->em->clear();
        $this->em->find(Enrollment::class, $this->enrollments['Abad']->getId())->setRemarkCode(Sf1Remark::Dropped)->setRemarkDate(new \DateTimeImmutable('2025-07-01'));
        $this->em->flush();

        $crawler = $this->client->submit($stale);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.nutrition-sheet', NutritionSheetFormType::ROSTER_CHANGED);
        self::assertSame([], $this->measurements());
        // each row left keeps its own learner's typed values
        self::assertStringNotContainsString('Abad', $crawler->filter('.nutrition-table')->text());
        self::assertCount(0, $crawler->filter(self::weightInput($this->enrollments['Abad'])));
        self::assertSame('42.50', $crawler->filter(self::weightInput($this->enrollments['Bautista']))->attr('value'));
        self::assertSame('39.00', $crawler->filter(self::weightInput($this->enrollments['Cruz']))->attr('value'));
    }

    public function testStaleSheetAfterALearnerJoinedSavesNothing(): void
    {
        $crawler = $this->client->request('GET', '/teacher/class/nutrition/baseline');
        $stale = $crawler->selectButton('Save weights')->form($this->values($crawler, [0 => ['40', '1.50'], 1 => ['42.5', '1.55'], 2 => ['39', '1.48']]));
        // Dizon is enrolled while the adviser is typing
        $this->em->clear();
        $dizon = new Enrollment($this->learner('120017170009', 'Dizon', 'Ana'), $this->em->find(SchoolYear::class, $this->sy->getId()), $this->em->find(Section::class, $this->artistic->getId()), $this->em->find(User::class, $this->teacher->getId()), new \DateTimeImmutable('2025-05-20'));
        $this->em->persist($dizon);
        $this->em->flush();

        $crawler = $this->client->submit($stale);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.nutrition-sheet', NutritionSheetFormType::ROSTER_CHANGED);
        self::assertSame([], $this->measurements());
        self::assertSame('40.00', $crawler->filter(self::weightInput($this->enrollments['Abad']))->attr('value'));
        self::assertSame('42.50', $crawler->filter(self::weightInput($this->enrollments['Bautista']))->attr('value'));
        self::assertSame('39.00', $crawler->filter(self::weightInput($this->enrollments['Cruz']))->attr('value'));
        self::assertSame('', (string) $crawler->filter(self::weightInput($dizon))->attr('value'));

        // saved again from the refreshed rows, every learner's values land on that learner
        $this->client->submit($crawler->selectButton('Save weights')->form());
        self::assertResponseRedirects('/teacher/class/nutrition/baseline');
        $weights = [];
        foreach ($this->measurements() as $m) {
            $weights[$m->getEnrollment()->getLearner()->getLastName()] = $m->getWeightKg();
        }
        ksort($weights);
        self::assertSame(['Abad' => '40.00', 'Bautista' => '42.50', 'Cruz' => '39.00'], $weights);
    }

    public function testStudentIs403(): void
    {
        $this->client->loginUser($this->createUser('sam', User::ROLE_STUDENT));

        $this->client->request('GET', '/teacher/class/nutrition');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/teacher/class/nutrition/baseline');
        self::assertResponseStatusCodeSame(403);
    }

    public function testSf8Download(): void
    {
        $this->client->request('GET', '/teacher/class/nutrition/baseline/sf8');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('SF8_303196_Grade 8-ARTISTIC_Baseline_2025-2026.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testUnknownPeriodIs404(): void
    {
        $this->client->request('GET', '/teacher/class/nutrition/midline');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdviserIs403(): void
    {
        $this->client->loginUser($other = $this->createUser('omar', User::ROLE_TEACHER));
        $this->assign($this->school, $this->sy, $other, User::ROLE_TEACHER);

        $this->client->request('GET', '/teacher/class/nutrition');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/teacher/class/nutrition/baseline', ['nutrition_sheet_form' => []]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/teacher/class/nutrition/baseline/sf8');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->measurements());
    }

    public function testPrincipalCannotFill(): void
    {
        $this->client->loginUser($this->createUser('rene', User::ROLE_PRINCIPAL));

        $this->client->request('GET', '/teacher/class/nutrition');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/teacher/class/nutrition/baseline/submit');
        self::assertResponseStatusCodeSame(403);
    }
}
