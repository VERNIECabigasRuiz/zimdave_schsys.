# SF2 Daily Attendance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The section adviser takes daily attendance; the system builds DepEd SF2 per month as Excel in the user's template layout, with submit/lock, a principal-kept school calendar, and a principal/admin attendance overview.

**Architecture:** Exceptions-only storage (`RollCall` per section per day + `AttendanceMark` for absent/late/cutting). `SchoolCalendar` answers "is this a school day"; `Sf2Roster` answers "who counts on which day"; `Sf2ReportBuilder` computes every SF2 figure into readonly DTOs; `Sf2Writer` lays them out like SF1's writer. Writes go through `AttendanceRecorder` under a per-section lock. `AttendanceVoter` gates TAKE / VIEW / REOPEN.

**Tech Stack:** Symfony 8.1, PHP 8.4, Doctrine ORM + migrations, Twig, Stimulus, PhpSpreadsheet (Xls), PHPUnit (KernelTestCase / WebTestCase).

**Spec:** `docs/superpowers/specs/2026-10-29-sf2-daily-attendance-design.md`

## Global Constraints

- Branch `feature/sf2`. Schema only via `php bin/console make:migration` + `doctrine:migrations:migrate` (dev) and `--env=test` (test DB).
- PHPUnit on this laptop: run through the scratchpad runner `pu.ps1` (PowerShell `Start-Process`; Avast suspends piped php.exe), always with a path argument. Commands below say `php bin/phpunit …` for brevity.
- Tests build services with `new` (the test container drops unused private services), `SchoolFixturesTrait` fixtures, `ClockSensitiveTrait::mockTime`. Fixture school year: 2025-06-01 .. 2026-04-30 (`schoolYear(2025, current: true)`).
- "Today" is `SchoolClock::now()` (school timezone), never `new \DateTimeImmutable()`.
- Months are `\DateTimeImmutable` at 00:00 on the 1st; route form `Y-m` with requirement `\d{4}-\d{2}`; dates `Y-m-d`.
- Marks: `LearnerMark::Absent='absent'`, `Late='late'`, `Cutting='cutting'`. Present = no mark. Late and Cutting count as present and as tardy.
- Day letters: M, T, W, TH, F.
- CSRF: stateless `submit` token as the rest of the app; Symfony Forms for every POST with fields.
- Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. A learner who joins or leaves mid-month (late enrollee, transfer in, transfer out, drop) — cells before/after their span must be blank and excluded from every total, ADA and per-day count. (Tests in Task 3 and Task 5.)
2. A day off added *after* a roll call was taken on that date — the roll call must vanish from totals, the grid and the overview, not double count. (Test in Task 5.)
3. Five consecutive absences spanning a weekend, a day off and the month boundary. (Test in Task 5.)
4. Principal or admin posting to the adviser's roll-call URL, or another teacher posting, or anyone posting into a submitted month — refused server-side, not only hidden in the UI. (Tests in Tasks 7–8.)
5. A section with more than 21 males / 25 females, and a month with 23 school days — nothing cut off in the Excel. (Test in Task 6.)

---

### Task 1: School calendar (days off) and the mark enum

**Files:**
- Create: `src/Entity/LearnerMark.php`, `src/Entity/SchoolDayOff.php`, `src/Repository/SchoolDayOffRepository.php`, `src/Attendance/SchoolCalendar.php`, migration
- Modify: `tests/SchoolFixturesTrait.php` (clear new tables first)
- Test: `tests/Attendance/SchoolCalendarTest.php`

**Interfaces — Produces:**
- `enum LearnerMark: string` — cases above; `label(): string` ("Absent", "Late", "Cutting classes"); `isTardy(): bool`; `code(): string` ('x', 'L', 'C').
- `SchoolDayOff(School $school, SchoolYear $schoolYear, \DateTimeImmutable $date, string $reason, User $createdBy, \DateTimeImmutable $createdAt)`; getters; `reason` `Assert\NotBlank`, `Assert\Length(max: 100)`; unique constraint (school_id, date).
- `SchoolDayOffRepository::findBetween(School, \DateTimeImmutable $from, \DateTimeImmutable $to): array<string Y-m-d, SchoolDayOff>`; `findForYear(School, SchoolYear): list<SchoolDayOff>` ordered by date.
- `SchoolCalendar` (service, ctor `SchoolDayOffRepository`):
  - `schoolDays(School, SchoolYear, \DateTimeImmutable $from, \DateTimeImmutable $to): list<\DateTimeImmutable>` — Mon–Fri in [from, to] ∩ [sy start, sy end], minus days off.
  - `monthDays(School, SchoolYear, \DateTimeImmutable $month): list<\DateTimeImmutable>` — `schoolDays` for that calendar month.
  - `dayOff(School, \DateTimeImmutable $date): ?SchoolDayOff`
  - `isSchoolDay(School, SchoolYear, \DateTimeImmutable $date): bool`
  - `cutOff(SchoolYear): \DateTimeImmutable` — first Friday on/after start date; no start date → first Friday of June of the year in the name's first 4 chars.

