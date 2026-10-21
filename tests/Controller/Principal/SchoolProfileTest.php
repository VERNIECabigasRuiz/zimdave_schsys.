<?php

namespace App\Tests\Controller\Principal;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
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
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->client->loginUser($this->maria);
    }

    private function reloadSchool(): School
    {
        $this->em->clear();

        return $this->em->getRepository(School::class)->find($this->school->getId());
    }

    private function pngFile(): string
    {
        $img = imagecreatetruecolor(300, 300);
        $path = sys_get_temp_dir().'/zim_logo_'.bin2hex(random_bytes(4)).'.png';
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

        self::assertCount(1, $crawler->filter('form[data-controller~="image-preview"] input#school_profile_form_logo[data-action~="image-preview#show"]'));
        self::assertCount(1, $crawler->filter('[data-image-preview-target="image"]'));
        self::assertStringContainsString('Click "Save school profile"', $crawler->filter('[data-image-preview-target="hint"]')->text());
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
        $this->em->getConnection()->executeStatement("UPDATE school SET region = '' WHERE id = ?", [$this->school->getId()]);
        $this->assign($this->school, $this->sy, $this->maria);
        $this->client->request('GET', '/principal/school');

        $this->client->submitForm('Save school profile', ['school_profile_form[address]' => 'Pagsabungan, Mandaue City']);

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
        $form['school_profile_form[logo]']->upload($this->pngFile());

        $this->client->submit($form);

        self::assertResponseRedirects('/principal/school');
        $logo = $this->reloadSchool()->getLogoPath();
        self::assertMatchesRegularExpression('#^uploads/logos/[0-9a-f]{32}\.webp$#', $logo);
        $absolute = static::getContainer()->getParameter('app.upload_root').'/'.$logo;
        self::assertFileExists($absolute);
        unlink($absolute);
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
