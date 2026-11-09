<?php

declare(strict_types=1);

namespace App\Tests\Announcements\Functional;

use App\Access\Domain\Model\User;
use App\Announcements\Domain\Model\Announcement;
use App\Tests\Controller\Admin\AdminTestCase;

class AnnouncementControllerTest extends AdminTestCase
{
    public function testPostEditAndDeleteAnnouncement(): void
    {
        $this->client->request('GET', '/admin/announcements/new');
        $this->client->submitForm('Post announcement', [
            'announcement_form[title]' => 'Quarter 1 grades due',
            'announcement_form[audience]' => User::ROLE_TEACHER,
            'announcement_form[body]' => "Please submit grades by Friday.\nThank you.",
        ]);
        self::assertResponseRedirects('/admin/announcements');
        $this->client->followRedirect();

        self::assertSelectorTextContains('.announcement', 'Quarter 1 grades due');
        self::assertSelectorTextContains('.announcement', 'Teachers');
        self::assertSelectorTextContains('.announcement', 'Admin');

        $this->client->clickLink('Edit');
        $this->client->submitForm('Save changes', [
            'announcement_form[title]' => 'Quarter 1 grades due Monday',
        ]);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.announcement', 'due Monday');

        $this->client->submit($crawler->selectButton('Delete')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.empty', 'No announcements yet');

        self::assertSame(0, $this->em->getRepository(Announcement::class)->count([]));
        self::assertContains('Posted announcement: Quarter 1 grades due', $this->activityActions());
    }

    public function testAnnouncementNeedsTitleAndMessage(): void
    {
        $this->client->request('GET', '/admin/announcements/new');
        $this->client->submitForm('Post announcement', [
            'announcement_form[title]' => '',
            'announcement_form[body]' => '',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testBlankOnlyTitleIsRefused(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/announcements/new');

        // Execute
        $this->client->submitForm('Post announcement', [
            'announcement_form[title]' => '   ',
            'announcement_form[body]' => 'Please submit grades by Friday.',
        ]);

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->em->getRepository(Announcement::class)->count([]));
    }

    public function testTitleAndMessageAreSavedTrimmed(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/announcements/new');

        // Execute
        $this->submitAnnouncement('Post announcement', '  Grades due  ', '  Friday  ');

        // Assert
        self::assertResponseRedirects('/admin/announcements');
        $announcement = $this->onlyAnnouncement();
        self::assertSame('Grades due', $announcement->getTitle());
        self::assertSame('Friday', $announcement->getBody());
        self::assertContains('Posted announcement: Grades due', $this->activityActions());
    }

    public function testTooLongTitleIsRefused(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/announcements/new');

        // Execute
        $this->submitAnnouncement('Post announcement', str_repeat('a', 151), 'Message');
        $tooLongStatus = $this->client->getResponse()->getStatusCode();
        $this->submitAnnouncement('Post announcement', str_repeat('a', 150), 'Message');

        // Assert
        self::assertSame(422, $tooLongStatus);
        self::assertResponseRedirects('/admin/announcements');
        self::assertSame(150, mb_strlen($this->onlyAnnouncement()->getTitle()));
    }

    public function testAnnouncementOfADeletedAuthorShowsNoName(): void
    {
        // Initialize
        $author = $this->createUser('ana', User::ROLE_ADMIN, 'Ana Cruz');
        $this->em->persist((new Announcement())->setTitle('Grades due')->setBody('Friday')->setAuthor($author));
        $this->em->flush();
        $this->em->getConnection()->executeStatement('DELETE FROM `user` WHERE id = ?', [$author->getId()]);
        $this->em->clear();

        // Execute
        $this->client->request('GET', '/admin/announcements');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.announcement h2', 'Grades due');
        self::assertSelectorTextNotContains('.announcement', 'Ana Cruz');
        self::assertSelectorTextNotContains('.announcement .muted', '·');
    }

    public function testUnknownAnnouncementIs404(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/announcements/new');
        $this->submitAnnouncement('Post announcement', 'Grades due', 'Friday');
        $announcement = $this->onlyAnnouncement();
        $id = $announcement->getId();
        $crawler = $this->client->request('GET', '/admin/announcements');
        $token = $crawler->filter('form[action="/admin/announcements/' . $id . '/delete"] input[name="_token"]')
            ->attr('value');
        $this->em->remove($announcement);
        $this->em->flush();

        // Execute
        $this->client->request('GET', '/admin/announcements/' . $id . '/edit');
        $editStatus = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/admin/announcements/' . $id . '/edit', ['announcement_form' => []]);
        $editPostStatus = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/admin/announcements/' . $id . '/delete', ['_token' => $token]);

        // Assert
        self::assertSame(404, $editStatus);
        self::assertSame(404, $editPostStatus);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRefusedEditKeepsTheEditPage(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/announcements/new');
        $this->submitAnnouncement('Post announcement', 'Grades due', 'Friday');
        $id = $this->onlyAnnouncement()->getId();
        $this->client->request('GET', '/admin/announcements/' . $id . '/edit');

        // Execute
        $this->submitAnnouncement('Save changes', '', 'New text');

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('h1', 'Edit announcement');
        self::assertSelectorTextContains('button[type="submit"]', 'Save changes');
        self::assertSelectorTextContains('textarea[name="announcement_form[body]"]', 'New text');
        self::assertSame('Grades due', $this->onlyAnnouncement()->getTitle());
    }

    public function testUpdateLogsTheNewTitle(): void
    {
        // Initialize
        $this->client->request('GET', '/admin/announcements/new');
        $this->submitAnnouncement('Post announcement', 'Grades due', 'Friday');
        $this->client->request('GET', '/admin/announcements/' . $this->onlyAnnouncement()->getId() . '/edit');

        // Execute
        $this->submitAnnouncement('Save changes', 'Grades due Monday', 'Monday');

        // Assert
        self::assertResponseRedirects('/admin/announcements');
        self::assertContains('Updated announcement: Grades due Monday', $this->activityActions());
    }

    private function submitAnnouncement(string $button, string $title, string $body): void
    {
        $this->client->submitForm($button, [
            'announcement_form[title]' => $title,
            'announcement_form[body]' => $body,
        ]);
    }

    /** The one stored announcement, freshly loaded. */
    private function onlyAnnouncement(): Announcement
    {
        $this->em->clear();
        $announcements = $this->em->getRepository(Announcement::class)->findAll();
        self::assertCount(1, $announcements);

        return $announcements[0];
    }
}
