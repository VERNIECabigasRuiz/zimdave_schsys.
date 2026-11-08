<?php

namespace App\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Grading\SectionGradeGrid;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Security\Voter\GradeVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The adviser's class grades: every subject's grades of the section they advise, one grading period at a time. */
#[IsGranted(User::ROLE_TEACHER)]
class TeacherClassGradesController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SectionRepositoryInterface $sections,
    ) {
    }

    #[Route('/teacher/class/grades', name: 'teacher_class_grades', requirements: ['learner' => '\d+'], methods: ['GET'])]
    public function grades(#[CurrentUser] User $user, SectionGradeGrid $grid, #[MapQueryParameter] ?int $period = null): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $section = null === $schoolYear ? null : $this->sections->findAdvisory($user, $schoolYear);
        if (null === $section) {
            throw $this->createAccessDeniedException('You do not advise a section this school year.');
        }
        $this->denyAccessUnlessGranted(GradeVoter::VIEW_SECTION, $section);

        $periods = $schoolYear->getGradingPeriods();
        if (null === $period) {
            $selected = $grid->defaultPeriod($section->getSchool(), $schoolYear);
        } else {
            $selected = $periods->findFirst(static fn (int $i, GradingPeriod $p) => $p->getId() === $period);
            if (null === $selected) {
                throw $this->createNotFoundException('No such grading period.');
            }
        }

        return $this->render('grades/class.html.twig', [
            'section' => $section,
            'periods' => $periods,
            'period' => $selected,
            'grid' => null === $selected ? null : $grid->build($section, $selected),
        ]);
    }
}
