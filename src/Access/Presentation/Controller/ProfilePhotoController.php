<?php

declare(strict_types=1);

namespace App\Access\Presentation\Controller;

use App\Access\Application\UseCase\Command\ChangeProfilePhotoCommand;
use App\Access\Application\UseCase\Command\RemoveProfilePhotoCommand;
use App\Access\Domain\Model\User;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Core\Presentation\Form\ImageUploadFormType;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The signed-in user's profile photo, shown on every profile page.
 */
#[IsGranted(new Expression("is_granted('ROLE_PRINCIPAL') or is_granted('ROLE_TEACHER')"))]
#[Route('/profile')]
class ProfilePhotoController extends BusController
{
    public function __construct(private readonly UseCaseBusInterface $useCaseBus)
    {
    }

    #[Route('/photo', name: 'profile_photo', methods: ['POST'])]
    public function photo(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(
            ImageUploadFormType::class,
            options: ['action' => $this->generateUrl('profile_photo')],
        );
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $message = 'The photo could not be uploaded.';
            foreach ($form->getErrors(true) as $error) {
                $message = $error->getMessage();
                break;
            }
            $this->addFlash('error', $message);

            return $this->back($request);
        }

        $file = $form->get('image')->getData();
        \assert($file instanceof File);
        $command = new ChangeProfilePhotoCommand((int) $user->getId(), $file->getPathname());
        $failure = $this->domainFailure($this->useCaseBus->handle($command));
        if (null !== $failure) {
            $this->addFlash('error', $failure->getMessage());
        } else {
            $this->addFlash('success', 'Photo updated.');
        }

        return $this->back($request);
    }

    #[Route('/photo/remove', name: 'profile_photo_remove', methods: ['POST'])]
    #[IsCsrfTokenValid('remove-photo')]
    public function photoRemove(Request $request, #[CurrentUser] User $user): Response
    {
        $this->domainFailure($this->useCaseBus->handle(new RemoveProfilePhotoCommand((int) $user->getId())));
        $this->addFlash('success', 'Photo removed.');

        return $this->back($request);
    }

    /** The photo upload form shown on every profile page (rendered as a sub-request). */
    public function photoForm(): Response
    {
        $form = $this->createForm(
            ImageUploadFormType::class,
            options: ['action' => $this->generateUrl('profile_photo')],
        );

        return $this->render('@Core/_photo_form.html.twig', ['form' => $form]);
    }

    /** Back to the profile tab the request came from. */
    private function back(Request $request): Response
    {
        $path = parse_url((string) $request->headers->get('referer'), \PHP_URL_PATH);
        $profile = $this->generateUrl('profile_show');

        return \is_string($path) && str_starts_with($path, $profile) && !str_starts_with($path, $profile . '/photo')
            ? $this->redirect($path)
            : $this->redirectToRoute('profile_show');
    }
}
