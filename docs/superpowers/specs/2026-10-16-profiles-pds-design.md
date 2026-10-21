# Profiles & Personal Data Sheet (CS Form No. 212, Revised 2026) — Design

Date: 2026-10-16
Status: approved in chat, awaiting written-spec review

## Goal

Principals (and later teachers) maintain their Personal Data Sheet inside ZIM School and
print it as a PDF that looks like the official CSC form. Principals also maintain their
school's profile (logo, address, contacts).

Source of truth for fields: `CS Form No. 212 Revised 2026 Personal Data Sheet PDS.xlsx`
(A4 portrait, 4 pages + continuation sheets C5–C11).

## Phasing

| Phase | Scope |
|---|---|
| **1 (this spec's build)** | Photo; PDS I. Personal Information (items 1–21); II. Family Background (items 22–25 incl. children); School Profile; PDF button rendering all 4 pages (unbuilt sections print empty) |
| 2 | Repeating sections: III Education, IV Eligibility, V Work Experience, VI L&D, VII Voluntary Work, VIII Other Info (skills, distinctions, memberships) — each added to the PDF with continuation pages |
| 3 | Questions 34–40, 41 References, Government-issued ID; My Profile for teachers (teacher layout) |
| later (optional) | "Download Excel" filling the official template |

Out of scope everywhere: signature, right thumbmark, date accomplished, oath block — left
blank on the PDF for wet signing.

## Decisions

- **Photo/logo storage:** uploaded and compressed server-side (not Google Drive links —
  Drive needs public sharing, which conflicts with RA 10173, and links break).
- **PDS storage:** approach 1 — a `PersonalDataSheet` row per user with real columns,
  plus one table per repeating section.
- **PDF:** server-side with Dompdf (`composer require dompdf/dompdf`), Twig template
  reproducing the official A4 layout.
- **School Profile edit rights (option A):** principal edits logo, address, contact
  number, email, Facebook page. School ID, name, region, division, district are shown
  read-only (admin-only).
- **Display name:** saving the PDS name rewrites `User.fullName` as
  `First M. Surname Ext.` (e.g. "Juan A. Dela Cruz Jr.").

## Data model (Phase 1)

All schema changes via Doctrine migration.

### `User` (existing) — add
- `photoPath` `?string(255)` — relative path under `public/uploads/photos/`.

### `School` (existing) — add
- `logoPath` `?string(255)` — under `public/uploads/logos/`
- `contactNumber` `?string(30)`
- `email` `?string(180)`
- `facebookUrl` `?string(255)`
- existing `address` becomes principal-editable.

### `PersonalDataSheet` (new, OneToOne → User, unique, created lazily on first visit)

I. Personal Information
| Item | Field(s) | Type / rule |
|---|---|---|
| 1 | `surname` | string(80), required |
| 2 | `firstName`, `nameExtension`, `middleName` | 80 req. / 10 / 80 |
| 3 | `dateOfBirth` | date, required, in the past |
| 4 | `placeOfBirth` | string(150) |
| 5 | `sexAtBirth` | enum `SexAtBirth`: MALE, FEMALE; required |
| 6 | `civilStatus`, `civilStatusOther` | enum `CivilStatus`: SINGLE, MARRIED, WIDOWED, SEPARATED, OTHERS; required. `civilStatusOther` (string 50) required only when OTHERS |
| 7 | `height` | decimal(4,2) metres, 0.50–2.50 |
| 8 | `weight` | decimal(5,2) kg, 10–300 |
| 9 | `bloodType` | string(5): A+, A-, B+, B-, AB+, AB-, O+, O- |
| 10 | `umidNo` | string(30) |
| 11 | `pagibigNo` | string(30) |
| 12 | `philhealthNo` | string(30) |
| 13 | `philsysPcn` | string(30) |
| 14 | `tin` | string(30) |
| 15 | `agencyEmployeeNo` | string(30) |
| 16 | `citizenship` (FILIPINO, DUAL), `dualCitizenshipBy` (BIRTH, NATURALIZATION), `dualCitizenshipCountry` | enums + string(80); the two dual fields required only when DUAL |
| 17 | `resHouseNo`, `resStreet`, `resSubdivision`, `resBarangay`, `resCity`, `resProvince`, `resZip` | strings; ZIP = 4 digits |
| 18 | `permSameAsResidential` (bool) + `perm*` same 7 fields | when true, perm fields are copied from res on save |
| 19 | `telephoneNo` | string(30) |
| 20 | `mobileNo` | string(20), `^09\d{9}$` |
| 21 | `email` | string(180), Email |

II. Family Background
| Item | Field(s) |
|---|---|
| 22 | `spouseSurname`, `spouseFirstName`, `spouseNameExtension`, `spouseMiddleName`, `spouseOccupation`, `spouseEmployer`, `spouseBusinessAddress`, `spouseTelephone` |
| 24 | `fatherSurname`, `fatherFirstName`, `fatherNameExtension`, `fatherMiddleName` |
| 25 | `motherMaidenSurname`, `motherFirstName`, `motherMiddleName` |

Plus `updatedAt` (datetime_immutable).

### `PdsChild` (new, ManyToOne → PersonalDataSheet, orphanRemoval, ordered by `position`)
Item 23: `fullName` string(150) required, `dateOfBirth` ?date (past), `position` int.

## Pages & routes

| Route | Name | Purpose |
|---|---|---|
| `GET /profile` | `profile_show` | Overview: photo, name, section tabs, completeness |
| `GET/POST /profile/personal` | `profile_personal` | Items 1–21 form |
| `GET/POST /profile/family` | `profile_family` | Items 22–25; children as add/remove rows (Form CollectionType) |
| `POST /profile/photo` | `profile_photo` | Upload photo |
| `POST /profile/photo/remove` | `profile_photo_remove` | Remove photo |
| `GET /profile/pds.pdf` | `profile_pdf` | Download PDS PDF |
| `GET/POST /principal/school` | `principal_school` | School Profile |

- `ProfileController` (`/profile`) — `#[IsGranted]` for principal or teacher; always
  operates on the current user's own PDS (no IDs in URLs).
- Layout: template picks the principal layout for principals; teacher layout in phase 3.
- Principal sidebar: Dashboard · Teachers · School Profile · My Profile.
- School Profile lives in `Principal\SchoolProfileController`; school resolved via the
  existing "principal's school for current SY" lookup, guarded by `SchoolVoter`. Not
  assigned → existing `not_assigned` page.
- Form types: `PersonalInfoFormType`, `FamilyBackgroundFormType`, `PdsChildFormType`,
  `SchoolProfileFormType` (only the editable fields are mapped, so locked fields cannot
  be changed by tampering), `ImageUploadFormType`.
- POST-redirect-GET with flash messages (existing `_flashes` partial).

## Services

- `ImageProcessor` — validates and re-encodes uploads with GD:
  - accepts JPEG/PNG/WebP by detected MIME (`File` constraint, max 5 MB);
  - photo: centre-crop to 7:9 (3.5 × 4.5 cm passport ratio), resize to 350×450, WebP q80;
  - logo: fit within 400×400 preserving aspect and transparency, WebP q85;
  - re-encoding strips EXIF/GPS and any embedded payload;
  - random filename (`bin2hex(random_bytes(16)).webp`); deletes the previous file on replace/remove.
  - upload dirs injected via `#[Autowire('%kernel.project_dir%/public/uploads/...')]`.
- `PdsPdfRenderer` — renders `profile/pdf/pds.html.twig` with Dompdf (A4 portrait),
  embedding the photo as a data URI; returns PDF bytes. Empty optional values print as
  `N/A`; unbuilt sections print their empty grids.
- `ActivityLogger` (existing) logs: profile updated, photo changed/removed, school profile updated.

## PDF layout

Four A4 pages mirroring the official sheets C1–C4 (section headers, item numbers,
labels, grid boxes, "CS FORM 212 (Revised 2026), Page n of 4" footer). Photo placed in
the PHOTO box on page 4. Children beyond the rows available on page 1 continue on a C7-style
continuation page. Filename: `PDS_<SURNAME>_<FIRSTNAME>.pdf`.

## Error handling

- Validation errors shown inline; nothing saved until valid.
- Invalid/oversized image → form error, old image kept.
- GD failure → flash error, old image kept, logged.
- Principal without an assignment for the current SY → `not_assigned` page for School Profile; My Profile still works.

## Testing (WebTestCase, existing fixtures trait)

- Principal can open and save Personal Info and Family; values persist; `fullName` updated.
- Validation: missing required fields, future birth date, bad mobile/ZIP/email,
  `civilStatusOther` required when OTHERS, dual-citizenship fields required when DUAL.
- "Same as residential" copies address.
- Children add/remove persists.
- Photo upload → stored file is 350×450 WebP; non-image rejected; remove deletes file.
- PDF route returns 200, `application/pdf`, body starts with `%PDF`.
- School Profile: principal saves editable fields + logo; tampered `name`/`schoolId`
  stay unchanged; principal of another school / teacher / anonymous get 403/redirect.
- Sidebar shows School Profile and My Profile for principals.
