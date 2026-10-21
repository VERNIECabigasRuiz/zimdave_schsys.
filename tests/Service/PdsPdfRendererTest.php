<?php

namespace App\Tests\Service;

use App\Entity\Citizenship;
use App\Entity\CivilStatus;
use App\Entity\PdsChild;
use App\Entity\PersonalDataSheet;
use App\Entity\SexAtBirth;
use App\Entity\User;
use App\Service\PdsPdfRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PdsPdfRendererTest extends KernelTestCase
{
    private PdsPdfRenderer $renderer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->renderer = static::getContainer()->get(PdsPdfRenderer::class);
    }

    private function sheet(): PersonalDataSheet
    {
        $user = (new User())->setUsername('juan')->setFullName('Juan Dela Cruz');
        $sheet = new PersonalDataSheet($user);
        $sheet->surname = 'Dela Cruz';
        $sheet->firstName = 'Juan';
        $sheet->middleName = 'Abad';
        $sheet->dateOfBirth = new \DateTimeImmutable('1990-05-01');
        $sheet->sexAtBirth = SexAtBirth::Male;
        $sheet->civilStatus = CivilStatus::Married;
        $sheet->citizenship = Citizenship::Filipino;
        $sheet->philsysPcn = '1234-5678-9012-3456';

        return $sheet;
    }

    public function testHtmlHasFourPagesNamesAndNaForEmptyFields(): void
    {
        $html = $this->renderer->renderHtml($this->sheet());

        self::assertStringContainsString('DELA CRUZ', $html);
        self::assertStringContainsString('1234-5678-9012-3456', $html);
        self::assertStringContainsString('01/05/1990', $html);
        foreach ([1, 2, 3, 4] as $page) {
            self::assertStringContainsString("CS FORM 212 (Revised 2026), Page $page of 4", $html);
        }
        self::assertMatchesRegularExpression('#TIN NO\.</td>\s*<td class="val">N/A</td>#', $html);
        self::assertStringContainsString('☒ Married', $html);
        self::assertStringContainsString('☐ Single', $html);
    }

    public function testRendersPdfBytes(): void
    {
        $pdf = $this->renderer->render($this->sheet());

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame(4, preg_match_all('#/Type\s*/Page\b#', $pdf));
    }

    public function testFullyFilledSheetStillPrintsOnFourPages(): void
    {
        $sheet = $this->sheet();
        $sheet->nameExtension = 'III';
        $sheet->placeOfBirth = 'Mandaue City, Cebu, Philippines';
        $sheet->civilStatus = \App\Entity\CivilStatus::Others;
        $sheet->civilStatusOther = 'Annulled (pending finality)';
        $sheet->citizenship = Citizenship::Dual;
        $sheet->dualCitizenshipBy = \App\Entity\DualCitizenshipBy::Birth;
        $sheet->dualCitizenshipCountry = 'United States of America';
        $sheet->height = '1.75';
        $sheet->weight = '68.50';
        $sheet->bloodType = 'AB+';
        foreach (['umidNo', 'pagibigNo', 'philhealthNo', 'tin', 'agencyEmployeeNo'] as $id) {
            $sheet->{$id} = '1234-5678-9012-3456';
        }
        foreach (['res', 'perm'] as $p) {
            $sheet->{$p.'HouseNo'} = 'Blk 12 Lot 34 Phase 2';
            $sheet->{$p.'Street'} = 'Sacris Road Extension';
            $sheet->{$p.'Subdivision'} = 'Pacific Grand Villas Subdivision';
            $sheet->{$p.'Barangay'} = 'Pagsabungan';
            $sheet->{$p.'City'} = 'City of Mandaue';
            $sheet->{$p.'Province'} = 'Cebu';
            $sheet->{$p.'Zip'} = '6014';
        }
        $sheet->telephoneNo = '(032) 123-4567';
        $sheet->mobileNo = '09171234567';
        $sheet->email = 'juan.delacruz.teacher@deped.gov.ph';
        $sheet->spouseSurname = 'Dela Cruz-Villanueva';
        $sheet->spouseFirstName = 'Maria Theresa Concepcion';
        $sheet->spouseMiddleName = 'Fernandez';
        $sheet->spouseOccupation = 'Registered Nurse';
        $sheet->spouseEmployer = 'Vicente Sotto Memorial Medical Center';
        $sheet->spouseBusinessAddress = 'B. Rodriguez Street, Sambag II, Cebu City, Cebu 6000';
        $sheet->spouseTelephone = '(032) 253-9891';
        $sheet->fatherSurname = 'Dela Cruz';
        $sheet->fatherFirstName = 'Pedro Antonio';
        $sheet->fatherMiddleName = 'Gonzales';
        $sheet->motherMaidenSurname = 'Abad';
        $sheet->motherFirstName = 'Lourdes Felicidad';
        $sheet->motherMiddleName = 'Ramirez';
        for ($i = 1; $i <= PdsPdfRenderer::CHILD_ROWS; ++$i) {
            $child = new PdsChild();
            $child->fullName = 'Francisco Miguel Abad Dela Cruz '.$i;
            $child->dateOfBirth = new \DateTimeImmutable('2010-01-0'.min($i, 9));
            $sheet->addChild($child);
        }

        $pdf = $this->renderer->render($sheet);

        self::assertSame(4, preg_match_all('#/Type\s*/Page\b#', $pdf));
    }

    public function testChildrenOverflowAddsContinuationPage(): void
    {
        $sheet = $this->sheet();
        for ($i = 1; $i <= 20; ++$i) {
            $child = new PdsChild();
            $child->fullName = 'Child '.$i;
            $sheet->addChild($child);
        }

        $html = $this->renderer->renderHtml($sheet);

        self::assertStringContainsString('Child 20', $html);
        self::assertStringContainsString('II. FAMILY BACKGROUND (continuation)', $html);
    }

    public function testPhotoIsEmbeddedInPhotoBox(): void
    {
        $images = new \App\Service\ImageProcessor(static::getContainer()->getParameter('app.upload_root'), new \Symfony\Component\Filesystem\Filesystem());
        $source = sys_get_temp_dir().'/zim_pdf_photo.jpg';
        imagejpeg(imagecreatetruecolor(400, 500), $source);
        $sheet = $this->sheet();
        $sheet->getUser()->setPhotoPath($images->storePhoto(new \Symfony\Component\HttpFoundation\File\File($source), null));

        $html = $this->renderer->renderHtml($sheet);

        self::assertMatchesRegularExpression('#class="photo-box"><img src="data:image/png;base64,[A-Za-z0-9+/=]{100,}"#', $html);
        $images->remove($sheet->getUser()->getPhotoPath());
        unlink($source);
    }

    private function education(\App\Entity\EducationLevel $level, string $school, ?int $from = null): \App\Entity\PdsEducation
    {
        $e = new \App\Entity\PdsEducation();
        $e->level = $level;
        $e->schoolName = $school;
        $e->yearFrom = $from;
        $e->course = 'Bachelor of Secondary Education';

        return $e;
    }

    private function work(int $i, bool $present = false): \App\Entity\PdsWorkExperience
    {
        $w = new \App\Entity\PdsWorkExperience();
        $w->positionTitle = 'Teacher Position '.$i;
        $w->office = 'Department of Education - Division of Mandaue City';
        $w->dateFrom = new \DateTimeImmutable(\sprintf('%d-06-01', 1990 + $i));
        $w->dateTo = $present ? null : new \DateTimeImmutable(\sprintf('%d-05-31', 1991 + $i));
        $w->present = $present;
        $w->monthlySalary = '30024.00';
        $w->salaryGrade = '11-1';
        $w->appointmentStatus = 'Permanent';

        return $w;
    }

    public function testListRowsAppearInTheirGrids(): void
    {
        $sheet = $this->sheet();
        $sheet->addEducation($this->education(\App\Entity\EducationLevel::College, 'Cebu Normal University', 2008));
        $sheet->addWorkExperience($this->work(30, present: true));
        $e = new \App\Entity\PdsEligibility();
        $e->name = 'Professional Teacher (RA 1080 / LET)';
        $e->licenseNo = '1234567';
        $sheet->addEligibility($e);
        $t = new \App\Entity\PdsTraining();
        $t->title = 'School-Based INSET';
        $t->dateFrom = new \DateTimeImmutable('2023-01-23');
        $t->hours = '24.00';
        $sheet->addTraining($t);
        $sheet->skills = ['Singing'];
        $sheet->memberships = ['National Organization of Professional Teachers'];

        $html = $this->renderer->renderHtml($sheet);

        foreach (['Cebu Normal University', 'Professional Teacher (RA 1080 / LET)', '1234567', 'Teacher Position 30', 'PRESENT', '30,024.00', '11-1', 'School-Based INSET', '23/01/2023', 'Singing', 'National Organization of Professional Teachers'] as $text) {
            self::assertStringContainsString(htmlspecialchars($text), $html, $text);
        }
        self::assertStringNotContainsString('(Continuation)', $html);
    }

    public function testEmptySectionsPrintNaInFirstRow(): void
    {
        $html = $this->renderer->renderHtml($this->sheet());

        // eligibility, work, L&D, voluntary, other info + each education level
        self::assertGreaterThanOrEqual(10, substr_count($html, '<td class="na">N/A</td>'));
    }

    public function testOverflowGoesToContinuationSheets(): void
    {
        $sheet = $this->sheet();
        for ($i = 1; $i <= 25; ++$i) {
            $sheet->addWorkExperience($this->work($i));
        }
        $sheet->addEducation($this->education(\App\Entity\EducationLevel::Graduate, 'University of the Visayas', 2014));
        $sheet->addEducation($this->education(\App\Entity\EducationLevel::Graduate, 'Cebu Normal University', 2018));
        $sheet->skills = array_map(static fn ($i) => 'Skill '.$i, range(1, 8));

        $html = $this->renderer->renderHtml($sheet);

        self::assertStringContainsString('WORK EXPERIENCE (Continuation)', $html);
        self::assertStringContainsString('EDUCATIONAL BACKGROUND (Continuation)', $html);
        self::assertStringContainsString('OTHER INFORMATION (Continuation)', $html);
        // the oldest job (row 25 after newest-first sort) is on the continuation sheet
        $continuation = substr($html, strpos($html, 'WORK EXPERIENCE (Continuation)'));
        self::assertStringContainsString('Teacher Position 1<', $continuation);
        self::assertStringContainsString('Cebu Normal University', substr($html, strpos($html, 'EDUCATIONAL BACKGROUND (Continuation)')));
        self::assertStringContainsString('Skill 8', $continuation);
    }

    public function testContinuationSheetsFollowOfficialOrder(): void
    {
        $sheet = $this->sheet();
        for ($i = 1; $i <= 25; ++$i) {
            $sheet->addWorkExperience($this->work($i));
        }
        for ($i = 1; $i <= 13; ++$i) {
            $child = new PdsChild();
            $child->fullName = 'Child '.$i;
            $sheet->addChild($child);
        }
        $sheet->addEducation($this->education(\App\Entity\EducationLevel::College, 'CNU', 2004));
        $sheet->addEducation($this->education(\App\Entity\EducationLevel::College, 'UC', 2008));

        $html = $this->renderer->renderHtml($sheet);

        $c6 = strpos($html, 'WORK EXPERIENCE (Continuation)');
        $c7 = strpos($html, 'II. FAMILY BACKGROUND (continuation)');
        $c8 = strpos($html, 'EDUCATIONAL BACKGROUND (Continuation)');
        self::assertTrue($c6 < $c7 && $c7 < $c8, 'Continuation sheets should run C6, C7, C8');
    }

    public function testAllGridsFullStillFourMainPages(): void
    {
        $sheet = $this->sheet();
        foreach (\App\Entity\EducationLevel::cases() as $level) {
            $edu = $this->education($level, 'Cebu Normal University - Graduate School of Education', 2000);
            $edu->highestLevel = '24 units';
            $edu->yearTo = 2004;
            $edu->yearGraduated = 2004;
            $edu->honors = 'Cum Laude, Academic Scholar';
            $sheet->addEducation($edu);
        }
        for ($i = 1; $i <= 7; ++$i) {
            $e = new \App\Entity\PdsEligibility();
            $e->name = 'Professional Teacher (RA 1080 / LET)';
            $e->rating = '82.40';
            $e->examDate = new \DateTimeImmutable('2012-09-30');
            $e->examPlace = 'Cebu City, Cebu';
            $e->licenseNo = '1234567';
            $e->licenseValidUntil = new \DateTimeImmutable('2027-03-14');
            $sheet->addEligibility($e);
        }
        for ($i = 1; $i <= 24; ++$i) {
            $sheet->addWorkExperience($this->work($i));
        }
        for ($i = 1; $i <= 17; ++$i) {
            $t = new \App\Entity\PdsTraining();
            $t->title = 'Division Training-Workshop on the MATATAG Curriculum Implementation '.$i;
            $t->dateFrom = new \DateTimeImmutable('2024-01-01');
            $t->dateTo = new \DateTimeImmutable('2024-01-05');
            $t->hours = '40.00';
            $t->type = 'Technical';
            $t->sponsor = 'DepEd Division of Mandaue City';
            $sheet->addTraining($t);
        }
        for ($i = 1; $i <= 9; ++$i) {
            $v = new \App\Entity\PdsVoluntaryWork();
            $v->organization = 'Brigada Eskwela, Pagsabungan National High School, Mandaue City';
            $v->dateFrom = new \DateTimeImmutable('2024-07-15');
            $v->dateTo = new \DateTimeImmutable('2024-07-20');
            $v->hours = '30.00';
            $v->nature = 'Coordinator';
            $sheet->addVoluntaryWork($v);
        }
        $sheet->skills = array_fill(0, 7, 'Computer literacy (MS Office, Canva)');
        $sheet->distinctions = array_fill(0, 7, 'Outstanding Teacher, Division of Mandaue City');
        $sheet->memberships = array_fill(0, 7, 'National Organization of Professional Teachers');

        $pdf = $this->renderer->render($sheet);

        self::assertSame(4, preg_match_all('#/Type\s*/Page\b#', $pdf));
    }

    public function testVeryLongTextMovesRowsToContinuationInsteadOfAddingPages(): void
    {
        $sheet = $this->sheet();
        $office = 'Department of Education - Region VII Central Visayas - Schools Division Office of Mandaue City - Pagsabungan National High School (Senior High School Department)';
        for ($i = 1; $i <= 24; ++$i) {
            $work = $this->work($i);
            $work->office = $office;
            $work->positionTitle = 'Master Teacher II (Secondary School Teacher, Senior High School Academic Track)';
            $sheet->addWorkExperience($work);
        }
        for ($i = 1; $i <= 17; ++$i) {
            $t = new \App\Entity\PdsTraining();
            $t->title = 'Regional Training of Trainers on the Implementation of the MATATAG Curriculum for Key Stage 1 to Key Stage 4 Teachers, School Heads and Education Program Supervisors '.$i;
            $t->dateFrom = new \DateTimeImmutable('2024-01-01');
            $t->hours = '40';
            $t->sponsor = 'Department of Education - Bureau of Learning Delivery in partnership with the National Educators Academy of the Philippines';
            $sheet->addTraining($t);
        }
        for ($i = 1; $i <= 9; ++$i) {
            $v = new \App\Entity\PdsVoluntaryWork();
            $v->organization = 'Philippine Red Cross - Mandaue City Chapter, Ouano Avenue, Barangay Centro, Mandaue City, Cebu, Philippines 6014 '.$i;
            $v->nature = 'Volunteer first aider and blood donation drive coordinator';
            $sheet->addVoluntaryWork($v);
        }

        $pdf = $this->renderer->render($sheet);
        $continuations = \App\Service\PdsListGrids::build($sheet)['continuations'];

        self::assertNotEmpty($continuations, 'Long rows should move to continuation sheets');
        self::assertSame(4 + \count($continuations), preg_match_all('#/Type\s*/Page\b#', $pdf), 'Each main page and each continuation sheet should be exactly one page');
    }

    public function testVeryLongEducationRowsMoveToC8AndPageOneStaysOnePage(): void
    {
        $sheet = $this->sheet();
        $long = str_repeat('Cebu Normal University Graduate School ', 5);
        foreach (\App\Entity\EducationLevel::cases() as $level) {
            $edu = $this->education($level, mb_substr($long, 0, 200), 2000);
            $edu->course = mb_substr($long, 0, 200);
            $edu->honors = mb_substr($long, 0, 200);
            $sheet->addEducation($edu);
        }

        $html = $this->renderer->renderHtml($sheet);
        $pdf = $this->renderer->render($sheet);

        self::assertStringContainsString('(see sheet C8)', $html);
        self::assertStringContainsString('EDUCATIONAL BACKGROUND (Continuation)', $html);
        $continuationPages = preg_match_all('#/Type\s*/Page\b#', $pdf) - 4;
        self::assertSame(1, $continuationPages, 'Only C8 should follow the 4 main pages');
    }

    public function testPageFourShowsAnswersReferencesAndId(): void
    {
        $sheet = $this->sheet();
        $sheet->q36 = false;
        $sheet->q35b = true;
        $sheet->q35bDetails = 'Libel complaint';
        $sheet->q35bDateFiled = new \DateTimeImmutable('2021-02-03');
        $sheet->q35bStatus = 'Dismissed';
        $sheet->q40c = true;
        $sheet->q40cDetails = 'SP-2024-0012';
        foreach (['Dr. Ana Reyes', 'Mr. Ben Cruz', 'Ms. Carla Santos'] as $name) {
            $ref = new \App\Entity\PdsReference();
            $ref->name = $name;
            $ref->address = 'DepEd Division Office, Mandaue City';
            $ref->contact = '09171234567';
            $sheet->addReference($ref);
        }
        $sheet->govIdType = 'PRC';
        $sheet->govIdNumber = '1234567';
        $sheet->govIdIssued = 'March 3, 2022 / Cebu City';

        $html = $this->renderer->renderHtml($sheet);
        $page4 = substr($html, strpos($html, '34.</td>'));

        self::assertStringContainsString('data-q="36">☐ YES ☒ NO', $page4);
        self::assertStringContainsString('data-q="35b">☒ YES ☐ NO', $page4);
        self::assertStringContainsString('data-q="37">☐ YES ☐ NO', $page4);
        foreach (['Libel complaint', '03/02/2021', 'Dismissed', 'SP-2024-0012', 'Dr. Ana Reyes', 'Ms. Carla Santos', 'PRC', '1234567', 'March 3, 2022 / Cebu City'] as $text) {
            self::assertStringContainsString($text, $page4, $text);
        }
        self::assertSame(4, preg_match_all('#/Type\s*/Page\b#', $this->renderer->render($sheet)));
    }

    public function testPageFourDetailsAreEscaped(): void
    {
        $sheet = $this->sheet();
        $sheet->q36 = true;
        $sheet->q36Details = '<script>alert(1)</script>';

        $html = $this->renderer->renderHtml($sheet);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testFilenameSanitized(): void
    {
        self::assertSame('PDS_DELA-CRUZ_JUAN.pdf', $this->renderer->filename($this->sheet()));
        self::assertSame('PDS.pdf', $this->renderer->filename(new PersonalDataSheet(new User())));
    }
}
