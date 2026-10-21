# PDS Phase 2 — Repeating Sections (III–VIII) — Design

Date: 2026-10-16
Status: design approved in chat; awaiting written-spec review
Builds on: `2026-10-16-profiles-pds-design.md` (phase 1)

## Goal

Fill the remaining list sections of CS Form No. 212 (Revised 2026) in My Profile and
print them in the PDS PDF, with continuation pages (official sheets C5–C11) when a list
has more rows than the form.

## Sections

All rows belong to the user's `PersonalDataSheet` (ManyToOne, `onDelete: CASCADE`,
`orphanRemoval`, `cascade: persist`). Dates follow the form: years for education,
dd/mm/yyyy for the rest. Every row has a `position` (int) used for the user's order
where no automatic sort applies.

| Section | Item | Entity | Form rows (PDF page) | Continuation sheet |
|---|---|---|---|---|
| III Educational Background | 26 | `PdsEducation` | 5 (p1) | C8 "EDUCATIONAL BACKGROUND (Continuation)" |
| IV Civil Service Eligibility | 27 | `PdsEligibility` | 7 (p2) | C9 "CIVIL SERVICE ELIGIBILITY (Continuation)" |
| V Work Experience | 28 | `PdsWorkExperience` | 24 (p2) | C6 "WORK EXPERIENCE (Continuation)" |
| VI Learning & Development | 29 | `PdsTraining` | 17 (p3) | C5 "LEARNING AND DEVELOPMENT (L&D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED (Continuation)" |
| VII Voluntary Work | 30 | `PdsVoluntaryWork` | 9 (p3) | C10 "VOLUNTARY WORK (Continuation)" |
| VIII Other Information | 31–33 | `PdsOtherInfo` | 7 (p3) | C11 "OTHER INFORMATION (Continuation)" |

### Fields

**PdsEducation** — `level` enum `EducationLevel` {Elementary, Secondary, Vocational ("VOCATIONAL / TRADE COURSE"), College, Graduate ("GRADUATE STUDIES")} required; `schoolName` string(200) required; `course` ?string(200) (basic education/degree/course); `yearFrom` ?int, `yearTo` ?int (1900–current year+6, `yearTo ≥ yearFrom`); `highestLevel` ?string(100) (units earned if not graduated); `yearGraduated` ?int (same range); `honors` ?string(200). Sorted by level order, then `yearFrom`. In the PDF each of the 5 level rows shows that level's first entry; extra entries go to C8.

**PdsEligibility** — `name` string(200) required (suggestions via `<datalist>`: Professional Teacher (RA 1080 / LET), Career Service Professional, Career Service Sub-Professional, Bar (RA 1080), Board (RA 1080), Barangay Eligibility, Driver's License); `rating` ?string(20); `examDate` ?date; `examPlace` ?string(200); `licenseNo` ?string(50); `licenseValidUntil` ?date. Order: user's `position`.

**PdsWorkExperience** — `dateFrom` date required; `dateTo` ?date; `present` bool (when true `dateTo` is ignored and printed as "PRESENT"); `dateTo ≥ dateFrom`; `positionTitle` string(200) required; `office` string(255) required; `monthlySalary` ?decimal(12,2) ≥ 0; `salaryGrade` ?string(10) matching `^\d{2}-\d$` (form's "00-0"); `appointmentStatus` ?string(50) (datalist: Permanent, Temporary, Substitute, Provisional, Contractual, Casual, Coterminous, Job Order, Contract of Service); `governmentService` bool. Sorted automatically: present first, then `dateFrom` descending.

**PdsTraining** — `title` string(255) required; `dateFrom` date required; `dateTo` ?date (≥ from); `hours` ?decimal(6,2) > 0; `type` ?string(50) (datalist: Managerial, Supervisory, Technical, Foundation); `sponsor` ?string(255). Sorted by `dateFrom` descending. The tab shows total hours.

**PdsVoluntaryWork** — `organization` string(255) required (name & address); `dateFrom` ?date; `dateTo` ?date (≥ from); `hours` ?decimal(6,2) > 0; `position` ?string(150) (position / nature of work). Sorted by `dateFrom` descending, undated last.

**PdsOtherInfo** — `kind` enum `OtherInfoKind` {Skill (31), Distinction (32), Membership (33)}; `text` string(255) required. The tab shows three lists. In the PDF the three columns fill side by side (row i = i-th of each kind); rows beyond 7 in any column go to C11.

## Pages

| Route | Name | Tab |
|---|---|---|
| `GET/POST /profile/education` | `profile_education` | Education |
| `GET/POST /profile/eligibility` | `profile_eligibility` | Eligibility |
| `GET/POST /profile/work` | `profile_work` | Work Experience |
| `GET/POST /profile/training` | `profile_training` | Learning & Development |
| `GET/POST /profile/voluntary` | `profile_voluntary` | Voluntary Work |
| `GET/POST /profile/other` | `profile_other` | Other Information |

- Same controller (`ProfileController`), same `_layout` (tabs become links), same save flow:
  validate → renumber positions → `touch()` → log `Updated own profile` → flush → flash → redirect.
- Each form is a `CollectionType` of the section's row form (`allow_add`, `allow_delete`,
  `by_reference: false`), rendered with the existing `collection` Stimulus controller as
  cards (one card per row, fields in a grid, Remove button). Empty state text per section.
- Validation group per section (like `family`), so required section-I fields never block a
  list save.
- `Assert\Count(max: 100)` per list (reviewer's minor #7 for children applies here too; children
  get `max: 30`).
- Per-section sort applied when reading (`PersonalDataSheet::sorted…()` helpers) so the
  page and the PDF show the same order.

## PDF

- Page 1 education grid, page 2 eligibility + work, page 3 L&D + voluntary + other info
  render real rows (blank rows pad to the form's count).
- Overflow pages use the continuation titles above, same columns, signature/date row,
  footer "CS FORM 212 (Revised 2026)". Order of continuation pages: C5, C6, C7, C8, C9,
  C10, C11 (after page 4).
- Empty optional cells print blank (not N/A) inside list grids — N/A is printed only on
  the first row of an empty section, as the form's guide says "Indicate N/A if not applicable".
- A test asserts the 4 main pages still fit with every grid full (A4, CPDF backend).

## Out of scope

Work Experience Sheet (duties attachment), questions 34–40, references, government ID
(phase 3), teacher access (phase 3).

## Testing

Per section: save rows and read them back in the expected order; remove a row; required
fields and date/format rules; validation of one section isn't blocked by empty section I.
PDF: rows appear in their grid; overflow creates the right continuation page; fully filled
main pages still render exactly 4 pages; empty section prints N/A in its first row.
