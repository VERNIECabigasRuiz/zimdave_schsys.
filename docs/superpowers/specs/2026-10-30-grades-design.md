# Grades — Teacher Grade Entry and Principal Lock — Design

Date: 2026-10-30 · Branch: `feature/grades` (off `feature/sf8`; none of the SF branches is merged yet)

## Goal

Each subject teacher enters one period grade per learner for every subject-section they are loaded
with. The principal locks a whole grading period for the school when grades are final, and can unlock
one class (subject × section) for a correction or the whole period. Advisers, the principal and the
admin see the grades read-only. This is the base for report cards (SF9) and the permanent record
(SF10) later.

## Decisions (from the user)

- The teacher enters **only the period grade** (a whole number), not component scores.
- The principal locks **the whole school per grading period**; unlocks **per class** or the whole period.
- **No teacher submit step.** Teachers enter and save; the principal watches progress and locks.
- **Who sees grades:** the subject teacher (own loads), the section's adviser (all subjects of the
  section), the principal and the admin. Students see nothing yet.
- **SHS (Grades 11–12) is left out** until the user confirms how semesters map to terms.
- Agreed defaults: **Grades 1–10 only** (Kinder uses DepEd's descriptive checklist); grades are
  **60–100**; components (Music, Arts, PE, Health) are graded, a parent subject (MAPEH) shows the
  rounded average of its components and is never stored; lock storage is approach A (one lock per
  school-period plus per-class unlock exceptions).

## Existing pieces this builds on

- `TeachingLoad` (section, subject, teacher) — `TeachingLoadRepository::findForTeacher(User, SchoolYear)`,
  `findForSection(Section)`, `findOne(Section, Subject)`.
- `GradingPeriod` (schoolYear, number, `open`, dates) — the admin's existing open/close switch
  (`admin_grading_period_toggle`); `SchoolYear::getGradingPeriods()`, `GradingSystem` (Terms = 3,
  Quarters = 4; `periodName()`), `GradingPeriod::getLabel()` ("Term 1").
- `Subject` — `getParent()`, `getComponents()`; only subjects without components are loaded and graded.
- `EnrollmentRepository::findClassList(Section)` (males first, by name), SF1 remarks (DRP), approved
  transfers (as `Sf2Roster` reads them), `SchoolClock`, `LockFactory`, `ActivityLogger`.

## Data (each through `make:migration` + `doctrine:migrations:migrate`)

- **`Grade`** — enrollment (`onDelete: CASCADE`), subject (`onDelete: RESTRICT`), gradingPeriod
  (`onDelete: CASCADE`), value (smallint, 60–100), updatedBy (User), updatedAt. Unique
  (enrollment, subject, gradingPeriod). No row = not graded yet.
- **`GradeLock`** — school, gradingPeriod, lockedBy (User), lockedAt. Unique (school, gradingPeriod).
- **`GradeUnlock`** — section, subject, gradingPeriod, unlockedBy (User), unlockedAt. Unique
  (section, subject, gradingPeriod). Only meaningful while the school's `GradeLock` for that period exists.

## Rules — `GradeRules` (service)

- **Gradable load:** a `TeachingLoad` whose section is in the current school year and whose grade
  level is Grade 1–10 (not Kinder, not 11–12), on a subject without components.
- **Class status for (section, subject, period)**, one of:
  - `NotOpen` — the admin has not opened the period (`GradingPeriod::isOpen()` false) and the school
    has no lock for it;
  - `Open` — the period is open and the school has no lock;
  - `Locked` — the school has a lock and the class has no unlock;
  - `Unlocked` — the school has a lock and the class has an unlock (editable even if the admin has
    since closed the period — the principal reopened it on purpose).
- **Teacher may edit** when they hold the load, the load is gradable, and the status is `Open` or `Unlocked`.
- **Principal actions** (principal of the school only; admin cannot):
  - *Lock period* — allowed when the period has been opened by the admin at least once or is open now
    (refused for `NotOpen`: "Term 1 isn't open for grading yet."); creates the `GradeLock` and deletes
    all of that period's `GradeUnlock`s for the school.
  - *Unlock period* — deletes the `GradeLock` and its `GradeUnlock`s.
  - *Unlock class* — only while locked; creates a `GradeUnlock`.
  - *Lock class again* — deletes that class's `GradeUnlock`.
  - Refusals: "Term 1 is already locked.", "Term 1 is not locked.", "This class is already unlocked.",
    "This class is not unlocked."
  - "Opened at least once": the period is open now or has any `Grade` rows or an earlier lock; kept
    simple as `open || has grades in this school`.
- **Learners on a grade sheet:** the section's class list (`findClassList`) plus learners transferred
  out of the section (as SF2 reads them). Learners who are dropped (SF1 DRP) or transferred out are
  listed greyed with their status ("Dropped", "Transferred out"); their existing grades show
  read-only and new grades can't be entered for them.
- **Values:** whole numbers 60–100. Blank = not graded (an existing grade cleared to blank is deleted).
- **Parent subject (e.g. MAPEH):** grade = round(average of its components' grades for that period),
  shown only when every component has a grade; otherwise blank. Never stored.

## Writes — `GradeRecorder`

- `saveSheet(TeachingLoad, GradingPeriod, array<int enrollmentId, ?int> $values, User $by)` — under
  a Symfony lock `grades-{sectionId}-{subjectId}`; refuses with the status message when not editable
  ("Term 1 is locked by the principal." / "Term 1 isn't open for grading yet."); ignores enrollment
  ids not on the sheet or not editable; upserts or deletes rows; logs "Saved Term 1 grades of {Math 8 –
  HONEST}" via `ActivityLogger`.
- `lockPeriod(School, GradingPeriod, User)`, `unlockPeriod(School, GradingPeriod)`,
  `unlockClass(Section, Subject, GradingPeriod, User)`, `lockClass(Section, Subject, GradingPeriod)` —
  under a per-school lock `grade-lock-{schoolId}`; each logged.

## Access — `GradeVoter`

Attributes on subject `TeachingLoad`: `GRADE_EDIT` (the load's teacher, load gradable — the
lock/open status is checked by `GradeRules`/`GradeRecorder`, as SF2/SF8 do), `GRADE_VIEW` (the
load's teacher, the section's adviser, the school's principal, an admin). On subject `Section`:
`GRADE_VIEW_SECTION` (adviser, principal, admin). On subject `School`: `GRADE_LOCK` (the school's
principal only).

## Screens

**Teacher — "My Grades"** (nav item after "My Teaching Load"; `/teacher/grades`, `TeacherGradeController`)
- `teacher_grades` (GET): one row per gradable load (subject – section), per period a progress
  "graded / listed" and a status chip (Open, Locked, Unlocked for you, Not open yet). SHS and Kinder
  loads are listed under "Not graded here yet" without links.
- `teacher_grades_sheet` (GET|POST `/{load}/{period}`): no., LRN, name, grade box (60–100) per
  learner, males then females; greyed rows for dropped / transferred out; period tabs. Symfony Form
  **keyed by enrollment id** with a class-list-changed check (as SF8:
  "The class list changed while you were editing. Check the rows and save again."). Validation:
  "Enter a grade from 60 to 100." Read-only with a status line when not editable. Flash
  "Saved Term 1 grades for {n} learners."

**Adviser — "Class grades"** (button on My Class beside SF1 / Attendance / Nutrition;
`teacher_class_grades` GET `/teacher/class/grades?period=`)
- Read-only grid: learners down, subjects across in report-card order (components under their parent,
  then the parent's average), for the chosen period. Blank cells highlighted; a footer per subject
  "graded / listed" and its teacher.

**Principal and admin — "Grades"** (`/schools/{id}/grades`, `SchoolGradeController`, `SchoolVoter::MANAGE`;
principal nav item "Grades"; admin school list button "Grades"; `principal_grades` redirect)
- `school_grades` (GET `?period=`): period switch; status line ("Open · not locked", "Locked on Oct 30,
  2026 by …", "Not opened for grading yet"); **Lock Term 1 / Unlock Term 1** (principal only, confirm);
  table grade → section → subject: teacher, graded / listed, class status; per class **Unlock** or
  **Lock again** while the period is locked (principal only); summary "182 of 200 classes complete".
- `school_grades_section` (GET `/sections/{section}/grades?period=`): the adviser's grid, read-only.
- POST actions, CSRF-protected: `school_grades_lock`, `school_grades_unlock`,
  `school_grades_unlock_class`, `school_grades_lock_class`.
- A section of another school → 404.

## Tests

- **`GradeRulesTest`**: every status combination (admin open/closed × lock × unlock); gradable-load
  rules (Kinder, Grade 11, parent subject, last year's section); parent average (all components /
  one missing).
- **`GradeRecorderTest`**: save upserts and deletes; refused when locked / not open; editable when
  unlocked even after the admin closes the period; ignores off-sheet and dropped learners; lock clears
  unlocks; unlock/lock class refusals with exact messages.
- **`GradeVoterTest`**: teacher (own vs other load), adviser, principal, admin, student.
- **`TeacherGradeControllerTest`**: overview statuses; save a sheet; out-of-range; locked read-only and
  POST refused; class-list change refused; other teacher 403; student 403; adviser grid read-only with
  MAPEH average.
- **`SchoolGradeControllerTest`**: overview; lock with confirm; unlock period; unlock and relock one
  class; lock refused when not open; admin views but can't lock (403); teacher 403; other school's
  section 404; `/principal/grades` redirect.

## Out of scope

SHS grading, Kinder checklist, component scores (WW/PT/exam) and transmutation, final/general average,
report cards (SF9), SF10, student view, Excel export, grade change history beyond `updatedBy/At` and the
activity log.
