<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Grading\Application\Query\Command\GetClassGradesCommand;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The adviser's class grades: every subject's grades of the section they advise, one grading period at a time. */
#[IsGranted(User::ROLE_TEACHER)]
class TeacherClassGradesController extends AbstractGradingController
{
    #[Route(
        '/teacher/class/grades',
        name: 'teacher_class_grades',
        requirements: ['learner' => '\d+'],
        methods: ['GET'],
    )]
    public function grades(#[CurrentUser] User $user, #[MapQueryParameter] ?int $period = null): Response
    {
        return $this->render(
            '@Grading/grades/class.html.twig',
            $this->ask(new GetClassGradesCommand((int) $user->getId(), $period)),
        );
    }
}
