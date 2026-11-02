# Service Record — Principal-Kept Record of Service, Printed as PDF — Design

Date: 2026-11-02 · Branch: `feature/service-record` (off `feature/card-slips`)

## Goal

The principal keeps each employee's **service record** in the app — the DepEd form "To Be Accomplished
By Employer": one row per period of service with designation, status, salary, station, leave without
pay, separation and remarks — and prints it as a PDF that matches the paper form. The row marked
"Present" is the person's **current designation**, which the employee IDs (a later feature) will print.

This is the first of three pieces: (1) service record, (2) a Divisions list with the Schools Division
Superintendent, (3) employee IDs for teachers and principals. Only (1) is in this spec.

## Decisions (from the user)

- **Only the principal fills it in**, for the teachers of their school **and for themselves**.
- **Admin views only** (PDF), never changes rows.
- **Teachers have no access** to service records, not even their own (option b).
- Signatories come from **school settings** (option a): "Prepared by" (name, designation) and
  "Certified correct" (name, designation); "Certified correct" falls back to the principal.
- Rows are **typed in** (approach A), not generated from salary tables and not an uploaded scan.
- The approved data (part 1), screens and access (part 2), and PDF (part 3) are below.

## Existing pieces this builds on

- PDS (`PersonalDataSheet`): `surname`, `firstName`, `middleName`, `nameExtension`, `dateOfBirth`,
  `placeOfBirth`, `agencyEmployeeNo`. The principal already opens a teacher's PDS PDF from the Teachers
  page (`school_teacher_pds`).
- School: name, region, division, address, `contactNumber`, `email`, `facebookUrl`, `logoPath`; the
  school profile form (`SchoolProfileFormType`, `principal/school_profile.html.twig`) already holds a
  signatory pair (`sf8CertifierName` / `sf8CertifierPosition`) — the new signatories follow that pattern.
- Who is where: `SchoolAssignmentRepository::findSchoolOf(User, SchoolYear, role)`,
  `findPrincipal(School, SchoolYear)`, `findTeacherAssignments(School, SchoolYear)`; current year via
  `SchoolYearRepository::findCurrent()`.
- PDF: dompdf through a Twig template, as `PdsPdfRenderer` and `CardSlipPdf` (`render()` → bytes,
  `html()` for tests). DepEd seal at `assets/images/deped-seal.png`.

## Data

### `ServiceRecordEntry` (new entity, one row of the form)

Belongs to the **person** (`User`), not to a school, so it follows a teacher who transfers.

| Field | Type | Rules |
|---|---|---|
| `employee` | ManyToOne User, not null, cascade delete | |
| `fromDate` | date, not null | |
| `toDate` | date, null | null = **Present**; at most one per employee |
| `designation` | string(100), not null | e.g. "Teacher III" |
| `status` | string(50), not null | suggested list: Regular/Permanent, Provisional, Substitute, Contractual, Casual, Temporary; other text allowed |
| `annualSalary` | decimal(12,2), not null | ≥ 0 |
| `station` | string(150), not null | place of assignment, e.g. "Mandaue City" |
| `branch` | string(20), not null | National / Local |
| `lwopFrom`, `lwopTo` | date, null | leave without pay; both or neither, from ≤ to |
| `separationDate` | date, null | |
| `separationCause` | string(150), null | |
| `remarks` | string(150), null | e.g. "SG13-4 NBC-601" |
| `updatedBy` | ManyToOne User, not null | |
| `updatedAt` | datetime, not null | shown on the page, not printed |

