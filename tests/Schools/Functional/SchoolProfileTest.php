<?php

namespace App\Tests\Schools\Functional;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Access\Domain\Model\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\DomCrawler\Field\FileFormField;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolProfileTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school(
            '303456',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->client->loginUser($this->maria);
    }

    private function reloadSchool(): School
    {
        $this->em->clear();

        return $this->em->getRepository(School::class)->find($this->school->getId())
            ?? self::fail('The school is gone.');
    }

    private function pngFile(): string
    {
        $img = imagecreatetruecolor(300, 300);
        $path = sys_get_temp_dir() . '/zim_logo_' . bin2hex(random_bytes(4)) . '.png';
        imagepng($img, $path);

        return $path;
    }

    public function testShowsLockedAndEditableFields(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);

        $crawler = $this->client->request('GET', '/principal/school');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '303456');
        self::assertSelectorTextContains('main', 'Pagsabungan NHS');
        self::assertCount(0, $crawler->filter('input[name="school_profile_form[name]"]'));
        self::assertCount(0, $crawler->filter('input[name="school_profile_form[schoolId]"]'));
        self::assertCount(1, $crawler->filter('input[name="school_profile_form[address]"]'));
        self::assertSelectorTextContains('.sidebar__link.is-active', 'School Profile');
    }

    public function testLogoInputShowsPreviewBeforeSaving(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);

        $crawler = $this->client->request('GET', '/principal/school');

        $logoInput = 'form[data-controller~="image-preview"] input#school_profile_form_logo'
            . '[data-action~="image-preview#show"]';
        self::assertCount(1, $crawler->filter($logoInput));
        self::assertCount(1, $crawler->filter('[data-image-preview-target="image"]'));
        self::assertStringContainsString(
            'Click "Save school profile"',
            $crawler->filter('[data-image-preview-target="hint"]')->text(),
        );
    }

    public function testSavesEditableFields(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $this->client->request('GET', '/principal/school');

        $this->client->submitForm('Save school profile', [
            'school_profile_form[address]' => 'Pagsabungan, Mandaue City',
            'school_profile_form[contactNumber]' => '032-123-4567',
            'school_profile_form[email]' => 'pnhs@deped.gov.ph',
            'school_profile_form[facebookUrl]' => 'https://facebook.com/pnhs',
        ]);

        self::assertResponseRedirects('/principal/school');
        $school = $this->reloadSchool();
        self::assertSame('Pagsabungan, Mandaue City', $school->getAddress());
        self::assertSame('032-123-4567', $school->getContactNumber());
        self::assertSame('pnhs@deped.gov.ph', $school->getEmail());
        self::assertSame('https://facebook.com/pnhs', $school->getFacebookUrl());
        self::assertContains('Updated school profile Pagsabungan NHS', $this->activityActions());
    }

    public function testSavesLessonPlanChecker(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $this->client->request('GET', '/principal/school');

        $this->client->submitForm('Save school profile', [
            'school_profile_form[lpCheckedByName]' => 'JULIE ANN I. NONOL',
            'school_profile_form[lpCheckedByPosition]' => 'Master Teacher I',
        ]);

        self::assertResponseRedirects('/principal/school');
        $school = $this->reloadSchool();
        self::assertSame('JULIE ANN I. NONOL', $school->getLpCheckedByName());
        self::assertSame('Master Teacher I', $school->getLpCheckedByPosition());
    }

    public function testSavesServiceRecordSignatories(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $this->client->request('GET', '/principal/school');

        $this->client->submitForm('Save school profile', [
            'school_profile_form[srPreparedByName]' => 'JANET T. BIHAG',
            'school_profile_form[srPreparedByDesignation]' => 'Teacher III',
            'school_profile_form[srCertifiedByName]' => 'EDGAR C. ESPINA JR., DevEdD',
            'school_profile_form[srCertifiedByDesignation]' => 'Administrative Officer V',
        ]);

        self::assertResponseRedirects('/principal/school');
        $school = $this->reloadSchool();
        self::assertSame('JANET T. BIHAG', $school->getSrPreparedByName());
        self::assertSame('Teacher III', $school->getSrPreparedByDesignation());
        self::assertSame('EDGAR C. ESPINA JR., DevEdD', $school->getSrCertifiedByName());
        self::assertSame('Administrative Officer V', $school->getSrCertifiedByDesignation());

        $this->client->request('GET', '/principal/school');
        $this->client->submitForm('Save school profile', [
            'school_profile_form[srPreparedByName]' => '',
            'school_profile_form[srPreparedByDesignation]' => '',
            'school_profile_form[srCertifiedByName]' => ' ',
            'school_profile_form[srCertifiedByDesignation]' => '',
        ]);
        $school = $this->reloadSchool();
        self::assertNull($school->getSrPreparedByName());
        self::assertNull($school->getSrCertifiedByName());
    }

    public function testTamperedLockedFieldsAreNotSaved(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $crawler = $this->client->request('GET', '/principal/school');
        $values = $crawler->selectButton('Save school profile')->form()->getPhpValues();
        $values['school_profile_form']['name'] = 'Hacked School';
        $values['school_profile_form']['schoolId'] = '999999';

        $this->client->request('POST', '/principal/school', $values);

        $school = $this->reloadSchool();
        self::assertSame('Pagsabungan NHS', $school->getName());
        self::assertSame('303456', $school->getSchoolId());
    }

    public function testSavesEvenWhenAdminOnlyFieldsAreInvalid(): void
    {
        $this->em->getConnection()->executeStatement(
            "UPDATE school SET region = '' WHERE id = ?",
            [$this->school->getId()],
        );
        $this->assign($this->school, $this->sy, $this->maria);
        $this->client->request('GET', '/principal/school');

        $this->client->submitForm(
            'Save school profile',
            ['school_profile_form[address]' => 'Pagsabungan, Mandaue City'],
        );

        self::assertResponseRedirects('/principal/school');
        self::assertSame('Pagsabungan, Mandaue City', $this->reloadSchool()->getAddress());
    }

    public function testFormLevelErrorIsShown(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $crawler = $this->client->request('GET', '/principal/school');
        $values = $crawler->selectButton('Save school profile')->form()->getPhpValues();
        $values['school_profile_form']['name'] = 'Hacked School';

        $this->client->request('POST', '/principal/school', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This form should not contain extra fields.');
    }

    public function testLogoUpload(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $crawler = $this->client->request('GET', '/principal/school');
        $form = $crawler->selectButton('Save school profile')->form();
        $field = $form['school_profile_form[logo]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($this->pngFile());

        $this->client->submit($form);

        self::assertResponseRedirects('/principal/school');
        $logo = $this->reloadSchool()->getLogoPath();
        self::assertMatchesRegularExpression('#^uploads/logos/[0-9a-f]{32}\.webp$#', (string) $logo);
        $absolute = static::getContainer()->getParameter('app.upload_root') . '/' . $logo;
        self::assertFileExists($absolute);
        unlink($absolute);
    }

    public function testUnreadableLogoShowsTheErrorUnderTheLogo(): void
    {
        // Initialize
        $this->assign($this->school, $this->sy, $this->maria);
        $corrupt = sys_get_temp_dir() . '/zim_logo_' . bin2hex(random_bytes(4)) . '.png';
        ob_start();
        imagepng(imagecreatetruecolor(40, 40));
        // Only the signature and header (what the form checks) are kept; with no image data GD can't decode it
        file_put_contents($corrupt, substr((string) ob_get_clean(), 0, 33));
        $crawler = $this->client->request('GET', '/principal/school');
        $form = $crawler->selectButton('Save school profile')->form(['school_profile_form[address]' => 'Centro']);
        $field = $form['school_profile_form[logo]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($corrupt);

        // Execute
        $this->client->submit($form);

        // Assert
        self::assertResponseStatusCodeSame(422);
        $logoRow = $this->client->getCrawler()->filter('#school_profile_form_logo')->closest('div');
        self::assertNotNull($logoRow);
        self::assertCount(1, $logoRow->filter('li'));
        self::assertNull($this->reloadSchool()->getAddress());
        unlink($corrupt);
    }

    public function testPrincipalUploadsIdSignature(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $crawler = $this->client->request('GET', '/principal/school');
        $form = $crawler->selectButton('Save school profile')->form();
        $field = $form['school_profile_form[idSignature]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($this->pngFile());

        $this->client->submit($form);

        self::assertResponseRedirects('/principal/school');
        $school = $this->reloadSchool();
        $signature = $school->getIdSignaturePath();
        self::assertMatchesRegularExpression('#^uploads/signatures/[0-9a-f]{32}\.webp$#', (string) $signature);
        self::assertSame($this->maria->getId(), $school->getIdSignatureBy()?->getId());
        $absolute = static::getContainer()->getParameter('app.upload_root') . '/' . $signature;
        self::assertFileExists($absolute);
        unlink($absolute);
    }

    public function testIdSignatureShowsToThePrincipalWhoUploadedIt(): void
    {
        // Initialize
        $this->assign($this->school, $this->sy, $this->maria);
        $this->school->setIdSignaturePath('uploads/signatures/maria.webp')->setIdSignatureBy($this->maria);
        $this->em->flush();

        // Execute
        $crawler = $this->client->request('GET', '/principal/school');

        // Assert
        self::assertCount(1, $crawler->filter('img[alt="Current ID signature"]'));
    }

    public function testIdSignatureOfAnEarlierPrincipalIsNotShown(): void
    {
        // Initialize
        $rosa = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $rosa);
        $this->school->setIdSignaturePath('uploads/signatures/maria.webp')->setIdSignatureBy($this->maria);
        $this->em->flush();
        $this->client->loginUser($rosa);

        // Execute
        $crawler = $this->client->request('GET', '/principal/school');

        // Assert
        self::assertCount(0, $crawler->filter('img[alt="Current ID signature"]'));
    }

    public function testInvalidEmailShowsError(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
        $this->client->request('GET', '/principal/school');

        $this->client->submitForm('Save school profile', ['school_profile_form[email]' => 'not-an-email']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error, .invalid-feedback, ul li');
        self::assertNull($this->reloadSchool()->getEmail());
    }

    public function testNotAssignedPrincipalSeesNotAssignedPage(): void
    {
        $this->client->request('GET', '/principal/school');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'You are not assigned to a school for S.Y. 2026-2027.');
    }

    public function testTeacherForbidden(): void
    {
        $this->client->loginUser($this->createUser('ana', User::ROLE_TEACHER));

        $this->client->request('GET', '/principal/school');

        self::assertResponseStatusCodeSame(403);
    }
}
