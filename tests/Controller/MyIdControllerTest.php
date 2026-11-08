<?php

namespace App\Tests\Controller;

use App\Staff\Domain\Model\PersonalDataSheet;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Entity\ServiceRecordEntry;
use App\Entity\StaffIdDetails;
use App\Access\Domain\Model\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class MyIdControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $rosa;
    private User $tess;
    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-11-02 10:00:00');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->rosa = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->rosa);
        $this->tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $sheet = new PersonalDataSheet($this->tess);
        $sheet->surname = 'Dela Cruz';
        $sheet->firstName = 'Juan';
        $sheet->middleName = 'Abad';
        $this->em->persist($sheet);
        $this->em->flush();
        $this->client->loginUser($this->tess);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function ready(User $user, string $designation): void
    {
        $path = 'uploads/photos/test_'.bin2hex(random_bytes(4)).'.webp';
        $absolute = static::getContainer()->getParameter('app.upload_root').'/'.$path;
        @mkdir(\dirname($absolute), 0o777, true);
        imagewebp(imagecreatetruecolor(35, 45), $absolute);
        $this->files[] = $absolute;
        $user->setPhotoPath($path);
        $this->em->persist((new ServiceRecordEntry($user, new \DateTimeImmutable('2020-01-01'), $this->rosa, new \DateTimeImmutable()))
            ->setDesignation($designation)->setStatus('Regular/Permanent')->setAnnualSalary('300000')
            ->setStation('Mandaue City')->setBranch('National'));
        $this->em->flush();
    }

    /** @return list<string> */
    private function menu(): array
    {
        return $this->client->getCrawler()->filter('.sidebar__link')->each(static fn ($a) => $a->text());
    }

    public function testTeacherSeesOwnIdPage(): void
    {
        $this->ready($this->tess, 'Teacher I');

        $this->client->request('GET', '/my-id');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'JUAN A. DELA CRUZ');
        self::assertSelectorTextContains('main', 'Teacher I');
        self::assertSelectorTextContains('main', 'ROSA CRUZ');
        self::assertContains('My ID', $this->menu());
        self::assertSelectorTextContains('.sidebar__link.is-active', 'My ID');
        self::assertCount(1, $this->client->getCrawler()->filter('a[href="/my-id/big.pdf"]'));
        self::assertCount(1, $this->client->getCrawler()->filter('a[href="/my-id/small.pdf"]'));
    }

    public function testPrincipalSeesOwnIdPage(): void
    {
        $this->client->loginUser($this->rosa);

        $this->client->request('GET', '/my-id');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'ROSA CRUZ');
        self::assertSelectorTextContains('main', 'No Divisions entry');
        self::assertContains('My ID', $this->menu());
    }

    public function testUnassignedIsDenied(): void
    {
        $loner = $this->createUser('loner', User::ROLE_TEACHER);
        $this->client->loginUser($loner);

        $this->client->request('GET', '/teacher');
        self::assertNotContains('My ID', $this->menu());

        $this->client->request('GET', '/my-id');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/my-id/big.pdf');
        self::assertResponseStatusCodeSame(403);
    }

    public function testStudentIsDenied(): void
    {
        $this->client->loginUser($this->createUser('stud', User::ROLE_STUDENT));

        $this->client->request('GET', '/my-id');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDetailsAreSaved(): void
    {
        $this->client->request('GET', '/my-id');
        $this->client->submitForm('Save ID details', [
            'staff_id_details_form[callName]' => 'Sir Jun',
            'staff_id_details_form[emergencyName]' => 'Maria Dela Cruz',
            'staff_id_details_form[emergencyContact]' => '0917 123 4567',
            'staff_id_details_form[gsisPolicyNo]' => '2003334189',
        ]);

        self::assertResponseRedirects('/my-id');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'ID details saved.');
        $this->em->clear();
        $details = $this->em->getRepository(StaffIdDetails::class)->findOneBy(['user' => $this->tess->getId()]);
        self::assertSame('Sir Jun', $details->getCallName());
        self::assertSame('Maria Dela Cruz', $details->getEmergencyName());
        self::assertSame('0917 123 4567', $details->getEmergencyContact());
        self::assertSame('2003334189', $details->getGsisPolicyNo());
    }

    public function testPdfDownloads(): void
    {
        $this->ready($this->tess, 'Teacher I');

        foreach (['big' => 'Big', 'small' => 'Small'] as $size => $label) {
            $this->client->request('GET', '/my-id/'.$size.'.pdf');

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/pdf');
            self::assertStringContainsString('ID ('.$label.') - DELA CRUZ, JUAN.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringStartsWith('%PDF-', (string) $this->client->getInternalResponse()->getContent());
        }
    }

    public function testPdfRefusedWithoutPhotoOrPosition(): void
    {
        $crawler = $this->client->request('GET', '/my-id');
        self::assertCount(0, $crawler->filter('a[href="/my-id/big.pdf"]'));
        self::assertCount(2, $crawler->filter('button[disabled]'));
        self::assertSelectorTextContains('main', 'No photo');

        $this->client->request('GET', '/my-id/big.pdf');

        self::assertResponseRedirects('/my-id');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Add your photo and a current (Present) service record row before printing your ID.');
    }

    public function testBadSizeIs404(): void
    {
        $this->client->request('GET', '/my-id/huge.pdf');

        self::assertResponseStatusCodeSame(404);
    }
}
