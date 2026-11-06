<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Middleware;

use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Middleware\CachingMiddleware;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\CallbackMiddleware;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\PlainQuery;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SampleQuery;
use App\Tests\Core\Unit\Infrastructure\Bus\Fixture\SectionQuery;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class CachingMiddlewareTest extends TestCase
{
    private ArrayAdapter $store;
    private TagAwareAdapter $cache;
    private TestHandler $records;
    private CachingMiddleware $middleware;

    protected function setUp(): void
    {
        $this->store = new ArrayAdapter();
        $this->cache = new TagAwareAdapter($this->store);
        $this->records = new TestHandler();
        $this->middleware = new CachingMiddleware($this->cache, new Logger('bus', [$this->records]));
    }

    public function testPassesThroughWithoutCacheable(): void
    {
        // Initialize
        $next = $this->handlerReturning(Result::success('fresh'));

        // Execute
        $this->dispatch(new PlainQuery(), $next);
        $result = $this->dispatch(new PlainQuery(), $next);

        // Assert
        self::assertSame('fresh', $result->data());
        self::assertSame(2, $next->calls);
        self::assertSame([], $this->store->getValues());
    }

    public function testMissCallsNextAndStores(): void
    {
        // Initialize
        $next = $this->handlerReturning(Result::success(['a' => [1, 2]]));

        // Execute
        $result = $this->dispatch(new SampleQuery(), $next);

        // Assert
        self::assertSame(['a' => [1, 2]], $result->data());
        self::assertSame(1, $next->calls);
        self::assertNotSame([], $this->store->getValues());
    }

    public function testHitSkipsNext(): void
    {
        // Initialize
        $next = $this->handlerReturning(Result::success(['a' => [1, 2]]));
        $this->dispatch(new SampleQuery(), $next);

        // Execute
        $result = $this->dispatch(new SampleQuery(), $next);

        // Assert
        self::assertTrue($result->isSuccess());
        self::assertSame(['a' => [1, 2]], $result->data());
        self::assertSame(1, $next->calls);
    }

    public function testNamespacePlaceholderUsesPropertyValue(): void
    {
        // Initialize
        $next = $this->handlerReturning(Result::success(7));
        $this->dispatch(new SectionQuery(7), $next);

        // Execute
        $this->cache->invalidateTags(['section-7']);
        $this->dispatch(new SectionQuery(7), $next);

        // Assert
        self::assertSame(2, $next->calls);
    }

    public function testFailureIsNotCached(): void
    {
        // Initialize
        $next = $this->handlerReturning(Result::failure(new \RuntimeException('broken'), 'X_CODE'));

        // Execute
        $this->dispatch(new SampleQuery(), $next);
        $result = $this->dispatch(new SampleQuery(), $next);

        // Assert
        self::assertTrue($result->isFailure());
        self::assertSame(2, $next->calls);
    }

    public function testObjectDataIsNotCached(): void
    {
        // Initialize
        $next = $this->handlerReturning(Result::success(['row' => new \stdClass()]));

        // Execute
        $this->dispatch(new SampleQuery(), $next);
        $this->dispatch(new SampleQuery(), $next);

        // Assert
        self::assertSame(2, $next->calls);
        self::assertTrue($this->records->hasRecordThatContains('holds an object', Level::Warning));
    }

    public function testCacheErrorFailsOpen(): void
    {
        // Initialize
        $broken = self::createStub(TagAwareAdapterInterface::class);
        $broken->method('getItem')->willThrowException(new \RuntimeException('cache down'));
        $this->middleware = new CachingMiddleware($broken, new Logger('bus', [$this->records]));
        $next = $this->handlerReturning(Result::success('fresh'));

        // Execute
        $result = $this->dispatch(new SampleQuery(), $next);

        // Assert
        self::assertSame('fresh', $result->data());
        self::assertSame(1, $next->calls);
        self::assertTrue($this->records->hasRecordThatContains('Cache lookup failed', Level::Warning));
    }

    private function handlerReturning(Result $result): CallbackMiddleware
    {
        return new CallbackMiddleware(
            static fn (Envelope $envelope): Envelope => $envelope->with(new HandledStamp($result, 'handler')),
        );
    }

    private function dispatch(object $message, CallbackMiddleware $next): Result
    {
        $envelope = $this->middleware->handle(new Envelope($message), new StackMiddleware($next));
        $result = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(Result::class, $result);

        return $result;
    }
}
