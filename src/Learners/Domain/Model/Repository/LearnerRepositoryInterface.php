<?php

declare(strict_types=1);

namespace App\Learners\Domain\Model\Repository;

use App\Access\Domain\Model\User;
use App\Learners\Domain\Model\Learner;

interface LearnerRepositoryInterface
{
    public function findById(int $id): ?Learner;

    public function findOneByLrn(string $lrn): ?Learner;

    /** @return list<Learner> */
    public function searchByLrnOrName(string $query, int $limit): array;

    public function findOneByUser(User $user): ?Learner;

    public function add(Learner $learner): void;
}
