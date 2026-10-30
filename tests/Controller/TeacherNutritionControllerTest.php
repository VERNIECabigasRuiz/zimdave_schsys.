<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\NutritionMeasurement;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf8Submission;
use App\Entity\User;
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
            $this->em->persist(new Enrollment($this->learner('12001717000'.$i, $last, 'Ana'), $this->sy, $this->artistic, $this->teacher, new \DateTimeImmutable('2025-05-20')));
        }
        $this->em->flush();
        $this->client->loginUser($this->teacher);
    }

    /** @param array<int, array{0: ?string, 1: ?string}> $measures weight and height by row */
    private function sheet(array $measures, string $date = '2025-08-20'): Crawler
    {
        $crawler = $this->client->request('GET', '/teacher/class/nutrition/baseline');
        $values = ['nutrition_sheet_form[weighedOn]' => $date];
        foreach ($measures as $i => [$weight, $height]) {
            $values["nutrition_sheet_form[entries][$i][weightKg]"] = $weight ?? '';
            $values["nutrition_sheet_form[entries][$i][heightM]"] = $height ?? '';
        }

        return $this->client->submit($crawler->selectButton('Save weights')->form($values));
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
        self::assertSame('40.00', $crawler->filter('input[name="nutrition_sheet_form[entries][0][weightKg]"]')->attr('value'));

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
        $stale = $crawler->selectButton('Save weights')->form(['nutrition_sheet_form[entries][0][weightKg]' => '55']);
        $this->client->submit($crawler->selectButton('Submit Baseline')->form());
        self::assertSame(1, $this->em->getRepository(Sf8Submission::class)->count([]));

        $this->client->submit($stale);

        self::assertSelectorTextContains('main', 'Baseline is submitted and locked.');
        $weights = array_map(static fn (NutritionMeasurement $m) => $m->getWeightKg(), $this->measurements());
        sort($weights);
        self::assertSame(['39.00', '40.00', '42.50'], $weights);
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
