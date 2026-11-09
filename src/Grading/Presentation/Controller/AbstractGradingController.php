<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Exception\AccountNotFoundException;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Grading\Domain\Exception\GradingAccessDeniedException;
use App\Grading\Domain\Exception\GradingNotFoundException;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/** What the grade pages share: asking the bus, and turning refusals into a 404, a 403 or a message. */
abstract class AbstractGradingController extends BusController
{
    public function __construct(
        protected readonly UseCaseBusInterface $useCaseBus,
        protected readonly QueryBusInterface $queryBus,
    ) {
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

    /** @param array<string, mixed> $file a card slip PDF query's data: content and fileName */
    protected function download(array $file): Response
    {
        \assert(\is_string($file['content']) && \is_string($file['fileName']));

        return $this->pdf($file['content'], $file['fileName']);
    }

    /** A PDF download; names with an Ñ fall back to underscores for old browsers. */
    protected function pdf(string $content, string $fileName): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $fileName,
                (string) preg_replace('/[^\x20-\x7E]/', '_', $fileName),
            ),
        ]);
    }

    /** The message of a refused change, or null when it was saved; not found is a 404, not allowed a 403. */
    protected function refusal(Result $result): ?string
    {
        $failure = $this->domainFailure($result);
        if (
            $failure instanceof GradingNotFoundException
            || $failure instanceof SchoolNotFoundException
            || $failure instanceof SchoolYearNotFoundException
            || $failure instanceof AccountNotFoundException
        ) {
            throw $this->createNotFoundException($failure->getMessage());
        }
        if ($failure instanceof GradingAccessDeniedException) {
            throw $this->createAccessDeniedException($failure->getMessage());
        }

        return $failure?->getMessage();
    }
}
