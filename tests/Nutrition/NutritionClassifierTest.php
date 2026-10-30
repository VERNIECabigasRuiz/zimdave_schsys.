<?php

namespace App\Tests\Nutrition;

use App\Nutrition\BmiCategory;
use App\Nutrition\HfaCategory;
use App\Nutrition\NutritionClassifier;
use PHPUnit\Framework\TestCase;

class NutritionClassifierTest extends TestCase
{
    private NutritionClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new NutritionClassifier(dirname(__DIR__, 2));
    }

    public function testTablesCoverEveryMonth(): void
    {
        $root = dirname(__DIR__, 2).'/resources/who/';
        foreach (['bmi_for_age.php' => 4, 'height_for_age.php' => 3] as $file => $width) {
            $table = require $root.$file;
            self::assertSame(['male', 'female'], array_keys($table), $file);
            foreach (['male', 'female'] as $sex) {
                self::assertSame(range(60, 228), array_keys($table[$sex]), "$file $sex");
                foreach ($table[$sex] as $month => $bounds) {
                    self::assertCount($width, $bounds, "$file $sex $month");
                    self::assertTrue(array_is_list($bounds), "$file $sex $month");
                    self::assertContainsOnlyFloat($bounds,"$file $sex $month");
                }
            }
        }
    }

    public function testBoyBmiBoundsAt60Months(): void
    {
        $expected = [
            [12.0, BmiCategory::SeverelyWasted],
            [12.05, BmiCategory::Wasted],
            [12.9, BmiCategory::Wasted],
            [12.95, BmiCategory::Normal],
            [18.3, BmiCategory::Normal],
            [18.35, BmiCategory::Overweight],
            [20.2, BmiCategory::Overweight],
            [20.25, BmiCategory::Obese],
        ];
        foreach ($expected as [$bmi, $category]) {
            self::assertSame($category, $this->classifier->bmiCategory(true, 60, $bmi), (string) $bmi);
        }
    }

    /** Some template bounds carry two decimals (boy 97 months: overweight to 22.87); they must not be rounded. */
    public function testTwoDecimalBoundsKeptExactly(): void
    {
        self::assertSame(BmiCategory::Overweight, $this->classifier->bmiCategory(true, 97, 22.87));
        self::assertSame(BmiCategory::Obese, $this->classifier->bmiCategory(true, 97, 22.88));
        self::assertSame(BmiCategory::Normal, $this->classifier->bmiCategory(true, 156, 24.79));
        self::assertSame(BmiCategory::Overweight, $this->classifier->bmiCategory(true, 156, 24.8));
    }

    public function testGirlBmiAt228Months(): void
    {
        self::assertSame(BmiCategory::SeverelyWasted, $this->classifier->bmiCategory(false, 228, 14.6));
        self::assertSame(BmiCategory::Normal, $this->classifier->bmiCategory(false, 228, 16.5));
        self::assertSame(BmiCategory::Overweight, $this->classifier->bmiCategory(false, 228, 29.8));
        self::assertSame(BmiCategory::Obese, $this->classifier->bmiCategory(false, 228, 36.3));
    }

    public function testHfaAt60Months(): void
    {
        self::assertSame(HfaCategory::SeverelyStunted, $this->classifier->hfaCategory(true, 60, 96.0));
        self::assertSame(HfaCategory::Stunted, $this->classifier->hfaCategory(true, 60, 100.0));
        self::assertSame(HfaCategory::Normal, $this->classifier->hfaCategory(true, 60, 119.2));
        self::assertSame(HfaCategory::Tall, $this->classifier->hfaCategory(true, 60, 119.3));
        self::assertSame(HfaCategory::Normal, $this->classifier->hfaCategory(false, 60, 99.9));
    }

    public function testHfaBoyAt228Months(): void
    {
        self::assertSame(HfaCategory::SeverelyStunted, $this->classifier->hfaCategory(true, 228, 154.5));
    }

    public function testAgeInMonths(): void
    {
        self::assertSame(60, $this->classifier->ageInMonths(new \DateTimeImmutable('2020-08-22'), new \DateTimeImmutable('2025-08-22')));
        self::assertSame(59, $this->classifier->ageInMonths(new \DateTimeImmutable('2020-08-23'), new \DateTimeImmutable('2025-08-22')));
        self::assertSame(60, $this->classifier->ageInMonths(new \DateTimeImmutable('2020-01-31'), new \DateTimeImmutable('2025-02-28')));
        self::assertSame(0, $this->classifier->ageInMonths(new \DateTimeImmutable('2025-09-01'), new \DateTimeImmutable('2025-08-22')));
    }

    public function testOutOfRangeNotComputed(): void
    {
        $on = new \DateTimeImmutable('2025-08-22');

        $kinder = $this->classifier->classify(true, new \DateTimeImmutable('2020-09-22'), $on, 20.0, 1.10);
        self::assertSame(59, $kinder->ageMonths);
        self::assertEqualsWithDelta(16.53, $kinder->bmi, 0.01);
        self::assertNull($kinder->bmiCategory);
        self::assertNull($kinder->hfaCategory);

        $older = $this->classifier->classify(false, new \DateTimeImmutable('2006-07-22'), $on, 55.0, 1.60);
        self::assertSame(229, $older->ageMonths);
        self::assertNotNull($older->bmi);
        self::assertNull($older->bmiCategory);
        self::assertNull($older->hfaCategory);

        self::assertNull($this->classifier->bmiCategory(true, 59, 16.0));
        self::assertNull($this->classifier->hfaCategory(true, 229, 170.0));

        $noBirthDate = $this->classifier->classify(true, null, $on, 30.0, 1.30);
        self::assertNull($noBirthDate->ageMonths);
        self::assertNull($noBirthDate->ageYears());
        self::assertNotNull($noBirthDate->bmi);
        self::assertNull($noBirthDate->bmiCategory);
        self::assertNull($noBirthDate->hfaCategory);
    }

    public function testClassify(): void
    {
        $born = new \DateTimeImmutable('2015-03-10');
        $on = new \DateTimeImmutable('2025-08-22');

        $result = $this->classifier->classify(true, $born, $on, 31.5, 1.32);
        self::assertSame(125, $result->ageMonths);
        self::assertSame(10, $result->ageYears());
        self::assertEqualsWithDelta(18.08, $result->bmi, 0.01);
        self::assertNotNull($result->bmiCategory);
        self::assertSame($this->classifier->bmiCategory(true, 125, 18.08), $result->bmiCategory);
        self::assertSame($this->classifier->hfaCategory(true, 125, 132.0), $result->hfaCategory);

        $heightOnly = $this->classifier->classify(true, $born, $on, null, 1.32);
        self::assertNull($heightOnly->bmi);
        self::assertNull($heightOnly->bmiCategory);
        self::assertNotNull($heightOnly->hfaCategory);

        $weightOnly = $this->classifier->classify(true, $born, $on, 31.5, null);
        self::assertNull($weightOnly->bmi);
        self::assertNull($weightOnly->bmiCategory);
        self::assertNull($weightOnly->hfaCategory);
    }
}
