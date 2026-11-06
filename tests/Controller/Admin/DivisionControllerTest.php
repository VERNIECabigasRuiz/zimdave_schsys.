<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Division;
use App\Access\Domain\Model\User;
use App\Repository\DivisionRepository;

class DivisionControllerTest extends AdminTestCase
{
    private function pngFile(): string
    {
        $img = imagecreatetruecolor(400, 150);
        $path = sys_get_temp_dir().'/zim_sig_'.bin2hex(random_bytes(4)).'.png';
        imagepng($img, $path);

        return $path;
    }

    private function division(string $region, string $name, string $sds): Division
    {
        $division = (new Division())->setRegion($region)->setName($name)->setSdsName($sds);
        $this->em->persist($division);
        $this->em->flush();

        return $division;
    }

    /** @return list<Division> */
    private function divisions(): array
    {
        $this->em->clear();

        return $this->em->getRepository(Division::class)->findAll();
    }

    public function testAdminAddsADivision(): void
    {
        $crawler = $this->client->request('GET', '/admin/divisions/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Add division')->form([
            'division_form[region]' => 'Region VII',
            'division_form[name]' => 'Mandaue City',
            'division_form[sdsName]' => 'Dr. Maria Santos',
            'division_form[sdsPosition]' => 'Schools Division Superintendent',
        ]);
        $form['division_form[signature]']->upload($this->pngFile());

        $this->client->submit($form);

        self::assertResponseRedirects('/admin/divisions');
        [$division] = $this->divisions();
        self::assertSame('Mandaue City', $division->getName());
        self::assertSame('Dr. Maria Santos', $division->getSdsName());
        self::assertMatchesRegularExpression('#^uploads/signatures/[0-9a-f]{32}\.webp$#', (string) $division->getSignaturePath());
        $absolute = static::getContainer()->getParameter('app.upload_root').'/'.$division->getSignaturePath();
        self::assertFileExists($absolute);
        unlink($absolute);

        $this->client->request('GET', '/admin/divisions');
        self::assertSelectorTextContains('main', 'Mandaue City');
        self::assertSelectorTextContains('main', 'Dr. Maria Santos');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Divisions');
    }

    public function testDuplicateIsRejected(): void
    {
        $this->division('Region VII', 'Mandaue City', 'Dr. Maria Santos');

        $this->client->request('GET', '/admin/divisions/new');
        $this->client->submitForm('Add division', [
            'division_form[region]' => 'Region VII',
            'division_form[name]' => 'Mandaue City',
            'division_form[sdsName]' => 'Someone Else',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This division is already on the list.');
        self::assertCount(1, $this->divisions());
    }

    public function testPositionDefaults(): void
    {
        $this->client->request('GET', '/admin/divisions/new');
        $this->client->submitForm('Add division', [
            'division_form[region]' => 'Region VII',
            'division_form[name]' => 'Cebu City',
            'division_form[sdsName]' => 'Dr. Pedro Reyes',
            'division_form[sdsPosition]' => '',
        ]);

        self::assertResponseRedirects('/admin/divisions');
        self::assertSame('Schools Division Superintendent', $this->divisions()[0]->getSdsPosition());
    }

    public function testDivisionMustBelongToRegion(): void
    {
        $this->client->request('GET', '/admin/divisions/new');
        $this->client->submitForm('Add division', [
            'division_form[region]' => 'Region VIII',
            'division_form[name]' => 'Mandaue City',
            'division_form[sdsName]' => 'Dr. Pedro Reyes',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose a division in the selected region.');
    }

    public function testAdminEditsADivision(): void
    {
        $division = $this->division('Region VII', 'Mandaue City', 'Dr. Maria Santos');

        $this->client->request('GET', '/admin/divisions/'.$division->getId().'/edit');
        $this->client->submitForm('Save division', ['division_form[sdsName]' => 'Dr. Ana Lim']);

        self::assertResponseRedirects('/admin/divisions');
        self::assertSame('Dr. Ana Lim', $this->divisions()[0]->getSdsName());
    }

    public function testNonAdminIsDenied(): void
    {
        $this->client->loginUser($this->createUser('rosa', User::ROLE_PRINCIPAL));

        $this->client->request('GET', '/admin/divisions');

        self::assertResponseStatusCodeSame(403);
    }

    public function testFindForSchool(): void
    {
        $mandaue = $this->division('Region VII', 'Mandaue City', 'Dr. Maria Santos');
        $this->division('Region VII', 'Cebu City', 'Dr. Pedro Reyes');
        $school = $this->school('303456', 'Pagsabungan NHS');
        $other = $this->school('303457', 'Other NHS')->setDivision('Bohol');
        $this->em->flush();

        $repository = static::getContainer()->get(DivisionRepository::class);

        self::assertSame($mandaue->getId(), $repository->findForSchool($school)?->getId());
        self::assertNull($repository->findForSchool($other));
    }
}
