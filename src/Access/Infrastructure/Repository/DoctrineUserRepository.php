<?php

declare(strict_types=1);

namespace App\Access\Infrastructure\Repository;

use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Access\Domain\Model\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class DoctrineUserRepository extends ServiceEntityRepository implements
    UserRepositoryInterface,
    PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findById(int $id): ?User
    {
        return $this->find($id);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => mb_strtolower(trim($username))]);
    }

    public function add(User $user): void
    {
        $this->getEntityManager()->persist($user);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->flush();
    }

    public function search(?string $query, ?string $role, ?bool $active, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC');

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('u.username LIKE :q OR u.fullName LIKE :q')
                ->setParameter('q', '%' . addcslashes(trim($query), '%_') . '%');
        }
        if (null !== $role) {
            $this->whereRole($qb, $role);
        }
        if (null !== $active) {
            $qb->andWhere('u.active = :active')->setParameter('active', $active);
        }

        $qb->setFirstResult(max(0, $page - 1) * $perPage)->setMaxResults($perPage);
        /** @var Paginator<User> $paginator */
        $paginator = new Paginator($qb);

        return ['items' => array_values(iterator_to_array($paginator)), 'total' => \count($paginator)];
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    public function countByRole(string $role): int
    {
        $qb = $this->createQueryBuilder('u')->select('COUNT(u.id)');
        $this->whereRole($qb, $role);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countInactive(): int
    {
        return $this->count(['active' => false]);
    }

    private function whereRole(QueryBuilder $qb, string $role): void
    {
        // roles is a JSON list such as ["ROLE_TEACHER"]
        $param = 'role_' . strtolower($role);
        $qb->andWhere(\sprintf('u.roles LIKE :%s', $param))
            ->setParameter($param, '%"' . $role . '"%');
    }
}
