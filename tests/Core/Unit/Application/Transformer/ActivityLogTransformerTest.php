<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Application\Transformer;

use App\Core\Application\Transformer\ActivityLogTransformer;
use App\Core\Domain\Model\ActivityLog;
use PHPUnit\Framework\TestCase;

final class ActivityLogTransformerTest extends TestCase
{
    public function testShapesAnEntry(): void
    {
        // Initialize
        $entry = new ActivityLog('ana', 'Signed in');

        // Execute
        $shaped = ActivityLogTransformer::toArray($entry);

        // Assert
        self::assertSame(
            ['username' => 'ana', 'action' => 'Signed in', 'createdAt' => $entry->getCreatedAt()],
            $shaped,
        );
    }
}
