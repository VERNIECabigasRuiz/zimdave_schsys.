<?php

namespace App\Tests\Billing\Functional;

use App\Access\Domain\Model\User;
use App\Billing\Domain\Model\Plan;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

class PlanControllerTest extends AdminTestCase
{
    private function findPlan(string $name): ?Plan
    {
        $this->em->clear();

        return $this->em->getRepository(Plan::class)->findOneBy(['name' => $name]);
    }

    /** The plan, freshly loaded; the test fails when there is none. */
    private function requirePlan(string $name): Plan
    {
        $plan = $this->findPlan($name);
        self::assertNotNull($plan, \sprintf('No plan "%s".', $name));

        return $plan;
    }

    private function submitNewPlan(string $name, int $teachers, int $students, bool $principal): void
    {
        $this->client->request('GET', '/admin/plans/new');
        $form = $this->client->getCrawler()->selectButton('Create plan')->form([
            'plan_form[name]' => $name,
            'plan_form[teachersPerSeat]' => (string) $teachers,
            'plan_form[studentsPerSeat]' => (string) $students,
        ]);
        $includesPrincipal = $form['plan_form[includesPrincipal]'];
        self::assertInstanceOf(ChoiceFormField::class, $includesPrincipal);
        if ($principal) {
            $includesPrincipal->tick();
        } else {
            $includesPrincipal->untick();
        }
        $this->client->submit($form);
    }

    public function testSeededPlansAreListed(): void
    {
        $crawler = $this->client->request('GET', '/admin/plans');

        $basic = $crawler->filter('tr:contains("Basic")')->text();
        self::assertStringContainsString('15', $basic);
        self::assertStringContainsString('1,000', $basic);
        self::assertStringContainsString('Yes', $basic);

        $individual = $crawler->filter('tr:contains("Individual")')->text();
        self::assertStringContainsString('65', $individual);
        self::assertStringContainsString('No', $individual);
    }

    public function testAdminCreatesPlan(): void
    {
        $this->submitNewPlan('Standard', 40, 2500, true);
        self::assertResponseRedirects('/admin/plans');

        $plan = $this->requirePlan('Standard');
        self::assertSame(40, $plan->getTeachersPerSeat());
        self::assertSame(2500, $plan->getStudentsPerSeat());
        self::assertTrue($plan->includesPrincipal());
        self::assertContains('Created plan Standard', $this->activityActions());
    }

    public function testDuplicatePlanNameRejected(): void
    {
        $this->submitNewPlan('Basic', 1, 1, true);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'This plan already exists.');
    }

    public function testAdminEditsPlan(): void
    {
        $basic = $this->plan('Basic');

        try {
            $this->client->request('GET', '/admin/plans/' . $basic->getId() . '/edit');
            $this->client->submitForm('Save plan', ['plan_form[teachersPerSeat]' => '20']);
            self::assertResponseRedirects('/admin/plans');

            self::assertSame(20, $this->requirePlan('Basic')->getTeachersPerSeat());
            self::assertContains('Updated plan Basic', $this->activityActions());
        } finally {
            // The seeded plans are shared by every test
            $this->requirePlan('Basic')->setTeachersPerSeat(15);
            $this->em->flush();
        }
    }

    public function testDeleteUnusedPlan(): void
    {
        $this->submitNewPlan('Trial', 1, 10, false);

        $crawler = $this->client->request('GET', '/admin/plans');
        $this->client->submit($crawler->filter('tr:contains("Trial")')->selectButton('Delete')->form());
        self::assertResponseRedirects('/admin/plans');

        self::assertNull($this->findPlan('Trial'));
        self::assertContains('Deleted plan Trial', $this->activityActions());
    }

    public function testDeletePlanInUseRefused(): void
    {
        $this->school('303456', 'Pagsabungan NHS');

        $crawler = $this->client->request('GET', '/admin/plans');
        $this->client->submit($crawler->filter('tr:contains("Basic")')->selectButton('Delete')->form());
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--error', 'Basic is used by 1 school(s) and cannot be deleted.');
        self::assertNotNull($this->findPlan('Basic'));
    }

    public function testDuplicateNameIsRefusedWithTheOtherErrors(): void
    {
        // Initialize
        $individual = $this->plan('Individual');
        $this->client->request('GET', '/admin/plans/new');

        // Execute
        $this->client->submitForm('Create plan', [
            'plan_form[name]' => 'Basic',
            'plan_form[teachersPerSeat]' => '',
            'plan_form[studentsPerSeat]' => '0',
        ]);
        $createStatus = $this->client->getResponse()->getStatusCode();
        $createText = $this->client->getCrawler()->filter('.form-card')->text();
        $this->client->request('GET', '/admin/plans/' . $individual->getId() . '/edit');
        $this->client->submitForm('Save plan', ['plan_form[name]' => 'Basic']);

        // Assert
        self::assertSame(422, $createStatus);
        self::assertStringContainsString('This plan already exists.', $createText);
        self::assertStringContainsString('This value should not be null.', $createText);
        self::assertStringContainsString('This value should be positive.', $createText);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'This plan already exists.');
        self::assertNotNull($this->findPlan('Individual'), 'The edit was not saved');
    }

    public function testEditKeepsItsOwnName(): void
    {
        // Initialize
        $basic = $this->plan('Basic');
        $this->client->request('GET', '/admin/plans/' . $basic->getId() . '/edit');

        // Execute
        $this->client->submitForm('Save plan');

        // Assert
        self::assertResponseRedirects('/admin/plans');
    }

    public function testUnknownPlanIs404(): void
    {
        // Initialize
        $this->submitNewPlan('Trial', 1, 10, false);
        $trial = $this->findPlan('Trial');
        self::assertNotNull($trial);
        $id = $trial->getId();
        $crawler = $this->client->request('GET', '/admin/plans');
        $token = $crawler->filter('form[action="/admin/plans/' . $id . '/delete"] input[name="_token"]')->attr('value');
        $this->em->remove($trial);
        $this->em->flush();

        // Execute
        $this->client->request('GET', '/admin/plans/' . $id . '/edit');
        $editStatus = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/admin/plans/' . $id . '/delete', ['_token' => $token]);

        // Assert
        self::assertSame(404, $editStatus);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPrincipalCannotOpenPlans(): void
    {
        $this->client->loginUser($this->createUser('maria', User::ROLE_PRINCIPAL));

        $this->client->request('GET', '/admin/plans');

        self::assertResponseStatusCodeSame(403);
    }
}
