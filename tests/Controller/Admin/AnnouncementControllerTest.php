<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Announcement;
use App\Entity\User;

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
}
