# SF8 Nutritional Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The section adviser records weight and height at baseline and endline; the system computes age, BMI, BMI-for-age and height-for-age from the WHO 5–19 reference and builds DepEd SF8 as Excel in the user's template layout, with submit/lock/reopen and a principal/admin overview.

**Architecture:** New namespace `App\Nutrition` beside `App\Attendance`. Storage: `NutritionAssessment` (section × period, date of weighing) + `NutritionMeasurement` (per enrollment) + `Sf8Submission` (lock). `NutritionClassifier` reads fixed WHO arrays from `resources/who/`; categories are computed on read. `Sf8ReportBuilder` turns a section + period into readonly DTOs used by the screens and by `Sf8Writer`. Writes go through `NutritionRecorder` under a per-section lock; `NutritionVoter` gates FILL / VIEW / REOPEN.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM + migrations, Twig, Symfony Form + Validator, symfony/lock, PhpSpreadsheet (xlsx), PHPUnit (KernelTestCase / WebTestCase).

**Spec:** `docs/superpowers/specs/2026-10-30-sf8-nutritional-status-design.md`

## Global Constraints

- Branch `feature/sf8` (off `feature/sf2`). Schema only via `php bin/console make:migration` + `doctrine:migrations:migrate` (dev) and `--env=test` (test DB). Never `schema:update`.
- PHPUnit on this laptop: run through a scratchpad runner `pu.ps1` (copy it from `C:\Users\ZiM\AppData\Local\Temp\claude\C--xampp-htdocs-zimdave-schsys\22ee8d0e-b078-4ba5-9079-90a34beb5e61\scratchpad\pu.ps1` into this session's scratchpad; PowerShell `Start-Process` + `Wait-Process`, retry on timeout — Avast suspends piped php.exe), always with a path argument. Commands below say `php bin/phpunit …` for brevity.
- Tests build services with `new` (the test container drops unused private services), use `SchoolFixturesTrait` fixtures and `ClockSensitiveTrait::mockTime`. Fixture school year: 2025-06-01 .. 2026-04-30 (`schoolYear(2025, current: true)`). Default mocked "now" for this plan: 2025-08-22 10:00.
- "Today" is `SchoolClock::now()`, never `new \DateTimeImmutable()`.
- Periods: `NutritionPeriod::Baseline = 'baseline'`, `NutritionPeriod::Endline = 'endline'`; route requirement `baseline|endline`, resolved by Symfony's BackedEnum resolver.
- Category labels exactly as the template: BMI "Severely Wasted", "Wasted", "Normal", "Overweight", "Obese"; HFA "Severely Stunted", "Stunted", "Normal", "Tall".
- WHO range: 60–228 months inclusive. Weight 5–200 kg, height 0.50–2.50 m, both or neither.
- Rows: males first, then females, by name — `RosterEntry` order from `Sf2Roster`.
- Every POST with fields is a Symfony Form; bare POST buttons use `IsCsrfTokenValid` as SF2 does.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. The date of weighing changes after measurements were saved, so learners join or leave the roster — off-roster measurements must be ignored in rows, counts and Excel, not shown or counted. (Test in Task 3.)
2. A learner under 60 months (Kinder), over 228 months, or with no birthdate — categories "—", left out of every count, but still listed and weighable. (Tests in Tasks 2–3.)
3. A BMI or height exactly on a table bound (e.g. boy 60 months BMI 18.3) — same category as the template's `>` comparisons (Normal, not Overweight). (Test in Task 2.)
4. Baseline and endline dates crossing (endline before baseline, or baseline moved after an existing endline) — refused with a clear message. (Test in Task 4.)
5. An adviser typing height in cm (152) or weight in grams — a validation message naming the unit, nothing saved. (Test in Task 7.)

---

### Task 1: Enums, entities, certifier fields, migration

**Files:**
- Create: `src/Nutrition/NutritionPeriod.php`, `src/Nutrition/BmiCategory.php`, `src/Nutrition/HfaCategory.php`, `src/Entity/NutritionAssessment.php`, `src/Entity/NutritionMeasurement.php`, `src/Entity/Sf8Submission.php`, `src/Repository/NutritionAssessmentRepository.php`, `src/Repository/Sf8SubmissionRepository.php`, `migrations/Version…php` (generated)
- Modify: `src/Entity/School.php` (two fields)
- Test: `tests/Entity/NutritionEntitiesTest.php`

**Interfaces — Produces:**
- `enum NutritionPeriod: string { Baseline, Endline }` + `label(): string` ("Baseline", "Endline").
- `enum BmiCategory: string { SeverelyWasted = 'severely_wasted', Wasted = 'wasted', Normal = 'normal', Overweight = 'overweight', Obese = 'obese' }` + `label()`; `enum HfaCategory: string { SeverelyStunted = 'severely_stunted', Stunted = 'stunted', Normal = 'normal', Tall = 'tall' }` + `label()`. Case order = template column order.
- `NutritionAssessment(Section $section, NutritionPeriod $period, \DateTimeImmutable $weighedOn, User $createdBy, \DateTimeImmutable $now)`; getters; `setWeighedOn()`, `touch(\DateTimeImmutable)`; `getMeasurements(): Collection`, `measurementFor(Enrollment): ?NutritionMeasurement`, `addMeasurement()`, `removeMeasurement()`. Unique (section_id, period).
- `NutritionMeasurement(NutritionAssessment, Enrollment)`; `?string getWeightKg()`/`setWeightKg(?string)` (decimal 5,2), `?string getHeightM()`/`setHeightM(?string)` (decimal 3,2), `?string getRemarks()`/`setRemarks(?string)` (string 100); `isWeighed(): bool` (both numbers set). Unique (assessment_id, enrollment_id); enrollment `onDelete: CASCADE`, assessment `onDelete: CASCADE`.
- `Sf8Submission(Section, NutritionPeriod, User $submittedBy, \DateTimeImmutable $submittedAt)`, unique (section_id, period), modelled on `Sf2Submission`.
- `NutritionAssessmentRepository::findFor(Section, NutritionPeriod): ?NutritionAssessment`, `findForSections(list<Section>, NutritionPeriod): array<int sectionId, NutritionAssessment>` (one query, measurements fetch-joined).
- `Sf8SubmissionRepository::findFor(Section, NutritionPeriod): ?Sf8Submission`, `findForSections(list<Section>, NutritionPeriod): array<int sectionId, Sf8Submission>`.
- `School::get/setSf8CertifierName(): ?string`, `get/setSf8CertifierPosition(): ?string` — nullable string 100, `#[Assert\Length(max: 100)]`, blank → null (`blankToNull`).

- [ ] **Step 1: Write `NutritionEntitiesTest`** (KernelTestCase, fixtures): `testPersistAssessmentWithMeasurements` (persist, clear, reload: weighedOn, period, one measurement with weight "31.50", height "1.32", `isWeighed()` true; a remarks-only measurement `isWeighed()` false); `testOneAssessmentPerSectionAndPeriod` (second Baseline for the same section → `UniqueConstraintViolationException`; an Endline is fine); `testSubmissionUnique` (same); `testUnenrollDeletesMeasurements` (remove the enrollment → measurement gone); `testCertifierFieldsBlankToNull` (`setSf8CertifierName('  ')` → null).
- [ ] **Step 2:** Run `php bin/phpunit tests/Entity/NutritionEntitiesTest.php` → FAIL (classes missing).
- [ ] **Step 3:** Implement enums, entities, repositories, School fields; `make:migration`, review the SQL, migrate dev and test.
- [ ] **Step 4:** Run → PASS; `php bin/console doctrine:schema:validate` clean.
- [ ] **Step 5:** Commit `feat: nutrition assessments, measurements and SF8 submissions`.

### Task 2: WHO reference and `NutritionClassifier`

**Files:**
- Create: `resources/who/bmi_for_age.php`, `resources/who/height_for_age.php`, `src/Nutrition/NutritionClassifier.php`, `src/Nutrition/NutritionResult.php`
- Test: `tests/Nutrition/NutritionClassifierTest.php`

**Interfaces — Produces:**
- Data files return `array{male: array<int, list<float>>, female: array<int, list<float>>}` keyed by months 60..228 (169 entries each). BMI list = [severelyWastedMax, wastedTo, normalTo, overweightTo]; HFA list (cm) = [severelyStuntedMax, stuntedTo, normalTo].
- Source: template sheet "BMI Tables", rows 4–172, **calculated** values (`getCalculatedValue()`, some cells are formulas like `=V4+0.1`). BMI boys C, E, G, I; girls K, M, O, Q. HFA boys V, X, Z; girls AB, AD, AF. Months in B (BMI) / U (HFA). Generate with a scratchpad script (not committed); the files carry a header comment naming the source and "generated — do not edit".
- `final readonly class NutritionResult { ?int $ageMonths; ?float $bmi; ?BmiCategory $bmiCategory; ?HfaCategory $hfaCategory; }` + `ageYears(): ?int` (intdiv 12).
- `NutritionClassifier` (`#[Autowire('%kernel.project_dir%')]`, loads both files lazily once):
  - `ageInMonths(\DateTimeImmutable $birthDate, \DateTimeImmutable $on): int` — completed months (`y*12 + m` of `diff`); negative → 0.
  - `bmiCategory(bool $male, int $months, float $bmi): ?BmiCategory` — null outside 60..228. `>` overweightTo → Obese; `>` normalTo → Overweight; `>` wastedTo → Normal; `>` severelyWastedMax → Wasted; else SeverelyWasted.
  - `hfaCategory(bool $male, int $months, float $heightCm): ?HfaCategory` — `>` normalTo → Tall; `>` stuntedTo → Normal; `>` severelyStuntedMax → Stunted; else SeverelyStunted.
  - `classify(bool $male, ?\DateTimeImmutable $birthDate, \DateTimeImmutable $weighedOn, ?float $weightKg, ?float $heightM): NutritionResult` — bmi = w / h² when both set; categories null when either is missing, birthDate null, or months out of range; HFA needs only height.

- [ ] **Step 1: Write `NutritionClassifierTest`** (plain `TestCase`, `new NutritionClassifier(dirname(__DIR__, 2))`):
  - `testTablesCoverEveryMonth`: both files have keys 60..228 for male and female, lists of 4 / 3 floats.
  - `testBoyBmiBoundsAt60Months` (bounds 12.0 / 12.9 / 18.3 / 20.2): 12.0 → SeverelyWasted, 12.05 → Wasted, 12.9 → Wasted, 12.95 → Normal, 18.3 → Normal, 18.35 → Overweight, 20.2 → Overweight, 20.25 → Obese.
  - `testGirlBmiAt228Months` (14.6 / 16.4 / 29.7 / 36.2): 14.6 → SeverelyWasted, 16.5 → Normal, 29.8 → Overweight, 36.3 → Obese.
  - `testHfaAt60Months`: boy (96.0 / 100.6 / 119.2): 96.0 → SeverelyStunted, 100.0 → Stunted, 119.2 → Normal, 119.3 → Tall; girl (95.1 / 99.8 / 118.9): 99.9 → Normal.
  - `testHfaBoyAt228Months`: 154.5 → SeverelyStunted.
  - `testAgeInMonths`: 2020-08-22 → 2025-08-22 = 60; 2020-08-23 → 2025-08-22 = 59; 2020-01-31 → 2025-02-28 = 60.
  - `testOutOfRangeNotComputed`: 59 and 229 months → both categories null, bmi still computed; birthDate null → null categories.
  - `testClassify`: boy born 2015-03-10 weighed 2025-08-22, 31.5 kg, 1.32 m → ageMonths 125, ageYears 10, bmi ≈ 18.08 (delta 0.01), category equal to `bmiCategory(true, 125, 18.08)`; height-only → bmi null, bmiCategory null, hfaCategory set.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Write the extraction script in the scratchpad, generate both data files, implement the classifier and result.
- [ ] **Step 4:** Run → PASS. Spot-check three random months against the template by eye and note them in the commit body.
- [ ] **Step 5:** Commit `feat: classify BMI-for-age and height-for-age from the WHO reference`.

### Task 3: SF8 figures — `Sf8ReportBuilder`

**Files:**
- Create: `src/Nutrition/Sf8Row.php`, `src/Nutrition/Sf8Summary.php`, `src/Nutrition/Sf8Report.php`, `src/Nutrition/Sf8Status.php`, `src/Nutrition/Sf8ReportBuilder.php`
- Test: `tests/Nutrition/Sf8ReportBuilderTest.php`

**Interfaces:**
- Consumes: Task 1 repositories, Task 2 classifier, `Sf2Roster::forMonth()` + `RosterEntry::counts()`, `SchoolClock`.
- Produces:
  - `enum Sf8Status: string { NotStarted = 'not_started', Draft = 'draft', Submitted = 'submitted' }` + `label()` ("Not started", "Draft", "Submitted").
  - `final readonly class Sf8Row { RosterEntry $entry; ?string $lrn; ?\DateTimeImmutable $birthDate; NutritionResult $result; ?float $weightKg; ?float $heightM; ?string $remarks; bool $weighed; }`.
  - `final readonly class Sf8Summary` built from rows: `bmi(BmiCategory, ?bool $male = null): int`, `hfa(HfaCategory, ?bool $male = null): int`, `weighed(?bool $male = null): int`, `onRoster(?bool $male = null): int` (`null` = both sexes); `static sum(list<Sf8Summary>): Sf8Summary`. Counts include only weighed rows with a non-null category.
  - `final readonly class Sf8Report { Section $section; NutritionPeriod $period; \DateTimeImmutable $weighedOn; bool $dateSet; list<Sf8Row> $rows; Sf8Summary $summary; Sf8Status $status; ?Sf8Submission $submission; ?string $adviserName; ?string $principalName; ?string $principalPosition; ?string $certifierName; ?string $certifierPosition; }` + `males(): list<Sf8Row>`, `females(): list<Sf8Row>`. Principal name/position: same source as `Sf2ReportBuilder` uses for the school head.
  - `Sf8ReportBuilder::build(Section, NutritionPeriod): Sf8Report` — weighedOn = assessment date or today (`dateSet` false); roster = `Sf2Roster::forMonth($section, $weighedOn)` filtered by `counts($weighedOn)`; measurements matched by enrollment id, others ignored. Status: submission → Submitted; assessment → Draft; else NotStarted.
  - `Sf8ReportBuilder::buildForSchool(School, SchoolYear, NutritionPeriod): list<Sf8Report>` — every section of the year, ordered by grade then name, using the repositories' `findForSections` (no per-section queries for assessments/submissions).

- [ ] **Step 1: Write `Sf8ReportBuilderTest`** (KernelTestCase, fixtures, mockTime 2025-08-22):
  - `testRowsAndSummary`: 2 boys + 2 girls; baseline 2025-08-04 with 3 measured, 1 not → rows males then females, `weighed()` 3, `onRoster()` 4, `bmi(Normal, true)` matches the classifier; status Draft.
  - `testNotStartedUsesToday`: no assessment → weighedOn 2025-08-22, `dateSet` false, status NotStarted, all rows not weighed, counts 0.
  - `testUnder60MonthsListedButNotCounted`: a learner born 2021-01-10, measured → row present, categories null, `weighed()` counts them, `bmi(...)` sums exclude them.
  - `testTransferredOutAfterBaselineOnlyOnBaseline`: approved transfer out 2025-10-01; baseline 2025-08-04 lists the learner, endline 2026-03-02 does not.
  - `testDateChangeIgnoresOffRosterMeasurement`: learner enrolled (transfer in) 2025-09-15 measured under a baseline dated 2025-09-20; move the date to 2025-09-01 → learner not in rows, not in counts.
  - `testSubmittedStatus` and `testBuildForSchoolOrdersSections` (two grades; one query count assertion not required).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: compute SF8 rows and summary for a section and period`.

### Task 4: Rules and writes — `NutritionRules`, `NutritionRecorder`

**Files:**
- Create: `src/Nutrition/NutritionRules.php`, `src/Nutrition/NutritionRecorder.php`, `src/Nutrition/NutritionException.php`, `src/Nutrition/MeasurementInput.php`
- Test: `tests/Nutrition/NutritionRecorderTest.php`

**Interfaces:**
- Consumes: Tasks 1–3, `LockFactory`, `SchoolClock`, `LearnerLock::BUSY` message.
- Produces:
  - `final readonly class MeasurementInput { int $enrollmentId; ?float $weightKg; ?float $heightM; ?string $remarks; }`.
  - `NutritionRules::isLocked(Section, NutritionPeriod): bool`; `refusalForDate(Section, NutritionPeriod, \DateTimeImmutable $weighedOn): ?string` with exact messages:
    - outside the year: `Weigh inside the school year (Jun 1, 2025 – Apr 30, 2026).`
    - after today: `The date of weighing can't be in the future.`
    - endline not after baseline: `The endline must be after the baseline (Aug 4, 2025).`
    - baseline not before endline: `The baseline must be before the endline (Mar 2, 2026).`
  - `NutritionRecorder::save(Section, NutritionPeriod, \DateTimeImmutable $weighedOn, list<MeasurementInput>, User $by): NutritionAssessment` — under lock `nutrition-section-{id}`; refuses when locked (`{Baseline} is submitted and locked.`) or a date refusal; creates the assessment on first save; upserts measurements for enrollments on the roster for `$weighedOn`, ignores other ids; an input with all three empty removes that measurement; normalises remarks (trim, blank → null).
  - `NutritionRecorder::submit(Section, NutritionPeriod, User, bool $confirmUnweighed): Sf8Submission` — refuses: no assessment (`Set the date of weighing and save first.`), already submitted, unweighed learners without confirm (`3 learners have no weight and height. Confirm to submit anyway.` — singular `1 learner has`).
  - `NutritionRecorder::reopen(Section, NutritionPeriod): void` — refuses when not submitted (`Baseline is not submitted.`).

- [ ] **Step 1: Write `NutritionRecorderTest`** (KernelTestCase, mockTime 2025-08-22): `testFirstSaveCreatesAssessment`; `testSaveUpdatesAndClears` (second save changes weight, an all-empty input deletes the row); `testIgnoresEnrollmentNotOnRoster` (id of another section's enrollment → no row); `testRefusesFutureAndOutOfYearDates`; `testEndlineMustFollowBaseline` and `testBaselineMustPrecedeEndline` (messages above); `testSubmitNeedsConfirmForUnweighed` (message with count, then success with confirm); `testSubmittedPeriodRefusesSave`; `testReopenDeletesSubmission`; `testReopenNotSubmittedRefused`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: save, submit and reopen nutrition assessments`.

### Task 5: Access — `NutritionVoter`

**Files:**
- Create: `src/Security/Voter/NutritionVoter.php`
- Test: `tests/Security/NutritionVoterTest.php`

**Interfaces — Produces:** constants `FILL = 'NUTRITION_FILL'`, `VIEW = 'NUTRITION_VIEW'`, `REOPEN = 'NUTRITION_REOPEN'`; subject `Section`. Same rules and helpers as `AttendanceVoter`: FILL — not admin, the section's adviser, section in the current school year; VIEW — admin, principal of the school, or the adviser; REOPEN — admin or principal. (Lock and date rules stay in `NutritionRules`.)

- [ ] **Step 1: Write `NutritionVoterTest`** mirroring `AttendanceVoterTest`: adviser FILL/VIEW yes, REOPEN no; principal VIEW/REOPEN yes, FILL no; admin VIEW/REOPEN yes, FILL no; another teacher none; student none; adviser of last year's section FILL no.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: who may fill, view and reopen SF8`.

### Task 6: SF8 Excel — template and `Sf8Writer`

**Files:**
- Create: `templates/forms/sf8-template.xlsx`, `src/Nutrition/Sf8Writer.php`
- Test: `tests/Nutrition/Sf8WriterTest.php`

**Interfaces:**
- Consumes: `Sf8Report` (Task 3).
- Produces: `Sf8Writer::write(Sf8Report): Spreadsheet` (one sheet "Nutritional Status") and `Sf8Writer::filename(Sf8Report): string` → `SF8_{schoolId}_{grade}-{section}_{Baseline|Endline}_{2025-2026}.xlsx`.
- Template prep (scratchpad script, run once, output committed): from `C:\Users\ZiM\Downloads\SF8-NUTRITIONAL-STATUS-SY26-27.xlsx` keep only "Nutritional Status"; remove all defined names; clear values/formulas in A12:O61, A63:O112, C118:O120, the header values E5, I5, L5, O5, C7, F7, H7, L7, O7 and the signature values A124, E124, I124, I125, M124, M125; delete rows 127–129 (the "not included in printing" notes). Keep styles, merges, widths, print setup. Verify by reloading that no cell contains `#REF!`.
- Cells (values, never formulas): E5 school name, I5 district, L5 division, O5 region, C7 school ID, F7 grade label, H7 section name, L7 track/strand or "N/A", O7 "2025-2026"; A4 "(For All Grade Levels) — Baseline" / "— Endline". Males from row 12, females from row 63: A no., B LRN, C name, G birthdate (Excel date, `mm/dd/yyyy`), H age years, I weight, J height, K height² (4 dp), L BMI (2 dp), M BMI label, N HFA label, O remarks; blanks for not weighed / not computed ("—" not written to Excel). Summary rows 118 MALE / 119 FEMALE / 120 TOTAL: C, E, F, G, H = BMI categories in enum order, I total, J, K, L, M = HFA, N total. A124 date of weighing, E124/E125 adviser name / "Adviser", I124/I125 certifier, M124/M125 principal.
- More than 50 in a group: `insertNewRowBefore` (males before row 62, females before the row after the last female row) and shift every later address by the inserted count — keep the offsets in one place.

- [ ] **Step 1: Write `Sf8WriterTest`** (build an `Sf8Report` by hand, write, save to a temp .xlsx, reload): `testHeader` (E5, C7, F7, H7, O7, A4 with "Baseline"); `testRows` (B12 LRN, C12 name, I12 31.5, M12 "Normal", first female at row 63; not-weighed row has blank I/M); `testSummary` (C118..N120 match `Sf8Summary`, I120 = total weighed-and-computed); `testSignatures` (A124 date, E124 adviser, I124 certifier, M124 principal); `testOverflowInsertsRows` (55 males → first female at row 68, summary MALE at row 123, all 55 names present); `testNoFormulasOrRefErrors` (no cell value starts with `=` or contains `#REF!`); `testFilename`.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Prepare the template, implement the writer. **Step 4:** Run → PASS.
- [ ] **Step 5: Visual check.** Scratch script writes a sample (30 learners, one Kinder-age, two not weighed) and renders it and the user's template with Excel COM `CopyPicture` → PNG (as for SF1/SF2); compare and fix until they match.
- [ ] **Step 6:** Commit `feat: SF8 Excel in the DepEd template layout`.

### Task 7: Adviser screens

**Files:**
- Create: `src/Controller/TeacherNutritionController.php`, `src/Controller/AbstractNutritionController.php` (shared section page + download), `src/Form/NutritionSheet.php` and `src/Form/NutritionEntry.php` (form models), `src/Form/NutritionSheetFormType.php`, `src/Form/NutritionEntryFormType.php`, `src/Form/Sf8SubmitFormType.php`, `templates/nutrition/index.html.twig`, `templates/nutrition/sheet.html.twig`, `templates/nutrition/_table.html.twig`, `templates/nutrition/_summary.html.twig`
- Modify: `templates/teacher/_layout.html.twig` (nav "Nutrition" active for `teacher_class_nutrition*` / `teacher_class_sf8`, and exclude those from "My class"), `templates/learners/class_list.html.twig` ("Nutrition · SF8" button beside "Attendance · SF2" in both scopes)
- Test: `tests/Controller/TeacherNutritionControllerTest.php`

**Interfaces:**
- Consumes: Tasks 1–6.
- Form models (mutable, Validator attributes): `NutritionSheet { ?\DateTimeImmutable $weighedOn (#[Assert\NotNull(message: 'Enter the date of weighing.')]); list<NutritionEntry> $entries (#[Assert\Valid]) }`; `NutritionEntry { int $enrollmentId; ?float $weightKg (#[Assert\Range(min: 5, max: 200, notInRangeMessage: 'Enter weight in kilograms, between 5 and 200.')]); ?float $heightM (#[Assert\Range(min: 0.5, max: 2.5, notInRangeMessage: 'Enter height in metres, e.g. 1.32.')]); ?string $remarks (#[Assert\Length(max: 100)]) }` + `#[Assert\Callback]` "Enter both weight and height, or neither." Maps to `list<MeasurementInput>`.
- `NutritionSheetFormType`: `weighedOn` DateType (single_text, immutable), `entries` CollectionType of `NutritionEntryFormType` (`enrollmentId` hidden, `weightKg`/`heightM` NumberType scale 2, `remarks` text).
- Routes (`#[Route('/teacher/class/nutrition')]`, `#[IsGranted(User::ROLE_TEACHER)]`, section = `SectionRepository::findAdvisory(user, current year)` else 403):
  - `teacher_class_nutrition` GET `''` — two cards from `Sf8ReportBuilder::build` for each period: status label, date, "{w} of {n} weighed", summary.
  - `teacher_class_nutrition_period` GET|POST `/{period}` — VIEW for GET, FILL for POST (403 otherwise). Read-only render when locked. On valid POST → `NutritionRecorder::save`, flash `Saved {Baseline} weights for {n} learners.`, redirect to same URL; `NutritionException` → form error at top. Table rows: LRN, name, birthdate, age, weight, height, remarks inputs; BMI, BMI category, HFA from the report ("—" when not computed). Submit form shown when status is Draft and FILL is granted; status line "Submitted {M j, Y} by {name} · locked" otherwise.
  - `teacher_class_nutrition_submit` POST `/{period}/submit` — `Sf8SubmitFormType` (`confirm_unweighed` checkbox shown when any row is not weighed) → `NutritionRecorder::submit`, flash `{Baseline} submitted to the principal.`
  - `teacher_class_sf8` GET `/{period}/sf8` — xlsx (`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`), disposition as `teacher_class_sf2`, `Sf8Writer::filename`.

- [ ] **Step 1: Write `TeacherNutritionControllerTest`** (WebTestCase, mockTime 2025-08-22, adviser with 3 learners): `testIndexShowsBothPeriods` ("Baseline", "Endline", "Not started"); `testSaveSheet` (date 2025-08-20, two learners measured → redirect, flash, DB has 2 measurements; follow → BMI category label visible); `testValidationMessages` (height 152 → "Enter height in metres, e.g. 1.32."; weight only → "Enter both weight and height, or neither."; nothing saved); `testFutureDateRefused` ("The date of weighing can't be in the future."); `testSubmitWithConfirm` (without confirm → message "1 learner has no weight and height."; with → submitted, page shows "locked", inputs gone); `testLockedPostRefused` (POST after submit → no change, 403 or error message); `testSf8Download` (200, xlsx content type, filename `SF8_..._Baseline_2025-2026.xlsx`); `testNonAdviserIs403`; `testPrincipalCannotFill` (`/teacher/class/nutrition` → 403).
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS; `php bin/console lint:twig templates/` and `lint:container` clean.
- [ ] **Step 5:** Commit `feat: adviser records weights and heights and submits SF8`.

### Task 8: Principal and admin — overview, section page, reopen, certifier

**Files:**
- Create: `src/Controller/SchoolNutritionController.php` (extends `AbstractNutritionController`), `templates/nutrition/overview.html.twig`, `templates/nutrition/section.html.twig`
- Modify: `src/Controller/Principal/DashboardController.php` (`principal_nutrition` redirect like `principal_attendance`), `templates/principal/_layout.html.twig` (nav "Nutrition", active for `school_nutrition*`), `templates/admin/schools/index.html.twig` ("Nutrition" button beside "Attendance"), `src/Form/SchoolProfileFormType.php` + its template (fields `sf8CertifierName` "SF8 certified correct by", `sf8CertifierPosition` "Position", both optional, in their own fieldset "School forms")
- Test: `tests/Controller/SchoolNutritionControllerTest.php`

**Interfaces:**
- Consumes: Tasks 1–7.
- Routes under `#[Route('/schools/{id}')]` + `#[IsGranted(SchoolVoter::MANAGE, 'school')]`; section must belong to the school, else 404:
  - `school_nutrition` GET `/nutrition?period=baseline|endline&compare=1` — `Sf8ReportBuilder::buildForSchool`; table: grade, section (link), adviser, status, weighed / on roster, BMI counts × 5, HFA counts × 4; school total row from `Sf8Summary::sum`. `compare=1` builds both periods and shows each count cell as "baseline → endline".
  - `school_nutrition_section` GET `/sections/{section}/nutrition/{period}` — read-only table (VIEW), Download, Reopen form when submitted and REOPEN granted.
  - `school_nutrition_sf8` GET `/sections/{section}/nutrition/{period}/sf8`.
  - `school_nutrition_reopen` POST `/sections/{section}/nutrition/{period}/reopen` (`IsCsrfTokenValid('nutrition-reopen-'~section.id~'-'~period.value)`) → `NutritionRecorder::reopen`, flash `{Baseline} for {section} reopened.`

- [ ] **Step 1: Write `SchoolNutritionControllerTest`** (mockTime 2025-08-22): principal GET overview → 200 with section name, "Not started"; after a saved baseline → "Draft", counts; `?compare=1` → shows "→"; section page → 200, no inputs, Download link; sf8 → xlsx; reopen submitted → submission gone, flash; reopen with bad token → no change; admin GET overview → 200 and can reopen; teacher GET `/schools/{id}/nutrition` → 403; another school's section → 404; `/principal/nutrition` redirects to `school_nutrition`; principal saves certifier name/position on `/principal/school` → stored, and the SF8 download's I124 holds the name.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS; `lint:twig`, `lint:container` clean.
- [ ] **Step 5:** Commit `feat: principal nutrition overview, SF8 reopen and certifier setting`.

### Task 9: Whole-suite check and browser smoke test

- [ ] **Step 1:** `php bin/phpunit tests` → all PASS (report the count; was 775 before this branch).
- [ ] **Step 2:** `php bin/console doctrine:schema:validate`, `lint:container`, `lint:twig templates/` → clean.
- [ ] **Step 3:** In the dev app at http://localhost:8081 (`cache:clear` first): as an adviser save a baseline for a few learners, submit, download SF8 and render it to PNG; as the principal set the certifier, open the overview (both periods, compare), open the section page, reopen. Fix anything broken, each fix with a test.
- [ ] **Step 4:** Commit any fixes (`fix: …`).
