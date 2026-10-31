# Card Slips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Advisers print a per-term card slip for each learner (grades, attendance, comments, signature lines), two per short bond sheet, with comments pre-suggested from the learner's term.

**Architecture:** New namespace `App\CardSlip`. Storage: `CardSlipComment` (enrollment × period: ticked codes + typed text). `CardSlipComments` holds the fixed comment catalog; `CardSlipSuggester` turns a learner's term into suggested codes; `TermAttendance` counts a term's attendance; `CardSlipBuilder` assembles `CardSlip` DTOs from the grade grid, attendance and saved/suggested comments; `CardSlipPdf` renders them with dompdf, two per page. Adviser screen in `TeacherCardSlipController`; principal/admin download from the section grades page.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM + migrations, Twig, Symfony Form + Validator, dompdf 3.1, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-10-31-card-slips-design.md`

## Global Constraints

- Branch `feature/card-slips` (off `feature/grade-changes`). Schema only via `php bin/console make:migration --no-interaction` + `doctrine:migrations:migrate` (dev after a `mysqldump` backup to `C:\xampp\htdocs\db_backups\`, and `--env=test`).
- Run tests with `php bin/phpunit <path>` (PowerShell); never two phpunit runs at once; full suite (~6 min) once per task before committing.
- Tests use `SchoolFixturesTrait` (`clearSchoolData()` must delete `CardSlipComment` before `Enrollment`), `ClockSensitiveTrait::mockTime(...)`, `openGrading()`; lock a period with `GradeRecorder::lockPeriod`.
- Comment texts are exactly the spec's (section "Built-in comments"), codes exactly as listed there.
- Descriptors (from the average rounded half-up to a whole number): Advancing 90–100, Benchmarking 80–89, Connecting 75–79, Developing 65–74, Emerging below 65. Remarks: "Passed" (≥ 75), "Failed" (< 75), "" (no grade). Missing top-level grade → average "Incomplete", no descriptor. Average shown with two decimals.
- Exact copy: "CARD SLIP · {Term 1}" · "S.Y. {2026-2027}" · "DRAFT – grades not final" · "{Term 1} isn't locked yet. Slips will print as DRAFT until the principal locks the grades." · "Comments saved for {n} learners." · "Choose at most 3 comments for {name}." · "Keep the comment under 300 characters." · "No learners to print slips for." · signature captions "Adviser" and "Parent/Guardian (signature over printed name)" and "Date:".
- PDF: US Letter portrait (8.5 × 11 in), each slip 8.5 × 5.5 in, two per page, dashed cut line between; single-learner PDF = one page with the slip in the top half. Filenames `Card slips - Grade {7} {SECTION} - {Term 1}.pdf` and `Card slip - {SF1 NAME} - {Term 1}.pdf` (slashes replaced as the SF1 filename does). Responses use `HeaderUtils::makeDisposition` as `AbstractLearnerController` does.
- Do not edit `assets/styles/admin.css` (unrelated unstaged edits live there); the PDF has its own inline CSS. Never stage the unrelated attendance edits; stage by path. Commit trailer `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A learner who enrolled or transferred in mid-term — attendance counts only days they were on the roster (Test in Task 3).
2. A term without start/end dates, or a term entirely in the future — attendance shows "—" / zero school days, no crash (Test in Task 3).
3. MAPEH with a component ungraded — MAPEH grade blank, slip average "Incomplete", suggestion `incomplete` (Test in Task 4).
4. An odd number of learners — the last page holds one slip; no blank extra page (Test in Task 5).
5. Worst-case content (10+ subjects with components, 3 long comments + a 300-character typed comment, long name) still fits one half page (Manual check in Task 5).

---

### Task 1: `CardSlipComment` entity, repository, migration

**Files:**
- Create: `src/Entity/CardSlipComment.php`, `src/Repository/CardSlipCommentRepository.php`, migration (generated)
- Modify: `tests/SchoolFixturesTrait.php`
- Test: `tests/Repository/CardSlipCommentRepositoryTest.php`