- [ ] **Step 1: Write `SchoolCalendarTest`** (KernelTestCase, fixtures, day off 2025-08-21 "Ninoy Aquino Day"):
  - `testMonthDaysAreWeekdaysWithoutDaysOff`: August 2025 → 20 dates, first `2025-08-01`, contains no `2025-08-21`, no Saturday/Sunday.
  - `testSchoolDaysStayInsideTheSchoolYear`: `schoolDays(.., 2025-05-26, 2025-06-06)` → first `2025-06-02` (June 1 2025 is a Sunday), 5 dates.
  - `testIsSchoolDay`: `2025-08-21` false, `2025-08-23` (Sat) false, `2025-08-22` true, `2026-05-04` false (after end).
  - `testCutOffIsFirstFridayFromStart`: start 2025-06-01 → `2025-06-06`; start set to 2025-06-16 → `2025-06-20`.
  - `testDayOffLookup`: `dayOff(school, 2025-08-21)->getReason()` = "Ninoy Aquino Day".
- [ ] **Step 2:** Run `php bin/phpunit tests/Attendance/SchoolCalendarTest.php` → FAIL (classes missing).
- [ ] **Step 3:** Create the enum, entity, repository, `SchoolCalendar`; add `SchoolDayOff` to the front of `clearSchoolData()`'s list. `make:migration`, review it holds only `school_day_off`, migrate dev and test.
- [ ] **Step 4:** Run the test → PASS. Run `php bin/console doctrine:schema:validate` → mapping and DB in sync.
- [ ] **Step 5:** Commit `feat: school calendar of days off for attendance`.

### Task 2: Roll calls, marks and submissions

**Files:**
- Create: `src/Entity/RollCall.php`, `src/Entity/AttendanceMark.php`, `src/Entity/Sf2Submission.php`, their repositories, migration
- Modify: `tests/SchoolFixturesTrait.php` (clear `AttendanceMark`, `RollCall`, `Sf2Submission` before `SchoolDayOff`)
- Test: `tests/Entity/RollCallTest.php`, `tests/Repository/RollCallRepositoryTest.php`

**Interfaces — Produces:**
- `RollCall(Section $section, \DateTimeImmutable $date, User $takenBy, \DateTimeImmutable $takenAt)`; `getSection()`, `getDate()`, `getTakenBy()`, `getTakenAt()`, `getUpdatedAt(): ?\DateTimeImmutable`, `getMarks(): Collection<AttendanceMark>`, `markFor(Enrollment): ?LearnerMark`, `replaceMarks(array<int enrollmentId, array{Enrollment, LearnerMark}> $marks, User $by, \DateTimeImmutable $at): void` (removes marks not in the list, updates changed, adds new; sets updatedAt when it already existed — i.e. when called a second time). Unique (section_id, date). `marks` one-to-many, `cascade: ['persist']`, `orphanRemoval: true`.
- `AttendanceMark(RollCall, Enrollment, LearnerMark)`; `getEnrollment()`, `getMark()`, `setMark()`. Unique (roll_call_id, enrollment_id); `enrollment` join column `onDelete: 'CASCADE'`.
- `Sf2Submission(Section, \DateTimeImmutable $month, User $submittedBy, \DateTimeImmutable $submittedAt)`; getters. Unique (section_id, month). `Section` FK `onDelete: CASCADE` for all three.
- `RollCallRepository::findOne(Section, \DateTimeImmutable $date): ?RollCall`; `findBetween(Section, from, to): array<string Y-m-d, RollCall>` (marks fetch-joined); `findForSchoolOn(School, SchoolYear, date): array<int sectionId, RollCall>`; `countSectionsOn(School, list<\DateTimeImmutable>): int` (distinct sections with a roll call on any of the dates).
- `Sf2SubmissionRepository::findOne(Section, \DateTimeImmutable $month): ?Sf2Submission`; `findForMonth(School, SchoolYear, month): array<int sectionId, Sf2Submission>`.

- [ ] **Step 1: Write tests.**
  - `RollCallTest::testReplaceMarksAddsChangesAndRemoves` (plain unit test, entities built with `new`, enrollments given ids by reflection): replace with {A: Absent, B: Late} → 2 marks, `markFor(A)` Absent; replace with {B: Cutting, C: Absent} → `markFor(A)` null, `markFor(B)` Cutting, `markFor(C)` Absent, `getUpdatedAt()` not null.
  - `RollCallRepositoryTest::testFindBetweenKeysByDate` (persisted): two roll calls 2025-08-04 and 2025-08-05 for a section, one on 2025-08-04 for another section → `findBetween(section, 08-01, 08-31)` keys `['2025-08-04','2025-08-05']`.
  - `testUnenrollDeletesMarks`: remove the enrollment via DQL delete → the mark is gone, roll call stays.
