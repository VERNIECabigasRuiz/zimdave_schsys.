<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** The kernel builds its container from scratch, and only for the environments it allows. */
final class KernelBuildTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/kernel-build-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->cacheDir);
    }

    public function testBuildsAFreshContainer(): void
    {
        // Initialize
        $kernel = $this->kernel('test');

        // Execute
        $kernel->boot();

        // Assert
        self::assertSame('test', $kernel->getContainer()->getParameter('kernel.environment'));
        self::assertSame($kernel->getContainer()->getParameter('app.timezone'), date_default_timezone_get());
        $kernel->shutdown();
    }

    public function testRefusesAnEnvironmentItDoesNotAllow(): void
    {
        // Expect
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('is not registered as allowed');

        // Initialize
        $kernel = $this->kernel('staging');

        // Execute
        $kernel->boot();
    }

    /** A kernel that builds its container in a folder of its own, so it never reuses a cached one. */
    private function kernel(string $environment): Kernel
    {
        return new class ($environment, false, $this->cacheDir) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $buildDir)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->buildDir . '/cache';
            }

            public function getLogDir(): string
            {
                return $this->buildDir . '/log';
            }
        };
    }
}