**Interfaces:**
- Produces: `CardSlipComment(Enrollment $enrollment, GradingPeriod $period)`; `getCodes(): list<string>`, `getCustomText(): ?string`, `update(list<string> $codes, ?string $customText, User $by, \DateTimeImmutable $at): void`, `getUpdatedBy(): ?User`, `getUpdatedAt()`. Unique (enrollment, grading_period); enrollment and period `CASCADE`, updatedBy `SET NULL`; codes JSON; customText `length: 300`.
- Produces: `CardSlipCommentRepository::findForEnrollments(list<int> $enrollmentIds, GradingPeriod): array<int enrollmentId, CardSlipComment>`.

- [ ] **Step 1: Test** `testFindForEnrollmentsKeysByEnrollment` (two learners, one saved row each for term 1, one for term 2 → term-1 lookup returns 2 keyed by enrollment id, term-2 lookup of another id returns `[]`); `testUpdateStoresCodesAndText` (codes `['excellent','see_adviser']`, text "Active in class." persisted and reloaded).
- [ ] **Step 2: Run** `php bin/phpunit tests/Repository/CardSlipCommentRepositoryTest.php` — FAIL (class missing).
- [ ] **Step 3: Implement** entity + repository; add `CardSlipComment::class` to `clearSchoolData()` before `Enrollment::class`.
- [ ] **Step 4: Migrate** (backup dev first; migration must contain only `card_slip_comment`), dev and `--env=test`.
- [ ] **Step 5: Run** the test — PASS. Full suite once.
- [ ] **Step 6: Commit** `feat: card slip comments storage`.

### Task 2: Comment catalog and suggestion rules

**Files:**
- Create: `src/CardSlip/CardSlipComments.php`, `src/CardSlip/CardSlipSuggester.php`, `src/CardSlip/TermFacts.php`
- Test: `tests/CardSlip/CardSlipSuggesterTest.php`, `tests/CardSlip/CardSlipCommentsTest.php`

**Interfaces:**
- Produces: `final class CardSlipComments` with `public const GROUPS = ['Academic' => [...codes], 'Attendance' => [...], 'Behaviour' => [...], 'Suggestion for parents' => [...]]`, `public static function text(string $code, list<string> $failingSubjects = []): string` (fills `{subjects}`), `public static function isKnown(string $code): bool`, `public static function joinSubjects(list<string>): string` ("A", "A and B", "A, B and C").
- Produces: `final readonly class TermFacts(array $grades /* array<string label, ?int> top-level subjects */, ?float $average, ?float $previousAverage, ?int $absences, ?int $tardies, int $schoolDays)`; `failingSubjects(): list<string>` (grade < 75, in given order); `isIncomplete(): bool` (any null grade).
- Produces: `CardSlipSuggester::suggest(TermFacts $facts): list<string>` — at most 3 codes, rules and priority exactly as the spec's "Suggestion rules".

- [ ] **Step 1: Tests** — `CardSlipCommentsTest`: every code in GROUPS has a text; `text('needs_improvement', ['Mathematics','Science'])` === "Needs to improve in Mathematics and Science. Please attend remedial sessions."; joinSubjects for 1/2/3 items. `CardSlipSuggesterTest` (data provider, each case = facts → expected codes):
  - avg 92, abs 0, tardy 0, days 50 → `['excellent','perfect_attendance']`
  - avg 86.5, prev 82, abs 1 → `['very_good','improved']`
  - avg 81, prev 79.5, abs 6 → `['good','absences','see_adviser']` (rise 1.5 < 3, no improved; absences ≥ 5 adds see_adviser)
  - Math 72, avg 80, abs 2, tardy 6 → `['needs_improvement','tardiness','see_adviser']`
  - one null grade → `['incomplete', …]` and no `needs_improvement` even if another grade < 75
  - avg 78 no failing, abs 1 → `['study_home']`
  - prev null → never `improved`; days 0 → never `perfect_attendance`; result never longer than 3.