- [ ] **Step 2:** Run both → FAIL.
- [ ] **Step 3:** Implement; `make:migration` (three tables), migrate dev and test.
- [ ] **Step 4:** Run → PASS; `doctrine:schema:validate` in sync.
- [ ] **Step 5:** Commit `feat: roll calls, attendance marks and SF2 submissions`.

### Task 3: Who counts on which day — `Sf2Roster`

**Files:**
- Create: `src/Attendance/Sf2Roster.php`, `src/Attendance/RosterEntry.php`
- Modify: `src/Repository/TransferRequestRepository.php` (+ `findApprovedOutOf`)
- Test: `tests/Attendance/Sf2RosterTest.php`

**Interfaces:**
- Consumes: `SchoolCalendar::cutOff` (Task 1), `EnrollmentRepository::findClassList`, `TransferRequestRepository::findApprovedInto`, `Sf1Remarks::textFor`.
- Produces:
  - `TransferRequestRepository::findApprovedOutOf(Section $section, \DateTimeImmutable $since): list<TransferRequest>` — approved, `fromSection` = section, `decidedAt >= since`.
  - `final readonly class RosterEntry` — `Enrollment $enrollment`, `bool $male`, `string $name` (`Learner::getSf1Name()`), `\DateTimeImmutable $from`, `?\DateTimeImmutable $until` (last counted day, inclusive), `bool $lateEnrollment`, `bool $transferredIn`, `bool $transferredOut`, `bool $dropped`, `bool $undatedDrop`, `?string $remark`; `counts(\DateTimeImmutable $day): bool`.
  - `Sf2Roster::forMonth(Section, \DateTimeImmutable $month): list<RosterEntry>` — class list + learners transferred out of the section since the month's 1st; males first then females, each by name; entries whose span misses the month entirely are left out.
- Rules (spec "Learner's span"): `from` = approved transfer-in `decidedAt` (date part) if any, else max(`enrolledAt` date, sy start). `until` = DRP `remarkDate` − 1 day; transferred out → `decidedAt` − 1 day; else null. `lateEnrollment` = not transferred in and `from` > cut-off. Remark: transferred out → "T/O {toSchool name}"; DRP → "DRP {remarkDetails}" (or "DRP" without details); else `Sf1Remarks::textFor()`.

