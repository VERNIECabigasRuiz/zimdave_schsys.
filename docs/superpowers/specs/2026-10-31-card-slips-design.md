# Card Slips — Per-Term Progress Slip with Suggested Comments — Design

Date: 2026-10-31 · Branch: `feature/card-slips` (off `feature/grade-changes`)

## Goal

After each term the adviser prints a **card slip** for every learner in the advisory class: ZIM School's
own half-page progress slip (not a DepEd form) with the term's grades, the term's attendance, the
adviser's comments, and lines for the adviser's and the parent's signatures. The parent signs to show
the slip was read. Comments are semi-automatic: the app pre-ticks fitting comments from the learner's
term, the adviser accepts, changes or adds a typed one.

## Decisions (from the user)

- Size: **half of a short bond paper** (8.5 × 5.5 in, landscape); two slips per short bond sheet with a
  dashed cut line.
- **Per term**: the adviser prints the slips of a chosen term.
- Content: grades, attendance, adviser's comments and remarks, parent signature.
- Comments: **smart suggestions** (option A) — rules pre-tick comments; the adviser can untick, tick
  others from the list, and type one.
- **Final grades only** (option A): before the principal locks the term, slips print with a
  "DRAFT – grades not final" watermark; after locking they print clean. A grade corrected through an
  approved change request shows on a reprint.
- Comment language: **English only**; a fixed built-in list (the typed comment can be any language).
- Approved slip layout (part 1): see Layout.

## Existing pieces this builds on

- Grades: `SectionGradeGrid::build(Section, GradingPeriod)` (report-card order, components under their
  parent, MAPEH = `GradeRules::parentGrade()` rounded average), `GradeRules::sheet(Section)`
  (`SheetLearner` with `editable` — dropped / transferred out are not editable),
  `GradeRepository::findForEnrollmentsByPeriod()`.
- Lock: `GradeLockRepository::findFor(School, GradingPeriod)`.
- Attendance: `SchoolCalendar::schoolDays(School, SchoolYear, from, to)`, `RollCallRepository`
  (roll calls of a section between dates, with `AttendanceMark`s: `LearnerMark::Absent|Late|Cutting`),
  `Sf2Roster` / `RosterEntry::counts(day)` (whether a learner counts on a day). SF2 counts Late and
  Cutting together as "tardy".
- `GradingPeriod` start/end dates and `getLabel()` ("Term 1"); `School::getLogoPath()`, school name and
  address; `Section::getAdviser()`, label, grade level.
- PDF: dompdf through a Twig template, as `LearnerId\IdCardPdf` (`render()` → bytes, `html()` for tests).
- Adviser area: `TeacherClassController` (`/teacher/class`, the adviser's current section), its ID-card
  pages (`teacher_class_ids`, `teacher_class_id`) are the pattern for "whole class" and "one learner"
  PDFs. Principal/admin section pages under `/schools/{id}/sections/{section}`.

## Data (through `make:migration` + `doctrine:migrations:migrate`)

- **`CardSlipComment`** — enrollment (`CASCADE`), gradingPeriod (`CASCADE`), codes (JSON list of
  built-in comment codes the adviser ticked, may be empty), customText (string ≤ 300, nullable),
  updatedBy (User, `SET NULL`, nullable), updatedAt. Unique (enrollment, gradingPeriod).
  **No row = the adviser hasn't saved yet → the slip uses the suggestions.** A saved row (even with no
  codes) is the adviser's choice and is used as is.

## Built-in comments — `CardSlipComments` (PHP class, `App\CardSlip`)

A fixed list of `code => text` in four groups; `{subject}` / `{subjects}` are filled from the learner's
data. Exact texts:

- **Academic**
  - `excellent` — "Excellent performance this term. Keep up the good work!"
  - `very_good` — "Very good performance this term."
  - `good` — "Good performance this term. Keep it up."
  - `improved` — "Showed clear improvement from last term."
  - `needs_improvement` — "Needs to improve in {subjects}. Please attend remedial sessions."
  - `incomplete` — "Some grades are not yet complete. Please see the subject teachers."
