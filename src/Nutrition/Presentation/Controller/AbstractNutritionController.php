<?php

declare(strict_types=1);

namespace App\Nutrition\Presentation\Controller;

use App\Access\Domain\Exception\AccountNotFoundException;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Nutrition\Application\Query\Command\GetNutritionPeriodCommand;
use App\Nutrition\Application\Query\Command\GetSf8FileCommand;
use App\Nutrition\Domain\Exception\NutritionAccessDeniedException;
use App\Nutrition\Domain\Model\NutritionPeriod;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The nutrition pages shared by the adviser's class and the school pages: a section's weighing
 * table for a period with its SF8 summary, and the SF8 download. Subclasses decide the routes and who may write.
 */
abstract class AbstractNutritionController extends BusController
{
    public function __construct(
        protected readonly UseCaseBusInterface $useCaseBus,
        protected readonly QueryBusInterface $queryBus,
    ) {
    }

    /** @return array<string, mixed> the period page of the adviser's section ($schoolId null) or $sectionId */
    protected function periodPage(int $actorId, ?int $schoolId, ?int $sectionId, NutritionPeriod $period): array
    {
        return $this->ask(new GetNutritionPeriodCommand($actorId, $schoolId, $sectionId, $period));
    }

    /**
     * The section's page for the period: the sheet with the period query's report. Pass `form` (a
     * NutritionSheetFormType form) with `editable` to render inputs, `submit_form`, `reopen_url`; routes need
     * `layout`, `period_route`, `sf8_route`, `route_params` and `index_route` (nullable).
     *
     * @param array<string, mixed> $page the period query's data
     * @param array<string, mixed> $vars
     */
    protected function renderSection(array $page, NutritionPeriod $period, array $vars): Response
    {
        return $this->render('@Nutrition/nutrition/sheet.html.twig', $vars + [
            'section' => $page['section'],
            'period' => $period,
            'report' => $page['report'],
            'form' => null,
            'editable' => false,
            'submit_form' => null,
            'reopen_url' => null,
            'index_route' => null,
        ]);
    }

    protected function downloadSf8(int $actorId, ?int $schoolId, ?int $sectionId, NutritionPeriod $period): Response
    {
        $file = $this->ask(new GetSf8FileCommand($actorId, $schoolId, $sectionId, $period));
        \assert(\is_string($file['content']) && \is_string($file['fileName']));

        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // section names may have an Ñ; old browsers get underscores
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file['fileName'],
                (string) preg_replace('/[^\x20-\x7E]/', '_', $file['fileName']),
            ),
        ]);
    }

    /**
     * The data of a page query; anything not found is a 404, anything not allowed a 403.
     *
     * @return array<string, mixed>
     */
    protected function ask(Query $query): array
    {
        $result = $this->queryBus->handle($query);
        $this->refusal($result);
        $data = $result->data();
        \assert(\is_array($data));

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** The message of a refused change, or null when it was saved; not found is a 404, not allowed a 403. */
    protected function refusal(Result $result): ?string
    {
        $failure = $this->domainFailure($result);
        if (
            $failure instanceof SectionNotFoundException
            || $failure instanceof SchoolNotFoundException
            || $failure instanceof SchoolYearNotFoundException
            || $failure instanceof AccountNotFoundException
        ) {
            throw $this->createNotFoundException($failure->getMessage());
        }
        if ($failure instanceof NutritionAccessDeniedException) {
            throw $this->createAccessDeniedException($failure->getMessage());
        }

        return $failure?->getMessage();
    }
}
