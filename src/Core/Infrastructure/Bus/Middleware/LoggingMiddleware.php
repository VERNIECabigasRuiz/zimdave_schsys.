<?php

declare(strict_types=1);

namespace App\Core\Infrastructure\Bus\Middleware;

use App\Core\Application\Bus\Loggable;
use App\Core\Application\Bus\Result;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[WithMonologChannel('bus')]
final class LoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        $class = $message::class;

        $this->logger->info('Dispatching ' . $class, $message instanceof Loggable ? $message->toLogArray() : []);

        $envelope = $stack->next()->handle($envelope, $stack);

        $result = $envelope->last(HandledStamp::class)?->getResult();
        if ($result instanceof Result && $result->isFailure()) {
            $this->logger->warning('Handled ' . $class . ' with failure', ['errorCode' => $result->errorCode()]);
        } else {
            $this->logger->info('Handled ' . $class . ' successfully');
        }

        return $envelope;
    }
}
