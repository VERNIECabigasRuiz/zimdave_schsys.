<?php

declare(strict_types=1);

namespace App\Access\Presentation\Controller;

use Symfony\Component\Security\Http\Attribute\IsGranted;
use App\Access\Application\Query\Command\GetAccountCommand;
use App\Access\Application\Query\Command\SearchAccountsCommand;
use App\Access\Application\UseCase\Command\CreateAccountCommand;
use App\Access\Application\UseCase\Command\ResetAccountPasswordCommand;
use App\Access\Application\UseCase\Command\ToggleAccountActiveCommand;
use App\Access\Application\UseCase\Command\UpdateAccountCommand;
use App\Access\Domain\Exception\AccountNotFoundException;
use App\Access\Domain\Exception\AccountRoleChangeRefusedException;
use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Password\TemporaryPasswordGenerator;
use App\Access\Presentation\Form\UserAccountFormType;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\DomainException;
use App\Core\Presentation\Controller\BusController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * @phpstan-import-type Account from \App\Access\Application\Transformer\AccountTransformer
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin')]
class AccountController extends BusController
{
    /** URL slug => role, for the PRINCIPAL / TEACHER / STUDENT menu items. */
    public const ROLE_SLUGS = [
        'admins' => User::ROLE_ADMIN,
        'principals' => User::ROLE_PRINCIPAL,
        'teachers' => User::ROLE_TEACHER,
        'students' => User::ROLE_STUDENT,
    ];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
    ) {
    }

    #[Route('/accounts', name: 'admin_accounts', methods: ['GET'])]
    public function index(
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $status = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->renderList(null, $q, $status, $page);
    }

    #[Route(
        '/{slug}',
        name: 'admin_accounts_by_role',
        requirements: ['slug' => 'admins|principals|teachers|students'],
        methods: ['GET'],
    )]
    public function byRole(
        string $slug,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter] ?string $status = null,
        #[MapQueryParameter] int $page = 1,
    ): Response {
        return $this->renderList($slug, $q, $status, $page);
    }

    #[Route('/accounts/new', name: 'admin_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[MapQueryParameter] ?string $role = null): Response
    {
        $command = new CreateAccountCommand();
        // Students are created by enrolling, so that role is never preset here
        if (null !== $role && isset(self::ROLE_SLUGS[$role]) && User::ROLE_STUDENT !== self::ROLE_SLUGS[$role]) {
            $command->role = self::ROLE_SLUGS[$role];
        }
        $command->temporaryPassword = $this->passwordGenerator->generate();

        $form = $this->createForm(UserAccountFormType::class, $command);
        $form->handleRequest($request);

        /** @var array{fullName: string, username: string, role: string}|null $created */
        $created = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $created) {
            $this->addFlash('credentials', [
                'title' => 'Account created',
                'fullName' => $created['fullName'],
                'username' => $created['username'],
                'password' => $command->temporaryPassword,
            ]);
            $slug = array_search($created['role'], self::ROLE_SLUGS, true);

            if ($request->request->has('save_and_new')) {
                return $this->redirectToRoute('admin_account_new', ['role' => $slug]);
            }

            return $this->redirectToRoute('admin_accounts_by_role', ['slug' => $slug]);
        }

        return $this->render('@Access/admin/accounts/form.html.twig', [
            'form' => $form,
            'account' => null,
        ]);
    }

    #[Route('/accounts/{id}/edit', name: 'admin_account_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $account = $this->account($id);
        $command = UpdateAccountCommand::fromAccount($account);
        $form = $this->createForm(UserAccountFormType::class, $command, [
            'is_new' => false,
            'lock_role' => $this->isSelf($account['username']),
            'learner_lrn' => $account['learnerLrn'],
        ]);
        $form->handleRequest($request);

        /** @var Account|null $saved */
        $saved = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $saved) {
            $this->addFlash('success', \sprintf('Saved changes to %s.', $saved['fullName']));

            return $this->redirectToRoute('admin_accounts_by_role', [
                'slug' => array_search($saved['role'], self::ROLE_SLUGS, true),
            ]);
        }

        return $this->render('@Access/admin/accounts/form.html.twig', [
            'form' => $form,
            'account' => $account,
        ]);
    }

    #[Route(
        '/accounts/{id}/toggle-active',
        name: 'admin_account_toggle_active',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"account-" ~ args["id"]'), tokenKey: '_token')]
    public function toggleActive(Request $request, int $id): Response
    {
        $result = $this->useCaseBus->handle(new ToggleAccountActiveCommand($id, $this->actingUsername()));
        $failure = $this->domainFailure($result);
        if ($failure instanceof AccountNotFoundException) {
            throw $this->createNotFoundException();
        }
        if (null !== $failure) {
            $this->addFlash('error', $failure->getMessage());
        } else {
            /** @var Account $account */
            $account = $result->data();
            $state = $account['active'] ? 'active' : 'inactive';
            $this->addFlash('success', \sprintf('%s is now %s.', $account['fullName'], $state));
        }

        return $this->redirectBack($request);
    }

    #[Route(
        '/accounts/{id}/reset-password',
        name: 'admin_account_reset_password',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"account-" ~ args["id"]'), tokenKey: '_token')]
    public function resetPassword(Request $request, int $id): Response
    {
        $result = $this->useCaseBus->handle(new ResetAccountPasswordCommand($id, $this->actingUsername()));
        $failure = $this->domainFailure($result);
        if ($failure instanceof AccountNotFoundException) {
            throw $this->createNotFoundException();
        }
        if (null !== $failure) {
            $this->addFlash('error', $failure->getMessage());
        } else {
            /** @var array{fullName: string, username: string, password: string} $reset */
            $reset = $result->data();
            $this->addFlash('credentials', ['title' => 'Password reset'] + $reset);
        }

        return $this->redirectBack($request);
    }

    private function renderList(?string $slug, ?string $q, ?string $status, int $page): Response
    {
        $role = null !== $slug ? self::ROLE_SLUGS[$slug] : null;
        $active = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
        $page = max(1, $page);

        $result = $this->queryBus->handle(new SearchAccountsCommand($q, $role, $active, $page, self::PER_PAGE));
        $this->domainFailure($result);
        /** @var array{accounts: list<Account>, total: int, schoolNames: array<int, string>} $list */
        $list = $result->data();

        return $this->render('@Access/admin/accounts/index.html.twig', [
            'accounts' => $list['accounts'],
            'school_names' => $list['schoolNames'],
            'slug' => $slug,
            'role' => $role,
            'q' => $q,
            'status' => $status,
            'page' => $page,
            'pages' => max(1, (int) ceil($list['total'] / self::PER_PAGE)),
            'total' => $list['total'],
        ]);
    }

    /** @return array{id: int, role: ?string, fullName: string, username: string, active: bool, learnerLrn: ?string} */
    private function account(int $id): array
    {
        $result = $this->queryBus->handle(new GetAccountCommand($id));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException();
        }
        /** @var array{id: int, role: ?string, fullName: string, username: string, active: bool, learnerLrn: ?string} $account */
        $account = $result->data();

        return $account;
    }

    /**
     * Sends the form's command through the bus. Gives the result's data, or null after putting
     * the refusal on the form.
     *
     * @template T
     *
     * @param FormInterface<T> $form
     */
    private function submit(FormInterface $form, CreateAccountCommand|UpdateAccountCommand $command): mixed
    {
        $result = $this->useCaseBus->handle($command);
        $failure = $this->domainFailure($result);
        if (null !== $failure) {
            $this->showOnForm($form, $failure);
        }

        return null === $failure ? $result->data() : null;
    }

    /**
     * Puts a refusal under the field it is about.
     *
     * @template T
     *
     * @param FormInterface<T> $form
     */
    private function showOnForm(FormInterface $form, DomainException $failure): void
    {
        // Role refusals go under the role; the rest (a username taken a moment ago) under the username
        $isRoleRefusal = $failure instanceof AccountRoleChangeRefusedException;
        $field = $form->get($isRoleRefusal ? 'role' : 'username');
        foreach ($isRoleRefusal ? $failure->reasons() : [$failure->getMessage()] as $reason) {
            $field->addError(new FormError($reason));
        }
    }

    private function isSelf(string $username): bool
    {
        return $username === $this->getUser()?->getUserIdentifier();
    }

    private function actingUsername(): string
    {
        return $this->getUser()?->getUserIdentifier() ?? '';
    }

    private function redirectBack(Request $request): Response
    {
        $back = $request->request->getString('_back');

        // Only follow local paths, never another site
        if (str_starts_with($back, '/') && !str_starts_with($back, '//')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('admin_accounts');
    }
}
