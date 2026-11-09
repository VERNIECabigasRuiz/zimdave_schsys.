<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Application;

use App\Access\Application\Api\AccountDirectoryInterface;
use App\Access\Domain\Model\User;
use App\Core\Domain\Exception\ImageProcessingException;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Schools\Application\Query\Command\GetSchoolProfileCommand;
use App\Schools\Application\Query\Handler\GetSchoolProfileHandler;
use App\Schools\Application\Service\PrincipalSchoolFinder;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\UseCase\Command\UpdateSchoolProfileCommand;
use App\Schools\Application\UseCase\Handler\UpdateSchoolProfileHandler;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

final class SchoolProfileHandlersTest extends TestCase
{
    private User $principal;

    protected function setUp(): void
    {
        $this->principal = (new User())->setFullName('Maria Santos');
    }

    public function testSavesTheFieldsTrimmedToNull(): void
    {
        // Initialize
        $school = (new School())->setName('Alpha NHS');
        $command = UpdateSchoolProfileCommand::fromSchool(
            7,
            SchoolTransformer::toArray($school, new \DateTimeImmutable()),
        );
        $command->address = ' Centro ';
        $command->email = ' ';
        $command->lpCheckedByName = 'Cora';
        $handler = $this->handler($school, null, 'Updated school profile Alpha NHS');

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => 'Alpha NHS'], $result->data());
        self::assertSame('Centro', $school->getAddress());
        self::assertNull($school->getEmail());
        self::assertSame('Cora', $school->getLpCheckedByName());
        self::assertSame(['userId' => 7, 'logo' => false, 'idSignature' => false], $command->toLogArray());
    }

    public function testStoresTheLogoAndTheSignatureWithItsUploader(): void
    {
        // Initialize
        $school = (new School())->setName('Alpha NHS')->setLogoPath('uploads/logos/old.webp');
        $command = new UpdateSchoolProfileCommand(7);
        $command->logoFile = '/tmp/logo';
        $command->idSignatureFile = '/tmp/signature';
        $images = $this->createMock(ImageProcessorInterface::class);
        $images->expects(self::once())->method('storeLogo')->with('/tmp/logo', 'uploads/logos/old.webp')
            ->willReturn('uploads/logos/new.webp');
        $images->expects(self::once())->method('storeSignature')->with('/tmp/signature', null)
            ->willReturn('uploads/signatures/new.webp');
        $handler = $this->handler($school, $images, 'Updated school profile Alpha NHS');

        // Execute
        $handler($command);

        // Assert
        self::assertSame('uploads/logos/new.webp', $school->getLogoPath());
        self::assertSame('uploads/signatures/new.webp', $school->getIdSignaturePath());
        self::assertSame($this->principal, $school->getIdSignatureBy());
    }

    public function testAnUnreadableImageSavesNothing(): void
    {
        // Initialize
        $school = (new School())->setName('Alpha NHS');
        $command = new UpdateSchoolProfileCommand(7);
        $command->logoFile = '/tmp/logo';
        $images = $this->createStub(ImageProcessorInterface::class);
        $images->method('storeLogo')->willThrowException(new ImageProcessingException('Unreadable image.'));
        $handler = $this->handler($school, $images, null);

        // Execute
        try {
            $handler($command);
            $refused = false;
        } catch (ImageProcessingException) {
            $refused = true;
        }

        // Assert
        self::assertTrue($refused);
        self::assertNull($school->getLogoPath());
    }

    public function testRefusesAPrincipalWithoutASchool(): void
    {
        // Expect
        $this->expectException(SchoolNotFoundException::class);

        // Initialize
        $handler = $this->handler(null, null, null);

        // Execute
        $handler(new UpdateSchoolProfileCommand(7));
    }

    public function testReadsTheProfileOrNothing(): void
    {
        // Initialize
        $school = (new School())->setName('Alpha NHS');
        $command = new GetSchoolProfileCommand(7);
        $clock = $this->createStub(SchoolClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-11-07'));
        $getSchoolProfileHandler = new GetSchoolProfileHandler($this->finder($school), $clock);
        $getSchoolProfileHandler2 = new GetSchoolProfileHandler($this->finder(null), $clock);

        // Execute
        $found = $getSchoolProfileHandler($command);
        $none = $getSchoolProfileHandler2($command);

        // Assert
        /** @var array{school: array{name: string}, schoolYear: array{name: string}} $foundData */
        $foundData = $found->data();
        /** @var array<string, mixed> $noneData */
        $noneData = $none->data();
        self::assertSame('Alpha NHS', $foundData['school']['name']);
        self::assertSame('2026-2027', $foundData['schoolYear']['name']);
        self::assertNull($noneData['school']);
        self::assertSame(['userId' => 7], $command->toLogArray());
    }

    public function testWithoutACurrentYearThereIsNoSchool(): void
    {
        // Initialize
        $finder = $this->finder(new School(), false);

        // Execute
        [$user, $schoolYear, $school] = $finder->find(7);

        // Assert
        self::assertSame($this->principal, $user);
        self::assertNull($schoolYear);
        self::assertNull($school);
    }

    private function finder(?School $school, bool $withCurrentYear = true): PrincipalSchoolFinder
    {
        $accounts = $this->createStub(AccountDirectoryInterface::class);
        $accounts->method('findById')->willReturn($this->principal);
        $years = $this->createStub(SchoolYearRepositoryInterface::class);
        $years->method('findCurrent')->willReturn($withCurrentYear ? (new SchoolYear())->setName('2026-2027') : null);
        $assignments = $this->createStub(SchoolAssignmentRepositoryInterface::class);
        $assignments->method('findSchoolOf')->willReturn($school);

        return new PrincipalSchoolFinder($accounts, $years, $assignments);
    }

    private function handler(
        ?School $school,
        ?ImageProcessorInterface $images,
        ?string $expectedLog,
    ): UpdateSchoolProfileHandler {
        $logger = $this->createMock(ActivityLoggerInterface::class);
        if (null === $expectedLog) {
            $logger->expects(self::never())->method('log');
        } else {
            $logger->expects(self::once())->method('log')->with($expectedLog);
        }

        return new UpdateSchoolProfileHandler(
            $this->finder($school),
            $images ?? $this->createStub(ImageProcessorInterface::class),
            $logger,
        );
    }
}
