# SF1 School Register Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Store DepEd SF1 learner data (personal info on `Learner`, per-year modality/remarks on `Enrollment`), let principal/admin/adviser edit it, and download a section's SF1 as an `.xls` laid out like the LIS file.

**Architecture:** New nullable columns on `Learner` and `Enrollment` with formatting helpers on the entities. A small `App\Sf1` namespace: `Sf1Remarks` (remarks text incl. automatic T/I), `Sf1ReportBuilder` (queries → readonly `Sf1Report`/`Sf1Row` DTOs), `Sf1Writer` (DTO → PhpSpreadsheet workbook, layout only). Controllers stay thin and reuse `AbstractLearnerController` + `LearnerScope`.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM + migrations, Twig, Symfony Form/Validator, `phpoffice/phpspreadsheet` (Xls writer), PHPUnit (`KernelTestCase`, `WebTestCase` via `AdminTestCase`).

**Spec:** `docs/superpowers/specs/2026-10-29-sf1-school-register-design.md`

## Global Constraints

- Branch `feature/sf1`. Every commit message ends with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- All new `Learner`/`Enrollment` SF1 fields are optional (nullable), except `Enrollment::learningModality` which defaults to `LearningModality::FaceToFace`. Blank strings are stored as `null`.
- Schema changes only through `bin/console make:migration` + `doctrine:migrations:migrate` (also run for the test DB: `--env=test`).
- Editing is guarded by the existing `LearnerVoter::EDIT`; SF1 download by `requireSection()` + `LearnerVoter::VIEW_CLASS`. No new voter attributes.
- Current school year only (class lists, enrollment record, SF1).
- File name: `SF1_{SY start year}_{grade label} - {SECTION NAME}.xls`; content type `application/vnd.ms-excel`; legal paper, landscape, fit to one page wide.
- Upper-case with `mb_strtoupper` (names contain Ñ).
- Running PHPUnit on this laptop: Avast can suspend `php.exe`. Run via PowerShell `Start-Process php -ArgumentList 'bin/phpunit','--filter','X' -NoNewWindow -PassThru -RedirectStandardOutput <scratch file>` + `Wait-Process -Timeout 600`, retry on timeout; do not pipe phpunit in the Bash tool. Commands below are written as `php bin/phpunit …` for brevity.
- Code style `@Symfony`; match surrounding comment density and idiom (short `/** … */` docblocks, `static fn`, `\sprintf`).

## Review Focus

1. **Names with Ñ / accents** (`BAÑEZ`, `IDAÑO`): must upper-case correctly and survive the Xls round trip → test in Task 1 (`getSf1Name`) and Task 7 (writer cell).
2. **A section with no learners**: download still works, totals 0/0/0, no empty-range errors → tests in Task 6 and Task 8.
3. **No adviser / no principal assigned** (plans without a principal): signature names blank, no null errors → test in Task 6.
4. **Learner transferred in, then moved to another section of the new school**: automatic T/I still shows → test in Task 3.
5. **Over-long input** (remark details > 150, address > column length): 422 with a validation message, not a DB error → tests in Task 2 and Task 4.

---

### Task 1: Learner SF1 fields and formatting helpers

**Files:**
- Modify: `src/Entity/Learner.php`
- Create: `migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Modify: `composer.json`, `composer.lock`, `symfony.lock` (via composer)
- Test: `tests/Entity/LearnerSf1Test.php`

**Interfaces:**
- Produces on `Learner` (getter + fluent setter each; setters trim and turn `''` into `null`):
  `motherTongue` ?string(50), `ipGroup` ?string(50), `religion` ?string(50), `addressStreet` ?string(100), `addressBarangay` ?string(50), `addressCity` ?string(50), `addressProvince` ?string(50), `fatherLastName`/`fatherFirstName`/`fatherMiddleName` ?string(50), `motherLastName`/`motherFirstName`/`motherMiddleName` ?string(50), `guardianName` ?string(100), `guardianRelationship` ?string(30), `contactNumber` ?string(20). Each with `#[Assert\Length(max: N)]`.
