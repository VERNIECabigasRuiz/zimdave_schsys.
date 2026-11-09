<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Domain;

use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingSystem;
use App\Schools\Domain\Model\SchoolType;
use PHPUnit\Framework\TestCase;

final class SchoolEnumsTest extends TestCase
{
    public function testSchoolTypesLabelsAndArticles(): void
    {
        // Execute
        $elementary = SchoolType::Elementary->withArticle();
        $junior = SchoolType::JuniorHigh->withArticle();
        $integrated = SchoolType::Integrated->withArticle();

        // Assert
        self::assertSame('an Elementary school', $elementary);
        self::assertSame('a Junior High School', $junior);
        self::assertSame('an Integrated School', $integrated);
        self::assertSame('Senior High School', SchoolType::SeniorHigh->label());
    }

    public function testSchoolTypesOfferTheirGradeLevels(): void
    {
        // Execute
        $elementary = SchoolType::Elementary->gradeLevels();
        $junior = SchoolType::JuniorHigh->gradeLevels();
        $senior = SchoolType::SeniorHigh->gradeLevels();
        $integrated = SchoolType::Integrated->gradeLevels();

        // Assert
        self::assertSame(GradeLevel::Kinder, $elementary[0]);
        self::assertSame(GradeLevel::Grade6, $elementary[6]);
        self::assertSame([GradeLevel::Grade7, GradeLevel::Grade8, GradeLevel::Grade9, GradeLevel::Grade10], $junior);
        self::assertSame([GradeLevel::Grade11, GradeLevel::Grade12], $senior);
        self::assertCount(13, $integrated);
    }

    public function testGradeLevelLabels(): void
    {
        // Execute
        $kinder = GradeLevel::Kinder->label();
        $grade7 = GradeLevel::Grade7->label();

        // Assert
        self::assertSame('Kindergarten', $kinder);
        self::assertSame('Grade 7', $grade7);
        self::assertTrue(GradeLevel::Grade11->isSeniorHigh());
        self::assertFalse(GradeLevel::Grade10->isSeniorHigh());
    }

    public function testGradingSystems(): void
    {
        // Execute
        $terms = GradingSystem::Terms->label();
        $quarters = GradingSystem::Quarters->label();

        // Assert
        self::assertSame('Terms (3)', $terms);
        self::assertSame('Quarters (4)', $quarters);
        self::assertSame('Quarter', GradingSystem::Quarters->periodName());
        self::assertSame(3, GradingSystem::Terms->periodCount());
    }
}
