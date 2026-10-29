# SF1 School Register — Design

Date: 2026-10-29
Status: design approved in chat; awaiting written-spec review
Branch: `feature/sf1` (from `feature/learners`)

## Goal

Schools keep the data DepEd's **School Form 1 (SF 1) School Register** asks for, and download a
section's SF1 as an Excel file laid out like the one LIS generates
(`school_form_1_ver2014.2.1.1`, sample: `SF1_2025_Grade 8 (Year II) - ARTISTIC.xls`).

The principal, the admin and the section's adviser can edit a learner's personal information,
including the new SF1 fields.

## Out of scope

- Importing learners from an LIS SF1 file (possible later phase).
- Listing transferred-out learners with a T/O remark on the old section's SF1. A transferred learner
  leaves the section, as today, so T/O is not offered.
- SF2 and other school forms.
- Downloading SF1 for past school years (class lists are current-year only today).
- Learners editing their own information (they see it read-only).
- Non-adviser subject teachers editing learners.

## Data

All new fields are **optional** (nullable). Existing learners and the quick enrolment flow keep
working; missing SF1 data shows as a badge (see Completeness) and as blank cells in the file.

### `Learner`: personal information that follows the child across years

| Field | Type / rule |
|---|---|
| `motherTongue` | ?string(50) — free text, form suggests common values |
| `ipGroup` | ?string(50) — Indigenous Peoples ethnic group |
| `religion` | ?string(50) — free text, form suggests common values |
| `addressStreet` | ?string(100) — house # / street / sitio / purok |
| `addressBarangay` | ?string(50) |
| `addressCity` | ?string(50) — municipality / city |
| `addressProvince` | ?string(50) |
| `fatherLastName`, `fatherFirstName`, `fatherMiddleName` | ?string(50) each |
| `motherLastName`, `motherFirstName`, `motherMiddleName` | ?string(50) each — mother's **maiden** name |
| `guardianName` | ?string(100) — only when the guardian is not a parent |
| `guardianRelationship` | ?string(30) |
| `contactNumber` | ?string(20) — parent's or guardian's |

Blank strings are stored as `null`, like `School`'s optional fields.

Suggestion lists (HTML `datalist`, not enforced):
- Mother tongue: Cebuano / Sinugbuanong Binisaya, Tagalog, Ilocano, Hiligaynon, Waray, Bikol,
  Kapampangan, Pangasinan, Maranao, Maguindanaoan, Tausug, Chavacano, Bol-anon, English.
- Religion: Christianity, Roman Catholic, Islam, Iglesia ni Cristo, Seventh-day Adventist,
  Born Again Christian, Jehovah's Witness, Aglipay, Others.

### `Enrollment`: per school year

| Field | Type / rule |
|---|---|
| `learningModality` | `LearningModality` enum, required, default `FaceToFace` |
| `remarkCode` | ?`Sf1Remark` enum |
| `remarkDetails` | ?string(150) — school name, reason, reference number… per the legend |
| `remarkDate` | ?date_immutable — effectivity date |

`LearningModality` (new enum, value → label): `face_to_face` Face to Face, `modular_print`
Modular (Print), `modular_digital` Modular (Digital), `online` Online, `tv` Educational TV,
`radio` Radio-Based Instruction, `homeschooling` Homeschooling, `blended` Blended.

`Sf1Remark` (new enum, value → code / label / required information):

| Value | Code | Label | Details to give |
|---|---|---|---|
| `transferred_in` | T/I | Transferred In | Name of public (P) / private (PR) school & effectivity date |
| `dropped` | DRP | Dropped | Reason and effectivity date |
| `late_enrollment` | LE | Late Enrollment | Reason (enrollment beyond 1st Friday of SY) |
| `cct` | CCT | CCT Recipient | CCT control/reference number & effectivity date |
| `balik_aral` | B/A | Balik Aral | Name of school last attended & year |
| `sned` | SNED | Special Needs Education | Specify |
| `accelerated` | ACL | Accelerated | Specify level & effectivity date |

A remark with a code and no details is allowed (LIS does the same).

