<?php

declare(strict_types=1);

namespace App\Access\Presentation\Controller;

use App\Access\Application\UseCase\Command\ChangePasswordCommand;
use App\Access\Domain\Exception\PasswordUnchangedException;
use App\Access\Domain\Model\User;
use App\Access\Presentation\Form\ChangePasswordFormType;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends BusController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('@Access/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Never runs: the firewall's logout handles /logout before it gets here.
     *
     * @codeCoverageIgnore
     */
    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on your firewall.');
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(): Response
    {
        return $this->render('@Access/security/forgot_password.html.twig');
    }

    #[Route('/change-password', name: 'app_change_password')]
    public function changePassword(
        Request $request,
        #[CurrentUser] User $user,
        UseCaseBusInterface $useCaseBus,
    ): Response {
        $firstTime = $user->mustChangePassword();

        $form = $this->createForm(ChangePasswordFormType::class, null, [
            'require_current_password' => !$firstTime,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            \assert(\is_string($plainPassword));
            $result = $useCaseBus->handle(new ChangePasswordCommand((int) $user->getId(), $plainPassword));

            $failure = $this->domainFailure($result);
            if ($failure instanceof PasswordUnchangedException) {
                $form->get('plainPassword')->get('first')->addError(new FormError($failure->getMessage()));
            } elseif (null === $failure) {
                $this->addFlash('success', 'Your password has been updated.');

                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('@Access/security/change_password.html.twig', [
            'form' => $form,
            'first_time' => $firstTime,
        ]);
    }
}
