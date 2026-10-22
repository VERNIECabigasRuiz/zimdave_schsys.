# Subject Offerings — Design

Date: 2026-10-22
Status: design approved in chat; awaiting written-spec review

## Goal

Each school sets the subjects it offers per school year, because schools differ (special
programs, private schools, SHS strands). Principals manage their own school's subjects, admins
any school's, and a teacher on a plan without a principal (Individual) manages the subjects of
their own section's grade. This is the base for teacher loading and subject grades (next pieces).

## Data — `Subject` entity

One row per subject a school offers in a school year.

| Field | Type / rule |
|---|---|
| `school` | ManyToOne School, required |
| `schoolYear` | ManyToOne SchoolYear, required |
| `gradeLevel` | enum `GradeLevel`, required; must be offered by the school type (`SchoolType::gradeLevels()`) |
| `code` | string(20), required, trimmed and stored uppercase, `^[A-Z0-9-]+$`, e.g. `MATH7`, `MA7` |
| `name` | string(100), required, trimmed, e.g. "Mathematics" |
| `parent` | ManyToOne Subject, nullable, `onDelete: CASCADE`; makes this subject a **component** (e.g. Music and Arts of MAPEH) |
| `semester` | ?int (1 or 2); required for Grades 11–12, must be null for K–10 |
| `track` | ?enum `ShsTrack`; Grades 11–12 only; null = every SHS section of the grade |
| `strand` | ?string(60); Grades 11–12 only; null = every strand (matched case-insensitively) |
| `position` | int, report-card order within the grade (and semester); new subjects go last |
| `createdAt` | datetime_immutable |

- Unique: (`school`, `schoolYear`, `code`) — "The code MATH7 is already used this school year."
  ; (`school`, `schoolYear`, `gradeLevel`, `semester`, `name`) — "Grade 7 already has a subject named Mathematics." (semester is null for K–10, so the name check is done in the service, not by a DB index, since MySQL treats NULLs as distinct).
- Components:
  - the parent must be in the same school, school year and grade level, and must not itself be a component (one level deep);
  - a subject that has components cannot be made a component;
  - a component takes its parent's `semester`, `track` and `strand` (the form hides these fields for components; the service copies them on save, and editing a parent's values updates its components).
- Label: `MATH7 – Mathematics`; component: `MA7 – Music and Arts (MAPEH)`.

### Subjects a section takes

`SubjectRepository::findForSection(Section): list<Subject>` — subjects of the section's school, school
year and grade level whose `track` is null or equals the section's track and whose `strand` is null
or equals the section's strand (case-insensitive), ordered by semester, position, name. Used later by
teacher loading and grades.

Grading rule recorded for later pieces: a subject **with** components is not loaded or graded
directly — its components are, and its grade is the average of its components' grades. A subject
without components is loaded and graded itself.

## DepEd preset — `src/Data/DepEdSubjects.php`

A fixed list in code, like `PhilippineDivisions`. `DepEdSubjects::forGrade(GradeLevel, SchoolYear): list<array{code, name, components: list<array{code, name}>}>`,
in report-card order. Codes get the grade number appended (`MATH` + `7` → `MATH7`). Valid from
S.Y. 2026-2027 onward. Source: DepEd MATATAG Curriculum Phase 1 page (learning areas per grade) and
the MATATAG rollout schedule (Grade 10 joins in S.Y. 2027-2028).

| Grades | Subjects (code prefix – name) |
|---|---|
| 1 | LANG – Language; RL – Reading and Literacy; MATH – Mathematics; MKB – Makabansa; GMRC – Good Manners and Right Conduct |
| 2 | FIL – Filipino; ENG – English; MATH – Mathematics; MKB – Makabansa; GMRC – Good Manners and Right Conduct |
| 3 | Grade 2 list plus SCI – Science (after Mathematics) |
| 4–6 | FIL – Filipino; ENG – English; MATH – Mathematics; SCI – Science; AP – Araling Panlipunan; GMRC – Good Manners and Right Conduct; EPP – Edukasyong Pantahanan at Pangkabuhayan; MAPEH – MAPEH → MA – Music and Arts, PEH – Physical Education and Health |
| 7–9, and 10 from S.Y. 2027-2028 | FIL – Filipino; ENG – English; MATH – Mathematics; SCI – Science; AP – Araling Panlipunan; VE – Values Education; TLE – Technology and Livelihood Education; MAPEH – MAPEH → MA – Music and Arts, PEH – Physical Education and Health |
| 10 before S.Y. 2027-2028 (K to 12) | FIL – Filipino; ENG – English; MATH – Mathematics; SCI – Science; AP – Araling Panlipunan; ESP – Edukasyon sa Pagpapakatao; TLE – Technology and Livelihood Education; MAPEH – MAPEH → MUS – Music, ARTS – Arts, PE – Physical Education, HLTH – Health |
| Kindergarten, 11–12 | none (Kinder is assessed by developmental domains; the strengthened SHS curriculum differs by strand and is entered by hand, then brought back each year) |

