# Class Sections — Design

Date: 2026-10-16
Status: design approved in chat; awaiting written-spec review

## Goal

Each school has class sections per school year, with an optional class adviser. Principals
manage their school's sections, admins any school's, and a teacher on a plan without a
principal (Individual) creates their own single section. This is the base for student
enrolment (next piece).

## Data — `Section` entity

| Field | Type / rule |
|---|---|
| `school` | ManyToOne School, required |
| `schoolYear` | ManyToOne SchoolYear, required |
| `gradeLevel` | enum `GradeLevel` {Kinder, Grade1 … Grade12}, required; must be offered by the school type |
| `name` | string(60), required, trimmed |
| `track` | enum `ShsTrack` {Academic, TvlTechPro ("TVL / TechPro"), Sports, ArtsDesign ("Arts and Design")}; required for Grades 11–12, must be null otherwise |
| `strand` | ?string(60) (datalist: STEM, ABM, HUMSS, GAS, ICT, Home Economics, Industrial Arts, Agri-Fishery Arts); only for Grades 11–12 |
| `adviser` | ManyToOne User, nullable, `onDelete: SET NULL` |
| `createdAt` | datetime_immutable |

- Unique: (`school`, `schoolYear`, `gradeLevel`, `name`) — "This section name already exists in Grade 7 for this school year." ; (`schoolYear`, `adviser`) — "<name> is already the adviser of Grade 8 – Rosal." (DB unique index allows multiple NULL advisers).
- Adviser must be a teacher assigned to the same school in the same S.Y. (validated in the service).
- Label: `Grade 7 – Sampaguita`; SHS: `Grade 11 – STEM 1 (Academic – STEM)` (strand part omitted when empty).

Grade levels per school type (`SchoolType::gradeLevels()`): Elementary K–6, Junior High 7–10, Senior High 11–12, Integrated K–12.

## Services

- `SectionManager`:
  - `save(Section $section): void` — validates adviser belongs to the school in that S.Y.; logs `Created section <label>` / `Updated section <label>` and `Assigned adviser <username> to <label>` when the adviser changes.
  - `delete(Section $section): void` — logs `Deleted section <label>`.
  - `bringBack(School, SchoolYear $from, SchoolYear $to): int` — copies grade level, name, track, strand (no adviser), skipping names that already exist; logs `Brought back N sections`.
  - All writes are refused with the existing plan-blocked message when `PlanLimits::blockedReason($school)` is not null (expired plan = view-only).
- `SectionRepository`: `findForSchool(School, SchoolYear): list<Section>` ordered by grade level then name; `findAdvisory(User, SchoolYear): ?Section`.

## Pages

Shared controller `SchoolSectionController`, `#[Route('/schools/{id}/sections')]`, guarded by `SchoolVoter::MANAGE` (admin any school, principal own school); layout chosen like the Teachers page.

| Route | Name | Purpose |
|---|---|---|
| `GET /schools/{id}/sections` | `school_sections` | list grouped by grade level: name, track/strand, adviser; New / Edit / Delete; "Bring back sections from S.Y. <previous>" when the previous S.Y. has sections not yet here |
| `GET/POST …/new` | `school_sections_new` | form |
| `GET/POST …/{section}/edit` | `school_sections_edit` | form |
| `POST …/{section}/delete` | `school_sections_delete` | CSRF `section-<id>` |
| `POST …/bring-back` | `school_sections_bring_back` | CSRF `bring-back-sections` |

- Form `SectionFormType`: grade level (choices limited to the school type), name, track + strand (shown only for Grades 11–12 via a small Stimulus toggle; server-side rules are authoritative), adviser (`EntityType` of the school's current-S.Y. teachers, ordered by name, "No adviser yet" placeholder).
- Principal sidebar: **Dashboard · Teachers · Sections · School Profile · My Profile** (`principal_sections` redirects to the own school's list, like `principal_teachers`).
- Admin: a **Sections** link on each school row in the admin Schools page (next to Teachers).

## Teacher

- Dashboard shows "Adviser of <label>" or "No advisory class this S.Y.".
- Plans with `includesPrincipal = false`: when the teacher has no advisory yet, the dashboard shows **Create my section** → `GET/POST /teacher/section` (`teacher_section_new`): grade level, name, track/strand; adviser = the teacher (not editable); refused if they already advise a section or the plan is expired. On plans with a principal the route returns 403.

## Out of scope

Student enrolment, capacity, schedules, subjects, deleting-protection for sections with students (added with enrolment).

## Testing

- Entity/validation: grade level must fit school type; SHS needs track, non-SHS must not have track/strand; duplicate name per grade level; one advisory per teacher per S.Y.; adviser must be a current teacher of that school.
- Principal: list/create/edit/delete own school's sections; another school → 403; teacher role → 403; expired plan → blocked with message; bring back copies without advisers and skips existing.
- Admin: manage any school's sections.
- Teacher: dashboard shows advisory; Individual-plan teacher creates one section and becomes adviser; second attempt refused; Basic-plan teacher gets 403 on `/teacher/section`.
- Activity log entries for create/update/adviser/delete/bring back.
