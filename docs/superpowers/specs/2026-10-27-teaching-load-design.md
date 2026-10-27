# Teaching Load and Class Timetables — Design

Date: 2026-10-27
Status: design approved in chat; awaiting written-spec review

## Goal

The principal builds each section's weekly timetable (Monday to Friday, by class period) by
plotting subjects and their teachers onto a grid, and the system makes sure no teacher is in two
places at once. The pairing of a teacher with a subject in a section is the **teaching load**.
Teachers see their own load, and both dashboards show who is teaching right now.

Builds on subject offerings (`2026-10-22-subjects-design.md`): a subject **with** components (MAPEH)
is not loaded; its components (Music and Arts, PE and Health) are.

## Who can do what

| Who | Can |
|---|---|
| Principal of the school, admin (`SchoolVoter::MANAGE`, same as sections and subjects) | Set class periods, plot timetables, view any teacher's load in that school |
| Teacher | View their own teaching load (read only) and the "Your class now" dashboard card |
| Individual-plan schools | No timetable for now (out of scope) |

## Data

### `ClassPeriod` — the school day

One ordered list per school per school year, shared by every section. Each row is either a class
period or a break.

| Field | Type / rule |
|---|---|
| `school` | ManyToOne School, required |
| `schoolYear` | ManyToOne SchoolYear, required |
| `position` | int, order in the day |
| `startsAt` | time_immutable, required |
| `endsAt` | time_immutable, required, after `startsAt` |
| `breakLabel` | ?string(40); null = class period, set = break (e.g. "Recess", "Lunch") |

- Rows are kept in `startsAt` order and must not overlap (touching is fine: 08:30 end, 08:30 start).
- A class period's number ("Period 3") is not stored; it is its rank among the class periods.
- A class period with schedule entries can't be deleted or turned into a break
  ("Period 3 has classes in 4 sections. Remove them first."). Its times can be changed.

### `Subject.meetingsPerWeek` — new field

- ?int, 1–10 (a subject may meet twice in a day, e.g. a double TLE period). Required on subjects that are loaded (no components, or a
  component). Must be null on a subject that has components.
- Existing subjects get null from the migration. Their timetable card says "Set meetings per week
  on the Subjects page" and can't be dragged until it is set.
- The DepEd preset fills it from the time allotment in DepEd Order No. 10, s. 2024 (MATATAG), rounded
  to meetings per week. The exact numbers are checked against that order while planning. The
  principal can edit them.

### `TeachingLoad` — a teacher for a subject in a section

| Field | Type / rule |
|---|---|
| `section` | ManyToOne Section, required |
| `subject` | ManyToOne Subject, required; must be one of `SubjectRepository::findForSection($section)` and have no components |
| `teacher` | ManyToOne User, required; must have a `SchoolAssignment` to the section's school and school year as a teacher |
| `createdAt` | datetime_immutable |

- Unique (`section`, `subject`): one teacher per subject per section.
- Created when the principal picks a teacher on a card. Deleted when the teacher is cleared, which
  is only allowed while the load has no schedule entries.

### `ScheduleEntry` — one plotted cell

| Field | Type / rule |
|---|---|
| `load` | ManyToOne TeachingLoad, required, `onDelete: RESTRICT` |
| `day` | enum `Weekday` (1 Mon … 5 Fri) |
| `period` | ManyToOne ClassPeriod, required, `onDelete: RESTRICT`; must be a class period, not a break, of the same school and school year |
| `term` | int: 0 = full year (K–10), 1 or 2 = semester (copied from the subject) |
| `section`, `teacher`, `schoolYear` | copies of the load's values, kept in sync by the service, so the database can enforce the rules below |

- Unique (`section`, `day`, `period`, `term`): a section has one class per slot.
- Unique (`teacher`, `schoolYear`, `day`, `period`, `term`): a teacher has one class per slot in
  the same term.
- A full-year class (term 0) clashes with semester classes (term 1 or 2) in the same slot. The
  database can't express that, so the service checks it while holding the school's lock.

### Deleting things that are in use

- A subject with a teaching load can't be deleted ("Mathematics is loaded in 3 sections.").
- A section with teaching loads can't be deleted until its timetable is cleared.
- A teacher with teaching loads can't be removed from the school ("Ms. Santos still has 6 loads
  this school year.").

## Service — `TimetableManager`

All changes go through one service. Each call takes the school's lock
(`symfony/lock`, key `school-timetable-{id}`, same pattern as `SchoolTeacherLock`), checks, saves and
writes the activity log. Refusals throw `TimetableException` with a message for the principal.

- `setTeacher(Section, Subject, ?User)` — creates, changes or deletes the load.
  - Changing the teacher checks every plotted cell of the load against the new teacher's
    schedule. If any clash, nothing changes and the message lists every clashing slot.
  - Clearing the teacher is refused while the load has cells.