One migration adds the columns; `learning_modality` defaults to `face_to_face` for existing rows.

### Derived values (not stored)

- **Age as of 1st Friday of June**: the learner's age in whole years on the first Friday of June of
  the year the section's school year starts (`SchoolYear::startDate` year).
- **SF1 name**: `LAST, FIRST EXT, MIDDLE` in upper case — extension after the first name
  (`BAÑEZ, REMUEL JR, BRAVO`); missing middle name prints `-`. Parent names print
  `LAST, FIRST MIDDLE`, upper case.
- **Remarks text**: if the enrollment has a `remarkCode`, `{code} {details} {date Y-m-d}`
  (parts that are empty are left out). Otherwise, if the enrollment came from an **approved
  transfer** — the latest approved `TransferRequest` whose `enrollment` is this enrollment and
  whose `toSchool` is the enrollment's school (a transfer moves the same enrollment row, so this
  survives a later move between sections) — `T/I {from school name} {decidedAt Y-m-d}`.
  Otherwise empty. A manual remark always wins over the automatic T/I. An admin's direct transfer
  creates no request, so it gets no automatic T/I; the remark is set by hand.
- **SF1 incomplete**: a learner is incomplete when any of these is missing:
  barangay, city, province; at least one parent name (father last+first or mother last+first) or a
  guardian name; contact number.

## Editing

### Who

Unchanged `LearnerVoter::EDIT`: principal/admin for their school; the adviser for a learner in
their own advisory section. Students can view, not edit. No new voter rules.

### Learner details form

`LearnerFormType` and `learners/_fields.html.twig` grow from one block into fieldsets:

1. **Identity** — LRN (only with `CHANGE_LRN`), names, sex, birthdate. As today.
2. **Other information** — mother tongue, IP group, religion (with datalists).
3. **Address** — house/street, barangay, city, province.
4. **Family** — father's name (3 fields), mother's maiden name (3 fields), guardian name and
   relationship, contact number.

Used by the edit page for all three roles and by the enrol page (all new fields optional).
Saving goes through the existing `handleEdit` / `EnrollmentService::update` path.

### This year's enrollment form

New `EnrollmentRecordFormType` (learning modality, remark code, remark details, remark date) on its
own page, reached from the learner's show page:

- Principal/admin: `GET|POST /schools/{id}/learners/{learner}/enrollment` → `school_learners_enrollment`
- Adviser: `GET|POST /teacher/class/{learner}/enrollment` → `teacher_class_enrollment`

It edits only the learner's enrollment in the **current** school year; a learner without one gets
404. Guarded by `LearnerVoter::EDIT`, CSRF-protected by the form. When an automatic T/I applies the
page shows it as a hint: "Automatic: T/I {school} {date}. Choose a remark to replace it."

### Show page and student dashboard

`learners/_details.html.twig` gets read-only blocks: Other information, Address, Family, and
"School year {name}" (modality and remarks text). The student dashboard renders the same blocks.

### Completeness badge

The class list shows an "SF1 incomplete" pill next to each incomplete learner, linking to their
edit page for users who may edit.

## SF1 download

### Routes and access

- Principal/admin: `GET /schools/{id}/sections/{section}/sf1` → `school_learners_sf1`
- Adviser: `GET /teacher/class/sf1` → `teacher_class_sf1` (their current advisory section)

Access: the same as viewing that class list — `requireSection()` (section of this school in the
current school year, the adviser's own on their pages; 404 otherwise) and `LearnerVoter::VIEW_CLASS`
on the section. Like class lists, only the **current** school year's sections can be downloaded;
past-year SF1s are out of scope for now. A "Download SF1" button sits on the class list page.

Response: `StreamedResponse`, content type `application/vnd.ms-excel`, attachment named
`SF1_{SY start year}_{grade label} - {SECTION NAME}.xls`, e.g. `SF1_2025_Grade 8 - ARTISTIC.xls`.

### Content

Mirrors the LIS sheet: one worksheet, **legal paper, landscape**, fit to one page wide, 47 columns
with the LIS merges.

1. **Title**: "School Form 1 (SF 1) School Register" and "(This replaces Form 1, Master List &
   STS Form 2-Family Background and Profile)".