- [ ] **Step 1: Write `Sf2RosterTest`** (mockTime 2025-09-15; enrolledAt set via reflection or the Enrollment ctor arg):
  - `testOnTimeEnrolleeCountsFromSchoolYearStart`: enrolledAt 2025-05-20 → `from` 2025-06-01, `until` null, `lateEnrollment` false.
  - `testLateEnrolleeCountsFromEnrollment`: enrolledAt 2025-08-11 → `from` 2025-08-11, `lateEnrollment` true; `counts(2025-08-08)` false, `counts(2025-08-11)` true.
  - `testDropStopsTheDayBefore`: DRP remark date 2025-08-20, details "b.1 Illness" → `until` 2025-08-19, remark "DRP b.1 Illness".
  - `testUndatedDropKeepsCounting`: DRP without date → `until` null, `undatedDrop` true.
  - `testTransferredOutStaysInOldSectionUntilTheDayBefore`: approved transfer from this section decided 2025-08-14 → entry present in `forMonth(section, 2025-08)`, `transferredOut` true, `until` 2025-08-13, remark starts "T/O "; not present in `forMonth(section, 2025-09)`.
  - `testTransferredInCountsFromDecision`: the receiving section's entry `from` 2025-08-14, `transferredIn` true, `lateEnrollment` false.
  - `testMalesFirst`: two males, one female → order M, M, F.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: SF2 roster of who counts on which day`.

### Task 4: Recording — `AttendanceRecorder` and calendar writes

**Files:**
- Create: `src/Attendance/AttendanceRecorder.php`, `src/Attendance/AttendanceException.php`, `src/Attendance/AttendanceRules.php`
- Test: `tests/Attendance/AttendanceRecorderTest.php`

**Interfaces:**
- Consumes: Tasks 1–3, `SchoolClock`, `LockFactory`, `ActivityLogger`, `EntityManagerInterface`.
- Produces:
  - `AttendanceException extends \RuntimeException` (message shown to the user as a flash).
  - `AttendanceRules` (service): `refusalToTake(Section, \DateTimeImmutable $date): ?string` — in order: outside the school year → "That date is outside the school year."; weekend → "No classes on weekends."; day off → "No classes: {reason}."; after today → "You can't take attendance for a future date."; month submitted → "{Month Y} is submitted and locked." `isLocked(Section, month): bool`. `missingDays(Section, month): list<\DateTimeImmutable>` — school days ≤ today with no roll call. `monthHasEnded(Section, month): bool` — last school day of the month < today, or the school year's end < today.
  - `AttendanceRecorder`:
    - `takeRollCall(Section, \DateTimeImmutable $date, array<int enrollmentId, ?LearnerMark> $marks, User $by): RollCall` — refuses with `refusalToTake`; ignores enrollments not counted that day (via `Sf2Roster`); creates or updates; logs "Took attendance of {grade} – {SECTION} for {Y-m-d}" or "Updated attendance of … for …"; flush. Lock key `attendance-section-{id}`; busy → `AttendanceException(LearnerLock::BUSY)`.
    - `submit(Section, \DateTimeImmutable $month, User $by, bool $confirmMissing): Sf2Submission` — refuses: already submitted; month not ended → "You can submit {Month} once its last school day has passed."; missing days and not confirmed → "{n} school days have no attendance. Confirm that everyone was present on those days."; logs "Submitted SF2 of … for {F Y}".
    - `reopen(Section, \DateTimeImmutable $month): void` — refuses when not submitted; deletes; logs "Reopened SF2 of … for {F Y}".
    - `addDaysOff(School, SchoolYear, \DateTimeImmutable $from, ?\DateTimeImmutable $to, string $reason, User $by): array{added: int, sectionsWithAttendance: int}` — weekdays in range, skips weekends and existing days off; refuses a date outside the school year or `to < from` or a range longer than 62 days; logs "Added day off {from}[ to {to}]: {reason}".
    - `removeDayOff(SchoolDayOff): void` — logs "Removed day off {date}".

- [ ] **Step 1: Write `AttendanceRecorderTest`** (mockTime 2025-08-22 10:00, a Friday; section with adviser and 3 enrolled learners):
  - `testTakeCreatesThenUpdates`: take 2025-08-22 {A: Absent, B: Late, C: null} → 1 roll call, 2 marks, an ActivityLog "Took attendance of Grade 8 – ARTISTIC for 2025-08-22"; take again {B: Cutting} → same roll call, 1 mark Cutting, log "Updated attendance …".
  - `testRefusals` (data provider, expect `AttendanceException` + message): `2025-08-23` weekend; `2025-08-21` day off "Ninoy Aquino Day" → "No classes: Ninoy Aquino Day."; `2025-08-25` future; `2025-05-30` outside; a July date after submitting July → "July 2025 is submitted and locked."
  - `testMarksForNotCountedLearnersAreIgnored`: learner enrolled 2025-08-22 … marked on 2025-08-20 → no mark stored.
  - `testSubmitNeedsAnEndedMonthAndConfirmation`: submit August on 2025-08-22 → "once its last school day has passed"; mockTime 2025-09-02, July with 2 roll calls → refused for missing days; with `confirmMissing: true` → submitted.
  - `testReopenDeletesTheSubmission`.
  - `testAddDaysOffRange`: 2025-12-19 .. 2026-01-02 "Christmas break" → added 11 (weekdays Dec 19, 22–26, 29–31, Jan 1–2), re-adding → added 0; a roll call on 2025-12-19 → `sectionsWithAttendance` 1; `to` before `from` refused.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: take attendance, submit and reopen SF2, manage days off`.

### Task 5: SF2 figures — `Sf2ReportBuilder`

**Files:**
- Create: `src/Attendance/Sf2Report.php`, `src/Attendance/Sf2Row.php`, `src/Attendance/Sf2Summary.php`, `src/Attendance/Sf2ReportBuilder.php`
- Test: `tests/Attendance/Sf2ReportBuilderTest.php`

**Interfaces:**
- Consumes: Tasks 1–3, `SchoolAssignmentRepository::findPrincipal`, `Sf2SubmissionRepository`, `AttendanceRules::missingDays`, `SchoolClock`.
- Produces:
  - `Sf2Row` (readonly): `int $enrollmentId`, `int $learnerId`, `string $name`, `bool $male`, `list<string> $cells` (one per school day: `Sf2Row::PRESENT=''`, `ABSENT='x'`, `LATE='L'`, `CUTTING='C'`, `NOT_COUNTED='-'`), `int $absences`, `int $tardies`, `?string $remark`, `bool $fiveConsecutive`, `bool $undatedDrop`.
  - `Sf2Summary` (readonly): `int $daysOfClasses`; each of `enrolmentAtCutOff`, `lateEnrollment`, `registered`, `consecutiveAbsent`, `dropped`, `transferredOut`, `transferredIn` as `array{int, int, int}` (M, F, total); `percentEnrolment`, `ada`, `percentAttendance` as `array{?float, ?float, ?float}` rounded to 2 decimals (null when the divisor is 0); `enrolmentAtCutOff` is `null` when the month ends before the cut-off.
  - `Sf2Report` (readonly): `schoolId`, `schoolName`, `schoolYear` ("2025 - 2026"), `\DateTimeImmutable $month`, `gradeLevel`, `section` (upper-case), `list<\DateTimeImmutable> $days`, `list<bool> $taken` (roll call exists per day), `list<Sf2Row> $male`, `list<Sf2Row> $female`, `list<int> $malePresent`, `list<int> $femalePresent` (per day; combined = sum), `Sf2Summary $summary`, `?string $adviserName`, `?string $schoolHeadName`, `?Sf2Submission $submission`, `list<\DateTimeImmutable> $missingDays`, `list<string> $daysOff` ("Aug 21 – Ninoy Aquino Day"); `fileName(): string` → `SF2_{startYear}_{grade} - {SECTION}_{F}.xls` with `/ \ %` → `-`; `dayLetter(int $i): string`.
  - `Sf2ReportBuilder::build(Section, \DateTimeImmutable $month): Sf2Report`.