- `getSf1Name(): string` — `LAST, FIRST EXT, MIDDLE` upper case; missing middle → `-`.
- `getFatherSf1Name(): ?string`, `getMotherSf1Name(): ?string` — `LAST, FIRST MIDDLE` upper case; `null` when both last and first are null.
- `getAgeOnFirstFridayOfJune(int $year): ?int` — whole years on `new \DateTimeImmutable("first friday of june $year")`; `null` without birthdate.
- `isSf1Incomplete(): bool` — true if any of barangay/city/province is null, or no parent (father last+first, or mother last+first) and no guardian name, or no contact number.

- [ ] **Step 1: Install PhpSpreadsheet**

Run: `composer require phpoffice/phpspreadsheet`
Expected: package installed, no recipe errors. (Installed here because Task 7 needs it; doing it first keeps the lock change in one commit.)

- [ ] **Step 2: Write the failing tests** in `tests/Entity/LearnerSf1Test.php` (`KernelTestCase` like `tests/Entity/LearnerTest.php`, validator from container)

```php
public function testSf1NameUpperCasesWithExtensionAndMiddle(): void
{
    $l = $this->learner('Bañez', 'Remuel', 'Bravo')->setExtension('Jr');
    self::assertSame('BAÑEZ, REMUEL JR, BRAVO', $l->getSf1Name());
}
public function testSf1NameWithoutMiddlePrintsDash(): void
{
    self::assertSame('BORJA, JUSTINE, -', $this->learner('Borja', 'Justine', null)->getSf1Name());
}
public function testParentNames(): void
{
    $l = $this->learner()->setFatherLastName('Arnol')->setFatherFirstName('Gerald')->setFatherMiddleName('Abuhat')
        ->setMotherLastName('Molina')->setMotherFirstName('Angelle');
    self::assertSame('ARNOL, GERALD ABUHAT', $l->getFatherSf1Name());
    self::assertSame('MOLINA, ANGELLE', $l->getMotherSf1Name());
    self::assertNull($this->learner()->getFatherSf1Name());
}
public function testAgeOnFirstFridayOfJune(): void
{
    // first Friday of June 2025 is 2025-06-06
    self::assertSame(13, $this->learner()->setBirthDate(new \DateTimeImmutable('2012-06-06'))->getAgeOnFirstFridayOfJune(2025));
    self::assertSame(12, $this->learner()->setBirthDate(new \DateTimeImmutable('2012-06-07'))->getAgeOnFirstFridayOfJune(2025));
}
public function testBlankStringsBecomeNull(): void
{
    self::assertNull($this->learner()->setReligion('  ')->getReligion());
}
public function testIncompleteness(): void
{
    $l = $this->learner();
    self::assertTrue($l->isSf1Incomplete());
    $l->setAddressBarangay('Pagsabungan')->setAddressCity('Mandaue City')->setAddressProvince('Cebu')
        ->setMotherLastName('Molina')->setMotherFirstName('Angelle')->setContactNumber('09171234567');
    self::assertFalse($l->isSf1Incomplete());
    $l->setMotherFirstName(null)->setGuardianName('Lola Nena');
    self::assertFalse($l->isSf1Incomplete());
    $l->setContactNumber(null);
    self::assertTrue($l->isSf1Incomplete());
}
public function testTooLongStreetIsInvalid(): void
{
    $l = $this->learner()->setAddressStreet(str_repeat('x', 101));
    self::assertNotEmpty($this->validator->validate($l));
}
```

(`learner(string $last = 'Dela Cruz', string $first = 'Juan', ?string $middle = 'Santos')` is a local helper building an unpersisted `Learner` like `LearnerTest::learner()`.)

- [ ] **Step 3: Run to verify failure**

Run: `php bin/phpunit tests/Entity/LearnerSf1Test.php`
Expected: FAIL — undefined methods.

- [ ] **Step 4: Add the fields, setters and helpers to `Learner`**

Use a private static `blankToNull(?string): ?string` like `School::blankToNull`. ORM columns `#[ORM\Column(length: N, nullable: true)]`.