- `plot(Section, Subject, Weekday, ClassPeriod): ScheduleEntry` — refused when:
  1. the subject has no teacher yet;
  2. the subject has no `meetingsPerWeek`, or already has that many cells ("Mathematics already has
     all 5 meetings this week.");
  3. the section already has a class in that slot and term ("Grade 7-Rizal already has Science on
     Mon, Period 3.");
  4. the teacher already has a class in that slot, in the same term or across full year and
     semester ("Ms. Santos already teaches Mathematics in Grade 8-Mabini on Mon, Period 3.");
  5. the slot is a break, or the subject or period is from another school, school year, grade
     level or semester.
- `remove(ScheduleEntry)`.
- If the lock is held, or a unique constraint fails because of a concurrent save: "Another change
  is being saved. Please try again."

Read side (`TimetableQuery` or repository methods):
- `sectionGrid(Section, term)` — cells for the grid, plus cards (subject, teacher, used / per week).
- `busyMap(Section, term)` — for every teacher of the school: the slots where they teach in
  another section, with "Subject, Section" text. Drives the green and red highlighting.
- `teacherGrid(User, SchoolYear, term)` — a teacher's week, their loads and total periods per week.
- `now(School, \DateTimeImmutable)` — the current slot (period, break, before first, after last, or
  no classes on weekends), classes running now, teachers who are free this period, and the next period.
- `nowForTeacher(User, \DateTimeImmutable)` — the teacher's current class or break, and their next
  class today.

### Time

"Now" is Philippine time. The app sets `app.timezone: Asia/Manila` and reads the time through Symfony
Clock (`ClockInterface`), converted to that zone, so it doesn't depend on the laptop's `php.ini`, and
tests can pin the time with `MockClock`.

## Screens

### Class periods (principal)

`/schools/{id}/class-periods`. A list of periods and breaks with add, edit and delete. The form
fields: start time, end time, and "This is a break" with a label. Linked from the school's menu next
to Sections and Subjects.

### Section timetable (principal)

`/schools/{id}/sections/{section}/timetable`, linked from each section's row.

- Grid: Monday to Friday across, class periods and breaks down. Breaks are a full-width grey row
  with their label.
- Side list: the section's loadable subjects for the grade (and semester), each a card showing a
  teacher dropdown (teachers assigned to the school this school year) and "used/per week".
  - A card with no teacher, or no meetings per week, can't be dragged.
  - A full card fades and can't be dragged.
- **Drag** (main way): while a card is dragged, free cells for its teacher turn green, and busy
  cells turn red and show where the teacher is. Dropping on a red or filled cell is refused.
- **Click** (alternative): clicking an empty cell opens a dialog to pick the subject. Its teacher
  comes from the card, and subjects whose teacher is busy at that slot are listed but disabled, with
  the reason.
- ✕ on a cell removes it.
- SHS sections get a "1st sem / 2nd sem" switch that changes the grid and the cards.
- Until class periods exist, the page shows "Set up your class periods first" with a link.

Built as a Stimulus controller (`timetable_controller.js`). Every action saves at once through
small JSON endpoints under the timetable URL:

| Method and path | Body | Does |
|---|---|---|
| `GET …/timetable/state?term=` | — | Grid, cards and busy map |
| `PUT …/timetable/loads/{subject}` | `{teacherId: int\|null}` | `setTeacher` |
| `POST …/timetable/entries` | `{subjectId, day, periodId}` | `plot` |
| `DELETE …/timetable/entries/{entry}` | — | `remove` |

Request bodies are bound with `#[MapRequestPayload]` DTOs and validated. Writes need the CSRF token
the page already uses. Each write response carries the new state, so the page redraws from what
the server saved. A refusal is HTTP 422 with `{message}`: the cell goes back, a red message appears
under the grid, and the state reloads.

### Teacher's load

- Principal: `/schools/{id}/teachers/{teacher}/load`, linked from the teacher's row.
- Teacher: `/teacher/load`, "My teaching load" in the teacher menu.

Both pages: a read-only weekly grid (cells show subject and section), a list of loads (subject,
section, meetings per week), and **total periods per week**. SHS loads show the semester switch.

### Dashboard cards

Each card is a Turbo frame that reloads itself every minute.

- **Principal, "Classes right now":** a table of classes running now (teacher, subject, section,
  period and times), then **"Free this period"**: teachers of the school with no class in the
  current period. Outside class periods it says the next period ("Next: Period 1 at 07:30"), during a
  break it names the break, and on weekends it says "No classes today."
- **Teacher, "Your class now":** "Now: Mathematics · Grade 7-Rizal · until 10:45", then "Next:
  Science · Grade 8-Mabini · 10:45". On a break or free period: "Free period. Next class: …". At the
  end of the day: "No more classes today."

## Activity log

`ActivityLogger` entries for: class period added, changed, deleted; teacher set, changed, cleared on
a load; class plotted; class removed. Example: "Plotted Mathematics (Ms. Santos) in Grade 7-Rizal,
Mon Period 3."

## Testing

- `TimetableManager` (KernelTestCase): every refusal rule; back-to-back periods are fine; full year
  vs semester clash; the meetings-per-week limit; changing a teacher with and without clashes;
  clearing a teacher with cells; the lock-held message.
- `ClassPeriod` rules: overlap refused, delete and turn-into-break refused while in use.
- Delete protection for subjects, sections and teacher removal.
- "Now" with `MockClock`: during a period, during a break, before the first and after the last
  period, weekends, free teachers, the teacher's next class.
- HTTP (WebTestCase): every page and JSON endpoint; principal of the school and admin allowed;
  another school's principal and teachers denied on edit routes; a teacher sees only their own load;
  422 messages; CSRF required on writes.
- One check in a real browser of drag, drop, the green and red highlighting, and click to plot
  before calling the feature done.

## Out of scope

Rooms; printing the teacher's load or DepEd forms; copying a timetable from another school year;
timetables on the Individual plan; suggesting free slots automatically; classes on Saturday.