- Figures: as the spec's "Computed figures". Five consecutive: runs over the learner's counted school days from the 4 school days before the month through the month's end; a run of ≥ 5 absences that includes a day of the month flags the row and counts once. Days with no roll call count everyone present. Roll calls on dates that are no longer school days are ignored.

- [ ] **Step 1: Write `Sf2ReportBuilderTest`** (mockTime 2025-09-05; August 2025 has 20 school days with day off Aug 21; 2 males, 2 females on time; 1 female late enrollee from 2025-08-11; 1 male dropped 2025-08-20):
  - `testDaysAndCells`: `days` count 20, no Aug 21; male row 1 with Absent on 08-01 (a Friday, the first school day) has `cells[0]` = 'x'; Late 08-04 → `cells[1]` 'L'; Cutting 08-05 → `cells[2]` 'C'; late enrollee's cells before 08-11 = '-'.
  - `testPerLearnerTotals`: absences 1, tardies 2 for that row.
  - `testPerDayPresentCounts`: on 08-01 `malePresent` = 2 − 1 absent = 1 (dropped male counted until 08-19), `femalePresent` = 2 (late enrollee not yet counted).
  - `testSummaryFormulas`: with known marks compute by hand in the test and assert `ada`, `percentAttendance`, `registered` (M 1, F 3, total 4 — dropped male not registered at month end), `percentEnrolment` (registered ÷ enrolment as of cut-off 2025-06-06 (M 2, F 2) × 100 → M 50.0, F 150.0, total 100.0), `lateEnrollment` F 1, `dropped` M 1.
  - `testFiveConsecutiveAcrossWeekendAndDayOff`: absent Aug 18, 19, 20, 22, 25 (21 is off, 23–24 weekend) → `fiveConsecutive` true, `consecutiveAbsent` total 1; absent Aug 18–20, present 22, absent 25–26 → false.
  - `testRunStartingInPreviousMonth`: absent Jul 29–31 and Aug 1, 4 → flagged in August.
  - `testDayOffAddedAfterRollCallIsIgnored`: roll call on Aug 21 with an absence, then the day off exists → the absence isn't counted, Aug 21 not in `days`.
  - `testDaysWithoutRollCallCountPresent`: no roll calls at all → ADA = registered each day averaged; `missingDays` = 20 (all passed).
  - `testTransferredOutRowAndSummary`: transfer out decided Aug 14 → row remark "T/O …", cells from Aug 14 = '-', `transferredOut` total 1.
  - `testFileName`: section "A/B%C" → `SF2_2025_Grade 8 - A-B-C_August.xls`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: compute SF2 figures for a section and month`.

### Task 6: SF2 Excel — `Sf2Writer`

**Files:**
- Create: `src/Attendance/Sf2Writer.php`
- Test: `tests/Attendance/Sf2WriterTest.php`

**Interfaces:**
- Consumes: `Sf2Report` (Task 5).
- Produces: `Sf2Writer::write(Sf2Report): Spreadsheet` (one sheet titled "School Form 2 (SF2)").
- Layout copies the template (`C:\Users\ZiM\Downloads\School Form 2 (SF2) Daily Attendance Report of Learners.xlsx`, sheet AK93, A4 landscape): A2:AJ2 title, A3:AJ3 subtitle; row 6 School ID (label B6, value C6:E6), School Year (H6, K6:O6), Report for the Month of (Q6, X6:AC6); row 8 Name of School (B8, value merged to S8), Grade Level (T8:W8, X8:Y8), Section (Z8:AB8, AC8:AH8); A10:C13 learner-name header; D10:AB10 date note; row 11 dates (day number), row 12 day letters in D..; AC10:AD11 "Total for the Month", AC12:AC13 ABSENT, AD12:AD13 TARDY, AE10:AJ13 remarks header text. Learner rows from 14: number in A, name B:C merged, cells D.., absences AC, tardies AD, remark AE:AJ merged. Male block = max(21, males) rows, then "MALE | TOTAL Per Day" row (A:C merged, per-day present counts); female block = max(25, females) rows, "FEMALE | TOTAL Per Day", "Combined TOTAL PER DAY". Below (offset by the extra rows): guidelines 1–6 and formulas (A–O), codes legend and drop-out reasons (Q–Z), summary box (AB–AJ, M/F/TOTAL in AH/AI/AJ), certification (AB86–AC89 equivalent) with the adviser's name above "(Signature of Teacher over Printed Name)", "Attested by:" with the school head's name above "(Signature of School Head over Printed Name)". Copy the template's texts verbatim (as dumped in the spec session; read the template cells again while implementing). Thin borders on the grid, fit to 1 page wide.
- Mark rendering: Absent → 'x' centered. Late → gradient fill (linear, 90°, stops: colour `#808080` at 0–50 %, white 50–100 %) upper half; Cutting → reversed (lower half). If Excel/LibreOffice render doesn't show halves, fall back to light grey fill + 'L'/'C' text and ledger the ruling.

