<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\LearnerRepository;
use App\Repository\SchoolYearRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Finds a learner in any school, by LRN or name, and opens their page through their school. */
#[IsGranted(User::ROLE_ADMIN)]
class LearnerController extends AbstractController
{
    private const int MAX_RESULTS = 25;

    public function __construct(
        private readonly LearnerRepository $learners,
        private readonly EnrollmentRepository $enrollments,
        private readonly SchoolYearRepository $schoolYears,
    ) {
    }

    #[Route('/admin/learners', name: 'admin_learners', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?string $q = null): Response
    {
        $query = null !== $q ? trim($q) : '';
        $schoolYear = $this->schoolYears->findCurrent();

        $rows = [];
        if ('' !== $query) {
            foreach ($this->learners->searchByLrnOrName($query, self::MAX_RESULTS) as $learner) {
                $current = null !== $schoolYear ? $this->enrollments->findCurrent($learner, $schoolYear) : null;
                // not enrolled this year: the most recent school still gives the admin a way in
                $enrollment = $current ?? ($this->enrollments->findHistory($learner)[0] ?? null);
                $rows[] = ['learner' => $learner, 'current' => $current, 'enrollment' => $enrollment];
            }
        }

        return $this->render('admin/learners/index.html.twig', [
            'query' => $query,
            'rows' => $rows,
            'school_year' => $schoolYear,
        ]);
    }
}
