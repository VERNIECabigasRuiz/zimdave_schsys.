<?php

namespace App\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\Section;
use App\LearnerId\IdCardBuilder;
use App\LearnerId\IdCardPdf;
use App\LearnerId\IdLayout;
use App\Learners\Application\Service\LearnerScope;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Domain\Exception\LearnerNotFoundException;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Presentation\Security\LearnerVoter;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\School;
use App\Schools\Presentation\Security\SchoolVoter;
use App\Sf1\Sf1ReportBuilder;
use App\Sf1\Sf1Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The SF1 file and the ID card PDFs of the school's and the adviser's learner pages. */
class LearnerDownloadController extends AbstractController
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly Sf1ReportBuilder $sf1Reports,
        private readonly Sf1Writer $sf1Writer,
        private readonly IdCardBuilder $idCards,
        private readonly IdCardPdf $idCardPdf,
    ) {
    }

    #[Route('/schools/{id}/sections/{section}/ids', name: 'school_learners_ids', requirements: ['id' => '\d+', 'learner' => '\d+', 'section' => '\d+'], methods: ['GET'])]
    #[IsGranted(SchoolVoter::MANAGE, 'school')]
    public function schoolIds(School $school, int $section, #[MapQueryParameter] string $layout = 'sheet'): Response
    {
        return $this->downloadIds($this->schoolSection($school, $section), $layout);
    }

    #[Route('/schools/{id}/learners/{learner}/id', name: 'school_learners_id', requirements: ['id' => '\d+', 'learner' => '\d+', 'section' => '\d+'], methods: ['GET'])]
    #[IsGranted(SchoolVoter::MANAGE, 'school')]
    public function schoolId(School $school, int $learner, #[CurrentUser] User $user, #[MapQueryParameter] string $layout = 'sheet'): Response
    {
        return $this->downloadId($this->schoolPage($school), $learner, $user, $layout);
    }

    #[Route('/schools/{id}/sections/{section}/sf1', name: 'school_learners_sf1', requirements: ['id' => '\d+', 'learner' => '\d+', 'section' => '\d+'], methods: ['GET'])]
    #[IsGranted(SchoolVoter::MANAGE, 'school')]
    public function schoolSf1(School $school, int $section): Response
    {
        return $this->downloadSf1($this->schoolSection($school, $section));
    }

    #[Route('/teacher/class/sf1', name: 'teacher_class_sf1', requirements: ['learner' => '\d+'], methods: ['GET'])]
    #[IsGranted(User::ROLE_TEACHER)]
    public function classSf1(#[CurrentUser] User $user): Response
    {
        return $this->downloadSf1($this->advisory($user));
    }

    #[Route('/teacher/class/ids', name: 'teacher_class_ids', requirements: ['learner' => '\d+'], methods: ['GET'])]
    #[IsGranted(User::ROLE_TEACHER)]
    public function classIds(#[CurrentUser] User $user, #[MapQueryParameter] string $layout = 'sheet'): Response
    {
        return $this->downloadIds($this->advisory($user), $layout);
    }

    #[Route('/teacher/class/{learner}/id', name: 'teacher_class_id', requirements: ['learner' => '\d+'], methods: ['GET'])]
    #[IsGranted(User::ROLE_TEACHER)]
    public function classId(#[CurrentUser] User $user, int $learner, #[MapQueryParameter] string $layout = 'sheet'): Response
    {
        $section = $this->advisory($user);

        return $this->downloadId(new LearnerScope($section->getSchool(), $section->getSchoolYear(), $section), $learner, $user, $layout);
    }

    /** ID cards of every learner in the section, as a PDF. */
    private function downloadIds(Section $section, string $layout): Response
    {
        $this->denyAccessUnlessGranted(LearnerVoter::VIEW_CLASS, $section);
        $pdf = $this->idCardPdf->render($this->idCards->forSection($section), IdLayout::tryFrom($layout) ?? IdLayout::Sheet);

        return $this->download($pdf, 'application/pdf', IdCardPdf::sectionFileName($section));
    }

    /** One learner's ID card, e.g. to replace a lost one. */
    private function downloadId(LearnerScope $page, int $id, User $user, string $layout): Response
    {
        try {
            [$learner, $current] = $this->scope->enrolledLearner($page, $id, $user);
        } catch (LearnerNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }
        $this->denyAccessUnlessGranted(LearnerVoter::EDIT, $learner);
        $pdf = $this->idCardPdf->render([$this->idCards->forEnrollment($current)], IdLayout::tryFrom($layout) ?? IdLayout::Sheet);

        return $this->download($pdf, 'application/pdf', 'ID_'.$learner->getLrn().'.pdf');
    }

    /** The section's SF1 (School Register) as an .xls file laid out like LIS's. */
    private function downloadSf1(Section $section): Response
    {
        $this->denyAccessUnlessGranted(LearnerVoter::VIEW_CLASS, $section);
        $report = $this->sf1Reports->build($section);

        $file = tempnam(sys_get_temp_dir(), 'sf1');
        try {
            IOFactory::createWriter($this->sf1Writer->write($report), 'Xls')->save($file);
            $content = (string) file_get_contents($file);
        } finally {
            unlink($file);
        }

        return $this->download($content, 'application/vnd.ms-excel', $report->fileName());
    }

    private function download(string $content, string $type, string $fileName): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => $type,
            // section names may have an Ñ; old browsers get underscores
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, (string) preg_replace('/[^\x20-\x7E]/', '_', $fileName)),
        ]);
    }

    private function schoolPage(School $school): LearnerScope
    {
        try {
            return $this->scope->page((int) $school->getId(), null);
        } catch (SchoolYearNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }
    }

    /** A section of the school this school year. */
    private function schoolSection(School $school, int $id): Section
    {
        try {
            return $this->scope->section($this->schoolPage($school), $id);
        } catch (SectionNotFoundException $e) {
            throw $this->createNotFoundException($e->getMessage());
        }
    }

    /** The section the teacher advises this school year. */
    private function advisory(User $user): Section
    {
        try {
            $section = $this->scope->advisory($user);
        } catch (LearnersAccessDeniedException $e) {
            throw $this->createAccessDeniedException($e->getMessage());
        }
        $this->denyAccessUnlessGranted(LearnerVoter::VIEW_CLASS, $section);

        return $section;
    }
}