- [ ] **Step 2: Run** both — FAIL.
- [ ] **Step 3: Implement** the three classes (pure PHP, no services needed for the catalog; the suggester is a plain service).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: card slip comment catalog and suggestions`.

### Task 3: `TermAttendance`

**Files:**
- Create: `src/CardSlip/TermAttendance.php`, `src/CardSlip/AttendanceTally.php`
- Test: `tests/CardSlip/TermAttendanceTest.php`

**Interfaces:**
- Consumes: `SchoolCalendar::schoolDays(School, SchoolYear, from, to)`, `RollCallRepository::findBetween(Section, from, to)` (roll calls with marks), `Sf2Roster::forMonth(Section, month)` → `RosterEntry` (`counts(day)`, enrollment id — check the class for the property name), `SchoolClock::now()`.
- Produces: `final readonly class AttendanceTally(int $schoolDays, int $present, int $absent, int $late)`; `TermAttendance::forSection(Section $section, GradingPeriod $period): array<int enrollmentId, ?AttendanceTally>` — null for every learner when the period has no start or end date.

- [ ] **Step 1: Tests** (kernel; take roll calls with `AttendanceRecorder` or persist `RollCall`/`AttendanceMark` as the attendance tests do): `testCountsTermDaysOnly` (term Aug 4–Aug 15, roll calls on Aug 1 and Aug 18 ignored); `testAbsentLateAndCutting` (2 Absent, 1 Late, 1 Cutting → absent 2, late 2, present = days − 2); `testDaysWithoutRollCallCountAsPresent`; `testLateEnrolleeCountsFromJoining` (LE learner from Aug 11 → school days 5); `testStopsAtToday` (mockTime mid-term); `testNoDatesGivesNull`; `testFutureTermHasZeroDays`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — days = school days in [start, min(end, today)]; per learner keep only days where any of the term's months' roster entries `counts`; marks from roll calls in range.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: attendance tally for a term`.

### Task 4: `CardSlipBuilder`

**Files:**
- Create: `src/CardSlip/CardSlip.php`, `src/CardSlip/CardSlipLine.php`, `src/CardSlip/CardSlipBuilder.php`
- Test: `tests/CardSlip/CardSlipBuilderTest.php`

**Interfaces:**
- Consumes: Tasks 1–3; `SectionGradeGrid::build(Section, GradingPeriod)` (`columns: list<GridColumn>` with `key`, `label`, `average`; `rows: list<{learner: SheetLearner, cells: array<key, ?int>}>`) — check how components relate to their parent column and use it for the component line; `GradeLockRepository::findFor`; the previous period = `$period->getSchoolYear()->getGradingPeriod($number - 1)`.
- Produces: `final readonly class CardSlipLine(string $label, ?int $grade, string $remarks, ?string $components /* "Music 85 · Arts 88 · PE 90 · Health 86" or null */)`.
- Produces: `final readonly class CardSlip(string $schoolName, ?string $schoolAddress, ?string $logoPath, string $periodLabel, string $schoolYear, string $learnerName, ?string $lrn, string $gradeSection /* "Grade 7 – ACTIVE" */, string $adviserName, list<CardSlipLine> $lines, ?float $average, ?string $descriptor, ?AttendanceTally $attendance, list<string> $comments /* rendered texts, typed one last */, bool $draft, int $enrollmentId, list<string> $suggestedCodes, list<string> $chosenCodes, ?string $customText)`; `averageText(): string` ("86.00" or "Incomplete").
- Produces: `CardSlipBuilder::forSection(Section, GradingPeriod): list<CardSlip>` (editable learners only, sheet order); `CardSlipBuilder::forEnrollment(Section, GradingPeriod, int $enrollmentId): ?CardSlip`; `public static function descriptor(float $average): string`.

