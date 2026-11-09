<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus\Middleware;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\Cacheable;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Caches the data of successful queries marked #[Cacheable]. Invalidate an entry
 * through its tag: the attribute's namespace with each {property} replaced by
 * that property's value. Cache problems never fail a query: it runs uncached.
 */
#[WithMonologChannel('bus')]
final class CachingMiddleware implements MiddlewareInterface
{
    public function __construct(
        #[Autowire(service: 'cache.bus')]
        private readonly TagAwareAdapterInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $attributes = (new \ReflectionClass($message))->getAttributes(Cacheable::class);

        if (!$message instanceof Query || $attributes === []) {
            return $stack->next()->handle($envelope, $stack);
        }

        $cacheable = $attributes[0]->newInstance();

        try {
            $item = $this->cache->getItem('bus.' . sha1($message::class . serialize($message)));
            $item->tag($this->resolveTag($cacheable->namespace, $message));
        } catch (\Throwable $exception) {
            $this->logger->warning('Cache lookup failed for ' . $message::class . ', running it uncached.', [
                'exceptionClass' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $stack->next()->handle($envelope, $stack);
        }

        // Only plain data is stored, wrapped so a cached null still counts as a hit
        $cached = $item->isHit() ? $item->get() : null;
        if (is_array($cached) && array_key_exists('data', $cached)) {
            return $envelope->with(new HandledStamp(Result::success($cached['data']), self::class));
        }

        $envelope = $stack->next()->handle($envelope, $stack);

        $result = $envelope->last(HandledStamp::class)?->getResult();
        if (!$result instanceof Result || $result->isFailure()) {
            return $envelope;
        }

        if (!$this->isPlainData($result->data())) {
            $this->logger->warning('Not caching ' . $message::class . ': its data holds an object.');

            return $envelope;
        }

        $item->set(['data' => $result->data()]);
        $item->expiresAfter($cacheable->ttl);
        $this->cache->save($item);

        return $envelope;
    }

    private function resolveTag(string $namespace, Query $message): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $matches) use ($message): string {
                $value = (new \ReflectionProperty($message, $matches[1]))->getValue($message);

                return is_scalar($value) ? (string) $value : throw new \LogicException('Not a scalar.');
            },
            $namespace,
        );
    }

    private function isPlainData(mixed $value): bool
    {
        return match (true) {
            is_object($value) => false,
            is_array($value) => array_all($value, fn (mixed $item): bool => $this->isPlainData($item)),
            default => true,
        };
    }
}
