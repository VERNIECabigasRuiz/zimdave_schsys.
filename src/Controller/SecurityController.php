<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('This method is intercepted by the logout key on your firewall.');
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(): Response
    {
        return $this->render('security/forgot_password.html.twig');
    }

    #[Route('/change-password', name: 'app_change_password')]
    public function changePassword(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ActivityLogger $activityLogger,
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $firstTime = $user->mustChangePassword();

        $form = $this->createForm(ChangePasswordFormType::class, null, [
            'require_current_password' => !$firstTime,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();

            if ($passwordHasher->isPasswordValid($user, $plainPassword)) {
                $form->get('plainPassword')->get('first')->addError(
                    new FormError('Your new password must be different from your current one.'),
                );
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
                $user->setMustChangePassword(false);
                $activityLogger->log('Changed password');
                $entityManager->flush();

                $this->addFlash('success', 'Your password has been updated.');

                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('security/change_password.html.twig', [
            'form' => $form,
            'first_time' => $firstTime,
        ]);
    }
}
