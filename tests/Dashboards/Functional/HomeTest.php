<?php

declare(strict_types=1);

namespace App\Tests\Dashboards\Functional;

use App\Tests\Support\AdminTestCase;

final class HomeTest extends AdminTestCase
{
    public function testAnAccountWithoutASchoolRoleSeesTheWelcomePage(): void
    {
        // Initialize
        $this->client->loginUser($this->createUser('guest', '', 'Gina Guest'));

        // Execute
        $this->client->request('GET', '/');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Hello, Gina Guest');
    }
}
