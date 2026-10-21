<?php

namespace App\Repository;

use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolAssignment>
 */
class SchoolAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolAssignment::class);
    }

    public function findPrincipal(School $school, SchoolYear $schoolYear): ?SchoolAssignment
    {
        return $this->findOneBy(['school' => $school, 'schoolYear' => $schoolYear, 'role' => User::ROLE_PRINCIPAL]);
    }

    /**
     * The school where $user works as $role in $schoolYear.
     */
    public function findSchoolOf(User $user, SchoolYear $schoolYear, string $role): ?School
    {
        return $this->findOneBy(['user' => $user, 'schoolYear' => $schoolYear, 'role' => $role])?->getSchool();
    }

    public function findForUser(User $user, SchoolYear $schoolYear): ?SchoolAssignment
    {
        return $this->findOneBy(['user' => $user, 'schoolYear' => $schoolYear]);
    }

    /**
     * @return array<int, SchoolAssignment> principal assignments keyed by school id
     */
    public function findPrincipalsBySchool(SchoolYear $schoolYear): array
    {
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
            $bySchool[$assignment->getSchool()->getId()] = $assignment;
        }

        return $bySchool;
    }

    /**
     * A principal assignment of $user in a school year that has not ended yet.
     */
    public function findUnfinishedPrincipalAssignment(User $user): ?SchoolAssignment
    {
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

    /**
     * @return array<int, string> school name keyed by user id
     */
    public function findSchoolNamesByUser(SchoolYear $schoolYear): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.user) AS userId', 's.name AS schoolName')
            ->join('a.school', 's')
            ->where('a.schoolYear = :sy')
            ->setParameter('sy', $schoolYear)
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'schoolName', 'userId');
    }

    /**
     * Active teachers per school in $schoolYear; these are the seats a plan limits.
     *
     * @return array<int, int> teacher count keyed by school id
     */
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
            ->setParameter('teacherRole', '%"'.User::ROLE_TEACHER.'"%')
            ->groupBy('a.school');
        if (null !== $school) {
            $qb->andWhere('a.school = :school')->setParameter('school', $school);
        }

        return array_map('intval', array_column($qb->getQuery()->getArrayResult(), 'teachers', 'schoolId'));
    }

    /**
     * Teachers of $school in $schoolYear (active or not), by name.
     *
     * @return list<SchoolAssignment>
     */
    public function findTeacherAssignments(School $school, SchoolYear $schoolYear): array
    {
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
            ->setParameter('teacherRole', '%"'.User::ROLE_TEACHER.'"%')
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Last year's teachers of $school who are not yet teachers of it in $current, by name.
     *
     * @return list<SchoolAssignment>
     */
    public function findTeachersToBringBack(School $school, SchoolYear $previous, SchoolYear $current): array
    {
        return $this->createQueryBuilder('a')
            ->addSelect('u')
            ->join('a.user', 'u')
            ->leftJoin(SchoolAssignment::class, 'now', 'WITH', 'now.user = u AND now.school = a.school AND now.schoolYear = :current')
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
            ->setParameter('teacherRole', '%"'.User::ROLE_TEACHER.'"%')
            ->orderBy('u.fullName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPrincipals(SchoolYear $schoolYear): int
    {
        return $this->count(['schoolYear' => $schoolYear, 'role' => User::ROLE_PRINCIPAL]);
    }
}
