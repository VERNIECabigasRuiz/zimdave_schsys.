<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\User;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class LearnerControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $pagsabungan;
    private School $mandaue;
    private Section $sampaguita;
    private Section $rosal;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-28');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->pagsabungan = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->mandaue = $this->school('303457', 'Mandaue NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->sampaguita = $this->newSection($this->pagsabungan, $this->sy, GradeLevel::Grade7, 'Sampaguita');
        $this->rosal = $this->newSection($this->mandaue, $this->sy, GradeLevel::Grade7, 'Rosal');
    }

    public function testSearchByLrnAcrossSchools(): void
    {
        $learner = $this->learner('123456789012', 'Reyes', 'Ana');
        $this->enroll($learner, $this->sy, $this->sampaguita, $this->admin);

        $crawler = $this->client->request('GET', '/admin/learners?q=123456789012');

        self::assertResponseIsSuccessful();
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Reyes, Ana', $text);
        self::assertStringContainsString('Pagsabungan NHS', $text);
        self::assertStringContainsString('Sampaguita', $text);
        $link = $crawler->selectLink('Reyes, Ana')->link()->getUri();
        self::assertStringEndsWith('/schools/'.$this->pagsabungan->getId().'/learners/'.$learner->getId(), $link);
    }

    public function testSearchUnknownLrnFindsNothing(): void
    {
        $this->client->request('GET', '/admin/learners?q=999999999999');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No learner found');
    }

    public function testAdminTransfersDirectly(): void
    {
        $learner = $this->learner('123456789012', 'Reyes', 'Ana');
        $this->enroll($learner, $this->sy, $this->sampaguita, $this->admin);
        $page = '/schools/'.$this->pagsabungan->getId().'/learners/'.$learner->getId();

        $this->client->request('GET', $page.'?to_school='.$this->mandaue->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action$="/transfer"] select[name="section"]');

        $this->client->request('POST', $page.'/transfer', [
            '_token' => $this->csrfToken('learner-'.$learner->getId()),
            'section' => (string) $this->rosal->getId(),
        ]);

        self::assertResponseRedirects($page);
        $this->em->clear();
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId(), 'schoolYear' => $this->sy->getId()]);
        self::assertSame($this->rosal->getId(), $enrollment->getSection()->getId());
        self::assertSame(0, $this->em->getRepository(TransferRequest::class)->count([]));
        self::assertContains('Transferred 123456789012 from Pagsabungan NHS to Mandaue NHS', $this->activityActions());
    }

    public function testTransferToSameSchoolSectionIsNotFound(): void
    {
        $learner = $this->learner('123456789012', 'Reyes', 'Ana');
        $this->enroll($learner, $this->sy, $this->sampaguita, $this->admin);
        $other = $this->newSection($this->pagsabungan, $this->sy, GradeLevel::Grade8, 'Dahlia');
        $page = '/schools/'.$this->pagsabungan->getId().'/learners/'.$learner->getId();

        $this->client->request('GET', $page);
        $this->client->request('POST', $page.'/transfer', [
            '_token' => $this->csrfToken('learner-'.$learner->getId()),
            'section' => (string) $other->getId(),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testPrincipalCannotOpenAdminSearch(): void
    {
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->pagsabungan, $this->sy, $maria);
        $this->client->loginUser($maria);

        $this->client->request('GET', '/admin/learners');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPrincipalCannotPostDirectTransfer(): void
    {
        $maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->pagsabungan, $this->sy, $maria);
        $learner = $this->learner('123456789012', 'Reyes', 'Ana');
        $this->enroll($learner, $this->sy, $this->sampaguita, $maria);
        $this->client->loginUser($maria);
        $page = '/schools/'.$this->pagsabungan->getId().'/learners/'.$learner->getId();

        $this->client->request('GET', $page);
        $this->client->request('POST', $page.'/transfer', [
            '_token' => $this->csrfToken('learner-'.$learner->getId()),
            'section' => (string) $this->rosal->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId()]);
        self::assertSame($this->sampaguita->getId(), $enrollment->getSection()->getId());
    }
}
