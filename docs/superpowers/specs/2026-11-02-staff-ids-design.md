# Staff IDs (teachers and principals) — design

Date: 2026-11-02 · Branch: `feature/staff-ids` (from `feature/service-record`)

## Goal

Every teacher and principal prints their own school ID as a PDF, in either of the two DepEd
templates the school uses (`SCHOOL ID_TEMPLATE_Big.docx`, `SCHOOL ID_TEMPLATE_Small_Regular.docx`).
A teacher's ID is signed by the school principal; a principal's ID by the Schools Division
Superintendent (SDS), kept in an admin-managed Divisions list.

## Decisions (from the user)

- Both templates, for both roles.
- Each person prints their own ID. No one prints IDs for others (not the principal, not the admin).
- The head's signature is an uploaded e-signature: the principal uploads theirs in School Profile,
  the admin uploads the SDS's in Divisions. Until uploaded, a blank signature line prints.
- Emergency contact and GSIS Policy No. are entered on the person's My ID page and kept.
- The school's division comes from the list (see Divisions below).
- Position comes from the person's service record "Present" row.

## Divisions (admin)

- New entity `Division`: `region`, `name` (the pair unique; both chosen from
  `PhilippineDivisions`, the same dropdowns the school form uses), `sdsName`, `sdsPosition`
  (default "Schools Division Superintendent"), `signaturePath` (nullable).
- Admin menu item **Divisions**: list, add, edit. Admin only.
- A school's Division is the entry whose region and name equal the school's `region` and
  `division`. Schools already pick these from the fixed dropdown, so no school data or form
  changes; a typed value can't drift from the list. A school whose pair has no entry has no SDS.

## Signatures

- School gets `idSignaturePath` (the principal's ID signature), uploaded in School Profile
  ("ID signature"), principal only.
- Signature images are kept with transparency (PNG/WebP), scaled to fit, stored like logos.

## My ID page

- Route for the signed-in teacher or principal: menu item **My ID** in both layouts.
- Only for a person assigned to a school this S.Y. (as teacher or principal); otherwise the menu
  item is hidden and the page is 403. A person can only see and print their own ID.
- Shows what will print and lists what's missing: photo, Present row (position), head signature,
  Divisions entry (principals), PDS numbers.
- Form: emergency contact name, emergency contact no(s)., GSIS Policy No. Stored per user in a
  new entity `StaffIdDetails` (one per user).
- Buttons **Big ID (PDF)** and **Small ID (PDF)**. Without a photo or a Present row the buttons
  are disabled and the PDF routes refuse (redirect back with a message). Every other missing item
  prints as a blank line.

## What goes on the card

| Item | Source |
|---|---|
| Name | PDS surname, first name, middle initial, extension; else the account's full name |
| Photo | the user's profile photo |
| Position | service record Present row designation |
| Employee no., TIN, PhilHealth, Pag-IBIG, blood type | PDS (`agencyEmployeeNo`, `tin`, `philhealthNo`, `pagibigNo`, `bloodType`) |
| GSIS Policy No., emergency contact | `StaffIdDetails` |
| Region, division, school name, address, logo | School |
| Website line | school Facebook URL, else school email |
| Head (teacher's ID) | the school's principal this S.Y.; position from their Present row, else "School Principal"; signature `School.idSignaturePath` |
| Head (principal's ID) | Division `sdsName`, `sdsPosition`, `signaturePath` |

## Layouts (PDF via dompdf, actual size — print at 100%)

**Big — 100 × 138 mm, front only, one card on long bond (8.5 × 13 in).**
Blue template background with the baked-in "REGION VII… / SCHOOLS DIVISION OF MANDAUE CITY"
painted over once in the same blue (stored as an asset); the school's region and division print
as text. Top: seal, "Republic of the Philippines", "Department of Education", region, division.
Middle: photo, name as `JUAN A. DELA CRUZ`, position. Bottom: head signature over head name and
position, school logo.

**Small — 54 × 86 mm (CR80), front and back side by side on letter.**
Front: red template background with seal, photo, EMPLOYEE NO, region/division, surname large
(`DELA CRUZ,`), first name + middle initial, school name, position.
Back: template background with its printed labels; emergency name and contact; TIN, GSIS Policy
No., PhilHealth, Pag-IBIG, blood type; blank Employee's Signature line; head signature, name and
position; school name, address, website line.

File names: `ID (Big) - DELA CRUZ, JUAN.pdf`, `ID (Small) - DELA CRUZ, JUAN.pdf`, with an ASCII
fallback for names like PEÑA.

## Out of scope

Printing IDs for others, batch sheets, PVC card-printer layout, QR codes, ID validity dates.

## Tests

- Divisions: admin can list/add/edit; non-admins get 403; duplicate region+name rejected.
- School Profile: principal uploads an ID signature.
- My ID: teacher and principal see their own; no school this S.Y. → menu hidden and 403; the
  form saves emergency contact and GSIS No.
- Head: a teacher's card shows the school principal (and their Present-row position, or "School
  Principal"); a principal's card shows the SDS from the matching Division; none → blank.
- Missing photo or Present row → PDF refused.
- Both PDFs render and contain the name, position and head name.
