<?php

declare(strict_types=1);

namespace App\Tests\Staff\Unit\Presentation;

use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Staff\Presentation\Security\ServiceRecordVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class ServiceRecordVoterTest extends TestCase
{
    public function testSignedOutVisitorsAreDenied(): void
    {
        // Initialize
        $voter = new ServiceRecordVoter(self::createStub(SchoolDirectoryInterface::class));

        // Execute
        $view = $voter->vote(new NullToken(), new School(), [ServiceRecordVoter::VIEW]);
        $edit = $voter->vote(new NullToken(), new School(), [ServiceRecordVoter::EDIT]);

        // Assert
        self::assertSame([VoterInterface::ACCESS_DENIED, VoterInterface::ACCESS_DENIED], [$view, $edit]);
    }
}