Validation (class-level, against the employee's other rows): `fromDate ≤ toDate`; no two rows overlap
(an open row runs to infinity); at most one open ("Present") row. Rows are always listed by `fromDate`.

### `ServiceRecord` header (new entity, one per employee)

`employee` (OneToOne User, unique), `bpNumber` (string(30), null — GSIS BP number, not in the PDS).
Created on first save of the BP number.

### School settings (new columns on `School`)

`srPreparedByName`, `srPreparedByDesignation`, `srCertifiedByName`, `srCertifiedByDesignation`
(string, null), edited on the school profile. Printing: "Certified correct" uses the principal of the
school this S.Y. and the text "School Principal" when its name is empty.

### Current designation

`ServiceRecordEntryRepository::findCurrent(User): ?ServiceRecordEntry` — the open row. Used later by IDs.

Schema through a migration (`make:migration`, `doctrine:migrations:migrate`).

## Screens and access

**Principal**

- Teachers page: each teacher row gets **Service record** next to the PDS link.
- Principal menu: **My service record**.
- **Service record page** (`/schools/{id}/service-records/{user}`):
  - Header from the PDS — name, birth date and place, employee no. — with a warning listing what the PDS
    is missing; the **BP number** box (saves on its own).
  - Table of rows, oldest first, each with **Edit** and **Delete** (delete asks to confirm, CSRF).
  - **Add row** form, pre-filled from the latest row: same designation, status, station, branch;
    `fromDate` = day after its `toDate` (or empty if it is open). Station defaults to the school's city
    when there is no row.
  - When the person has an open row and the new row starts later, a ticked checkbox **"Close the
    current row on <day before>"** sets the open row's `toDate` to the day before the new `fromDate`.
  - "Last changed by … on …" per row.
  - **Download PDF**.
- School profile: the four signatory fields.

**Admin** — on the school's teachers list and the principal entry: **Service record (PDF)**, view only.

**Teachers** — no links; every service record route answers 403.

**Voter** (`ServiceRecordVoter`, subject = employee `User`):

- `VIEW`: admin; or a principal for anyone assigned to their school this S.Y. (themselves included).
- `EDIT`: a principal for anyone assigned to their school this S.Y. (themselves included). Never admin.

A transferred teacher's record moves with the assignment: the old principal loses access, the new one
gains it, and all earlier rows stay.

## PDF

dompdf, **portrait, 8.5 × 13 in (long bond)**, file name `Service record - SURNAME, GIVEN.pdf`.

1. DepEd seal; "Republic of the Philippines" / "Department of Education" (Old English–style heading if
   dompdf renders it, else plain bold); region, "Schools Division of …", school name.
2. **SERVICE RECORD** and "(To Be Accomplished By Employer)"; BP number (left) and employee number
   (right) with their captions.
3. NAME line — Surname / Given Name / Middle Name — with "(If married woman give also full maiden
   name)"; BIRTH line — Date / Place — with "(Data herein should be checked from birth or baptismal
   certificate or some other reliable documents)".
4. The sentence "This is to certify that the employee named hereinabove actually rendered services in
   this Office as shown by the service record below, each line of which is supported by appointment and
   other papers actually issued by this Office and approved by the authorities concerned."
5. The table, with the form's two-row headings: SERVICE (Inclusive Dates) From / To · RECORD OF
   APPOINTMENT Designation / Status / Salary · OFFICE ENTITY/DIVISION Station/Place of Assignment /
   Branch · LEAVE W/O PAY From / To · SEPARATION Date / Cause · REMARKS. Dates `m/d/Y`, salary
   `443,028.00`, open row "Present". After the last row: "x-x-x NOTHING FOLLOWS x-x-x". Long records
   continue on the next page with the headings repeated (`thead` repeat).
6. "Issued in compliance with Executive Order No. 54 dated August 10, 1954 and in accordance with
   Circular No. 58 dated August 10, 1954 of the System."
7. PREPARED BY (name, designation) · CERTIFIED CORRECT (name, designation, "Chief or Head of Office")
   · Date (the printing date), once, after the table.
8. Page footer: school address, contact number, email, Facebook link.

Missing PDS values print as blank lines.

## Errors and edge cases

- Validation errors show on the form; nothing is saved.
- Closing the open row that would make it end before it starts is refused with a form error.
- No rows: the PDF prints an empty table with NOTHING FOLLOWS.
- A user without a PDS: header fields blank, the page warns.
- A principal with no school this S.Y.: no "My service record" link; routes 403.

## Testing

- Validation (KernelTestCase): from ≤ to, overlap, one open row, LWOP pair.
- Functional (WebTestCase):
  - principal adds, edits, deletes rows for a teacher and for themselves; pre-fill; closing the open row;
  - BP number saves;
  - school profile saves the signatories, and the PDF HTML shows them (with the principal fallback);
  - PDF downloads (`application/pdf`, file name) and its HTML lists the rows and NOTHING FOLLOWS;
  - admin views the PDF, gets 403 on every change;
  - a teacher (own record too) and another school's principal get 403;
  - after a transfer, the new principal sees the earlier rows and the old principal gets 403.
- `findCurrent()` returns the open row.

## Out of scope

- Salary tables / automatic rows; e-signatures; teacher self-view; the Divisions list and IDs (next specs).
