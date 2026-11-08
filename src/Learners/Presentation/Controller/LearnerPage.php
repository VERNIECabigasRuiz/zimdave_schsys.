<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Controller;

/** Which learners a Learners page manages, and where its links and layout point. */
final readonly class LearnerPage
{
    /**
     * @param int|null             $fixedSectionId the adviser's section: no section picker, nothing outside it
     * @param string               $routes         route-name prefix, e.g. "school_learners"
     * @param array<string, mixed> $routeParams    parameters every route of the page needs
     */
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public ?int $fixedSectionId,
        public string $routes,
        public array $routeParams,
        public string $layout,
    ) {
    }
}
