<?php

namespace App\Tests\Controller;

use App\Entity\CivilStatus;
use App\Entity\PersonalDataSheet;
use App\Entity\SexAtBirth;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class ProfileControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->client->loginUser($this->maria);
    }

    private function sheet(): ?PersonalDataSheet
    {
        $this->em->clear();

        return $this->em->getRepository(PersonalDataSheet::class)->findOneBy(['user' => $this->maria->getId()]);
    }

    /** @return array<string, string> */
    private function requiredPersonal(array $extra = []): array
    {
        return $extra + [
            'personal_info_form[surname]' => 'Santos',
            'personal_info_form[firstName]' => 'Maria',
            'personal_info_form[middleName]' => 'Cruz',
            'personal_info_form[dateOfBirth]' => '1985-03-14',
            'personal_info_form[sexAtBirth]' => 'female',
            'personal_info_form[civilStatus]' => 'married',
            'personal_info_form[citizenship]' => 'filipino',
        ];
    }

    public function testShowCreatesSheetLazilyAndShowsTabs(): void
    {
        $crawler = $this->client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->sheet());
        $tabs = $crawler->filter('.tabs__tab')->each(static fn ($a) => $a->text());
        self::assertContains('Personal Information', $tabs);
        self::assertContains('Family Background', $tabs);
        self::assertSelectorTextContains('.sidebar__link.is-active', 'My Profile');
    }

    public function testChoicesRenderAsChipsAndCheckboxBesideLabel(): void
    {
        $crawler = $this->client->request('GET', '/profile/personal');

        foreach (['sexAtBirth', 'civilStatus', 'citizenship', 'dualCitizenshipBy'] as $field) {
            self::assertCount(1, $crawler->filter("#personal_info_form_$field.choice-chips"), "$field should render as chips");
        }
        self::assertCount(1, $crawler->filter('.checkbox-row > #personal_info_form_permSameAsResidential + label'));
    }

    public function testSavePersonalInfoPersistsAndRenamesUser(): void
    {
        $this->client->request('GET', '/profile/personal');

        $this->client->submitForm('Save personal information', $this->requiredPersonal([
            'personal_info_form[bloodType]' => 'O+',
            'personal_info_form[mobileNo]' => '09171234567',
            'personal_info_form[philsysPcn]' => '1234-5678-9012-3456',
            'personal_info_form[height]' => '1.60',
        ]));

        self::assertResponseRedirects('/profile/personal');
        $sheet = $this->sheet();
        self::assertSame('Santos', $sheet->surname);
        self::assertSame(SexAtBirth::Female, $sheet->sexAtBirth);
        self::assertSame(CivilStatus::Married, $sheet->civilStatus);
        self::assertSame('1985-03-14', $sheet->dateOfBirth->format('Y-m-d'));
        self::assertSame('O+', $sheet->bloodType);
        self::assertSame('1234-5678-9012-3456', $sheet->philsysPcn);
        self::assertSame('Maria C. Santos', $this->findUser('maria')->getFullName());
        self::assertContains('Updated own profile', $this->activityActions());
    }

    public function testSameAsResidentialResyncsOnEverySave(): void
    {
        $this->client->request('GET', '/profile/personal');
        $this->client->submitForm('Save personal information', $this->requiredPersonal([
            'personal_info_form[resCity]' => 'Mandaue City',
            'personal_info_form[permSameAsResidential]' => '1',
        ]));
        self::assertSame('Mandaue City', $this->sheet()->permCity);

        $this->client->request('GET', '/profile/personal');
        $this->client->submitForm('Save personal information', ['personal_info_form[resCity]' => 'Cebu City']);

        self::assertSame('Cebu City', $this->sheet()->permCity);
    }

    public function testValidationErrorsShownInline(): void
    {
        $this->client->request('GET', '/profile/personal');

        $this->client->submitForm('Save personal information', $this->requiredPersonal([
            'personal_info_form[surname]' => '',
            'personal_info_form[mobileNo]' => '123',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter your surname.');
        self::assertSelectorTextContains('main', 'Mobile number must look like 09171234567.');
        self::assertNull($this->sheet()?->firstName);
        self::assertSame('Maria Santos', $this->findUser('maria')->getFullName());
    }

    private function postFamily(array $fields, array $children): void
    {
        $crawler = $this->client->request('GET', '/profile/family');
        $values = $crawler->selectButton('Save family background')->form()->getPhpValues();
        $values['family_background_form'] = array_merge($values['family_background_form'], $fields);
        $values['family_background_form']['children'] = $children;

        $this->client->request('POST', '/profile/family', $values);
    }

    public function testSaveSpouseParentsAndChildren(): void
    {
        $this->postFamily([
            'spouseSurname' => 'Santos',
            'spouseFirstName' => 'Jose',
            'spouseOccupation' => 'Engineer',
            'fatherSurname' => 'Cruz',
            'fatherFirstName' => 'Pedro',
            'motherMaidenSurname' => 'Reyes',
            'motherFirstName' => 'Lourdes',
        ], [
            ['fullName' => 'Ana Cruz Santos', 'dateOfBirth' => '2012-06-01'],
            ['fullName' => 'Ben Cruz Santos', 'dateOfBirth' => ''],
        ]);

        self::assertResponseRedirects('/profile/family');
        $sheet = $this->sheet();
        self::assertSame('Jose', $sheet->spouseFirstName);
        self::assertSame('Reyes', $sheet->motherMaidenSurname);
        $children = $sheet->getChildren()->map(static fn ($c) => [$c->fullName, $c->dateOfBirth?->format('Y-m-d'), $c->position])->toArray();
        self::assertSame([['Ana Cruz Santos', '2012-06-01', 0], ['Ben Cruz Santos', null, 1]], array_values($children));
    }

    public function testRemovingChildDeletesRowAndEmptyListIsValid(): void
    {
        $this->postFamily([], [['fullName' => 'Ana Cruz Santos', 'dateOfBirth' => '']]);
        self::assertCount(1, $this->sheet()->getChildren());

        $this->postFamily([], []);

        self::assertResponseRedirects('/profile/family');
        self::assertCount(0, $this->sheet()->getChildren());
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pds_child'));
    }

    public function testChildNameRequiredAndBirthDateNotFuture(): void
    {
        $this->postFamily([], [
            ['fullName' => '', 'dateOfBirth' => '2012-06-01'],
            ['fullName' => 'Ben', 'dateOfBirth' => '2030-01-01'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter the child\'s full name.');
        self::assertSelectorTextContains('main', 'Date of birth cannot be in the future.');
    }

    private function uploadPhoto(string $path, string $name = 'photo.jpg'): void
    {
        $crawler = $this->client->request('GET', '/profile');
        $form = $crawler->selectButton('Upload photo')->form();
        $form['image_upload_form[image]']->upload($path);
        $files = $form->getPhpFiles();
        $files['image_upload_form']['image']['name'] = $name;

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), $files);
    }

    private function jpegFile(): string
    {
        $img = imagecreatetruecolor(800, 800);
        $path = sys_get_temp_dir().'/zim_photo_'.bin2hex(random_bytes(4)).'.jpg';
        imagejpeg($img, $path);

        return $path;
    }

    private function absolute(string $path): string
    {
        return static::getContainer()->getParameter('app.upload_root').'/'.$path;
    }

    public function testPhotoInputShowsPreviewBeforeUploading(): void
    {
        $crawler = $this->client->request('GET', '/profile');

        self::assertCount(1, $crawler->filter('[data-controller~="image-preview"] input#image_upload_form_image[data-action~="image-preview#show"]'));
        self::assertCount(1, $crawler->filter('[data-image-preview-target="image"]'));
        self::assertStringContainsString('Click "Upload photo"', $crawler->filter('[data-image-preview-target="hint"]')->text());
    }

    public function testUploadPhotoStores350x450(): void
    {
        $this->uploadPhoto($this->jpegFile());

        self::assertResponseRedirects('/profile');
        $photo = $this->findUser('maria')->getPhotoPath();
        self::assertMatchesRegularExpression('#^uploads/photos/[0-9a-f]{32}\.webp$#', $photo);
        self::assertSame([350, 450], \array_slice(getimagesize($this->absolute($photo)), 0, 2));
        self::assertContains('Changed profile photo', $this->activityActions());
        unlink($this->absolute($photo));
    }

    public function testFakeJpgRejectedAndOldPhotoKept(): void
    {
        $this->uploadPhoto($this->jpegFile());
        $old = $this->findUser('maria')->getPhotoPath();
        $fake = sys_get_temp_dir().'/zim_fake_'.bin2hex(random_bytes(4)).'.jpg';
        file_put_contents($fake, '%PDF-1.4 not an image');

        $this->uploadPhoto($fake);

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Upload a JPG, PNG or WebP image.');
        self::assertSame($old, $this->findUser('maria')->getPhotoPath());
        self::assertFileExists($this->absolute($old));
        unlink($this->absolute($old));
    }

    public function testHugeImageShowsErrorInsteadOfCrashing(): void
    {
        $this->uploadPhoto(\App\Tests\Service\ImageProcessorTest::hugePngHeader(sys_get_temp_dir()), 'bomb.png');

        self::assertResponseRedirects('/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'The image is too large.');
        self::assertNull($this->findUser('maria')->getPhotoPath());
    }

    public function testInvalidCsrfTokenIsShownOnPersonalForm(): void
    {
        $crawler = $this->client->request('GET', '/profile/personal');
        $form = $crawler->selectButton('Save personal information')->form();
        $values = $form->getPhpValues();
        $values['personal_info_form'] = array_merge($values['personal_info_form'], [
            'surname' => 'Santos', 'firstName' => 'Maria', 'dateOfBirth' => '1985-03-14',
            'sexAtBirth' => 'female', 'civilStatus' => 'single', 'citizenship' => 'filipino',
            '_token' => 'not-the-token',
        ]);

        $this->client->request('POST', '/profile/personal', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'CSRF token is invalid');
    }

    public function testRemovePhotoRequiresCsrfAndDeletesFile(): void
    {
        $this->uploadPhoto($this->jpegFile());
        $photo = $this->findUser('maria')->getPhotoPath();

        $this->client->request('POST', '/profile/photo/remove', ['_token' => 'wrong']);
        self::assertSame($photo, $this->findUser('maria')->getPhotoPath());

        $crawler = $this->client->request('GET', '/profile');
        $this->client->submit($crawler->selectButton('Remove photo')->form());

        self::assertResponseRedirects('/profile');
        self::assertNull($this->findUser('maria')->getPhotoPath());
        self::assertFileDoesNotExist($this->absolute($photo));
        self::assertContains('Removed profile photo', $this->activityActions());
    }

    public function testPdfDownloadBeforeAnySave(): void
    {
        $this->client->request('GET', '/profile/pds.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $this->client->getInternalResponse()->getContent());
    }

    public function testProfilePageLinksToPdf(): void
    {
        $this->client->request('GET', '/profile');

        self::assertSelectorExists('a[href="/profile/pds.pdf"]');
    }

    /** Posts one list tab with the given rows, keeping the page's CSRF token. */
    private function postList(string $url, string $button, string $form, string $field, array $rows): void
    {
        $crawler = $this->client->request('GET', $url);
        $values = $crawler->selectButton($button)->form()->getPhpValues();
        $values[$form][$field] = $rows;

        $this->client->request('POST', $url, $values);
    }

    public function testEducationRowsSavedSortedByLevelWithEmptySectionOne(): void
    {
        $this->postList('/profile/education', 'Save education', 'education_section_form', 'educations', [
            ['level' => 'college', 'schoolName' => 'Cebu Normal University', 'course' => 'Bachelor of Secondary Education', 'yearFrom' => '2008', 'yearTo' => '2012', 'yearGraduated' => '2012', 'honors' => 'Cum Laude'],
            ['level' => 'elementary', 'schoolName' => 'Pagsabungan Elementary School', 'yearFrom' => '1996', 'yearTo' => '2002', 'yearGraduated' => '2002'],
        ]);

        self::assertResponseRedirects('/profile/education');
        $rows = $this->sheet()->getEducations()->map(static fn ($e) => $e->level->value.'|'.$e->schoolName.'|'.$e->position)->toArray();
        self::assertSame(['elementary|Pagsabungan Elementary School|0', 'college|Cebu Normal University|1'], array_values($rows));

        $crawler = $this->client->request('GET', '/profile/education');
        self::assertSame('Pagsabungan Elementary School', $crawler->filter('input[name="education_section_form[educations][0][schoolName]"]')->attr('value'));
        self::assertSelectorTextContains('.tabs__tab.is-active', 'Education');
    }

    public function testEducationYearsShareOneRow(): void
    {
        $this->postList('/profile/education', 'Save education', 'education_section_form', 'educations', [
            ['level' => 'college', 'schoolName' => 'Cebu Normal University', 'yearFrom' => '2008', 'yearTo' => '2012', 'yearGraduated' => '2012'],
        ]);
        $crawler = $this->client->request('GET', '/profile/education');
        $card = $crawler->filter('.list-card')->first();

        foreach (['yearFrom', 'yearTo', 'yearGraduated'] as $year) {
            self::assertCount(1, $card->filter(".field--third input[name\$=\"[$year]\"]"), "$year should be a third-width field");
        }
        self::assertCount(1, $card->filter('.field--wide input[name$="[course]"]'));
        self::assertCount(1, $card->filter('.field--two-thirds input[name$="[schoolName]"]'));
        $order = $card->filter('input, select')->each(static fn ($i) => preg_replace('/.*\[(\w+)\]$/', '$1', $i->attr('name')));
        self::assertSame(['level', 'schoolName', 'course', 'yearFrom', 'yearTo', 'yearGraduated', 'highestLevel', 'honors'], $order);
    }

    public function testEducationRequiredFieldsAndRemoveAll(): void
    {
        $this->postList('/profile/education', 'Save education', 'education_section_form', 'educations', [
            ['level' => 'college', 'schoolName' => '', 'yearFrom' => '2012', 'yearTo' => '2008'],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter the name of the school.');
        self::assertSelectorTextContains('main', 'The end year cannot be before the start year.');

        $this->postList('/profile/education', 'Save education', 'education_section_form', 'educations', [
            ['level' => 'college', 'schoolName' => 'CNU'],
        ]);
        self::assertCount(1, $this->sheet()->getEducations());
        $this->postList('/profile/education', 'Save education', 'education_section_form', 'educations', []);

        self::assertResponseRedirects('/profile/education');
        self::assertCount(0, $this->sheet()->getEducations());
    }

    public function testEligibilitySavedWithLicense(): void
    {
        $this->postList('/profile/eligibility', 'Save eligibility', 'eligibility_section_form', 'eligibilities', [
            ['name' => 'Professional Teacher (RA 1080 / LET)', 'rating' => '82.40', 'examDate' => '2012-09-30', 'examPlace' => 'Cebu City', 'licenseNo' => '1234567', 'licenseValidUntil' => '2027-03-14'],
        ]);

        self::assertResponseRedirects('/profile/eligibility');
        $row = $this->sheet()->getEligibilities()->first();
        self::assertSame(['Professional Teacher (RA 1080 / LET)', '1234567', '2027-03-14'], [$row->name, $row->licenseNo, $row->licenseValidUntil->format('Y-m-d')]);
        $this->client->request('GET', '/profile/eligibility');
        self::assertSelectorExists('datalist#eligibility-names option[value="Career Service Professional"]');
    }

    public function testWorkExperienceResortsWhenNoLongerPresent(): void
    {
        $job = static fn (string $title, string $from, string $to = '', bool $present = false) => array_filter([
            'positionTitle' => $title, 'office' => 'Department of Education - Division of Mandaue City',
            'dateFrom' => $from, 'dateTo' => $to, 'present' => $present ? '1' : null,
            'monthlySalary' => '30024', 'salaryGrade' => '11-1', 'appointmentStatus' => 'Permanent', 'governmentService' => '1',
        ], static fn ($v) => null !== $v);

        $this->postList('/profile/work', 'Save work experience', 'work_section_form', 'workExperiences', [
            $job('Teacher I', '2012-06-04', '2018-05-31'),
            $job('Principal I', '2023-01-02', present: true),
            $job('Master Teacher I', '2018-06-01', '2022-12-31'),
        ]);
        self::assertResponseRedirects('/profile/work');
        self::assertSame(['Principal I', 'Master Teacher I', 'Teacher I'], array_values($this->sheet()->getWorkExperiences()->map(static fn ($w) => $w->positionTitle)->toArray()));

        // Principal I ends before Master Teacher I's start was entered as current: re-sorted by date now
        $this->postList('/profile/work', 'Save work experience', 'work_section_form', 'workExperiences', [
            $job('Principal I', '2023-01-02', '2023-06-30'),
            $job('Master Teacher I', '2018-06-01', '2022-12-31'),
            $job('Teacher I', '2012-06-04', '2018-05-31'),
            $job('Principal II', '2023-07-01', present: true),
        ]);
        self::assertSame(['Principal II', 'Principal I', 'Master Teacher I', 'Teacher I'], array_values($this->sheet()->getWorkExperiences()->map(static fn ($w) => $w->positionTitle)->toArray()));
    }

    public function testSalaryTypedWithCommasAndPesoSignIsSavedAsNumber(): void
    {
        $this->postList('/profile/work', 'Save work experience', 'work_section_form', 'workExperiences', [
            ['positionTitle' => 'Teacher I', 'office' => 'DepEd', 'dateFrom' => '2012-06-04', 'monthlySalary' => '₱ 30,024.00'],
        ]);

        self::assertResponseRedirects('/profile/work');
        self::assertSame('30024.00', $this->sheet()->getWorkExperiences()->first()->monthlySalary);
    }

    public function testWorkExperienceSalaryGradeFormat(): void
    {
        $this->postList('/profile/work', 'Save work experience', 'work_section_form', 'workExperiences', [
            ['positionTitle' => 'Teacher I', 'office' => 'DepEd', 'dateFrom' => '2012-06-04', 'salaryGrade' => '11'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Use the format 00-0, e.g. 11-1.');
    }

    public function testTrainingsNewestFirstWithTotalHours(): void
    {
        $this->postList('/profile/training', 'Save learning and development', 'training_section_form', 'trainings', [
            ['title' => 'School-Based In-Service Training (INSET)', 'dateFrom' => '2023-01-23', 'dateTo' => '2023-01-27', 'hours' => '24', 'type' => 'Technical', 'sponsor' => 'DepEd Division of Mandaue City'],
            ['title' => 'Learning Action Cell on MATATAG Curriculum', 'dateFrom' => '2024-08-01', 'dateTo' => '2024-08-02', 'hours' => '16', 'type' => 'Technical', 'sponsor' => 'Pagsabungan NHS'],
        ]);

        self::assertResponseRedirects('/profile/training');
        self::assertSame(['Learning Action Cell on MATATAG Curriculum', 'School-Based In-Service Training (INSET)'], array_values($this->sheet()->getTrainings()->map(static fn ($t) => $t->title)->toArray()));
        $this->client->request('GET', '/profile/training');
        self::assertSelectorTextContains('.list-total', 'Total: 40 hours');
        self::assertSelectorExists('datalist#training-types option[value="Managerial"]');
    }

    public function testVoluntaryWorkUndatedLast(): void
    {
        $this->postList('/profile/voluntary', 'Save voluntary work', 'voluntary_section_form', 'voluntaryWorks', [
            ['organization' => 'Red Cross Mandaue Chapter, Mandaue City', 'nature' => 'Volunteer'],
            ['organization' => 'Brigada Eskwela, Pagsabungan NHS', 'dateFrom' => '2024-07-15', 'dateTo' => '2024-07-20', 'hours' => '30', 'nature' => 'Coordinator'],
        ]);

        self::assertResponseRedirects('/profile/voluntary');
        self::assertSame(['Brigada Eskwela, Pagsabungan NHS', 'Red Cross Mandaue Chapter, Mandaue City'], array_values($this->sheet()->getVoluntaryWorks()->map(static fn ($v) => $v->organization)->toArray()));
    }

    public function testOtherInformationThreeLists(): void
    {
        $crawler = $this->client->request('GET', '/profile/other');
        $values = $crawler->selectButton('Save other information')->form()->getPhpValues();
        $values['other_info_form']['skills'] = ['Singing', 'Computer literacy (MS Office, Canva)'];
        $values['other_info_form']['distinctions'] = ['Outstanding Teacher, Division of Mandaue City 2023'];
        $values['other_info_form']['memberships'] = ['National Organization of Professional Teachers'];

        $this->client->request('POST', '/profile/other', $values);

        self::assertResponseRedirects('/profile/other');
        $sheet = $this->sheet();
        self::assertSame(['Singing', 'Computer literacy (MS Office, Canva)'], $sheet->skills);
        self::assertSame(['Outstanding Teacher, Division of Mandaue City 2023'], $sheet->distinctions);
        self::assertSame(['National Organization of Professional Teachers'], $sheet->memberships);
        $this->client->request('GET', '/profile/other');
        self::assertSelectorTextContains('.tabs__tab.is-active', 'Other Information');
    }

    public function testListPagesDoNotRepeatFieldsAfterSaveButton(): void
    {
        foreach (['/profile/other', '/profile/education', '/profile/family'] as $url) {
            $crawler = $this->client->request('GET', $url);

            self::assertCount(0, $crawler->filter('.form-actions ~ div label, .form-actions ~ div'), "$url renders leftover fields after the Save button");
        }
    }

    public function testOtherInformationRejectsBlankLine(): void
    {
        $crawler = $this->client->request('GET', '/profile/other');
        $values = $crawler->selectButton('Save other information')->form()->getPhpValues();
        $values['other_info_form']['skills'] = ['Singing', ''];

        $this->client->request('POST', '/profile/other', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Remove empty lines or fill them in.');
    }

    public function testBadRowInOneListDoesNotBlockOtherTabs(): void
    {
        $this->client->request('GET', '/profile');
        $sheet = $this->sheet();
        $training = new \App\Entity\PdsTraining();
        $training->title = 'Old record';
        $training->dateFrom = new \DateTimeImmutable('2020-01-01');
        $training->hours = '0.00';
        $sheet->addTraining($training);
        $this->em->flush();

        $this->postList('/profile/eligibility', 'Save eligibility', 'eligibility_section_form', 'eligibilities', [['name' => 'Career Service Professional']]);
        self::assertResponseRedirects('/profile/eligibility');

        $this->client->request('GET', '/profile/personal');
        $this->client->submitForm('Save personal information', $this->requiredPersonal());
        self::assertResponseRedirects('/profile/personal');
    }

    public function testListsAreCappedAtOneHundredRows(): void
    {
        $rows = array_fill(0, 101, ['name' => 'Career Service Professional']);
        $this->postList('/profile/eligibility', 'Save eligibility', 'eligibility_section_form', 'eligibilities', $rows);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'List at most 100 entries.');

        $crawler = $this->client->request('GET', '/profile/other');
        $values = $crawler->selectButton('Save other information')->form()->getPhpValues();
        $values['other_info_form']['skills'] = array_fill(0, 101, 'Singing');
        $this->client->request('POST', '/profile/other', $values);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'List at most 100 entries.');
    }

    private function postQuestions(array $answers): void
    {
        $crawler = $this->client->request('GET', '/profile/questions');
        $values = $crawler->selectButton('Save answers')->form()->getPhpValues();
        $values['questions_form'] = array_merge($values['questions_form'], $answers);

        $this->client->request('POST', '/profile/questions', $values);
    }

    public function testQuestionsSaveYesWithDetailsAndNo(): void
    {
        $this->postQuestions(['q36' => '0', 'q40c' => '1', 'q40cDetails' => 'SP-2024-0012']);

        self::assertResponseRedirects('/profile/questions');
        $sheet = $this->sheet();
        self::assertSame([false, true, 'SP-2024-0012', null], [$sheet->q36, $sheet->q40c, $sheet->q40cDetails, $sheet->q37]);
        $crawler = $this->client->request('GET', '/profile/questions');
        self::assertSelectorTextContains('.tabs__tab.is-active', 'Questions 34–40');
        self::assertCount(1, $crawler->filter('#questions_form_q36.choice-chips'));
    }

    public function testQuestionsYesWithoutDetailsShowsError(): void
    {
        $this->postQuestions(['q35b' => '1', 'q35bDetails' => 'Libel']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter the date filed.');
        self::assertSelectorTextContains('main', 'Enter the status of the case.');
    }

    public function testChangingYesToNoClearsDetails(): void
    {
        $this->postQuestions(['q39' => '1', 'q39Details' => 'Canada']);
        self::assertSame('Canada', $this->sheet()->q39Details);

        $this->postQuestions(['q39' => '0']);

        self::assertNull($this->sheet()->q39Details);
    }

    public function testReferencesTabAlwaysShowsThreeRows(): void
    {
        $crawler = $this->client->request('GET', '/profile/references');

        self::assertCount(3, $crawler->filter('input[name$="[name]"][name^="references_form[references]"]'));
        self::assertSelectorTextContains('.tabs__tab.is-active', 'References & ID');
        self::assertSelectorExists('datalist#gov-id-types option[value="PRC"]');
    }

    public function testReferencesSavedWithGovernmentId(): void
    {
        $crawler = $this->client->request('GET', '/profile/references');
        $this->client->submit($crawler->selectButton('Save references and ID')->form(), [
            'references_form[references][0][name]' => 'Dr. Ana Reyes',
            'references_form[references][0][address]' => 'DepEd Division Office, Mandaue City',
            'references_form[references][0][contact]' => '09171234567',
            'references_form[references][1][name]' => 'Mr. Ben Cruz',
            'references_form[references][1][address]' => 'Pagsabungan, Mandaue City',
            'references_form[references][1][contact]' => 'ben@deped.gov.ph',
            'references_form[references][2][name]' => 'Ms. Carla Santos',
            'references_form[references][2][address]' => 'Cebu City',
            'references_form[references][2][contact]' => '(032) 123-4567',
            'references_form[govIdType]' => 'PRC',
            'references_form[govIdNumber]' => '1234567',
            'references_form[govIdIssued]' => 'March 3, 2022 / Cebu City',
        ]);

        self::assertResponseRedirects('/profile/references');
        $sheet = $this->sheet();
        self::assertSame(['Dr. Ana Reyes', 'Mr. Ben Cruz', 'Ms. Carla Santos'], array_values($sheet->getReferences()->map(static fn ($r) => $r->name)->toArray()));
        self::assertSame(['PRC', '1234567', 'March 3, 2022 / Cebu City'], [$sheet->govIdType, $sheet->govIdNumber, $sheet->govIdIssued]);
    }

    public function testReferencesNeedAllThreeOrNone(): void
    {
        $crawler = $this->client->request('GET', '/profile/references');
        $form = $crawler->selectButton('Save references and ID')->form();

        $this->client->submit($form, [
            'references_form[references][0][name]' => 'Dr. Ana Reyes',
            'references_form[references][0][address]' => 'DepEd Division Office, Mandaue City',
            'references_form[references][0][contact]' => '09171234567',
            'references_form[references][1][name]' => 'Mr. Ben Cruz',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter the office or residential address.');
        self::assertSelectorTextContains('main', 'List three references, or leave all three empty for now.');
        $page = new \Symfony\Component\DomCrawler\Crawler($this->client->getResponse()->getContent());
        self::assertCount(3, $page->filter('input[name^="references_form[references]"][name$="[name]"]'), 'All three rows stay on the page after an error');
        self::assertSame('Mr. Ben Cruz', $page->filter('input[name="references_form[references][1][name]"]')->attr('value'));

        $crawler = $this->client->request('GET', '/profile/references');
        $this->client->submit($crawler->selectButton('Save references and ID')->form(), ['references_form[govIdType]' => 'UMID']);
        self::assertResponseRedirects('/profile/references');
        self::assertCount(0, $this->sheet()->getReferences());
    }

    public function testStudentIsForbidden(): void
    {
        $this->client->loginUser($this->createUser('stud', User::ROLE_STUDENT));

        $this->client->request('GET', '/profile');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousIsSentToLogin(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/profile');

        self::assertResponseRedirects('/login');
    }
}
