<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolTransferControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    /** The receiving school (Maria). */
    private School $school;
    /** The school being left (Pedro). */
    private School $other;
    private User $maria;
    private User $pedro;
    private Section $rosal;
    private Section $mabini;
    private Learner $ana;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-28');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->other = $this->school('303457', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $this->maria);
        $this->pedro = $this->createUser('pedro', User::ROLE_PRINCIPAL, 'Pedro Cruz');
        $this->assign($this->other, $this->sy, $this->pedro);
        $this->rosal = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rosal');
        $this->mabini = $this->newSection($this->other, $this->sy, GradeLevel::Grade7, 'Mabini');
        $this->ana = $this->learner('111111111111', 'Abad', 'Ana');
        $this->enroll($this->ana, $this->sy, $this->mabini, $this->pedro);
        $this->client->loginUser($this->maria);
    }

    private function url(School $school, string $suffix = ''): string
    {
        return '/schools/'.$school->getId().'/transfers'.$suffix;
    }

    private function pending(?Learner $learner = null): TransferRequest
    {
        $learner ??= $this->ana;
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner, 'schoolYear' => $this->sy]);
        $request = new TransferRequest($enrollment, $this->rosal, $this->maria, new \DateTimeImmutable('2026-10-27'));
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    private function reload(TransferRequest $request): TransferRequest
    {
        $this->em->clear();

        return $this->em->getRepository(TransferRequest::class)->find($request->getId());
    }

    private function currentEnrollment(Learner $learner): Enrollment
    {
        $this->em->clear();

        return $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId(), 'schoolYear' => $this->sy->getId()]);
    }

    /** Posts $action on $request at $school's transfer page with a real token. */
    private function post(School $school, TransferRequest $request, string $action): void
    {
        $this->client->request('GET', '/profile');
        $token = $this->csrfToken('transfer-'.$request->getId());
        $this->client->request('POST', $this->url($school, '/'.$request->getId().'/'.$action), ['_token' => $token]);
    }

    public function testPrincipalRequestsTransfer(): void
    {
        $this->client->request('GET', '/schools/'.$this->school->getId().'/learners/enroll?lrn=111111111111');
        $this->client->submitForm('Request transfer', ['enroll_section_form[section]' => (string) $this->rosal->getId()]);
        self::assertResponseRedirects('/schools/'.$this->school->getId().'/learners');

        $crawler = $this->client->followRedirect();
        $this->client->click($crawler->selectLink('Transfer requests')->link());
        self::assertResponseIsSuccessful();
        $this->client->clickLink('Incoming');
        self::assertSelectorTextContains('.table', 'Abad, Ana');
        self::assertSelectorTextContains('.table', 'Waiting for Other NHS');
        self::assertSelectorTextContains('.table', 'Grade 7 – Rosal');
        self::assertCount(1, $this->client->getCrawler()->selectButton('Cancel'));
        self::assertCount(0, $this->client->getCrawler()->selectButton('Approve'));
    }

    public function testOldPrincipalApproves(): void
    {
        $request = $this->pending();
        $this->client->loginUser($this->pedro);

        $crawler = $this->client->request('GET', '/principal');
        self::assertSelectorTextContains('.stats', 'Transfer requests waiting: 1');
        $this->client->click($crawler->selectLink('Transfer requests waiting: 1')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.table', 'Abad, Ana');
        self::assertSelectorTextContains('.table', 'Pagsabungan NHS');

        $this->client->submitForm('Approve');
        self::assertResponseRedirects($this->url($this->other, '?tab=outgoing'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Approved transfer of Abad, Ana to Pagsabungan NHS.');
        self::assertSelectorTextContains('.table', 'Approved');

        self::assertSame(TransferStatus::Approved, $this->reload($request)->getStatus());
        $enrollment = $this->currentEnrollment($this->ana);
        self::assertSame($this->rosal->getId(), $enrollment->getSection()->getId());
        self::assertSame($this->school->getId(), $enrollment->getSchool()->getId());

        $this->client->request('GET', '/principal');
        self::assertSelectorTextNotContains('main', 'Transfer requests waiting');
    }

    public function testDecline(): void
    {
        $request = $this->pending();
        $this->client->loginUser($this->pedro);

        $this->client->request('GET', $this->url($this->other, '?tab=outgoing'));
        $this->client->submitForm('Decline');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Declined transfer of Abad, Ana.');

        self::assertSame(TransferStatus::Declined, $this->reload($request)->getStatus());
        self::assertSame($this->mabini->getId(), $this->currentEnrollment($this->ana)->getSection()->getId());

        $this->client->request('GET', '/schools/'.$this->other->getId().'/learners/'.$this->ana->getId());
        self::assertSelectorTextContains('.transfers', 'Declined');

        $this->client->loginUser($this->maria);
        $this->client->request('GET', $this->url($this->school, '?tab=incoming'));
        self::assertSelectorTextContains('.table', 'Declined');
    }

    public function testCancelByReceivingPrincipal(): void
    {
        $request = $this->pending();

        $this->client->request('GET', $this->url($this->school, '?tab=incoming'));
        $this->client->submitForm('Cancel');
        self::assertResponseRedirects($this->url($this->school, '?tab=incoming'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Cancelled transfer request for Abad, Ana.');

        self::assertSame(TransferStatus::Cancelled, $this->reload($request)->getStatus());
        self::assertSelectorTextContains('.table', 'Cancelled');
    }

    public function testReceivingPrincipalCannotApprove(): void
    {
        $request = $this->pending();

        // the request leaves Other NHS: Maria has no say there
        $this->post($this->other, $request, 'approve');
        self::assertResponseStatusCodeSame(403);
        $this->post($this->other, $request, 'decline');
        self::assertResponseStatusCodeSame(403);
        // and it does not leave her own school
        $this->post($this->school, $request, 'approve');
        self::assertResponseStatusCodeSame(404);

        // the school being left cannot cancel what it did not send
        $this->client->loginUser($this->pedro);
        $this->post($this->other, $request, 'cancel');
        self::assertResponseStatusCodeSame(404);
        $this->post($this->school, $request, 'cancel');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(TransferStatus::Pending, $this->reload($request)->getStatus());
        self::assertSame($this->mabini->getId(), $this->currentEnrollment($this->ana)->getSection()->getId());
    }

    public function testAdviserApprovesOnPlanWithoutPrincipal(): void
    {
        $this->other->setPlan($this->limitedPlan('Solo', 50, includesPrincipal: false));
        $tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->other, $this->sy, $tess, User::ROLE_TEACHER);
        $this->mabini->setAdviser($tess);
        $this->em->flush();
        $request = $this->pending();
        $this->client->loginUser($tess);

        $crawler = $this->client->request('GET', '/teacher');
        self::assertSelectorTextContains('main', 'Transfer requests waiting: 1');
        $crawler = $this->client->click($crawler->selectLink('Transfer requests waiting: 1')->link());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectLink('Incoming'));
        self::assertSelectorTextContains('.table', 'Abad, Ana');

        $this->client->submitForm('Approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Approved transfer of Abad, Ana to Pagsabungan NHS.');
        self::assertSame(TransferStatus::Approved, $this->reload($request)->getStatus());
        self::assertSame($this->rosal->getId(), $this->currentEnrollment($this->ana)->getSection()->getId());

        // the incoming tab is not theirs
        $this->client->request('GET', $this->url($this->other, '?tab=incoming'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdviserSeesAndDecidesOnlyTheirSection(): void
    {
        $this->other->setPlan($this->limitedPlan('Solo', 50, includesPrincipal: false));
        $tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->other, $this->sy, $tess, User::ROLE_TEACHER);
        $this->mabini->setAdviser($tess);
        $lito = $this->createUser('lito', User::ROLE_TEACHER, 'Lito Lim');
        $this->assign($this->other, $this->sy, $lito, User::ROLE_TEACHER);
        $luna = $this->newSection($this->other, $this->sy, GradeLevel::Grade7, 'Luna');
        $luna->setAdviser($lito);
        $this->em->flush();
        $ben = $this->learner('222222222222', 'Bautista', 'Ben');
        $this->enroll($ben, $this->sy, $luna, $lito);
        $this->pending();
        $theirs = $this->pending($ben);
        $this->client->loginUser($tess);

        $crawler = $this->client->request('GET', '/teacher');
        self::assertSelectorTextContains('main', 'Transfer requests waiting: 1');
        $this->client->click($crawler->selectLink('Transfer requests waiting: 1')->link());
        self::assertSelectorTextContains('.table', 'Abad, Ana');
        self::assertSelectorTextNotContains('main', 'Bautista');
        self::assertSelectorTextNotContains('main', '222222222222');

        $this->post($this->other, $theirs, 'approve');
        self::assertResponseStatusCodeSame(403);
        $this->post($this->other, $theirs, 'decline');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(TransferStatus::Pending, $this->reload($theirs)->getStatus());
        self::assertSame($luna->getId(), $this->currentEnrollment($ben)->getSection()->getId());
    }

    public function testAdviserReachesTransfersFromTheirClass(): void
    {
        $this->other->setPlan($this->limitedPlan('Solo', 50, includesPrincipal: false));
        $tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->other, $this->sy, $tess, User::ROLE_TEACHER);
        $this->mabini->setAdviser($tess);
        $this->em->flush();
        $this->client->loginUser($tess);

        $crawler = $this->client->request('GET', '/teacher/class');
        $this->client->click($crawler->selectLink('Transfer requests')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No transfer requests out of Grade 7 – Mabini');
    }

    public function testAdviserOnPlanWithPrincipalHasNoTransfersLinkOnTheirClass(): void
    {
        $tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->other, $this->sy, $tess, User::ROLE_TEACHER);
        $this->mabini->setAdviser($tess);
        $this->em->flush();
        $this->client->loginUser($tess);

        $crawler = $this->client->request('GET', '/teacher/class');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectLink('Transfer requests'));
    }

    public function testDecidingAnAlreadyDecidedRequestSaysSo(): void
    {
        $request = $this->pending();
        $request->decide(TransferStatus::Declined, $this->pedro, new \DateTimeImmutable('2026-10-27'));
        $this->em->flush();

        $this->client->loginUser($this->pedro);
        foreach (['approve', 'decline'] as $action) {
            $this->post($this->other, $request, $action);
            self::assertResponseRedirects($this->url($this->other, '?tab=outgoing'), null, $action);
            $this->client->followRedirect();
            self::assertSelectorTextContains('.alert--error', 'This request was already decided.');
        }

        $this->client->loginUser($this->maria);
        $this->post($this->school, $request, 'cancel');
        self::assertResponseRedirects($this->url($this->school, '?tab=incoming'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'This request was already decided.');

        self::assertSame(TransferStatus::Declined, $this->reload($request)->getStatus());
        self::assertSame($this->mabini->getId(), $this->currentEnrollment($this->ana)->getSection()->getId());
    }

    public function testOutsiderPostingToADecidedRequestIs403(): void
    {
        $request = $this->pending();
        $request->decide(TransferStatus::Declined, $this->pedro, new \DateTimeImmutable('2026-10-27'));
        $this->em->flush();

        // Maria may not decide what leaves Other NHS, decided or not
        $this->post($this->other, $request, 'approve');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdviserOnPlanWithPrincipalIs403(): void
    {
        $tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->other, $this->sy, $tess, User::ROLE_TEACHER);
        $this->mabini->setAdviser($tess);
        $this->em->flush();
        $request = $this->pending();
        $this->client->loginUser($tess);

        $this->client->request('GET', '/teacher');
        self::assertSelectorTextNotContains('main', 'Transfer requests waiting');
        $this->client->request('GET', $this->url($this->other));
        self::assertResponseStatusCodeSame(403);
        $this->post($this->other, $request, 'approve');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(TransferStatus::Pending, $this->reload($request)->getStatus());
    }

    public function testApproveRefusedWhenFull(): void
    {
        $this->school->setPlan($this->limitedPlan('Tiny', 1));
        $this->em->flush();
        $this->enroll($this->learner('222222222222', 'Bautista', 'Ben'), $this->sy, $this->rosal, $this->maria);
        $request = $this->pending();
        $this->client->loginUser($this->pedro);

        $this->client->request('GET', $this->url($this->other, '?tab=outgoing'));
        $this->client->submitForm('Approve');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'Approval refused: The Tiny plan allows 1 student(s) (1 used).');

        self::assertSame(TransferStatus::Pending, $this->reload($request)->getStatus());
        self::assertSame($this->mabini->getId(), $this->currentEnrollment($this->ana)->getSection()->getId());
    }

    public function testTeacherCannotRequest(): void
    {
        $teacher = $this->createUser('ana', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $teacher, User::ROLE_TEACHER);
        $this->client->loginUser($teacher);

        $this->client->request('GET', '/profile');
        $token = $this->csrfToken('enroll_section_form');
        $this->client->request('POST', $this->url($this->school, '/request?lrn=111111111111'), [
            'enroll_section_form' => ['section' => (string) $this->rosal->getId(), '_token' => $token],
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->url($this->school));
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(TransferRequest::class)->count([]));
    }

    public function testAdminSeesBothTabsAndDecides(): void
    {
        $request = $this->pending();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', $this->url($this->other, '?tab=outgoing'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->selectLink('Incoming'));
        $this->client->submitForm('Decline');
        self::assertSame(TransferStatus::Declined, $this->reload($request)->getStatus());
    }
}
