# SF2 — Daily Attendance Report of Learners — Design

Date: 2026-10-29 · Branch: `feature/sf2` (off `feature/learner-id`, which needs `feature/sf1`'s remarks)

## Goal

The section adviser takes attendance every school day in the system; the system builds DepEd's
School Form 2 (DO 58 s. 2017) for any month as an Excel file in the layout of the user's template
(`C:\Users\ZiM\Downloads\School Form 2 (SF2) Daily Attendance Report of Learners.xlsx`), with every
total and summary figure computed. The adviser submits each month; the principal views every
section's SF2, sees attendance statistics, keeps the school calendar and can reopen a submitted month.

## Decisions (from the user)

- Only the **section adviser** records attendance. Principal and admin **view only** (plus statistics).
- The **principal keeps the school calendar** (days off for the whole school).
- **Adviser submits, principal locks**: a submitted month is read-only; the principal (or admin) may reopen it.
- **Four marks**: Present (no mark), Absent, Late, Cutting classes — as the template's legend:
  *"blank – Present; (x) – Absent; Tardy (half shaded = Upper for Late Comer, Lower for Cutting Classes)"*.
- Storage: **exceptions only**, plus a roll-call record per section per day (approach 1).
- Agreed rules: school days are Mon–Fri within the school year minus days off (no Saturdays);
  days before a learner's start / after a drop or transfer-out are blank and not counted;
  no future days; students see nothing; admin is view-only like the principal.

## Data (each through `make:migration` + `doctrine:migrations:migrate`)

- **`SchoolDayOff`** — school, schoolYear, date (`date_immutable`), reason (string 100, required),
  createdBy (User), createdAt. Unique (school, date).
- **`RollCall`** — section, date, takenBy (User), takenAt, updatedAt. Unique (section, date).
  `marks`: one-to-many `AttendanceMark`, cascade persist, orphan removal.
- **`AttendanceMark`** — rollCall, enrollment, mark (`LearnerMark` enum). Unique (rollCall, enrollment).
  Deleting an enrollment (unenroll) deletes its marks (`onDelete: CASCADE`).
- **`LearnerMark`** enum (string-backed): `Absent = 'absent'`, `Late = 'late'`, `Cutting = 'cutting'`;
  `label()` ("Absent", "Late", "Cutting classes"), `isTardy()` (Late, Cutting).
- **`Sf2Submission`** — section, month (`date_immutable`, always the 1st), submittedBy, submittedAt.
  Unique (section, month). Its existence locks that month. Reopening deletes it.

## Rules — `SchoolCalendar` (service) and `AttendanceRules`

- **School day** for a school: Mon–Fri, within the school year's `startDate`..`endDate` (if set),
  and not a `SchoolDayOff`. `schoolDays(School, SchoolYear, month): list<DateTimeImmutable>`.
- **Cut-off date**: the first Friday on or after the school year's start date (template: "every
  1st Friday of School Calendar Days"). Without a start date: first Friday of June of the start year.
- **Learner's span in a section** (`AttendanceSpan`: `from`, `until` nullable):
  - `from` = the approved transfer-in date into this section (`TransferRequestRepository::findApprovedInto`
    → `decidedAt` date) if any, else the later of the enrollment's `enrolledAt` date and the school
    year's start (a learner enrolled before classes start counts from the first day).
  - `until` = the day before a DRP remark's `remarkDate` (the drop date is the first day not counted).
    A DRP without a date keeps counting, and the month grid warns "Set the drop date in {name}'s
    enrollment record".
  - For a learner **transferred out** of this section (approved `TransferRequest` with `fromSection` =
    this section), `until` = the day before `decidedAt`.
  - A within-school **move** (no transfer request) is a correction: the learner counts in the new
    section for their whole span and does not appear in the old one.
- A learner is **counted** on a school day inside their span. Not-counted cells are blank, can't be
  marked, and enter no total.
- **Present** on a counted day = no Absent mark (Late and Cutting count as present, and as tardy).
  A passed school day with no roll call counts everyone present (the adviser confirms this at submit).
- **Taking attendance** (`AttendanceVoter::TAKE`): the user is the section's adviser, the section is in
  the current school year, the date is a school day, not after today (`SchoolClock`), and the month is
  not submitted.
- **Day off added after a roll call**: allowed; the roll call stays in the database but its date is no
  longer a school day, so it's ignored everywhere. The calendar form warns how many sections took
  attendance that day.

## SF2 learner list

The section's class list (as SF1, males first, `EnrollmentRepository::findClassList`) **plus** learners
transferred out of this section whose `until` falls in or after the month's first day (their remark is
"T/O {to school name}"). Ordered males then females, by name.

