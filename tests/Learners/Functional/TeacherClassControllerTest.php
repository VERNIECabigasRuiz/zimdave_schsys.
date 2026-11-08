<?php

declare(strict_types=1);

namespace App\Tests\Learners\Functional;

use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Learners\Domain\Model\Learner;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Access\Domain\Model\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\FileFormField;

class TeacherClassControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $teacher;
    private Section $sampaguita;
    private Section $rosal;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-28');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school(
            '303456',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->sampaguita = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Sampaguita');
        $this->sampaguita->setAdviser($this->teacher);
        $this->rosal = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rosal');
        $this->em->flush();
        $this->client->loginUser($this->teacher);
    }

    private function enrolled(string $lrn, string $last, string $first, ?Section $section = null): Learner
    {
        $learner = $this->learner($lrn, $last, $first);
        $this->enroll($learner, $this->sy, $section ?? $this->sampaguita, $this->teacher);

        return $learner;
    }

    private function findLearner(string $lrn): ?Learner
    {
        $this->em->clear();

        return $this->em->getRepository(Learner::class)->findOneBy(['lrn' => $lrn]);
    }

    /** The saved learner with this LRN; the test fails without one. */
    private function learnerOf(string $lrn): Learner
    {
        $learner = $this->findLearner($lrn);
        self::assertNotNull($learner, $lrn);

        return $learner;
    }

    private function enrollmentCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(Enrollment::class)->count([]);
    }

    /** @return array<string, string> */
    private function newLearnerFields(): array
    {
        return [
            'enroll_section_form[learner][lastName]' => 'Dela Cruz',
            'enroll_section_form[learner][firstName]' => 'Juan',
            'enroll_section_form[learner][middleName]' => 'Santos',
            'enroll_section_form[learner][extension]' => '',
            'enroll_section_form[learner][sex]' => 'male',
            'enroll_section_form[learner][birthDate]' => '2013-02-14',
        ];
    }

    public function testAdviserSeesOwnClassAndNav(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->enrolled('222222222222', 'Diaz', 'Dina', $this->rosal);

        $crawler = $this->client->request('GET', '/teacher/class');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Grade 7 – Sampaguita');
        self::assertSelectorTextContains('.table', 'Abad, Ana');
        self::assertStringNotContainsString('Diaz', $crawler->filter('.table')->text());
        self::assertSelectorTextContains('.sidebar__link.is-active', 'My class');
    }

    public function testAdviserEnrollsIntoOwnSection(): void
    {
        $this->client->request('GET', '/teacher/class/enroll');
        $this->client->submitForm('Next', ['lrn' => '123456789012'], 'GET');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select');

        $this->client->submitForm('Enroll', $this->newLearnerFields());

        self::assertResponseRedirects('/teacher/class');
        $crawler = $this->client->followRedirect();
        self::assertSame('123456789012', $crawler->filter('.credentials dd code')->first()->text());
        $learner = $this->learnerOf('123456789012');
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId()]);
        self::assertNotNull($enrollment);
        self::assertSame($this->sampaguita->getId(), $enrollment->getSection()->getId());
    }

    public function testAdviserCannotPostAnotherSection(): void
    {
        $this->client->request('GET', '/teacher/class/enroll?lrn=123456789012');
        $form = $this->client->getCrawler()->selectButton('Enroll')->form($this->newLearnerFields());
        $values = $form->getPhpValues();
        $values['enroll_section_form']['section'] = (string) $this->rosal->getId();
        $this->client->request('POST', '/teacher/class/enroll?lrn=123456789012', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findLearner('123456789012'));
        self::assertSame(0, $this->enrollmentCount());
    }

    public function testEnrolledElsewhereRefusedForAdviser(): void
    {
        $other = $this->school('303457', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->enrolled(
            '111111111111',
            'Abad',
            'Ana',
            $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini'),
        );

        $crawler = $this->client->request('GET', '/teacher/class/enroll?lrn=111111111111');
        self::assertSelectorTextContains(
            '.card',
            'Abad, Ana is enrolled at Other NHS. Ask your principal to request a transfer.',
        );
        self::assertCount(0, $crawler->selectButton('Request transfer'));
        self::assertCount(0, $crawler->selectButton('Enroll'));

        $this->school->setPlan($this->limitedPlan('Solo', 40, includesPrincipal: false));
        $this->em->flush();
        $this->client->request('GET', '/teacher/class/enroll?lrn=111111111111');
        self::assertSelectorTextContains(
            '.card',
            'Abad, Ana is enrolled at Other NHS. Ask the administrator to transfer them.',
        );
    }

    public function testTeacherWithoutAdvisoryIs403(): void
    {
        $this->sampaguita->setAdviser(null);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/teacher');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectLink('My class'));

        $this->client->request('GET', '/teacher/class');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdviserWhoIsNoLongerATeacherIsForbidden(): void
    {
        // Initialize
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->teacher->setRole(User::ROLE_PRINCIPAL);
        $this->em->flush();
        $this->client->loginUser($this->teacher);
        $pages = [
            '/teacher/class',
            '/teacher/class/enroll',
            '/teacher/class/' . $learner->getId(),
            '/teacher/class/' . $learner->getId() . '/edit',
            '/teacher/class/' . $learner->getId() . '/enrollment',
            '/teacher/class/' . $learner->getId() . '/id',
            '/teacher/class/sf1',
            '/teacher/class/ids',
            '/teacher/class/grades',
        ];

        // Execute
        $statuses = [];
        foreach ($pages as $page) {
            $this->client->request('GET', $page);
            $statuses[$page] = $this->client->getResponse()->getStatusCode();
        }

        // Assert
        self::assertSame(array_fill_keys($pages, 403), $statuses);
    }

    public function testFormerAdviserIsForbidden(): void
    {
        $crawler = $this->client->request('GET', '/teacher');
        self::assertCount(1, $crawler->selectLink('My class'));
        $this->client->request('GET', '/teacher/class');
        self::assertResponseIsSuccessful();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $section = $this->em->find(Section::class, $this->sampaguita->getId());
        self::assertNotNull($section);
        $section->setAdviser(null);
        $this->em->flush();

        $this->client->request('GET', '/teacher/class');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/teacher/class/enroll');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdviserCannotMoveOrChangeLrn(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $crawler = $this->client->request('GET', '/teacher/class/' . $learner->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Move'));
        self::assertCount(1, $crawler->selectButton('Unenroll'));

        $crawler = $this->client->request('GET', '/teacher/class/' . $learner->getId() . '/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="learner_form[lrn]"]'));

        $values = $crawler->selectButton('Save')->form()->getPhpValues();
        $values['learner_form']['lrn'] = '999999999999';
        $this->client->request('POST', '/teacher/class/' . $learner->getId() . '/edit', $values);
        self::assertSame('111111111111', $this->learnerOf('111111111111')->getLrn());
        self::assertNull($this->findLearner('999999999999'));

        $url = '/schools/' . $this->school->getId() . '/learners/' . $learner->getId() . '/move';
        $this->client->request('POST', $url, [
            'section' => $this->rosal->getId(),
            '_token' => $this->csrfToken('learner-' . $learner->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId()]);
        self::assertNotNull($enrollment);
        self::assertSame($this->sampaguita->getId(), $enrollment->getSection()->getId());
    }

    public function testAdviserSavesSf1Fields(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $id = $learner->getId();

        $this->client->request('GET', '/teacher/class/' . $id . '/edit');
        $this->client->submitForm(
            'Save',
            ['learner_form[motherTongue]' => 'Cebuano', 'learner_form[guardianName]' => 'Nena Abad'],
        );

        self::assertResponseRedirects('/teacher/class/' . $id);
        $saved = $this->learnerOf('111111111111');
        self::assertSame('Cebuano', $saved->getMotherTongue());
        self::assertSame('Nena Abad', $saved->getGuardianName());
    }

    public function testAdviserSetsRemark(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $id = $learner->getId();

        $this->client->request('GET', '/teacher/class/' . $id . '/enrollment');
        $this->client->submitForm(
            'Save',
            ['enrollment_record_form[remarkCode]' => 'cct', 'enrollment_record_form[remarkDetails]' => 'REF-123'],
        );

        self::assertResponseRedirects('/teacher/class/' . $id);
        $this->em->clear();
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $id]);
        self::assertNotNull($enrollment);
        self::assertSame('CCT REF-123', $enrollment->getManualRemarkText());
    }

    public function testAdviserDownloadsSf1(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');

        $crawler = $this->client->request('GET', '/teacher/class');
        $this->client->click($crawler->selectLink('Download SF1')->link());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.ms-excel');
        self::assertStringContainsString(
            'filename="SF1_2026_Grade 7 - SAMPAGUITA.xls"',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testTeacherWithoutAdvisoryCannotDownloadSf1(): void
    {
        $this->sampaguita->setAdviser(null);
        $this->em->flush();

        $this->client->request('GET', '/teacher/class/sf1');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdviserUploadsIdPhoto(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $path = sys_get_temp_dir() . '/zim_id_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg(imagecreatetruecolor(400, 400), $path);

        $crawler = $this->client->request('GET', '/teacher/class/' . $learner->getId());
        $form = $crawler->selectButton('Upload photo')->form();
        $field = $form['image_upload_form[image]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
        $this->client->submit($form);

        self::assertResponseRedirects('/teacher/class/' . $learner->getId());
        $photo = $this->learnerOf('111111111111')->getUser()->getPhotoPath();
        self::assertNotNull($photo);
        unlink(static::getContainer()->getParameter('app.upload_root') . '/' . $photo);
    }

    public function testAdviserRemovesIdPhoto(): void
    {
        // Initialize
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $show = '/teacher/class/' . $learner->getId();
        $path = sys_get_temp_dir() . '/zim_id_' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg(imagecreatetruecolor(400, 400), $path);
        $form = $this->client->request('GET', $show)->selectButton('Upload photo')->form();
        $field = $form['image_upload_form[image]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($path);
        $this->client->submit($form);
        $photo = $this->learnerOf('111111111111')->getUser()->getPhotoPath();
        self::assertNotNull($photo);
        $this->client->request('GET', $show);

        // Execute
        $this->client->submitForm('Remove photo');

        // Assert
        self::assertResponseRedirects($show);
        $this->em->clear();
        self::assertNull($this->learnerOf('111111111111')->getUser()->getPhotoPath());
        self::assertFileDoesNotExist(static::getContainer()->getParameter('app.upload_root') . '/' . $photo);
    }

    public function testAdviserPrintsClassIds(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');

        $crawler = $this->client->request('GET', '/teacher/class');
        $this->client->click($crawler->selectLink('Paper sheets')->link());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString(
            'filename="IDs_2026_Grade 7 - SAMPAGUITA.pdf"',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testTeacherWithoutAdvisoryCannotPrintIds(): void
    {
        $this->sampaguita->setAdviser(null);
        $this->em->flush();

        $this->client->request('GET', '/teacher/class/ids');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherWithoutAdvisoryCannotEdit(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->sampaguita->setAdviser(null);
        $this->em->flush();

        $this->client->request('GET', '/teacher/class/' . $learner->getId() . '/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdviserEditsUnenrollsAndResets(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $id = $learner->getId();

        $this->client->request('GET', '/teacher/class/' . $id . '/edit');
        $this->client->submitForm('Save', ['learner_form[firstName]' => 'Anna']);
        self::assertResponseRedirects('/teacher/class/' . $id);

        $this->client->request('GET', '/teacher/class/' . $id);
        $this->client->submitForm('Reset password');
        self::assertResponseRedirects('/teacher/class/' . $id);
        $this->client->followRedirect();
        self::assertSelectorExists('.credentials');

        $this->client->submitForm('Unenroll');
        self::assertResponseRedirects('/teacher/class');
        self::assertSame(0, $this->enrollmentCount());
    }

    public function testAdviserCannotOpenLearnerOfAnotherSection(): void
    {
        $own = $this->enrolled('111111111111', 'Abad', 'Ana');
        $other = $this->enrolled('222222222222', 'Diaz', 'Dina', $this->rosal);
        $otherId = $other->getId();
        $hash = $other->getUser()->getPassword();
        $logsBefore = \count($this->activityActions());

        $this->client->request('GET', '/teacher/class/' . $own->getId());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/teacher/class/' . $otherId);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/teacher/class/' . $otherId . '/edit');
        self::assertResponseStatusCodeSame(404);

        $token = $this->csrfToken('learner-' . $otherId);
        $this->client->request('POST', '/teacher/class/' . $otherId . '/unenroll', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $token = $this->csrfToken('learner-' . $otherId);
        $this->client->request('POST', '/teacher/class/' . $otherId . '/reset-password', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);

        $this->em->clear();
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $otherId]);
        self::assertNotNull($enrollment);
        self::assertSame($this->rosal->getId(), $enrollment->getSection()->getId());
        $other = $this->em->getRepository(Learner::class)->find($otherId);
        self::assertNotNull($other);
        self::assertSame($hash, $other->getUser()->getPassword());
        self::assertCount($logsBefore, $this->activityActions());
    }
}
