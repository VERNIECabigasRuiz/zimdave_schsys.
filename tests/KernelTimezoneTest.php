<?php

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dates from URLs and forms are midnight in PHP's default timezone, "today" is midnight on the
 * school's clock; they only compare right when both are the school's timezone, whatever the
 * server's php.ini says.
 */
class KernelTimezoneTest extends KernelTestCase
{
    private string $serverTimezone;

    protected function setUp(): void
    {
        $this->serverTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        date_default_timezone_set($this->serverTimezone);
    }

    public function testBootUsesTheSchoolTimezone(): void
    {
        date_default_timezone_set('UTC');

        self::bootKernel();

        self::assertSame(static::getContainer()->getParameter('app.timezone'), date_default_timezone_get());
    }
}
