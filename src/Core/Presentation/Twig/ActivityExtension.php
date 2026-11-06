<?php

declare(strict_types=1);

namespace App\Core\Presentation\Twig;

use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Query\Command\GetRecentActivityCommand;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

class ActivityExtension
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    /**
     * "8:02 AM" for today, "Yesterday", otherwise "Oct 14, 2026".
     */
    #[AsTwigFilter('activity_time')]
    public function activityTime(\DateTimeInterface $time): string
    {
        $time = \DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $today = new \DateTimeImmutable('today');

        return match (true) {
            $time >= $today => $time->format('g:i A'),
            $time >= $today->modify('-1 day') => 'Yesterday',
            default => $time->format('M j, Y'),
        };
    }

    /**
     * Latest entries for the "!" notifications menu in the admin top bar.
     *
     * @return list<array{username: string, action: string, createdAt: \DateTimeImmutable}>
     */
    #[AsTwigFunction('latest_activity')]
    public function latestActivity(int $limit = 6): array
    {
        $result = $this->queryBus->handle(new GetRecentActivityCommand($limit));
        $exception = $result->exception();
        if (null !== $exception) {
            throw $exception;
        }

        /** @var list<array{username: string, action: string, createdAt: \DateTimeImmutable}> $entries */
        $entries = $result->data();

        return $entries;
    }
}