- **Attendance**
  - `perfect_attendance` — "Perfect attendance this term!"
  - `absences` — "Frequent absences affected learning. Please make sure your child attends regularly."
  - `tardiness` — "Often late to class. Please help your child come to school on time."
- **Behaviour**
  - `participates` — "Participates actively in class activities."
  - `responsible` — "Responsible and completes tasks on time."
  - `respectful` — "Respectful and kind to classmates and teachers."
  - `needs_focus` — "Needs to focus more during class discussions."
- **Suggestion for parents**
  - `study_home` — "Please guide your child in reviewing lessons at home."
  - `read_daily` — "Encourage your child to read every day."
  - `see_adviser` — "Please see the adviser for a short conference."

## Suggestion rules — `CardSlipSuggester` (service)

Input: the learner's term row (grades per column, term average, attendance) and the previous term's
average (if any). Output: ordered list of codes, **at most 3**, in this priority:

1. `incomplete` if any graded column has no grade; else
   - `needs_improvement` if any grade < 75 (`{subjects}` = those subjects' labels, joined with ", " and
     " and ");
   - else `excellent` if average ≥ 90, `very_good` if 85–89.99, `good` if 80–84.99.
2. `improved` if a previous term average exists, this term has an average, and it rose by ≥ 3 points.
3. `absences` if absences ≥ 5; else `tardiness` if tardies ≥ 5; else `perfect_attendance` if absences
   = 0 and tardies = 0 and the term had at least one school day.
4. If fewer than 3 codes so far and any grade < 75 or absences ≥ 5: `see_adviser`; otherwise if no
   academic code was added (average < 80 without a failing grade): `study_home`.