- [ ] **Step 1: Write `Sf2WriterTest`** (build an `Sf2Report` by hand, write, save Xls to a temp file, reload with `IOFactory::load`):
  - `testHeader`: C6 = school ID, K6 = "2025 - 2026", X6 = "August", C8 school name, X8 "Grade 8", AC8 "ARTISTIC".
  - `testDaysAndMarks`: D11 = 4, D12 = "M"; first male row 14: B14 name, D14 "x", AC14 1, AD14 2; the Late cell has a gradient fill.
  - `testTotalsRows`: "MALE | TOTAL Per Day" at A35 with 21 males or fewer; D35 = malePresent[0]; combined row A62 = sum.
  - `testSummaryValues`: the cells next to "Average Daily Attendance" hold ADA M/F/total; "No. of Days of Classes" value = 20.
  - `testExtraRows`: 23 males, 27 females → MALE total row at 14+23 = A37, every name present.
  - `testSignatures`: adviser and school-head names present above their captions.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Visual check.** Scratch script: build a sample report (30 learners, all four marks, a transfer, a drop), save `.xls`, render with Excel COM `CopyPicture` → PNG (as for SF1), and the template likewise; compare side by side. Fix layout until it matches. Ledger the half-fill ruling.
- [ ] **Step 6:** Commit `feat: SF2 Excel in the DepEd template layout`.

### Task 7: Access — `AttendanceVoter`

**Files:**
- Create: `src/Security/Voter/AttendanceVoter.php`
- Test: `tests/Security/AttendanceVoterTest.php`

**Interfaces — Produces:** constants `TAKE = 'ATTENDANCE_TAKE'`, `VIEW = 'ATTENDANCE_VIEW'`, `REOPEN = 'ATTENDANCE_REOPEN'`; subject `Section`. Rules: TAKE — not admin, section adviser, section in current school year. VIEW — admin, principal of the section's school (current-year assignment, as `LearnerVoter::isPrincipalOf`), or the adviser. REOPEN — admin or principal of the school. (Locked/date rules live in `AttendanceRules`, not the voter.)

- [ ] **Step 1: Write `AttendanceVoterTest`** (as `LearnerVoterTest`): adviser TAKE/VIEW yes, REOPEN no; principal VIEW/REOPEN yes, TAKE no; admin VIEW/REOPEN yes, TAKE no; another teacher none; student none; adviser of last year's section TAKE no.
- [ ] **Step 2:** Run → FAIL. **Step 3:** Implement. **Step 4:** Run → PASS.
- [ ] **Step 5:** Commit `feat: who may take, view and reopen attendance`.

### Task 8: Adviser screens

**Files:**
- Create: `src/Controller/TeacherAttendanceController.php`, `src/Controller/AbstractAttendanceController.php` (shared month grid + SF2 download), `src/Form/RollCallFormType.php`, `src/Form/Sf2SubmitFormType.php`, `templates/attendance/roll_call.html.twig`, `templates/attendance/month.html.twig`, `templates/attendance/_grid.html.twig`, `templates/attendance/_summary.html.twig`, `assets/controllers/attendance_mark_controller.js`, CSS in the existing stylesheet
- Modify: `templates/learners/class_list.html.twig` (an "Attendance" button: `teacher_class_attendance_month` in the teacher scope, `school_attendance_section` for the current month in the school scope), `templates/teacher/_layout.html.twig` (nav "Attendance" under My class), `templates/teacher/dashboard.html.twig` (card "Today's attendance: taken / not taken yet" for an adviser)
- Test: `tests/Controller/TeacherAttendanceControllerTest.php`

