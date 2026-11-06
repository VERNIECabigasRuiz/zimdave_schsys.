<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\SexAtBirth;
use App\Entity\User;
use Symfony\Component\Panther\Client;

final class RoleSmokeTest extends BrowserTestCase
{
    public function testAdminFlow(): void
    {
        // Initialize
        $this->createUser('zim', User::ROLE_ADMIN);
        $client = static::createPantherClient();

        // Execute
        $this->logIn($client, 'zim');
        $this->openFirstSideLink($client);

        // Assert
        $this->assertPageLoaded($client);
    }

    public function testPrincipalFlow(): void
    {
        // Initialize
        $schoolYear = $this->schoolYear((int) date('Y') - ((int) date('n') < 6 ? 1 : 0), true);
        $school = $this->school('300001', 'Smoke Test High School');
        $this->assign($school, $schoolYear, $this->createUser('principal', User::ROLE_PRINCIPAL));
        $client = static::createPantherClient();

        // Execute
        $this->logIn($client, 'principal');
        $this->openFirstSideLink($client);

        // Assert
        $this->assertPageLoaded($client);
    }

    public function testTeacherFlow(): void
    {
        // Initialize
        $schoolYear = $this->schoolYear((int) date('Y') - ((int) date('n') < 6 ? 1 : 0), true);
        $school = $this->school('300001', 'Smoke Test High School');
        $this->assign($school, $schoolYear, $this->createUser('teacher', User::ROLE_TEACHER), User::ROLE_TEACHER);
        $client = static::createPantherClient();

        // Execute
        $this->logIn($client, 'teacher');
        $this->openFirstSideLink($client);

        // Assert
        $this->assertPageLoaded($client);
    }

    public function testStudentFlow(): void
    {
        // Initialize
        $schoolYear = $this->schoolYear((int) date('Y') - ((int) date('n') < 6 ? 1 : 0), true);
        $school = $this->school('300001', 'Smoke Test High School');
        $section = $this->newSection($school, $schoolYear, GradeLevel::Grade7, 'Narra');
        $student = $this->createUser('123456789012', User::ROLE_STUDENT);
        $learner = (new Learner($student, new \DateTimeImmutable()))
            ->setLrn('123456789012')
            ->setLastName('Reyes')
            ->setFirstName('Ana')
            ->setSex(SexAtBirth::Female)
            ->setBirthDate(new \DateTimeImmutable('2013-05-01'));
        $this->em->persist($learner);
        $this->em->flush();
        $this->enroll($learner, $schoolYear, $section, $student);
        $client = static::createPantherClient();

        // Execute
        $this->logIn($client, '123456789012');
        $this->openFirstSideLink($client);

        // Assert
        $this->assertPageLoaded($client);
    }

    public function testPagesKeepTurboAndPrefetchSetting(): void
    {
        // Initialize
        $this->createUser('zim', User::ROLE_ADMIN);
        $client = static::createPantherClient();

        // Execute
        $this->logIn($client, 'zim');
        $turbo = $client->executeScript('return typeof window.Turbo');
        $prefetch = $client->getCrawler()->filter('meta[name="turbo-prefetch"]')->attr('content');

        // Assert
        self::assertSame('object', $turbo);
        self::assertSame('false', $prefetch);
    }

    /** Clicks the first side menu link to another page (the only one, for students). */
    private function openFirstSideLink(Client $client): void
    {
        $links = $client->getCrawler()->filter('.sidebar__nav a:not([aria-current])');
        if (0 === $links->count()) {
            $links = $client->getCrawler()->filter('.sidebar__nav a');
        }
        $href = (string) $links->first()->attr('href');

        $links->first()->click();
        $client->waitFor(\sprintf('.sidebar__nav a[aria-current="page"][href="%s"]', $href));
    }

    private function assertPageLoaded(Client $client): void
    {
        self::assertGreaterThan(0, $client->getCrawler()->filter('h1')->count());
        self::assertDoesNotMatchRegularExpression('/error|exception/i', $client->getTitle());
    }
}