"The school year is before 2027-2028" is decided by the school year's start date year (< 2027).
The list is reviewed by the product owner before release.

## Services

- `SubjectManager`:
  - `save(Subject $subject): void` — enforces the component and SHS rules above and the per-grade name uniqueness; logs `Created subject <label>` / `Updated subject <label>`.
  - `delete(Subject $subject): void` — deletes its components too; logs `Deleted subject <label>` (and `with N components` when any).
  - `addDepEdSet(School, SchoolYear, GradeLevel): int` — adds the preset for that grade with components and positions, skipping codes that already exist; logs `Added N DepEd subjects for Grade 7`.
  - `bringBack(School, SchoolYear $from, SchoolYear $to): int` — copies code, name, grade level, semester, track, strand, position and components, skipping codes that already exist in `$to` (a component is skipped with its parent); logs `Brought back N subjects`.
  - All writes are refused with the existing plan-blocked message when `PlanLimits::blockedReason($school)` is not null (expired plan = view-only).
- `SubjectRepository`: `findForSchool(School, SchoolYear): list<Subject>` ordered by grade level, semester, position, name; `findForSection(Section)` (above); `countForSchool(School, SchoolYear): int`.

## Pages

Shared controller `SchoolSubjectController`, `#[Route('/schools/{id}/subjects')]`, guarded by
`SchoolVoter::MANAGE` (admin any school, principal own school); works on the current school year
and chooses its layout like the Sections page.

| Route | Name | Purpose |
|---|---|---|
| `GET /schools/{id}/subjects` | `school_subjects` | list grouped by grade level (SHS also by semester): code, name, SHS narrowing; components indented under their parent; New / Edit / Delete; "Add DepEd subjects for Grade X" for each preset grade the school offers that has no subjects yet; "Bring back subjects from S.Y. <previous>" when the previous S.Y. has subjects whose codes are not here yet |
| `GET/POST …/new` | `school_subjects_new` | form (`?grade=7` preselects the grade) |
| `GET/POST …/{subject}/edit` | `school_subjects_edit` | form |
| `POST …/{subject}/delete` | `school_subjects_delete` | CSRF `subject-<id>`; the confirm text says "This also deletes its N components." when it has any |
| `POST …/deped` | `school_subjects_deped` | CSRF `deped-subjects`; field `grade` |
| `POST …/bring-back` | `school_subjects_bring_back` | CSRF `bring-back-subjects` |

- Form `SubjectFormType`: grade level (choices limited to the school type), code, name, "Component of"
  (that grade's subjects that are not components, "Not a component" placeholder; the choices follow
  the chosen grade via a small Stimulus controller, the server check is authoritative), position,
  and semester / track / strand for Grades 11–12 (shown only for those grades and hidden for
  components, like the Section form toggle).
- Principal sidebar: **Dashboard · Teachers · Sections · Subjects · School Profile · My Profile**
  (`principal_subjects` redirects to the own school's list, like `principal_sections`).
- Admin: a **Subjects** link on each school row in the admin Schools page (next to Sections).

## Teacher (Individual plan)

- Plans with `includesPrincipal = false`: the dashboard shows **My subjects** → `/teacher/subjects`
  (`teacher_subjects`, with `…/new`, `…/{subject}/edit`, `…/{subject}/delete`, `…/deped`,
  `…/bring-back` mirroring the school routes). It is limited to the grade level of the section the
  teacher advises in the current S.Y.; for Grades 11–12 new subjects default to that section's
  track and strand. The grade field is fixed to that grade.
- Without an advisory section it shows "Create your section first." with a link to
  `teacher_section_new`.
- On plans with a principal every `/teacher/subjects` route returns 403.

## Out of scope

Teacher loading, grades, schedules, units/hours, SHS presets, preparing a future school year before
it is current, and protecting subjects that have loads or grades from deletion (added with teacher
loading).

## Testing

- Data: `DepEdSubjects` — Grade 1, 3, 4 and 7 sets; Grade 10 old set for S.Y. 2026-2027 and
  MATATAG set for 2027-2028; Kinder and Grades 11–12 empty; codes unique within each grade.
- Entity/validation: grade level must fit the school type; code uppercased, format, unique per school
  and S.Y.; name unique per grade (and semester); SHS needs a semester, K–10 must not have
  semester/track/strand; component rules (same grade, one level, parent not a component, a parent
  cannot become a component); components copy the parent's semester/track/strand.
- `findForSection`: Grade 7 section gets Grade 7 subjects only; Grade 11 STEM and ABM sections get
  shared subjects plus only their own narrowed ones.
- Principal: list/create/edit/delete own school's subjects; deleting a parent removes its
  components; another school → 403; teacher role → 403; expired plan → blocked with message; DepEd
  set adds the right subjects and skips existing codes; bring back copies with components and SHS
  settings and skips existing codes.
- Admin: manage any school's subjects.
- Individual-plan teacher: manages subjects of their advisory grade only; another grade refused;
  "Create your section first." without an advisory; Basic-plan teacher gets 403.
- Activity log entries for create/update/delete/DepEd set/bring back.