**Interfaces:**
- Consumes: Tasks 1–7.
- Produces routes (all `#[IsGranted(User::ROLE_TEACHER)]`, section = `SectionRepository::findAdvisory(user, current year)`, else 403):
  - `teacher_class_attendance` `GET|POST /teacher/class/attendance/{date}` (`date` optional, `\d{4}-\d{2}-\d{2}`, default today): `denyAccessUnlessGranted(TAKE)` for POST, VIEW for GET. GET on a refused date shows the refusal message (no form). POST via `RollCallFormType` (option `entries: list<RosterEntry>` counted that day, one `EnumType` field `mark_{enrollmentId}` per entry, `required: false`, placeholder "Present"), then `AttendanceRecorder::takeRollCall`, learners on the roster but not counted that day are listed greyed with the reason ("Enrolled Aug 11", "Dropped", "Transferred out") and no field; flash "Attendance saved for {l, F j}." and redirect to the same URL; `AttendanceException` → flash error.
  - `teacher_class_attendance_month` `GET /teacher/class/attendance/month/{month}` (optional, default current month): `Sf2ReportBuilder::build` → `attendance/month.html.twig`.
  - `teacher_class_sf2` `GET /teacher/class/attendance/month/{month}/sf2`: Xls download like `downloadSf1` (`application/vnd.ms-excel`, same disposition handling).
  - `teacher_class_attendance_submit` `POST /teacher/class/attendance/month/{month}/submit`: `Sf2SubmitFormType` (`confirm_missing` checkbox, shown only when `missingDays` isn't empty) → `AttendanceRecorder::submit`.
- `AbstractAttendanceController` holds `renderMonth(Section, month, array $routes)` and `downloadSf2(Section, month)`, used again by Task 9.
- Month grid (`_grid`): learners down, school days across (day number + letter, header links to the roll call in the teacher scope), cells `x`/`L`/`C` (CSS classes `mark--absent`, `mark--late` top-half shading, `mark--cutting` bottom-half shading), `–` for a passed day not taken (`taken[i]` false), grey for not counted; totals and remarks columns; per-day totals rows. `_summary`: the summary box. Banners: five consecutive (names), undated drops. Status line: "Submitted {date} by {name} · locked" or the submit form when `AttendanceRules::monthHasEnded`. Month picker: previous/next month within the school year.
- Stimulus `attendance-mark`: on each row hides the `<select>` and shows a button chip cycling Present → Absent → Late → Cutting classes → Present, writing the select's value; updates the counters target ("{p} present · {a} absent · {l} late · {c} cutting"). Without JS, the selects work.

- [ ] **Step 1: Write `TeacherAttendanceControllerTest`** (WebTestCase, mockTime 2025-08-22 10:00, adviser logged in, 3 learners):
  - `testRollCallPageListsLearners`: GET `/teacher/class/attendance` → 200, three names, "Present" placeholder.
  - `testSaveRollCall`: submit the form with learner A Absent, B Late → redirect; DB has 1 roll call, 2 marks; follow → flash "Attendance saved".
  - `testEditRollCall`: second POST with A Present → 0 absences in the DB.
  - `testRefusedDatesShowMessage`: GET `/teacher/class/attendance/2025-08-21` → "No classes: Ninoy Aquino Day."; `/2025-08-25` → "future date"; POST to `/2025-08-25` → no roll call created.
  - `testMonthGrid`: GET `/teacher/class/attendance/month/2025-08` → 200, contains "x", the summary label "Average Daily Attendance", Download SF2 link.
  - `testSf2Download`: GET `/teacher/class/attendance/month/2025-08/sf2` → 200, `Content-Type` application/vnd.ms-excel, disposition filename `SF2_2025_Grade 8 - ARTISTIC_August.xls`.
  - `testSubmitLocksTheMonth`: mockTime 2025-09-02, POST submit for July with `confirm_missing` → submitted; POST a roll call on 2025-07-15 → refused "July 2025 is submitted and locked."
  - `testNonAdviserIs403`: a teacher without advisory → 403 on GET and POST.
  - `testPrincipalCannotTake`: principal (ROLE_PRINCIPAL) GET `/teacher/class/attendance` → 403 (role gate).
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement controllers, forms, templates, Stimulus controller, CSS, nav and dashboard card.
- [ ] **Step 4:** Run → PASS; `php bin/console lint:twig templates/` and `lint:container` clean.
- [ ] **Step 5:** Commit `feat: adviser takes attendance and submits SF2`.

### Task 9: Principal and admin — calendar, overview, section month, reopen

**Files:**
- Create: `src/Controller/SchoolCalendarController.php`, `src/Controller/SchoolAttendanceController.php` (extends `AbstractAttendanceController`), `src/Attendance/AttendanceStatistics.php`, `src/Attendance/SectionToday.php`, `src/Attendance/SectionMonth.php`, `src/Attendance/Sf2Status.php`, `src/Form/DayOffFormType.php`, `templates/attendance/calendar.html.twig`, `templates/attendance/overview.html.twig`, `templates/attendance/section_month.html.twig`
- Modify: `src/Controller/Principal/DashboardController.php` (+ `principal_attendance` and `principal_calendar` redirects like `principal_learners`), `templates/principal/_layout.html.twig` (nav "Attendance", active for `school_attendance*` and `school_calendar*`), `templates/principal/dashboard.html.twig` (card: sections taken today n/m, absent today, follow-ups count), `templates/admin/schools/index.html.twig` (an "Attendance" button beside "Learners")
- Test: `tests/Controller/SchoolAttendanceControllerTest.php`, `tests/Attendance/AttendanceStatisticsTest.php`

**Interfaces:**
- Consumes: Tasks 1–8.
- Produces:
  - `enum Sf2Status: string { NotSubmitted, Submitted, Late }` + `label()`. Late = submitted after the 5th of the next month, or not submitted and today is after the 5th of the next month.
  - `SectionToday` (readonly): `Section $section`, `bool $taken`, `int $present`, `int $absent`, `int $tardy`, `?string $adviserName`.
  - `SectionMonth` (readonly): `Section $section`, `Sf2Report $report`, `int $absences`, `int $tardies`, `Sf2Status $status`.
  - `AttendanceStatistics::today(School, SchoolYear): array{schoolDay: bool, reason: ?string, sections: list<SectionToday>}`; `month(School, SchoolYear, \DateTimeImmutable $month): list<SectionMonth>` (sections ordered by grade then name); `followUps(list<SectionMonth>): list<array{section: Section, row: Sf2Row}>`; `status(Section, month, ?Sf2Submission): Sf2Status`.
  - Routes, all under `#[Route('/schools/{id}')]` + `#[IsGranted(SchoolVoter::MANAGE, 'school')]`:
    - `school_calendar` `GET|POST /calendar` — list + `DayOffFormType` (from, to optional, reason) → `AttendanceRecorder::addDaysOff`; flash "{n} days off added." plus warning "{k} sections already took attendance on those days; those days no longer count." when k > 0.
    - `school_calendar_remove` `POST /calendar/{dayOff}/remove` (`IsCsrfTokenValid('day-off-'~id)`), 404 if the day off belongs to another school.
    - `school_attendance` `GET /attendance?month=Y-m` — overview: Today card, Month table with grade subtotals + school total + CSS bars, Needs follow-up list.
    - `school_attendance_section` `GET /sections/{section}/attendance/{month}` — read-only grid (VIEW), Download, Reopen form when submitted (REOPEN).
    - `school_attendance_sf2` `GET /sections/{section}/attendance/{month}/sf2`.
    - `school_attendance_reopen` `POST /sections/{section}/attendance/{month}/reopen` (`IsCsrfTokenValid('reopen-'~section)`).
    - Section must belong to `{id}` school, else 404.

- [ ] **Step 1: Write tests.**
  - `AttendanceStatisticsTest`: `testTodayTakenAndCounts` (two sections, one roll call with 1 absent, 1 late → taken true, present n−1, absent 1, tardy 1; other section taken false); `testNoClassesToday` (mockTime on a day off → schoolDay false, reason); `testStatus` (submitted 2025-09-04 → Submitted; submitted 2025-09-08 → Late; not submitted and today 2025-09-06 → Late; today 2025-09-03 → NotSubmitted); `testFollowUps` (one learner with 5 absences → one entry).
  - `SchoolAttendanceControllerTest` (mockTime 2025-08-22): principal GET `/schools/{id}/calendar` 200; POST range Dec 19 – Jan 2 → flash "11 days off added."; remove → gone; another school's day off → 404; GET `/schools/{id}/attendance` → 200 with section name, "Not taken yet"; GET section month → 200, no roll-call form, Download link; GET sf2 → Xls; reopen a submitted month → submission gone, flash; principal POST to `/teacher/class/attendance` → 403; admin GET overview → 200; adviser (teacher) GET `/schools/{id}/attendance` → 403; section of another school → 404; `/principal/attendance` redirects to `school_attendance`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run → PASS; `lint:twig`, `lint:container` clean.
- [ ] **Step 5:** Commit `feat: principal attendance overview, school calendar and SF2 reopen`.

### Task 10: Whole-suite check and browser smoke test

- [ ] **Step 1:** `php bin/phpunit tests` → all PASS (report the count).
- [ ] **Step 2:** `php bin/console doctrine:schema:validate`, `lint:container`, `lint:twig templates/` → clean.
- [ ] **Step 3:** In the dev app (cache:clear): as the HONEST adviser take today's attendance, open the month grid, download SF2 and render it to PNG; as the principal add a day off, open the overview and the section month. Fix anything broken (each fix with a test).
- [ ] **Step 4:** Commit any fixes (`fix: …`).
