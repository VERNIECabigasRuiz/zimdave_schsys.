<?php

declare(strict_types=1);

namespace App\Tests\Nutrition\Unit\Presentation;

use App\Nutrition\Presentation\Form\NutritionSheet;
use App\Nutrition\Presentation\Form\NutritionSheetFormType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;

final class NutritionSheetFormTypeTest extends TestCase
{
    public function testASheetPostedAsPlainTextIsRefusedWithoutMatchingTheRoster(): void
    {
        // Initialize
        $form = Forms::createFormFactory()->create(NutritionSheetFormType::class, new NutritionSheet());

        // Execute
        $form->submit('not a sheet');

        // Assert
        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid());
        foreach ($form->getErrors() as $error) {
            self::assertNotSame(NutritionSheetFormType::ROSTER_CHANGED, $error->getMessage());
        }
    }
}
