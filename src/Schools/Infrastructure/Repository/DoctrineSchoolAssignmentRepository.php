<?php

declare(strict_types=1);

namespace App\Schools\Infrastructure\Repository;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolYear;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolAssignment>
 */
class DoctrineSchoolAssignmentRepository extends ServiceEntityRepository implements SchoolAssignmentRepositoryInterface
{
    // Roles are a JSON list such as ["ROLE_TEACHER"]
    private const TEACHER_ROLE = '%"' . User::ROLE_TEACHER . '"%';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolAssignment::class);
    }

    public function findPrincipal(School $school, SchoolYear $schoolYear): ?SchoolAssignment
    {
        return $this->findOneBy(['school' => $school, 'schoolYear' => $schoolYear, 'role' => User::ROLE_PRINCIPAL]);
    }

    public function findSchoolOf(User $user, SchoolYear $schoolYear, string $role): ?School
    {
        return $this->findOneBy(['user' => $user, 'schoolYear' => $schoolYear, 'role' => $role])?->getSchool();
    }

    public function findForUser(User $user, SchoolYear $schoolYear): ?SchoolAssignment
    {
        return $this->findOneBy(['user' => $user, 'schoolYear' => $schoolYear]);
    }

    public function findPrincipalsBySchool(SchoolYear $schoolYear): array
    {
        /** @var list<SchoolAssignment> $assignments */
        $assignments = $this->createQueryBuilder('a')
            ->addSelect('s', 'u')
            ->join('a.school', 's')
            ->join('a.user', 'u')
            ->where('a.schoolYear = :sy')
            ->andWhere('a.role = :role')
            ->setParameter('sy', $schoolYear)
            ->setParameter('role', User::ROLE_PRINCIPAL)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        $bySchool = [];
        foreach ($assignments as $assignment) {
            $bySchool[(int) $assignment->getSchool()->getId()] = $assignment;
        }

        return $bySchool;
    }

    public function findUnfinishedPrincipalAssignment(User $user): ?SchoolAssignment
    {
        /** @var SchoolAssignment|null */
        return $this->createQueryBuilder('a')
            ->addSelect('s', 'sy')
            ->join('a.school', 's')
            ->join('a.schoolYear', 'sy')
            ->where('a.user = :user')
            ->andWhere('a.role = :role')
            ->andWhere('sy.endDate >= :today')
            ->setParameter('user', $user)
            ->setParameter('role', User::ROLE_PRINCIPAL)
            ->setParameter('today', new \DateTimeImmutable('today'), 'date_immutable')
            ->orderBy('sy.startDate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findSchoolNamesByUser(SchoolYear $schoolYear): array
    {
        /** @var list<array{userId: int|string, schoolName: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.user) AS userId', 's.name AS schoolName')
            ->join('a.school', 's')
            ->where('a.schoolYear = :sy')
            ->setParameter('sy', $schoolYear)
            ->getQuery()
            ->getArrayResult();

        $names = [];
        foreach ($rows as $row) {
            $names[(int) $row['userId']] = $row['schoolName'];
        }

        return $names;
    }

    public function countTeachersBySchool(SchoolYear $schoolYear, ?School $school = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.school) AS schoolId', 'COUNT(a.id) AS teachers')
            ->join('a.user', 'u')
            ->where('a.schoolYear = :sy')
            ->andWhere('a.role = :role')
            ->andWhere('u.active = true')
            ->setParameter('sy', $schoolYear)
            ->setParameter('role', User::ROLE_TEACHER)
            // The account must still be a teacher; a teacher promoted elsewhere keeps an old assignment
            ->andWhere('u.roles LIKE :teacherRole')
            ->setParameter('teacherRole', self::TEACHER_ROLE)
            ->groupBy('a.school');
        if (null !== $school) {
            $qb->andWhere('a.school = :school')->setParameter('school', $school);
        }

        /** @var list<array{schoolId: int|string, teachers: int|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['schoolId']] = (int) $row['teachers'];
        }

        return $counts;
    }

    public function findTeacherAssignments(School $school, SchoolYear $schoolYear): array
    {
        /** @var list<SchoolAssignment> */
        return $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.user', 'u')
            ->where('a.school = :school')
            ->andWhere('a.schoolYear = :sy')
            ->andWhere('a.role = :role')
            ->setParameter('school', $school)
            ->setParameter('sy', $schoolYear)
            ->setParameter('role', User::ROLE_TEACHER)
            // The account must still be a teacher; a teacher promoted elsewhere keeps an old assignment
            ->andWhere('u.roles LIKE :teacherRole')
            ->setParameter('teacherRole', self::TEACHER_ROLE)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAssignedTeachers(School $school, SchoolYear $schoolYear): array
    {
        /** @var list<User> */
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin(SchoolAssignment::class, 'a', 'WITH', 'a.user = u')
            ->andWhere('a.school = :school')->setParameter('school', $school)
            ->andWhere('a.schoolYear = :sy')->setParameter('sy', $schoolYear)
            ->andWhere('a.role = :role')->setParameter('role', User::ROLE_TEACHER)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findTeachersToBringBack(School $school, SchoolYear $previous, SchoolYear $current): array
    {
        /** @var list<SchoolAssignment> */
        return $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.user', 'u')
            ->leftJoin(
                SchoolAssignment::class,
                'now',
                'WITH',
                'now.user = u AND now.school = a.school AND now.schoolYear = :current',
            )
            ->where('a.school = :school')
            ->andWhere('a.schoolYear = :previous')
            ->andWhere('a.role = :role')
            ->andWhere('now.id IS NULL')
            ->setParameter('school', $school)
            ->setParameter('previous', $previous)
            ->setParameter('current', $current)
            ->setParameter('role', User::ROLE_TEACHER)
            // The account must still be a teacher; a teacher promoted elsewhere keeps an old assignment
            ->andWhere('u.roles LIKE :teacherRole')
            ->setParameter('teacherRole', self::TEACHER_ROLE)
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPrincipals(SchoolYear $schoolYear): int
    {
        return $this->count(['schoolYear' => $schoolYear, 'role' => User::ROLE_PRINCIPAL]);
    }

    public function findAssignablePrincipals(SchoolYear $schoolYear, ?User $include = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->leftJoin(SchoolAssignment::class, 'a', 'WITH', 'a.user = u AND a.schoolYear = :sy')
            ->andWhere('u.active = true')
            ->andWhere(null !== $include ? '(a.id IS NULL OR u = :include)' : 'a.id IS NULL')
            ->andWhere('u.roles LIKE :principalRole')
            ->setParameter('sy', $schoolYear)
            ->setParameter('principalRole', '%"' . User::ROLE_PRINCIPAL . '"%')
            ->orderBy('u.fullName', 'ASC');
        if (null !== $include) {
            $qb->setParameter('include', $include);
        }

        /** @var list<User> */
        return $qb->getQuery()->getResult();
    }

    public function add(SchoolAssignment $assignment): void
    {
        $this->getEntityManager()->persist($assignment);
    }

    public function remove(SchoolAssignment $assignment): void
    {
        $this->getEntityManager()->remove($assignment);
    }
}
