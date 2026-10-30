<?php

namespace App\Nutrition;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Classifies BMI-for-age and height-for-age against the WHO reference copied from the SF8 template
 * (resources/who). Comparisons are the template's: a value above a bound falls in the next category,
 * a value exactly on a bound stays in the lower one. Ages outside 60..228 months are not classified.
 */
class NutritionClassifier
{
    public const MIN_MONTHS = 60;
    public const MAX_MONTHS = 228;

    /** @var array{male: array<int, list<float>>, female: array<int, list<float>>}|null */
    private ?array $bmiTable = null;

    /** @var array{male: array<int, list<float>>, female: array<int, list<float>>}|null */
    private ?array $hfaTable = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /** Completed months from birth to $on; 0 if $on is before birth. */
    public function ageInMonths(\DateTimeImmutable $birthDate, \DateTimeImmutable $on): int
    {
        $diff = $birthDate->diff($on);
        if (1 === $diff->invert) {
            return 0;
        }

        return $diff->y * 12 + $diff->m;
    }

    public function bmiCategory(bool $male, int $months, float $bmi): ?BmiCategory
    {
        if (!self::inRange($months)) {
            return null;
        }
        $this->bmiTable ??= $this->load('bmi_for_age.php');
        [$severelyWastedMax, $wastedTo, $normalTo, $overweightTo] = $this->bmiTable[$male ? 'male' : 'female'][$months];

        return match (true) {
            $bmi > $overweightTo => BmiCategory::Obese,
            $bmi > $normalTo => BmiCategory::Overweight,
            $bmi > $wastedTo => BmiCategory::Normal,
            $bmi > $severelyWastedMax => BmiCategory::Wasted,
            default => BmiCategory::SeverelyWasted,
        };
    }

    public function hfaCategory(bool $male, int $months, float $heightCm): ?HfaCategory
    {
        if (!self::inRange($months)) {
            return null;
        }
        $this->hfaTable ??= $this->load('height_for_age.php');
        [$severelyStuntedMax, $stuntedTo, $normalTo] = $this->hfaTable[$male ? 'male' : 'female'][$months];

        return match (true) {
            $heightCm > $normalTo => HfaCategory::Tall,
            $heightCm > $stuntedTo => HfaCategory::Normal,
            $heightCm > $severelyStuntedMax => HfaCategory::Stunted,
            default => HfaCategory::SeverelyStunted,
        };
    }

    public function classify(bool $male, ?\DateTimeImmutable $birthDate, \DateTimeImmutable $weighedOn, ?float $weightKg, ?float $heightM): NutritionResult
    {
        $months = null === $birthDate ? null : $this->ageInMonths($birthDate, $weighedOn);
        $bmi = null !== $weightKg && null !== $heightM && $heightM > 0 ? $weightKg / ($heightM * $heightM) : null;

        return new NutritionResult(
            $months,
            $bmi,
            null !== $months && null !== $bmi ? $this->bmiCategory($male, $months, $bmi) : null,
            // Rounded so float noise (1.09 * 100 = 109.00000000000001) cannot lift an on-bound height into the
            // next category; Excel's 15-digit precision gives the template the same result. Bounds have <= 2 dp.
            null !== $months && null !== $heightM ? $this->hfaCategory($male, $months, round($heightM * 100, 2)) : null,
        );
    }

    private static function inRange(int $months): bool
    {
        return $months >= self::MIN_MONTHS && $months <= self::MAX_MONTHS;
    }

    /** @return array{male: array<int, list<float>>, female: array<int, list<float>>} */
    private function load(string $file): array
    {
        return require $this->projectDir.'/resources/who/'.$file;
    }
}
