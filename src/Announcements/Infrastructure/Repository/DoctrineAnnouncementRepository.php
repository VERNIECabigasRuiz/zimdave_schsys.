<?php

declare(strict_types=1);

namespace App\Announcements\Infrastructure\Repository;

use App\Announcements\Domain\Model\Announcement;
use App\Announcements\Domain\Model\Repository\AnnouncementRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Announcement>
 */
class DoctrineAnnouncementRepository extends ServiceEntityRepository implements AnnouncementRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Announcement::class);
    }

    public function findById(int $id): ?Announcement
    {
        return $this->find($id);
    }

    public function findNewestFirst(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function add(Announcement $announcement): void
    {
        $this->getEntityManager()->persist($announcement);
    }

    public function remove(Announcement $announcement): void
    {
        $this->getEntityManager()->remove($announcement);
    }
}