Remarks column: DRP → "DRP {remarkDetails}" (the reason, per legend 2); transferred in →
"T/I {from school}" (via `Sf1Remarks`); transferred out → "T/O {to school}"; otherwise the manual
SF1 remark text if any.

## Computed figures (per month; M, F, TOTAL)

- Per learner: absences, tardies (late + cutting) over counted school days.
- Per day: present count (male, female, combined) — the template's "TOTAL Per Day" rows.
- **Enrolment as of cut-off**: learners counted on the cut-off date (or, for months before it, blank).
- **Late enrollment during the month**: enrollments whose `from` is in the month, after the cut-off,
  and not a transfer in.
- **Registered learners as of end of the month**: learners counted on the month's last school day.
- **Percentage of enrolment** = registered ÷ enrolment as of cut-off × 100 (blank if divisor 0).
- **Average daily attendance** = total daily attendance (sum of per-day present) ÷ number of school days.
- **Percentage of attendance** = ADA ÷ registered × 100.
- **Learners with 5 consecutive days of absences**: a run of ≥ 5 consecutive counted school days
  absent (weekends and days off don't break or extend a run) that includes a day of the month;
  runs may start in the previous month.
- **Drop out / Transferred out / Transferred in**: DRP remark dates, approved transfers out of / into
  the section decided in the month.
- Percentages and ADA rounded to 2 decimals.

## Adviser screens (Teacher → My class → Attendance)

- `teacher_class_attendance` `GET|POST /teacher/class/attendance/{date?}` (date `Y-m-d`, default
  today): the roll call. Every counted learner listed with a mark select (Present / Absent / Late /
  Cutting classes) — a Symfony Form (`RollCallFormType`, one `EnumType` choice per learner, Present =
  empty). A Stimulus controller (`attendance-mark`) turns each select into a tap chip that cycles
  Present → Absent → Late → Cutting → Present and updates the counters ("38 present · 2 absent ·
  1 late · 0 cutting"). Not-counted learners shown greyed with why ("Enrolled Nov 4", "Dropped").
  Saving creates or updates the `RollCall` (under a per-section lock), replacing its marks; activity
  log "Took attendance of {section} for {date}" / "Updated attendance of …". Refusals with a clear
  message: weekend, day off ("No classes: {reason}"), outside the school year, future date, submitted
  month.
- `teacher_class_attendance_month` `GET /teacher/class/attendance/month/{month}` (`Y-m`, default
  current): the month grid — learners down, school days across (date + M/T/W/TH/F), cells `x` / `L` /
  `C`, `–` for a passed day not taken, blank for not counted; days off listed under the grid with
  their reasons. Each date heading links to that day's roll call. Under the grid the summary box
  (above figures). Banners: learners with 5 consecutive absences; DRPs without a date. Previous /
  next month links within the school year.
- `teacher_class_sf2` `GET /teacher/class/attendance/month/{month}/sf2`: the Excel download.
- `teacher_class_attendance_submit` `POST …/month/{month}/submit`: allowed once the month's last school
  day has passed (or the school year ended); if some passed school days have no roll call, the form
  needs a confirm checkbox ("{n} days have no attendance; everyone will count as present").
  Creates `Sf2Submission`; activity log. After it the month grid shows "Submitted {date} · locked".

## SF2 Excel — `Sf2ReportBuilder` (data) + `Sf2Writer` (layout)

- `Sf2Report` / `Sf2Row` readonly DTOs, like SF1. `Sf2Writer::write(Sf2Report): Spreadsheet`, saved as
  `.xls` (PhpSpreadsheet Xls writer, as SF1).
- Copies the template exactly: A4 landscape; A2 title, A3 subtitle; header labels and boxes (School ID,
  School Year, Report for the Month of, Name of School, Grade Level, Section); learner name A–C, 25 day
  columns D–AB (row 11 dates, row 12 day letters), AC ABSENT, AD TARDY, AE–AJ REMARK/S; male rows
  then "MALE | TOTAL Per Day", female rows then "FEMALE | TOTAL Per Day", "Combined TOTAL PER DAY";
  guidelines 1–6 with the three formulas; codes legend; reasons for drop-outs list (a–f); the summary
  box (Month, No. of Days of Classes, the nine M/F/TOTAL figures); certification with the adviser's
  name over "(Signature of Teacher over Printed Name)", "Attested by:" with the school head's name.
- The template has 21 male and 25 female rows; rows are added when a class has more (none are cut).
- Only school days get a column (at most 23 weekdays, so 25 columns always fit).
- Marks: Absent `x` centered. Late fills the **upper half** and Cutting the **lower half** of the
  cell, drawn as a two-stop gradient fill (colour/white at 50%). Before calling it done the output is
  rendered and checked against the template; if the half fill doesn't render in Excel, fall back to a
  light fill with `L` / `C` and show the user.
- File name `SF2_{startYear}_{grade} - {SECTION}_{Month}.xls` ("SF2_2025_Grade 8 - ARTISTIC_October.xls"),
  `/ \ %` replaced like SF1's `fileName()`.

## Principal and admin

- **Calendar** `school_calendar` `GET|POST /schools/{id}/calendar`: the school year's days off (date,
  weekday, reason, remove button with CSRF). Add form: from date, optional to date, reason — adds one
  `SchoolDayOff` per weekday in the range, skipping weekends and dates already off; dates outside the
  school year refused. Warns when roll calls exist on the added dates. Remove: `school_calendar_remove`
  POST. Activity logged. `SchoolVoter::MANAGE` (principal of the school, admin).
- **Attendance overview** `school_attendance` `GET /schools/{id}/attendance?month=Y-m`:
  - *Today*: per section — taken / not taken yet, present, absent, late+cutting, adviser; school total.
    On a non-school day: "No classes today ({reason} / weekend)".
  - *Month*: per section — days of classes, ADA, % attendance, absences, tardies, SF2 status (Not
    submitted / Submitted {date} / **Late** — submitted after, or still missing after, the 5th of the
    next month); grade-level subtotals and school total; a CSS bar per section for % attendance.
  - *Needs follow-up*: learners with 5 consecutive absences in the month (name, section, adviser, the
    dates), linking to the learner page.
- **Section month** `school_attendance_section` `GET /schools/{id}/sections/{section}/attendance/{month}`:
  the same month grid, read-only, with Download SF2 (`school_attendance_sf2`) and, when submitted,
  **Reopen** (`school_attendance_reopen` POST, CSRF) — deletes the `Sf2Submission`, activity logged.
- Links: "Attendance" in the principal menu and dashboard, an "Attendance" link on the class list.

## Access — `AttendanceVoter`

- `ATTENDANCE_TAKE` (subject Section): the section's adviser, current school year.
- `ATTENDANCE_VIEW` (subject Section): its adviser, the school's principal, admin.
- `ATTENDANCE_REOPEN` (subject Section): the school's principal, admin.
- Principal and admin never pass `TAKE`. Students pass nothing. All POSTs carry the stateless CSRF
  token; writes go through an `AttendanceRecorder` service under a `LockFactory` lock per section and
  log to `ActivityLogger`.

## Testing

- `SchoolCalendar`: weekends, days off, school-year bounds, cut-off Friday.
- Span/counting: from enrollment date, from transfer-in date, cut-off rule, until the drop date,
  transferred out, DRP without a date.
- `Sf2ReportBuilder`: per-learner absences/tardies; per-day M/F/combined; the three formulas with
  known numbers; 5 consecutive absences across a weekend, a day off and a month boundary; late
  enrollment, drop out, transfers in the summary; days without a roll call count present.
- `Sf2Writer`: write, read back, check header cells, a mark, totals, summary values, extra rows.
- Controllers: adviser saves and edits a roll call; refusals (future, weekend, day off, locked); submit
  (incl. the confirm for missing days) and reopen; principal views (200) but `TAKE` is 403; another
  teacher 403; a student 403; calendar add range / remove; overview numbers.
- Visual check: render the SF2 to an image and compare with the template.

## Out of scope

SF4, students viewing attendance, per-subject attendance, SMS to parents, biometric import.