2. **Header**: School ID, Region, Division; School Name, School Year (`2025 - 2026`), Grade Level,
   Section.
3. **Column headings** (two rows): LRN; NAME (Last Name, First Name, Middle Name); Sex (M/F);
   BIRTH DATE (mm/dd/yyyy); AGE as of 1st Friday June; MOTHER TONGUE (Grade 1 to 3 Only);
   IP (Ethnic Group); RELIGION; ADDRESS → House #/Street/Sitio/Purok, Barangay,
   Municipality/City, Province; PARENTS → Father's Name, Mother's Maiden Name;
   GUARDIAN (if Not Parent) → Name, Relationship; Contact Number of Parent or Guardian;
   Learning Modality; REMARKS (Please refer to the legend on last page).
4. **Rows**: every learner enrolled in the section. Males first, then females; each group ordered
   by last name, first name, middle name, enrollment id. Birthdate `mm-dd-yyyy`. Mother tongue
   printed only for Grades 1–3, blank otherwise.
5. **Totals**: after the males, count + "<=== TOTAL MALE"; after the females "<=== TOTAL FEMALE";
   then "<=== COMBINED".
6. **Footer**:
   - The legend "List and Code of Indicators under REMARKS column" (T/O, T/I, DRP, LE, CCT, B/A,
     SNED, ACL with required information), as LIS prints it.
   - Summary table: rows MALE / FEMALE / TOTAL; columns REGISTERED, BoSY, EoSY. REGISTERED = BoSY
     = learners on the list. EoSY = learners on the list minus DRP, filled only when the school
     year's end date has passed; blank before.
   - "Prepared by:" the section adviser's full name (upper case), "(Signature of Adviser over
     Printed Name)", BoSY Date / EoSY Date lines.
   - "Certified Correct:" the school's principal for that school year (upper case), "(Signature of
     School Head over Printed Name)", BoSY Date / EoSY Date lines. Blank name if none.
   - "Generated on: {weekday, Month d, yyyy}". No "Generated thru LIS" line.

### Components

- `App\Sf1\Sf1Report` — readonly DTO: header values, `male` and `female` lists of `Sf1Row`
  (readonly DTO with the printed values), counts, EoSY counts or null, adviser name, school head
  name, generated date.
- `App\Sf1\Sf1ReportBuilder` — service: `build(Section $section): Sf1Report`. Loads enrollments of
  the section with learners, approved transfers into it, and the school head; does the ordering and
  derived values.
- `App\Sf1\Sf1Writer` — service: `write(Sf1Report $report): Spreadsheet`. Layout only: widths,
  merges, fonts, borders, wrap, print setup. No queries.
- Controllers stay thin: check access, build, write with PhpSpreadsheet's `Xls` writer into a
  `StreamedResponse`.

Dependency: `composer require phpoffice/phpspreadsheet`.

## Testing

- Unit: SF1 name formatting (with/without middle name and extension), age as of first Friday of
  June (birthday just before/after), remarks text (manual, automatic T/I, manual over automatic,
  none), completeness rules.
- `Sf1ReportBuilder` (KernelTestCase): males before females and ordering, totals, mother tongue
  blank for Grade 4+, automatic T/I from an approved transfer only (not pending/declined), EoSY
  only after the end date, adviser and principal names.
- `Sf1Writer`: write to a temp file, reload with PhpSpreadsheet, assert header cells, first male
  row, total rows, signatory names, legal landscape.
- HTTP (WebTestCase):
  - principal, admin and adviser save the new learner fields;
  - other school's principal, a non-adviser teacher and a student get 403 on edit;
  - enrollment record saves for the current year; 404 without a current enrollment;
  - SF1 download: 200 with Excel content type and file name for principal/admin/adviser; 403 for a
    non-adviser teacher, a student and another school's principal;
  - class list shows the "SF1 incomplete" badge for an incomplete learner and not for a complete one.
