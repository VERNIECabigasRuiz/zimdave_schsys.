<?php

namespace App\Tests\Entity;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\LearningModality;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf1Remark;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class EnrollmentSf1Test extends TestCase
{
    private function enrollment(): Enrollment
    {
        $sy = new SchoolYear();
        $section = (new Section(new School(), $sy))->setGradeLevel(GradeLevel::Grade8)->setName('Artistic');

        return new Enrollment(new Learner(new User(), new \DateTimeImmutable()), $sy, $section, new User(), new \DateTimeImmutable());
    }

    public function testFaceToFaceByDefaultWithoutRemark(): void
    {
        $enrollment = $this->enrollment();

        self::assertSame(LearningModality::FaceToFace, $enrollment->getLearningModality());
        self::assertSame('Face to Face', $enrollment->getLearningModality()->label());
        self::assertNull($enrollment->getManualRemarkText());
    }

    public function testManualRemarkText(): void
    {
        $enrollment = $this->enrollment()->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('Moved abroad')
            ->setRemarkDate(new \DateTimeImmutable('2025-09-01'));
        self::assertSame('DRP Moved abroad 2025-09-01', $enrollment->getManualRemarkText());

        $enrollment->setRemarkDetails(' ')->setRemarkDate(null);
        self::assertNull($enrollment->getRemarkDetails());
        self::assertSame('DRP', $enrollment->getManualRemarkText());
    }

    public function testRemarkCodes(): void
    {
        self::assertSame(['T/I', 'DRP', 'LE', 'CCT', 'B/A', 'SNED', 'ACL'], array_map(static fn (Sf1Remark $r) => $r->code(), Sf1Remark::cases()));
        self::assertSame('Balik Aral', Sf1Remark::BalikAral->label());
        self::assertSame('Name of school last attended & year', Sf1Remark::BalikAral->requiredInfo());
    }
}