- [ ] **Step 1: Tests** — `testGradesInReportCardOrderWithMapeh` (Filipino, Math, MAPEH with 4 components → lines in order, MAPEH grade = rounded average, components line exact); `testAverageAndDescriptor` (provider: 89.50 → Advancing, 89.49 → Benchmarking, 74.50 → Connecting, 74.49 → Developing, 64.49 → Emerging; `averageText()` two decimals); `testMissingComponentMakesIncomplete` (Review Focus 3: MAPEH null, average null, "Incomplete", suggested includes `incomplete`); `testRemarks` (75 Passed, 74 Failed, null ""); `testDroppedAndTransferredOutExcluded`; `testDraftUntilLocked`; `testSavedCommentsOverrideSuggestions` and `testEmptySavedListStaysEmpty` (saved `[]` + no text → comments `[]`); `testCustomTextLast`; `testImprovedUsesPreviousTerm`.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — average over top-level columns (parent subjects once, components excluded), descriptor from `round($avg)` half-up.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: build card slips from grades, attendance and comments`.

### Task 5: PDF and voter

**Files:**
- Create: `src/CardSlip/CardSlipPdf.php`, `templates/card_slip/pdf.html.twig`, `src/Security/Voter/CardSlipVoter.php`
- Test: `tests/CardSlip/CardSlipPdfTest.php`, `tests/Security/CardSlipVoterTest.php`

**Interfaces:**
- Consumes: Task 4 `CardSlip`; dompdf as `LearnerId\IdCardPdf` uses it (logo as a data URI or absolute file path the way ID cards load the school logo).
- Produces: `CardSlipPdf::render(list<CardSlip> $slips): string` (PDF bytes), `CardSlipPdf::html(list<CardSlip> $slips): string`; `CardSlipPdf::filename(Section, GradingPeriod): string`, `CardSlipPdf::learnerFilename(CardSlip): string`.
- Produces: `CardSlipVoter::EDIT = 'CARD_SLIP_EDIT'` (Section: its adviser, current school year), `CardSlipVoter::PRINT = 'CARD_SLIP_PRINT'` (Section: adviser, the school's principal, admin).

- [ ] **Step 1: Tests** — `CardSlipPdfTest`: `html()` of 3 slips contains 3 `.slip` blocks and 2 `.page` blocks, the second page with one slip (Review Focus 4); exact captions ("CARD SLIP · Term 1", "Adviser", "Parent/Guardian (signature over printed name)", "Date:"); "DRAFT – grades not final" present only for a draft slip; `render()` starts with `%PDF-`; filenames exact (incl. a section name with "/" replaced). `CardSlipVoterTest`: EDIT adviser ✓, other teacher ✗, principal ✗, admin ✗, last year's section ✗; PRINT adviser ✓, principal of the school ✓, other principal ✗, admin ✓, teacher ✗.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — Letter page `@page { size: 8.5in 11in; margin: 0 }`, each `.slip` exactly 5.5in tall, `.page` with page-break-after except the last, dashed cut line between the two slips; diagonal light-grey watermark for draft; fonts and sizes chosen so Review Focus 5 fits.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Manual layout check (Review Focus 5)** — write a throwaway script in the scratchpad that builds a worst-case `CardSlip` list (12 lines incl. MAPEH components, 3 longest comments + 300-char typed comment, a 60-character name, 3 slips) and saves `render()` output to the scratchpad; render page 1 to PNG (headless Edge `--screenshot` on the `html()` output saved as a file works) and look at it: both slips fit in their halves, nothing overflows the cut line. Adjust CSS until it does; note the result in the report.
- [ ] **Step 6: Commit** `feat: card slip PDF, two per short bond page`.

### Task 6: Adviser screen

**Files:**
- Create: `src/Controller/TeacherCardSlipController.php`, `src/Form/CardSlipCommentsFormType.php`, `src/Form/CardSlipCommentsData.php`, `src/CardSlip/CardSlipCommentRecorder.php`, `templates/card_slip/index.html.twig`
- Modify: My Class page template (add "Card slips" button beside Grades / SF1 / IDs)
- Test: `tests/Controller/TeacherCardSlipControllerTest.php`

**Interfaces:**
- Consumes: Tasks 1–5; the adviser's current section the way `TeacherClassController::scope()` finds it; `GradeSheetFormType::matchRoster` (public static) for the class-list-changed check; `SectionGradeGrid::defaultPeriod` is NOT the default here — default period = latest locked term, else the earliest open, else the last.
- Produces: routes `teacher_class_card_slips` (GET|POST `/teacher/class/card-slips?period=`), `teacher_class_card_slip_reset` (POST `/teacher/class/card-slips/{period}/{enrollment}/reset`, CSRF id `"card-slip-reset-" ~ enrollment`), `teacher_class_card_slips_pdf` (GET `/teacher/class/card-slips/{period}.pdf`), `teacher_class_card_slip_pdf` (GET `/teacher/class/card-slips/{period}/{enrollment}.pdf`).
- Produces: `CardSlipCommentRecorder::save(Section, GradingPeriod, array<int enrollmentId, array{codes: list<string>, text: ?string}>, User $by): int` (upserts rows for editable learners only, ignores unknown codes, returns count saved); `reset(Enrollment, GradingPeriod): void`.

- [ ] **Step 1: Tests** — `testPageShowsSuggestionsTicked` (learner with Math 72 → `needs_improvement` checkbox checked and marked "suggested"); `testSaveComments` (tick `participates`, untick a suggestion, type "Active in class." → flash "Comments saved for {n} learners.", PDF html shows exactly those); `testMoreThanThreeRefused` ("Choose at most 3 comments for {name}."); `testTypedTooLong` ("Keep the comment under 300 characters."); `testReset`; `testDraftNotice` (unlocked → notice text; locked → no notice); `testClassListChangedRefused`; `testPdfAll` and `testPdfOne` (200, `application/pdf`, exact filenames); `testDefaultPeriodIsLatestLocked`; `testNotAdviser403`; `testNoLearners` ("No learners to print slips for.").
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** — thin controller; form = one collection entry per editable learner keyed by enrollment id with `codes` (ChoiceType multiple, expanded, choices from `CardSlipComments::GROUPS`) and `text` (TextareaType, `Length(max: 300)` with the copy, `required: false`); the "at most 3" rule as a constraint/callback with the learner's name; access via `CardSlipVoter::EDIT` (page, save, reset) and `PRINT` (PDFs).
- [ ] **Step 4: Run** — PASS; `lint:twig`; full suite.
- [ ] **Step 5: Commit** `feat: advisers write and print card slips`.

### Task 7: Principal/admin download and whole-branch check

**Files:**
- Modify: `src/Controller/SchoolGradeController.php` (or a new `SchoolCardSlipController` under `/schools/{id}`), `templates/grades/school_section.html.twig` (button "Card slips (PDF)" for the selected term)
- Test: `tests/Controller/SchoolCardSlipTest.php`

**Interfaces:**
- Produces: route `school_section_card_slips_pdf` (GET `/schools/{id}/sections/{section}/card-slips/{period}.pdf`, `SchoolVoter::MANAGE` + `CardSlipVoter::PRINT`).

- [ ] **Step 1: Tests** — principal 200 PDF with the section filename; admin 200; another school's section 404; period of another school year 404; teacher 403; button present on the section grades page.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** — PASS. Then the whole-branch checks: `php bin/phpunit` (all), `lint:twig templates`, `lint:container`, `doctrine:schema:validate` (dev and `--env=test`).
- [ ] **Step 5: Commit** `feat: principal and admin print a section's card slips`.
