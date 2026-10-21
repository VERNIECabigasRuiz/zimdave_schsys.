<?php

namespace App\Repository;

use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\User;
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
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
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

    /**
     * Accounts matching the given filters, newest first.
     *
     * @param ?string $role   one of User::ROLE_LABELS keys
     * @param ?bool   $active true = active only, false = inactive only
     *
     * @return Paginator<User>
     */
    public function search(?string $query = null, ?string $role = null, ?bool $active = null, int $page = 1, int $perPage = 20): Paginator
    {
        $qb = $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->addOrderBy('u.id', 'DESC');

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('u.username LIKE :q OR u.fullName LIKE :q')
                ->setParameter('q', '%'.addcslashes(trim($query), '%_').'%');
        }
        if (null !== $role) {
            $this->whereRole($qb, $role);
        }
        if (null !== $active) {
            $qb->andWhere('u.active = :active')->setParameter('active', $active);
        }

        $qb->setFirstResult(max(0, $page - 1) * $perPage)->setMaxResults($perPage);

        return new Paginator($qb);
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

    public function countActiveAdmins(): int
    {
        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.active = true');
        $this->whereRole($qb, User::ROLE_ADMIN);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Active principals with no school in $schoolYear, plus $include (the school's current principal).
     *
     * @return list<User>
     */
    public function findAssignablePrincipals(SchoolYear $schoolYear, ?User $include = null): array
    {
        $qb = $this->createQueryBuilder('u')
            ->leftJoin(SchoolAssignment::class, 'a', 'WITH', 'a.user = u AND a.schoolYear = :sy')
            ->andWhere('u.active = true')
            ->andWhere(null !== $include ? '(a.id IS NULL OR u = :include)' : 'a.id IS NULL')
            ->setParameter('sy', $schoolYear)
            ->orderBy('u.fullName', 'ASC');
        if (null !== $include) {
            $qb->setParameter('include', $include);
        }
        $this->whereRole($qb, User::ROLE_PRINCIPAL);

        return $qb->getQuery()->getResult();
    }

    private function whereRole(QueryBuilder $qb, string $role): void
    {
        // roles is a JSON list such as ["ROLE_TEACHER"]
        $param = 'role_'.strtolower($role);
        $qb->andWhere(\sprintf('u.roles LIKE :%s', $param))
            ->setParameter($param, '%"'.$role.'"%');
    }
}
