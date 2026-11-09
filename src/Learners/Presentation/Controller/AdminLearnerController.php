<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Learners\Application\Query\Command\ListAdminLearnersCommand;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The administrator's learner search across schools; a learner's page opens at their school. */
#[IsGranted(User::ROLE_ADMIN)]
class AdminLearnerController extends BusController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    #[Route('/admin/learners', name: 'admin_learners', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?string $q = null): Response
    {
        $result = $this->queryBus->handle(new ListAdminLearnersCommand($q ?? ''));
        $this->domainFailure($result);
        /** @var array{query: string, rows: list<array<string, mixed>>, schoolYear: ?array{name: string}} $list */
        $list = $result->data();

        return $this->render('@Learners/admin/learners/index.html.twig', [
            'query' => $list['query'],
            'rows' => $list['rows'],
            'school_year' => $list['schoolYear'],
        ]);
    }
}
