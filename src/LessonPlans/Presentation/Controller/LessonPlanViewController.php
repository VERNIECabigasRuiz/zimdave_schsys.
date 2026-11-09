<?php

declare(strict_types=1);

namespace App\LessonPlans\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\LessonPlans\Application\Query\Command\GetLessonPlanCommand;
use App\LessonPlans\Application\Query\Command\GetLessonPlanPdfCommand;
use App\LessonPlans\Application\Query\Command\GetSchoolLessonPlansCommand;
use App\LessonPlans\Application\Service\LessonPlanPolicy;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\String\u;

/** Reading and printing lesson plans: the teacher's own, and every plan of the principal's school. */
class LessonPlanViewController extends AbstractLessonPlanController
{
    #[Route('/principal/lesson-plans', name: 'principal_lesson_plans', methods: ['GET'])]
    #[IsGranted(User::ROLE_PRINCIPAL)]
    public function school(#[CurrentUser] User $user): Response
    {
        $page = $this->ask(new GetSchoolLessonPlansCommand((int) $user->getId()));
        if (null === $page['school']) {
            return $this->render('@Core/principal/not_assigned.html.twig', [
                'school_year' => $page['schoolYear'],
                'not_assigned' => true,
            ]);
        }

        return $this->render('@LessonPlans/lesson_plans/school.html.twig', [
            'school' => $page['school'],
            'plans' => $page['plans'],
        ]);
    }

    #[Route('/lesson-plans/{id}', name: 'lesson_plan_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, #[CurrentUser] User $user): Response
    {
        $page = $this->ask(new GetLessonPlanCommand((int) $user->getId(), $id, LessonPlanPolicy::VIEW));

        return $this->render('@LessonPlans/lesson_plans/show.html.twig', ['plan' => $page['plan']]);
    }

    #[Route('/lesson-plans/{id}.pdf', name: 'lesson_plan_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(int $id, #[CurrentUser] User $user): Response
    {
        $file = $this->ask(new GetLessonPlanPdfCommand((int) $user->getId(), $id));
        \assert(\is_string($file['content']) && \is_string($file['fileName']));

        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file['fileName'],
                (string) u($file['fileName'])->ascii(),
            ),
        ]);
    }
}
