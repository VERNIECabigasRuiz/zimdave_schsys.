<?php

namespace App\CardSlip;

use App\Entity\CardSlipComment;
use App\Entity\Enrollment;
use App\Entity\GradingPeriod;
use App\Entity\Section;
use App\Entity\User;
use App\Grading\GradeRules;
use App\Repository\CardSlipCommentRepository;
use App\Service\SchoolClock;
use Doctrine\ORM\EntityManagerInterface;

/** Saves the adviser's card slip comments; a learner without a saved row prints the suggested ones. */
class CardSlipCommentRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CardSlipCommentRepository $comments,
        private readonly GradeRules $rules,
        private readonly SchoolClock $clock,
    ) {
    }

    /**
     * Upserts the comments of the section's editable learners; entries for anyone else are skipped,
     * and so are codes not in the catalog. A blank typed comment is stored as none.
     *
     * @param array<int, array{codes: list<string>, text: ?string}> $entries by enrollment id
     *
     * @return int the number of learners saved
     */
    public function save(Section $section, GradingPeriod $period, array $entries, User $by): int
    {
        $editable = [];
        foreach ($this->rules->sheet($section) as $learner) {
            if ($learner->editable) {
                $editable[(int) $learner->enrollment->getId()] = $learner;
            }
        }
        $ids = array_values(array_intersect(array_keys($editable), array_map('intval', array_keys($entries))));
        $saved = $this->comments->findForEnrollments($ids, $period);
        $now = $this->clock->now();

        foreach ($ids as $id) {
            $entry = $entries[$id];
            $codes = array_values(array_unique(array_filter(
                array_map('strval', $entry['codes'] ?? []),
                static fn (string $code) => CardSlipComments::isKnown($code),
            )));
            $text = trim((string) ($entry['text'] ?? ''));

            $comment = $saved[$id] ?? null;
            if (null === $comment) {
                $comment = new CardSlipComment($editable[$id]->enrollment, $period);
                $this->entityManager->persist($comment);
            }
            $comment->update($codes, '' === $text ? null : $text, $by, $now);
        }
        $this->entityManager->flush();

        return \count($ids);
    }

    /** Deletes the learner's saved comments, so the slip prints the suggested ones again. */
    public function reset(Enrollment $enrollment, GradingPeriod $period): void
    {
        $comment = $this->comments->findForEnrollments([(int) $enrollment->getId()], $period)[$enrollment->getId()] ?? null;
        if (null !== $comment) {
            $this->entityManager->remove($comment);
            $this->entityManager->flush();
        }
    }
}
