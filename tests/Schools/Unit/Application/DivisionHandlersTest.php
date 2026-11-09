<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Application;

use App\Core\Domain\Exception\ImageProcessingException;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Schools\Application\Query\Command\GetDivisionCommand;
use App\Schools\Application\Query\Command\ListDivisionsCommand;
use App\Schools\Application\Query\Handler\GetDivisionHandler;
use App\Schools\Application\Query\Handler\ListDivisionsHandler;
use App\Schools\Application\Service\DivisionDetailsWriter;
use App\Schools\Application\UseCase\Command\CreateDivisionCommand;
use App\Schools\Application\UseCase\Command\UpdateDivisionCommand;
use App\Schools\Application\UseCase\Handler\CreateDivisionHandler;
use App\Schools\Application\UseCase\Handler\UpdateDivisionHandler;
use App\Schools\Application\Transformer\DivisionTransformer;
use App\Schools\Domain\Exception\DivisionNotFoundException;
use App\Schools\Domain\Exception\DivisionTakenException;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class DivisionHandlersTest extends TestCase
{
    public function testAddsADivision(): void
    {
        // Initialize
        $divisions = $this->mockDivisions(null, null);
        $divisions->expects(self::once())->method('add');
        $command = $this->create('Region VII', 'Mandaue City');
        $handler = new CreateDivisionHandler($this->writer($divisions, null, 'Added division Mandaue City.'));

        // Execute
        $result = $handler($command);

        // Assert
        self::assertSame(['name' => 'Mandaue City'], $result->data());
        self::assertSame(['region' => 'Region VII', 'name' => 'Mandaue City'], $command->toLogArray());
    }

    public function testAddRefusesADivisionOnTheList(): void
    {
        // Expect
        $this->expectException(DivisionTakenException::class);
        $this->expectExceptionMessageIsOrContains('This division is already on the list.');

        // Initialize
        $divisions = $this->divisions(null, $this->division(3));
        $handler = new CreateDivisionHandler($this->writer($divisions, null, null));

        // Execute
        $handler($this->create('Region VII', 'Mandaue City'));
    }

    public function testDivisionSignatureIsStoredAndOldOneReplaced(): void
    {
        // Initialize
        $division = $this->division(3)->setSignaturePath('uploads/signatures/old.webp');
        $command = UpdateDivisionCommand::fromDivision(DivisionTransformer::toArray($division));
        $command->signatureFile = '/tmp/upload';
        $images = $this->createMock(ImageProcessorInterface::class);
        $images->expects(self::once())->method('storeSignature')
            ->with('/tmp/upload', 'uploads/signatures/old.webp')
            ->willReturn('uploads/signatures/new.webp');
        $divisions = $this->divisions($division, $division);
        $handler = new UpdateDivisionHandler(
            $divisions,
            $this->writer($divisions, $images, 'Saved division Mandaue City.'),
        );

        // Execute
        $handler($command);

        // Assert
        self::assertSame('uploads/signatures/new.webp', $division->getSignaturePath());
        self::assertSame(3, $command->toLogArray()['id']);
    }

    public function testAnUnreadableSignatureSavesNothing(): void
    {
        // Initialize
        $division = $this->division(3);
        $command = UpdateDivisionCommand::fromDivision(DivisionTransformer::toArray($division));
        $command->sdsName = 'Dr. Changed';
        $command->signatureFile = '/tmp/upload';
        $images = $this->createStub(ImageProcessorInterface::class);
        $images->method('storeSignature')->willThrowException(new ImageProcessingException('Unreadable image.'));
        $divisions = $this->divisions($division, $division);
        $handler = new UpdateDivisionHandler($divisions, $this->writer($divisions, $images, null));

        // Execute
        try {
            $handler($command);
            $refused = false;
        } catch (ImageProcessingException) {
            $refused = true;
        }

        // Assert
        self::assertTrue($refused);
        self::assertSame('Dr. Tester', $division->getSdsName());
    }

    public function testUpdateOfAnUnknownDivisionIsRefused(): void
    {
        // Expect
        $this->expectException(DivisionNotFoundException::class);

        // Initialize
        $divisions = $this->divisions(null, null);
        $handler = new UpdateDivisionHandler($divisions, $this->writer($divisions, null, null));

        // Execute
        $handler(new UpdateDivisionCommand(9));
    }

    public function testListsAndGetsDivisions(): void
    {
        // Initialize
        $division = $this->division(3);
        $divisions = $this->divisions($division, null);
        $divisions->method('findAllOrdered')->willReturn([$division]);
        $get = new GetDivisionCommand(3);
        $listDivisionsHandler = new ListDivisionsHandler($divisions);
        $getDivisionHandler = new GetDivisionHandler($divisions);

        // Execute
        $list = $listDivisionsHandler(new ListDivisionsCommand());
        $one = $getDivisionHandler($get);

        // Assert
        self::assertSame([DivisionTransformer::toArray($division)], $list->data());
        self::assertSame(DivisionTransformer::toArray($division), $one->data());
        self::assertSame([], (new ListDivisionsCommand())->toLogArray());
        self::assertSame(['id' => 3], $get->toLogArray());
    }

    public function testGetOfAnUnknownDivisionIsRefused(): void
    {
        // Expect
        $this->expectException(DivisionNotFoundException::class);

        // Initialize
        $getDivisionHandler = new GetDivisionHandler($this->divisions(null, null));

        // Execute
        $getDivisionHandler(new GetDivisionCommand(9));
    }

    private function create(string $region, string $name): CreateDivisionCommand
    {
        $command = new CreateDivisionCommand();
        $command->region = $region;
        $command->name = $name;
        $command->sdsName = 'Dr. Tester';

        return $command;
    }

    private function division(int $id): Division
    {
        $division = (new Division())->setRegion('Region VII')->setName('Mandaue City')->setSdsName('Dr. Tester');
        (new \ReflectionProperty(Division::class, 'id'))->setValue($division, $id);

        return $division;
    }

    /** @return DivisionRepositoryInterface&\PHPUnit\Framework\MockObject\Stub */
    private function divisions(?Division $byId, ?Division $byPair): DivisionRepositoryInterface
    {
        $divisions = $this->createStub(DivisionRepositoryInterface::class);
        $divisions->method('findById')->willReturn($byId);
        $divisions->method('findByRegionAndName')->willReturn($byPair);

        return $divisions;
    }

    /** @return DivisionRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private function mockDivisions(?Division $byId, ?Division $byPair): DivisionRepositoryInterface
    {
        $divisions = $this->createMock(DivisionRepositoryInterface::class);
        $divisions->method('findById')->willReturn($byId);
        $divisions->method('findByRegionAndName')->willReturn($byPair);

        return $divisions;
    }

    private function writer(
        DivisionRepositoryInterface $divisions,
        ?ImageProcessorInterface $images,
        ?string $expectedLog,
    ): DivisionDetailsWriter {
        $logger = $this->createMock(ActivityLoggerInterface::class);
        if (null === $expectedLog) {
            $logger->expects(self::never())->method('log');
        } else {
            $logger->expects(self::once())->method('log')->with($expectedLog);
        }

        return new DivisionDetailsWriter(
            $divisions,
            $images ?? $this->createStub(ImageProcessorInterface::class),
            $logger,
        );
    }
}
