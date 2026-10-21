<?php

namespace App\Twig;

use App\Repository\ActivityLogRepository;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

class AppExtension
{
    public function __construct(
        private readonly ActivityLogRepository $activityLogs,
    ) {
    }

    /**
     * "8:02 AM" for today, "Yesterday", otherwise "Oct 14, 2026".
     */
    #[AsTwigFilter('activity_time')]
    public function activityTime(\DateTimeInterface $time): string
    {
        $time = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
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
     * @return list<\App\Entity\ActivityLog>
     */
    #[AsTwigFunction('latest_activity')]
    public function latestActivity(int $limit = 6): array
    {
        return $this->activityLogs->findRecent($limit);
    }
}
