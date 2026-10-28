<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

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
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
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

    private function csrfToken(string $id): string
    {
        static::getContainer()->get('request_stack')->push($this->client->getRequest());
        $token = static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        $this->client->getRequest()->getSession()->save();

        return $token;
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
        $learner = $this->findLearner('123456789012');
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId()]);
        self::assertSame($this->sampaguita->getId(), $enrollment->getSection()->getId());
    }

    public function testAdviserCannotPostAnotherSection(): void
    {
        $this->client->request('GET', '/teacher/class/enroll?lrn=123456789012');
        $form = $this->client->getCrawler()->selectButton('Enroll')->form($this->newLearnerFields());
        $values = $form->getPhpValues();
        $values['enroll_section_form']['section'] = (string) $this->rosal->getId();
        $this->client->request('POST', '/teacher/class/enroll?lrn=123456789012', $values);

        self::assertFalse($this->client->getResponse()->isRedirect());
        self::assertNull($this->findLearner('123456789012'));
        self::assertSame(0, $this->enrollmentCount());
    }

    public function testEnrolledElsewhereRefusedForAdviser(): void
    {
        $other = $this->school('303457', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->enrolled('111111111111', 'Abad', 'Ana', $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini'));

        $crawler = $this->client->request('GET', '/teacher/class/enroll?lrn=111111111111');
        self::assertSelectorTextContains('.card', 'Abad, Ana is enrolled at Other NHS. Ask your principal to request a transfer.');
        self::assertCount(0, $crawler->selectButton('Request transfer'));
        self::assertCount(0, $crawler->selectButton('Enroll'));

        $this->school->setPlan($this->limitedPlan('Solo', 40, includesPrincipal: false));
        $this->em->flush();
        $this->client->request('GET', '/teacher/class/enroll?lrn=111111111111');
        self::assertSelectorTextContains('.card', 'Abad, Ana is enrolled at Other NHS. Ask the administrator to transfer them.');
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

    public function testFormerAdviserIsForbidden(): void
    {
        $crawler = $this->client->request('GET', '/teacher');
        self::assertCount(1, $crawler->selectLink('My class'));
        $this->client->request('GET', '/teacher/class');
        self::assertResponseIsSuccessful();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->find(Section::class, $this->sampaguita->getId())->setAdviser(null);
        $this->em->flush();

        $this->client->request('GET', '/teacher/class');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/teacher/class/enroll');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdviserCannotMoveOrChangeLrn(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $crawler = $this->client->request('GET', '/teacher/class/'.$learner->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->selectButton('Move'));
        self::assertCount(1, $crawler->selectButton('Unenroll'));

        $crawler = $this->client->request('GET', '/teacher/class/'.$learner->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="learner_form[lrn]"]'));

        $this->client->request('POST', '/schools/'.$this->school->getId().'/learners/'.$learner->getId().'/move', [
            'section' => $this->rosal->getId(),
            '_token' => $this->csrfToken('learner-'.$learner->getId()),
        ]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId()]);
        self::assertSame($this->sampaguita->getId(), $enrollment->getSection()->getId());
    }

    public function testAdviserEditsUnenrollsAndResets(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $id = $learner->getId();

        $this->client->request('GET', '/teacher/class/'.$id.'/edit');
        $this->client->submitForm('Save', ['learner_form[firstName]' => 'Anna']);
        self::assertResponseRedirects('/teacher/class/'.$id);

        $this->client->request('GET', '/teacher/class/'.$id);
        $this->client->submitForm('Reset password');
        self::assertResponseRedirects('/teacher/class/'.$id);
        $this->client->followRedirect();
        self::assertSelectorExists('.credentials');

        $this->client->submitForm('Unenroll');
        self::assertResponseRedirects('/teacher/class');
        self::assertSame(0, $this->enrollmentCount());
    }

    public function testAdviserCannotOpenLearnerOfAnotherSection(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana', $this->rosal);

        $this->client->request('GET', '/teacher/class/'.$learner->getId());
        self::assertResponseStatusCodeSame(404);
    }
}