Truncate to 3. Behaviour comments are never suggested (the adviser knows the learner; the data doesn't).

## The slip — `CardSlipBuilder` (service) → `CardSlip` (readonly DTO)

For (Section, GradingPeriod) builds one `CardSlip` per **editable** learner on `GradeRules::sheet()`
(dropped / transferred-out learners get no slip), males first then females by name:

- Header: school name, address, logo path, "CARD SLIP · {Term 1}", "S.Y. {2026–2027}", learner SF1
  name, LRN, "Grade {7} – {SECTION}", adviser full name.
- **Grades**: the rows of `SectionGradeGrid::build()` for that learner, in its column order — each
  top-level subject with its grade (or null) and remarks "Passed" (≥ 75) / "Failed" (< 75) / "" (null);
  a parent subject (MAPEH) shows its computed grade and a small line with its components
  ("Music 85 · Arts 88 · PE 90 · Health 86").
- **Term average**: average of the top-level subjects' grades (parent subjects count once, components
  don't count separately), shown with **two decimals** and its DO 15 descriptor — Advancing 90–100,
  Benchmarking 80–89, Connecting 75–79, Developing 65–74, Emerging below 65 (descriptor from the
  average rounded to a whole number). If any top-level grade is missing: "Incomplete", no descriptor.
- **Attendance** for the term: school days = `SchoolCalendar::schoolDays()` from the term start to the
  earlier of the term end and today, counting only days the learner `counts` on the roster; absent =
  Absent marks on those days; late = Late + Cutting marks (SF2's tardy). Present = school days − absent.
  A school day without a roll call counts as present (same as SF2). Term without dates → attendance
  shown as "—".
- **Comments**: the saved `CardSlipComment` codes (or the suggestions when none is saved), rendered
  with placeholders filled, then the typed comment. Printed as bullet lines.
- **Draft**: true when the school has no `GradeLock` for the period.

## Screens

**Adviser — Card slips** (`/teacher/class/card-slips`, `TeacherCardSlipController`; button
"Card slips" on My Class beside Grades / SF1 / IDs)
- `teacher_class_card_slips` (GET `?period=`, POST): term tabs (default: the latest locked term, else
  the open one, else the last). A notice when the term isn't locked: "Term 1 isn't locked yet. Slips
  will print as DRAFT until the principal locks the grades."
- Table: no., learner, term average, absences, then for each learner the **comment checkboxes**
  (grouped; the saved or suggested ones ticked, suggested ones marked "suggested") and a **typed
  comment** box (≤ 300). One Symfony Form for the whole class, keyed by enrollment id, with the
  class-list-changed check (`GradeSheetFormType::matchRoster`). **Save comments** → flash
  "Comments saved for {n} learners." Validation: more than 3 ticked → "Choose at most 3 comments for
  {name}."; typed > 300 → "Keep the comment under 300 characters."
- **Reset to suggestions** per learner (POST, CSRF) deletes that learner's saved row.
- **Print all slips** → `teacher_class_card_slips_pdf` (GET `/card-slips/{period}.pdf`): one PDF, two
  slips per short bond page. **Print** per learner → `teacher_class_card_slip_pdf`
  (GET `/card-slips/{period}/{enrollment}.pdf`).
- Only the section's adviser. A section with no editable learners: "No learners to print slips for."

**Principal and admin** — on the school section grades page (`school_grades_section`), a **Card slips
(PDF)** button for the chosen term → `school_section_card_slips_pdf`
(GET `/schools/{id}/sections/{section}/card-slips/{period}.pdf`, `SchoolVoter::MANAGE`). Read-only:
they print with whatever the adviser saved (or suggestions).

## Layout (PDF, dompdf)

Page: US Letter portrait (8.5 × 11 in), two slips per page (each 8.5 × 5.5 in, ~0.3 in margins), a
dashed cut line between them; an odd last slip leaves the lower half blank. **Print-ready**: the PDF is
sized exactly to short bond so it prints at 100% with no scaling; the adviser prints the class on
⌈learners ÷ 2⌉ sheets and cuts along the line. A **single learner's** PDF is one short bond page with
that slip in the top half (cut line under it), so one slip can be printed when needed. Slip, as approved:

```
[logo] SCHOOL NAME                                    CARD SLIP · TERM 1
       Address                                        S.Y. 2026–2027
Name: …     LRN: …     Grade 7 – ACTIVE     Adviser: …
LEARNING AREA   GRADE  REMARKS   |  ATTENDANCE (Term 1): School days · Present · Absent · Late
…                                |  ADVISER'S COMMENTS: • … • … • …
TERM AVERAGE    86.00 Benchmarking|
______ Adviser        ______ Parent/Guardian (signature over printed name)    Date: ______
```

Draft slips carry a large diagonal light-grey "DRAFT – grades not final". Filename
`Card slips - Grade 7 ACTIVE - Term 1.pdf` / `Card slip - DELA CRUZ, JUAN - Term 1.pdf` (slashes
replaced, as the SF1 filename fix does).

## Access

- New `CardSlipVoter` (or attributes on an existing section voter): `CARD_SLIP_EDIT` on `Section` —
  the section's adviser, current school year; `CARD_SLIP_PRINT` on `Section` — the adviser, the
  school's principal, an admin.

## Tests

- **`CardSlipSuggesterTest`** (unit): each rule and the priority/truncation; `{subjects}` joining
  ("Math", "Math and Science", "Math, Science and English"); no `improved` without a previous term.
- **`CardSlipBuilderTest`** (kernel): grades in report-card order with MAPEH and components; term
  average and descriptor at the boundaries (the descriptor uses the average rounded half-up to a whole
  number: 89.50 → Advancing, 89.49 → Benchmarking, 74.50 → Connecting, 74.49 → Developing); missing grade → Incomplete; attendance counts only term dates and
  enrolled days, Late + Cutting = late; dropped/transferred-out excluded; draft flag by lock; saved
  comments override suggestions; empty saved list stays empty.
- **`TeacherCardSlipControllerTest`**: page with suggestions ticked; save; > 3 refused; reset; draft
  notice; PDF responses (content type `application/pdf`, filename) and the HTML contains learner names,
  grades and "DRAFT" only when unlocked; non-adviser 403.
- **`SchoolCardSlipTest`**: principal and admin get the section PDF; other school 404; teacher 403.

## Out of scope

SF9, Kinder / Grade 1 descriptive grading, comments in other languages or editable comment lists,
email/SMS to parents, student/parent online view, tracking whether slips were returned signed, honors.
