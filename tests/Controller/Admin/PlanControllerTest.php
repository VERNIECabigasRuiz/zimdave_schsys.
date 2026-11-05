<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Plan;
use App\Entity\User;

class PlanControllerTest extends AdminTestCase
{
    private function findPlan(string $name): ?Plan
    {
        $this->em->clear();

        return $this->em->getRepository(Plan::class)->findOneBy(['name' => $name]);
    }

    private function submitNewPlan(string $name, int $teachers, int $students, bool $principal): void
    {
        $this->client->request('GET', '/admin/plans/new');
        $form = $this->client->getCrawler()->selectButton('Create plan')->form([
            'plan_form[name]' => $name,
            'plan_form[teachersPerSeat]' => (string) $teachers,
            'plan_form[studentsPerSeat]' => (string) $students,
        ]);
        if ($principal) {
            $form['plan_form[includesPrincipal]']->tick();
        } else {
            $form['plan_form[includesPrincipal]']->untick();
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

        $plan = $this->findPlan('Standard');
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
            $this->client->request('GET', '/admin/plans/'.$basic->getId().'/edit');
            $this->client->submitForm('Save plan', ['plan_form[teachersPerSeat]' => '20']);
            self::assertResponseRedirects('/admin/plans');

            self::assertSame(20, $this->findPlan('Basic')->getTeachersPerSeat());
            self::assertContains('Updated plan Basic', $this->activityActions());
        } finally {
            // The seeded plans are shared by every test
            $this->findPlan('Basic')->setTeachersPerSeat(15);
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

    public function testPrincipalCannotOpenPlans(): void
    {
        $this->client->loginUser($this->createUser('maria', User::ROLE_PRINCIPAL));

        $this->client->request('GET', '/admin/plans');

        self::assertResponseStatusCodeSame(403);
    }
}
