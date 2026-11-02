<?php

namespace App\Nutrition;

/**
 * SF8's summary table, by sex. weighed() counts every weighed learner; bmi() and hfa() count only
 * those with a computed category, so learners outside the WHO 5–19 range are left out of them.
 */
final readonly class Sf8Summary
{
    private const MALE = 0;
    private const FEMALE = 1;

    /**
     * @param array{array<string, int>, array<string, int>} $bmi      by sex, then BmiCategory value
     * @param array{array<string, int>, array<string, int>} $hfa      by sex, then HfaCategory value
     * @param array{int, int}                               $weighed  by sex
     * @param array{int, int}                               $onRoster by sex
     */
    private function __construct(
        private array $bmi,
        private array $hfa,
        private array $weighed,
        private array $onRoster,
    ) {
    }

    /** @param list<Sf8Row> $rows */
    public static function fromRows(array $rows): self
    {
        $bmi = $hfa = [[], []];
        $weighed = $onRoster = [0, 0];
        foreach ($rows as $row) {
            $g = $row->entry->male ? self::MALE : self::FEMALE;
            ++$onRoster[$g];
            if (!$row->weighed) {
                continue;
            }
            ++$weighed[$g];
            if (null !== $category = $row->result->bmiCategory) {
                $bmi[$g][$category->value] = ($bmi[$g][$category->value] ?? 0) + 1;
            }
            if (null !== $category = $row->result->hfaCategory) {
                $hfa[$g][$category->value] = ($hfa[$g][$category->value] ?? 0) + 1;
            }
        }

        return new self($bmi, $hfa, $weighed, $onRoster);
    }

    /** @param list<Sf8Summary> $summaries */
    public static function sum(array $summaries): self
    {
        $bmi = $hfa = [[], []];
        $weighed = $onRoster = [0, 0];
        foreach ($summaries as $summary) {
            foreach ([self::MALE, self::FEMALE] as $g) {
                foreach ($summary->bmi[$g] as $category => $n) {
                    $bmi[$g][$category] = ($bmi[$g][$category] ?? 0) + $n;
                }
                foreach ($summary->hfa[$g] as $category => $n) {
                    $hfa[$g][$category] = ($hfa[$g][$category] ?? 0) + $n;
                }
                $weighed[$g] += $summary->weighed[$g];
                $onRoster[$g] += $summary->onRoster[$g];
            }
        }

        return new self($bmi, $hfa, $weighed, $onRoster);
    }

    /** @param ?bool $male null for both sexes */
    public function bmi(BmiCategory $category, ?bool $male = null): int
    {
        return self::pick(array_map(static fn (array $counts) => $counts[$category->value] ?? 0, $this->bmi), $male);
    }

    /** @param ?bool $male null for both sexes */
    public function hfa(HfaCategory $category, ?bool $male = null): int
    {
        return self::pick(array_map(static fn (array $counts) => $counts[$category->value] ?? 0, $this->hfa), $male);
    }

    /** Learners with both weight and height, whether or not their categories could be computed. */
    public function weighed(?bool $male = null): int
    {
        return self::pick($this->weighed, $male);
    }

    public function onRoster(?bool $male = null): int
    {
        return self::pick($this->onRoster, $male);
    }

    /** @param array{int, int} $bySex */
    private static function pick(array $bySex, ?bool $male): int
    {
        return match ($male) {
            null => $bySex[self::MALE] + $bySex[self::FEMALE],
            true => $bySex[self::MALE],
            false => $bySex[self::FEMALE],
        };
    }
}
