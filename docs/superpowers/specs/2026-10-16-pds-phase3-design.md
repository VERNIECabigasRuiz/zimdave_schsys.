# PDS Phase 3 — Questions 34–40, References & ID, Teacher Profiles — Design

Date: 2026-10-16
Status: design approved in chat (incl. option A: principals download teachers' PDS); awaiting written-spec review
Builds on: `2026-10-16-profiles-pds-design.md`, `2026-10-16-pds-phase2-design.md`

## Goal

Complete page 4 of CS Form No. 212 (Revised 2026) in My Profile, open My Profile to
teachers in a small teacher area, and let a principal (and admin) download the PDS of
the teachers of their school.

## 1. Tab "Questions 34–40" (`/profile/questions`, `profile_questions`)

Stored on `PersonalDataSheet` as nullable booleans (null = not answered) plus text:

| Item | Answer field | "If YES" fields |
|---|---|---|
| 34a within the third degree | `q34a` | `q34Details` string(255) — shared by 34a and 34b, required if either is Yes |
| 34b within the fourth degree (LGU career employees) | `q34b` | (shared) |
| 35a guilty of an administrative offense | `q35a` | `q35aDetails` string(255) |
| 35b criminally charged before any court | `q35b` | `q35bDetails` string(255), `q35bDateFiled` date, `q35bStatus` string(100) |
| 36 convicted of any crime/violation | `q36` | `q36Details` |
| 37 separated from the service | `q37` | `q37Details` |
| 38a candidate in an election within the last year (except barangay) | `q38a` | `q38aDetails` |
| 38b resigned within 3 months before an election to campaign | `q38b` | `q38bDetails` |
| 39 immigrant / permanent resident of another country | `q39` | `q39Details` (country) |
| 40a member of an indigenous group | `q40a` | `q40aDetails` (specify) |
| 40b person with disability | `q40b` | `q40bDetails` (PWD ID no.) |
| 40c solo parent | `q40c` | `q40cDetails` (solo parent ID no.) |

- Form: each answer is a Yes/No `ChoiceType` (expanded, `choice-chips` style), not required.
- Validation group `questions`: a Callback requires each question's "If YES" field(s) when the
  answer is Yes (35b: details + date filed + status). When the answer is No, its details are
  cleared on save. Question wording on the page copies the form exactly.
- PDF page 4: ☒/☐ YES and NO per item; details printed after "If YES, give details:"; an
  unanswered item prints both boxes empty.

## 2. Tab "References & ID" (`/profile/references`, `profile_references`)

- **41. References:** `PdsReference` entity (ManyToOne sheet, `position`, `name` string(150),
  `address` string(255), `contact` string(100)). The form always shows exactly **3** rows
  (controller fills missing rows; no add/remove). Rows that are completely empty are not saved.
  Group `references`: a started row needs name, address and contact; Callback: once any
  reference is entered, all 3 are required ("List three references.").
- **Government-issued ID:** on the sheet — `govIdType` string(80) (datalist: Passport, GSIS,
  SSS, PRC, Driver's License, UMID, PhilSys National ID, Postal ID, Voter's ID), `govIdNumber`
  string(80), `govIdIssued` string(150) ("date/place of issuance", free text as on the form).
- PDF page 4: references in the 3 rows; ID lines filled. Signature, date accomplished, right
  thumbmark and oath stay blank.

## 3. Teacher area

- `TeacherController` (`#[IsGranted(ROLE_TEACHER)]`, `/teacher`, route `teacher_dashboard`):
  welcome card with the teacher's name, school (current-SY assignment) and school year; "not
  assigned" message when there is none (My Profile still works).
- `templates/teacher/_layout.html.twig`: same shell as the principal layout; nav **Dashboard ·
  My Profile**; brand subtitle "Teacher".
- `HomeController`: teachers are redirected to `teacher_dashboard`.
- `ProfileController`: access becomes principal **or** teacher (`#[IsGranted]` with an
  expression or a small voter attribute `PROFILE_OWN`); `profile/_layout.html.twig` extends the
  principal or teacher layout by role.
- Admin and students still cannot open `/profile` (403).

## 4. Principal (and admin) downloads a teacher's PDS

- Route `GET /schools/{id}/teachers/{teacher}/pds.pdf` (`school_teacher_pds`) on
  `SchoolTeacherController`, guarded by `SchoolVoter::MANAGE` on the school; 404 unless the user
  is a teacher of that school in the current S.Y.
- Uses `PdsPdfRenderer` with the teacher's sheet (created empty if none, not flushed — a
  download never writes the teacher's data); filename as in phase 1.
- Teachers page: a **"PDS"** link per teacher row (opens the PDF).
- Logged as `Downloaded PDS of <username>`.

## Testing

- Questions: Yes without details → error on that question; No clears details; 35b requires date
  and status; unanswered stays null; page 4 HTML shows ☒ YES / ☒ NO / empty boxes correctly.
- References: 3 rows always shown; partial row errors; 1 or 2 references → "List three
  references."; all empty is valid; PDF shows them; ID fields saved and printed.
- Teacher: login lands on `/teacher`; dashboard shows school; My Profile tabs work for a
  teacher with teacher nav; admin/student get 403 on `/profile`.
- Principal: downloads PDF of own school's teacher (200 application/pdf); other school's teacher
  → 403/404; teacher of no current assignment → 404; teacher role cannot use the route; nothing
  is written to the teacher's PDS by the download.
- Full PDF still 4 main pages with page 4 filled.