- [ ] **Step 5: Run to verify pass**

Run: `php bin/phpunit tests/Entity/LearnerSf1Test.php` → PASS.

- [ ] **Step 6: Migration**

Run: `php bin/console make:migration --no-interaction`, check it only adds the nullable `learner` columns, then `php bin/console doctrine:migrations:migrate --no-interaction` and the same with `--env=test`.

- [ ] **Step 7: Commit**

```bash
git add composer.json composer.lock symfony.lock src/Entity/Learner.php migrations tests/Entity/LearnerSf1Test.php
git commit -m "feat: SF1 personal info fields on learners"
```

---

### Task 2: Learner details form, show page and student dashboard

**Files:**
- Modify: `src/Form/LearnerFormType.php`
- Modify: `templates/learners/_fields.html.twig`, `templates/learners/_details.html.twig`
- Modify: `templates/student/dashboard.html.twig`, `src/Controller/StudentController.php` (only if the learner isn't already passed)
- Test: `tests/Controller/SchoolLearnerControllerTest.php`, `tests/Controller/TeacherClassControllerTest.php`, `tests/Controller/Admin/LearnerControllerTest.php` (or wherever admin edits through `school_learners_edit`), `tests/Controller/StudentDashboardTest.php`

**Interfaces:**
- Consumes: Task 1 `Learner` properties.
- Produces: form fields `learner_form[motherTongue]`, `[ipGroup]`, `[religion]`, `[addressStreet]`, `[addressBarangay]`, `[addressCity]`, `[addressProvince]`, `[fatherLastName]`, `[fatherFirstName]`, `[fatherMiddleName]`, `[motherLastName]`, `[motherFirstName]`, `[motherMiddleName]`, `[guardianName]`, `[guardianRelationship]`, `[contactNumber]` (all `TextType`, `required => false`). The enroll form embeds the same type, so its names are `enroll_section_form[learner][…]`.

- [ ] **Step 1: Write failing HTTP tests**

- `SchoolLearnerControllerTest::testPrincipalSavesSf1Fields`: GET edit, `submitForm('Save', ['learner_form[religion]' => 'Christianity', 'learner_form[addressBarangay]' => 'Pagsabungan', 'learner_form[motherLastName]' => 'Molina', 'learner_form[contactNumber]' => '09171234567'])` → redirect to show; `findLearner()` has those values; show page `assertSelectorTextContains('.info-list', 'Pagsabungan')`.
- `testSf1FieldTooLongIs422`: `learner_form[addressStreet]` = 101 × `x` → 422, value not saved.
- Same save test as admin (logged-in admin on the school pages).
- `TeacherClassControllerTest::testAdviserSavesSf1Fields` via `/teacher/class/{id}/edit` with `learner_form[motherTongue]` = `Cebuano`.
- `TeacherClassControllerTest::testNonAdviserCannotEdit`: another teacher (no advisory) → 403 on `/teacher/class/{id}/edit` (keep if it already exists).
- `StudentDashboardTest::testShowsOwnSf1InfoReadOnly`: learner with religion set → dashboard shows `Christianity`, no `form` with `learner_form`.

- [ ] **Step 2: Run to verify failure**

Run: `php bin/phpunit --filter 'Sf1|NonAdviserCannotEdit'` → new tests FAIL.

- [ ] **Step 3: Extend `LearnerFormType`** with the 16 fields. Datalist suggestions via `'attr' => ['list' => 'sf1-mother-tongues']` / `'sf1-religions'`; the `<datalist>` elements with the spec's values go in `_fields.html.twig`.

- [ ] **Step 4: Fieldsets in `_fields.html.twig`**: Identity (as today), "Other information", "Address", "Family" — `<fieldset><legend>` with `form-grid` rows.

- [ ] **Step 5: `_details.html.twig`** gets three more `info-list` groups (Other information, Address, Family) printing `—` for nulls; guardian shows `name (relationship)`.

- [ ] **Step 6: Student dashboard** includes `learners/_details.html.twig` in a "My information" card.

- [ ] **Step 7: Run to verify pass**, then the whole learner suite: `php bin/phpunit tests/Controller` → PASS (existing enroll/edit tests must still pass).

- [ ] **Step 8: Commit** — `feat: edit SF1 personal info for principal, admin and adviser`

---

### Task 3: Enrollment SF1 fields, enums and remarks text

**Files:**
- Create: `src/Entity/LearningModality.php`, `src/Entity/Sf1Remark.php`
- Modify: `src/Entity/Enrollment.php`
- Modify: `src/Repository/TransferRequestRepository.php`
- Create: `src/Sf1/Sf1Remarks.php`
- Create: `migrations/Version….php` (generated)
- Test: `tests/Entity/EnrollmentSf1Test.php`, `tests/Service/Sf1RemarksTest.php`

**Interfaces:**
- `enum LearningModality: string` — cases/values/labels exactly as the spec table; `label(): string`.
- `enum Sf1Remark: string` — cases `TransferredIn='transferred_in'`, `Dropped='dropped'`, `LateEnrollment='late_enrollment'`, `Cct='cct'`, `BalikAral='balik_aral'`, `Sned='sned'`, `Accelerated='accelerated'`; `code(): string` (`T/I`, `DRP`, `LE`, `CCT`, `B/A`, `SNED`, `ACL`), `label(): string`, `requiredInfo(): string` — texts from the spec table.
- `Enrollment`: `getLearningModality(): LearningModality` / `setLearningModality(LearningModality)`; `getRemarkCode(): ?Sf1Remark` / setter; `getRemarkDetails(): ?string` / setter (trim, blank→null, `Assert\Length(max: 150)`); `getRemarkDate(): ?\DateTimeImmutable` / setter; `getManualRemarkText(): ?string` → `"{code} {details} {Y-m-d}"` without empty parts, `null` without code.
- `TransferRequestRepository::findApprovedInto(Enrollment $enrollment): ?TransferRequest` — status `Approved`, `t.enrollment = :enrollment`, `toSection.school = enrollment.school`, newest `decidedAt` then `id`.
- `App\Sf1\Sf1Remarks` (service): `automaticFor(Enrollment): ?string` → `"T/I {fromSchool name} {decidedAt Y-m-d}"` or null; `textFor(Enrollment): ?string` → manual text ?? automatic.

- [ ] **Step 1: Failing entity tests** (`tests/Entity/EnrollmentSf1Test.php`, plain `TestCase` with unpersisted objects)

```php
self::assertSame(LearningModality::FaceToFace, $enrollment->getLearningModality());
self::assertNull($enrollment->getManualRemarkText());
$enrollment->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('Moved abroad')->setRemarkDate(new \DateTimeImmutable('2025-09-01'));
self::assertSame('DRP Moved abroad 2025-09-01', $enrollment->getManualRemarkText());
$enrollment->setRemarkDetails(' ')->setRemarkDate(null);
self::assertSame('DRP', $enrollment->getManualRemarkText());
self::assertSame('B/A', Sf1Remark::BalikAral->code());
```

- [ ] **Step 2: Failing service tests** (`tests/Service/Sf1RemarksTest.php`, `KernelTestCase` + `SchoolFixturesTrait`, pattern from `tests/Service/TransferManagerTest.php`): two schools, a learner enrolled at A, a `TransferRequest` A→B approved with `decide(Approved, $by, new \DateTimeImmutable('2026-06-16'))` and `$enrollment->moveTo($sectionAtB)`.
  - `testApprovedTransferGivesAutomaticTi`: `textFor()` === `'T/I {A name} 2026-06-16'`.
  - `testManualRemarkWins`: set `Sf1Remark::Sned` → `textFor()` starts with `SNED`.
  - `testPendingOrDeclinedGivesNothing`: pending and declined requests → `null`.
  - `testStillTiAfterMoveWithinNewSchool` (Review Focus 4): after approval, `moveTo($otherSectionAtB)` → still the T/I text.
  - `testNoTransferGivesNull`.

- [ ] **Step 3: Run to verify failure** — `php bin/phpunit --filter 'EnrollmentSf1|Sf1Remarks'` → FAIL.

- [ ] **Step 4: Implement** the enums, the `Enrollment` fields (`#[ORM\Column(length: 20, enumType: LearningModality::class, options: ['default' => 'face_to_face'])]`, remark code `length: 20, nullable: true`, details `length: 150, nullable: true`, date `type: 'date_immutable', nullable: true`), the repository method and `Sf1Remarks`.

- [ ] **Step 5: Run to verify pass** → PASS.

- [ ] **Step 6: Migration** as in Task 1 Step 6; check existing rows get `face_to_face`.

- [ ] **Step 7: Commit** — `feat: learning modality and SF1 remarks on enrollments`

---

### Task 4: "This school year" enrollment record page

**Files:**
- Create: `src/Form/EnrollmentRecordFormType.php`
- Modify: `src/Controller/AbstractLearnerController.php` (new `handleEnrollmentRecord`; `renderShow` passes `remarks_text`)
- Modify: `src/Controller/SchoolLearnerController.php`, `src/Controller/TeacherClassController.php` (routes)
- Create: `templates/learners/enrollment_record.html.twig`, `templates/learners/_enrollment_details.html.twig`
- Modify: `templates/learners/show.html.twig`, `templates/student/dashboard.html.twig`
- Test: `tests/Controller/SchoolLearnerControllerTest.php`, `tests/Controller/TeacherClassControllerTest.php`

**Interfaces:**
- Consumes: Task 3 `Enrollment` setters, `Sf1Remarks::textFor()` / `automaticFor()`, `LearningModality`, `Sf1Remark`.
- Produces: routes `school_learners_enrollment` (`GET|POST /schools/{id}/learners/{learner}/enrollment`) and `teacher_class_enrollment` (`GET|POST /teacher/class/{learner}/enrollment`); form fields `enrollment_record_form[learningModality]`, `[remarkCode]`, `[remarkDetails]`, `[remarkDate]`; submit button label `Save`.
- `protected function handleEnrollmentRecord(Request $request, LearnerScope $scope, int $id): Response` — `requireLearner($scope, $id, false)` (current enrollment required; 404 otherwise), `denyAccessUnlessGranted(LearnerVoter::EDIT, $learner)`, binds the form to the current `Enrollment`, flushes, logs `Updated SF1 record of {lrn}` via the activity logger, flash `Saved {full name}.`, redirects to show.

- [ ] **Step 1: Failing HTTP tests**
  - `testPrincipalSetsModalityAndRemark`: show page has link `This school year`; submit `learningModality=modular_digital`, `remarkCode=late_enrollment`, `remarkDetails=Enrolled in July` → redirect; show page `.info-list` contains `Modular (Digital)` and `LE Enrolled in July`.
  - `testRemarkDetailsTooLongIs422` (Review Focus 5): 151 chars → 422.
  - `testAutomaticTiHintShown`: approved transfer into the school → GET page contains `Automatic: T/I`.
  - `testAdviserSetsRemark` via `/teacher/class/{id}/enrollment`.
  - `testOtherSchoolPrincipalGets403Or404` and `testStudentGets403`.
  - `testLearnerWithoutCurrentEnrollmentGets404` (learner only enrolled last SY, reached through the school pages history).

- [ ] **Step 2: Run to verify failure** → FAIL (404 routes).

- [ ] **Step 3: Implement** form type (`EnumType` for both enums, remark code `required => false`, placeholder `— none —`, `choice_label` with `code() – label()`; `help` on details listing `requiredInfo()` per code is optional), the handler, both routes, templates. `_enrollment_details.html.twig` shows `School year {name}`: Section, Learning modality, Remarks (`remarks_text ?? '—'`). Show page: "This school year" button next to "Edit details" when `can.edit and current`.

- [ ] **Step 4: Run to verify pass**, then `php bin/phpunit tests/Controller` → PASS.

- [ ] **Step 5: Commit** — `feat: edit learning modality and SF1 remarks per school year`

---

### Task 5: "SF1 incomplete" badge on the class list

**Files:**
- Modify: `templates/learners/class_list.html.twig`
- Modify: `src/Repository/EnrollmentRepository.php` (`ordered()` gains `e.id` tiebreaker)
- Test: `tests/Controller/SchoolLearnerControllerTest.php`

**Interfaces:**
- Consumes: `Learner::isSf1Incomplete()`.
- Produces: `<span class="pill pill--warn">SF1 incomplete</span>` in the name cell; when `is_granted('LEARNER_EDIT', learner)` it is an `<a>` to `routes ~ '_edit'`.

- [ ] **Step 1: Failing test** `testClassListMarksIncompleteLearners`: one bare learner, one with barangay/city/province/mother name/contact filled → `.table tbody tr` 1 contains `SF1 incomplete`, the complete one doesn't (`assertSelectorCount(1, '.table .pill--warn')`).
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the pill (reuse existing pill classes from `assets/styles`; add `pill--warn` only if no warning variant exists) and the `e.id` tiebreaker.
- [ ] **Step 4: Run → PASS**, plus `tests/Repository/EnrollmentRepositoryTest.php`.
- [ ] **Step 5: Commit** — `feat: flag learners with incomplete SF1 data`

---

### Task 6: SF1 report builder

**Files:**
- Create: `src/Sf1/Sf1Row.php`, `src/Sf1/Sf1Report.php`, `src/Sf1/Sf1ReportBuilder.php`
- Test: `tests/Service/Sf1ReportBuilderTest.php`

**Interfaces:**
- Consumes: `EnrollmentRepository::findClassList(Section)` (boys first, ordered), `SchoolAssignmentRepository::findPrincipal(School, SchoolYear)`, `Sf1Remarks::textFor()`, Task 1 helpers, `ClockInterface`.
- Produces:

```php
final readonly class Sf1Row
{
    public function __construct(
        public string $lrn, public string $name, public string $sex /* 'M'|'F' */,
        public string $birthDate /* m-d-Y */, public ?int $age, public ?string $motherTongue,
        public ?string $ipGroup, public ?string $religion, public ?string $street, public ?string $barangay,
        public ?string $city, public ?string $province, public ?string $father, public ?string $mother,
        public ?string $guardianName, public ?string $guardianRelationship, public ?string $contactNumber,
        public string $learningModality, public ?string $remarks,
    ) {}
}

final readonly class Sf1Report
{
    /** @param list<Sf1Row> $male @param list<Sf1Row> $female */
    public function __construct(
        public string $schoolId, public string $region, public string $division, public string $schoolName,
        public string $schoolYear /* '2025 - 2026' */, public string $gradeLevel /* 'Grade 8' */, public string $section,
        public array $male, public array $female,
        public ?int $eosyMale, public ?int $eosyFemale, // null until the SY end date has passed
        public ?string $adviserName, public ?string $schoolHeadName, // upper case
        public \DateTimeImmutable $generatedOn,
    ) {}
    public function fileName(): string; // 'SF1_2025_Grade 8 - ARTISTIC.xls'
}
```

- `Sf1ReportBuilder::build(Section $section): Sf1Report`. Age uses the SY start date's year. Mother tongue only for `GradeLevel` 1–3. SY string: start year `' - '` start year + 1. EoSY = count minus enrollments whose `remarkCode` is `Dropped`, only when `clock->now() > endDate`.

- [ ] **Step 1: Failing tests** (`KernelTestCase`, `ClockSensitiveTrait`, `SchoolFixturesTrait`; SY 2025, Grade 8 section `Artistic` with adviser `Dave Sosmeña`, principal `Rene Petancio`):
  - `testMalesThenFemalesOrderedWithTotals`: 2 boys, 1 girl → `count($r->male) === 2`, `count($r->female) === 1`, first male `name` is the alphabetically first.
  - `testRowValues`: LRN, `'M'`, `'03-20-2012'`, age `13`, remarks via `Sf1Remarks`, modality label `Face to Face`.
  - `testMotherTongueBlankForGrade4Up` (Grade 8 → null) and kept for a Grade 2 section.
  - `testEosyOnlyAfterEndDate`: mock time `2026-01-27` → `null`; mock `2026-05-15` with one `Dropped` boy → `eosyMale === 1`.
  - `testSignatories`: `adviserName === 'DAVE SOSMEÑA'`, `schoolHeadName === 'RENE PETANCIO'`.
  - `testNoAdviserNoPrincipal` (Review Focus 3): both `null`, no exception.
  - `testEmptySection` (Review Focus 2): empty lists, `fileName()` still right.
  - `testFileName`: `'SF1_2025_Grade 8 - ARTISTIC.xls'`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** the DTOs and builder.
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `feat: build SF1 report data for a section`

---

### Task 7: SF1 Excel writer

**Files:**
- Create: `src/Sf1/Sf1Writer.php`
- Test: `tests/Service/Sf1WriterTest.php`

**Interfaces:**
- Consumes: `Sf1Report`, `Sf1Row` (Task 6). Tests construct the DTOs directly — no DB.
- Produces: `Sf1Writer::write(Sf1Report $report): Spreadsheet` (layout only, no queries).

Layout (1-based columns as in the LIS file; merge each field across its span):

| Field | Columns | | Field | Columns |
|---|---|---|---|---|
| LRN | A–B (1–2) | | Barangay | R–T (18–20) |
| Name | C–F (3–6) | | Municipality/City | U–V (21–22) |
| Sex | G (7) | | Province | W–AA (23–27) |
| Birth date | H–I (8–9) | | Father | AB–AE (28–31) |
| Age | J–K (10–11) | | Mother | AF–AJ (32–36) |
| Mother tongue | L–M (12–13) | | Guardian name | AK–AN (37–40) |
| IP | N (14) | | Relationship | AO (41) |
| Religion | O (15) | | Contact | AP–AQ (42–43) |
| House/Street | P–Q (16–17) | | Modality | AR (44) |
| | | | Remarks | AS–AU (45–47) |

Rows: 1 title, 2 subtitle, 3 School ID (label A, value F) / Region (K) / Division (Q label, T value), 4 School Name (A, F) / School Year (Q, T) / Grade Level (Z, AE) / Section (AJ, AM), 5–6 two-row column headings (texts from the spec; ADDRESS spans P–AA, PARENTS AB–AJ, GUARDIAN AK–AO on row 5 with sub-headings on row 6; single-field headings merged rows 5–6), then male rows, `"{n}" | "<=== TOTAL MALE"` row, female rows, TOTAL FEMALE row, COMBINED row, then footer: legend block (A–U), summary MALE/FEMALE/TOTAL × REGISTERED/BoSY/EoSY (V–AD), Prepared by (AE…) and Certified Correct (AN…) with name, caption, BoSY/EoSY date lines; last line `Generated on: {l, F j, Y}`. Thin borders on header + data + total rows; wrap text in headings; data font 8pt. Page setup: `PageSetup::PAPERSIZE_LEGAL`, `ORIENTATION_LANDSCAPE`, `setFitToWidth(1)`, `setFitToHeight(0)`, repeat rows 5–6 at top.

- [ ] **Step 1: Failing tests** — write the workbook with `IOFactory::createWriter($ss, 'Xls')` to a temp file in `sys_get_temp_dir()`, reload with `IOFactory::load()`, sheet 0:
  - `testHeader`: `A1` contains `School Form 1 (SF 1) School Register`; school ID/name/SY/grade/section cell values.
  - `testRowsAndTotals`: 2 males + 1 female → `A7` = LRN of first male, `C7` = `BAÑEZ, REMUEL JR, BRAVO` (Review Focus 1), row 9 `A`=`2` and `C`=`<=== TOTAL MALE`, row 11 `C`=`<=== TOTAL FEMALE`, row 12 `A`=`3`, `C`=`<=== COMBINED`.
  - `testSignatoriesAndGeneratedOn`: adviser and school head names present in the sheet (search the footer cells), `Generated on: Tuesday, January 27, 2026`.
  - `testPageSetup`: legal + landscape.
  - `testEmptyReport` (Review Focus 2): writes without exception; TOTAL MALE row directly follows the headings with `0`.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** `Sf1Writer` — private helpers per block (`header()`, `headings()`, `rows()`, `totals()`, `footer()`), a column-span map as a class constant matching the table above.
- [ ] **Step 4: Run → PASS.** Also open the generated file in Excel once and compare with `C:\Users\ZiM\Downloads\SF1_2025_Grade 8 (Year II) - ARTISTIC.xls` (manual check, note any differences for the user).
- [ ] **Step 5: Commit** — `feat: write SF1 as an Excel sheet like LIS`

---

### Task 8: SF1 download routes and button

**Files:**
- Modify: `src/Controller/AbstractLearnerController.php` (`downloadSf1(LearnerScope $scope, Section $section): Response`)
- Modify: `src/Controller/SchoolLearnerController.php` (`school_learners_sf1`), `src/Controller/TeacherClassController.php` (`teacher_class_sf1`)
- Modify: `templates/learners/class_list.html.twig` (button)
- Test: `tests/Controller/SchoolLearnerControllerTest.php`, `tests/Controller/TeacherClassControllerTest.php`

**Interfaces:**
- Consumes: `Sf1ReportBuilder::build()`, `Sf1Writer::write()`, `Sf1Report::fileName()`, `requireSection()`, `LearnerVoter::VIEW_CLASS`.
- Produces: `GET /schools/{id}/sections/{section}/sf1` → `school_learners_sf1`; `GET /teacher/class/sf1` → `teacher_class_sf1` (declare it **before** `/{learner}` routes; `learner` requirement `\d+` already prevents a clash). Response: `StreamedResponse` writing `IOFactory::createWriter($ss, 'Xls')->save('php://output')`, `Content-Type: application/vnd.ms-excel`, `Content-Disposition` attachment via `HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, preg_replace('/[^\x20-\x7E]/', '_', $fileName))` (section names may contain Ñ).

- [ ] **Step 1: Failing tests**
  - `testPrincipalDownloadsSf1`: class list has link `Download SF1`; GET → 200, `Content-Type` `application/vnd.ms-excel`, `Content-Disposition` contains `SF1_2026_Grade 7 - SAMPAGUITA.xls`; `$client->getInternalResponse()->getContent()` non-empty (use `$client->request` then `ob_start`/`getInternalResponse` per existing download tests, e.g. the PDS PDF test).
  - Admin downloads → 200.
  - `TeacherClassControllerTest::testAdviserDownloadsSf1` → 200.
  - `testOtherSchoolPrincipalCannotDownload` → 403 (voter) or 404 (`requireSection`), assert the actual one.
  - `testStudentCannotDownload` → 403; non-adviser teacher on `/teacher/class/sf1` → 403.
  - `testEmptySectionDownloads` (Review Focus 2) → 200.
- [ ] **Step 2: Run → FAIL.**
- [ ] **Step 3: Implement** handler, two routes, button (`btn btn--ghost`, `path(routes ~ '_sf1', …)` — school route needs `section: section.id`, teacher route none).
- [ ] **Step 4: Run → PASS.**
- [ ] **Step 5: Commit** — `feat: download a section's SF1`

---

### Task 9: Whole-branch verification

- [ ] **Step 1:** `php bin/console lint:container`, `php bin/console lint:twig templates/`, `php bin/console doctrine:schema:validate` → all OK.
- [ ] **Step 2:** Full suite `php bin/phpunit` → all PASS (report the counts).
- [ ] **Step 3:** `vendor/bin/php-cs-fixer fix` if installed; otherwise skip and say so.
- [ ] **Step 4:** Clear cache, open http://localhost:8081/ — as principal: fill a learner's SF1 info, set a remark, download SF1 and open it in Excel.
- [ ] **Step 5:** Commit any fixes — `chore: SF1 lint and style fixes`.
