<?php

namespace App\Sf1;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolAssignmentRepository;
use Symfony\Component\Clock\ClockInterface;

/** Gathers a section's SF1 (School Register) from its enrollments. */
class Sf1ReportBuilder
{
    public function __construct(
        private readonly EnrollmentRepository $enrollments,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly Sf1Remarks $remarks,
        private readonly ClockInterface $clock,
    ) {
    }

    public function build(Section $section): Sf1Report
    {
        $school = $section->getSchool();
        $schoolYear = $section->getSchoolYear();
        $startYear = (int) ($schoolYear->getStartDate()?->format('Y') ?? substr($schoolYear->getName(), 0, 4));
        // SF1 asks for the mother tongue in Grades 1 to 3 only
        $withMotherTongue = \in_array($section->getGradeLevel(), [GradeLevel::Grade1, GradeLevel::Grade2, GradeLevel::Grade3], true);

        $male = $female = [];
        $droppedMale = $droppedFemale = 0;
        foreach ($this->enrollments->findClassList($section) as $enrollment) {
            $isMale = SexAtBirth::Male === $enrollment->getLearner()->getSex();
            $row = $this->row($enrollment, $startYear, $withMotherTongue);
            $dropped = Sf1Remark::Dropped === $enrollment->getRemarkCode() ? 1 : 0;
            if ($isMale) {
                $male[] = $row;
                $droppedMale += $dropped;
            } else {
                $female[] = $row;
                $droppedFemale += $dropped;
            }
        }

        $now = $this->clock->now();
        $ended = null !== $schoolYear->getEndDate() && $schoolYear->getEndDate() < $now;
        $principal = $this->assignments->findPrincipal($school, $schoolYear)?->getUser();

        return new Sf1Report(
            schoolId: $school->getSchoolId(),
            region: $school->getRegion(),
            division: $school->getDivision(),
            schoolName: $school->getName(),
            schoolYear: $startYear.' - '.($startYear + 1),
            gradeLevel: $section->getGradeLevel()?->label() ?? '',
            section: mb_strtoupper($section->getName()),
            male: $male,
            female: $female,
            eosyMale: $ended ? \count($male) - $droppedMale : null,
            eosyFemale: $ended ? \count($female) - $droppedFemale : null,
            adviserName: null !== $section->getAdviser() ? mb_strtoupper($section->getAdviser()->getFullName()) : null,
            schoolHeadName: null !== $principal ? mb_strtoupper($principal->getFullName()) : null,
            generatedOn: $now,
        );
    }

    private function row(Enrollment $enrollment, int $startYear, bool $withMotherTongue): Sf1Row
    {
        $learner = $enrollment->getLearner();

        return new Sf1Row(
            lrn: $learner->getLrn(),
            name: $learner->getSf1Name(),
            sex: SexAtBirth::Male === $learner->getSex() ? 'M' : 'F',
            birthDate: $learner->getBirthDate()?->format('m-d-Y') ?? '',
            age: $learner->getAgeOnFirstFridayOfJune($startYear),
            motherTongue: $withMotherTongue ? $learner->getMotherTongue() : null,
            ipGroup: $learner->getIpGroup(),
            religion: $learner->getReligion(),
            street: $learner->getAddressStreet(),
            barangay: $learner->getAddressBarangay(),
            city: $learner->getAddressCity(),
            province: $learner->getAddressProvince(),
            father: $learner->getFatherSf1Name(),
            mother: $learner->getMotherSf1Name(),
            guardianName: $learner->getGuardianName(),
            guardianRelationship: $learner->getGuardianRelationship(),
            contactNumber: $learner->getContactNumber(),
            learningModality: $enrollment->getLearningModality()->label(),
            remarks: $this->remarks->textFor($enrollment),
        );
    }
}
