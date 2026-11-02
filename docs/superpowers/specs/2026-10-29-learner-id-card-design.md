# Learner ID Card — Design

Date: 2026-10-29
Status: design approved in chat; awaiting written-spec review
Branch: `feature/learner-id` (from `feature/sf1`, whose guardian/contact fields it prints)

## Goal

Schools print school IDs for their learners from data the system already holds, plus an ID photo.
Public school IDs must be free to learners (reported as DO 65 s. 2010; no-collection policy restated
in DM 41 s. 2024), so printing must work cheaply on ordinary paper as well as on PVC card printers.

DepEd publishes no national learner-ID template. The card follows common Philippine public-school
practice: DepEd seal and school logo, photo, name, LRN, grade and section, school year, emergency
contact, school head, return address. Under the Data Privacy Act (RA 10173) a lost card should
reveal little: no birthdate, no street address.

## Out of scope

- Teacher and staff IDs.
- Signature images (the learner and the school head sign by hand).
- A QR verification page or any public URL.
- Per-school colors; the card uses one fixed palette.
- Learners uploading their own photo.
- IDs for past school years (current school year only, like class lists and SF1).

## The card

CR80, 54 × 86 mm, portrait. Palette: deep blue `#0b3d91` bands, gold `#f2b705` thin rule, white body,
dark grey text. Fonts: dompdf's DejaVu Sans (handles Ñ).

### Front

1. **Header band** (blue): DepEd seal (left), school logo (right, if the school has one), lines
   "Republic of the Philippines" / "Department of Education", "{region} · Division of {division}",
   the school name in bold upper case, "School ID {schoolId}". Gold rule under the band.
2. **Photo**: the learner's ID photo (350 × 450 px, 7:9) in a white frame; a grey silhouette when
   there is none.
3. **Name**: `FIRST MIDDLE-INITIAL. LAST EXT` in upper case, e.g. `GERALD JAMES M. ARNOL`,
   `REMUEL B. BAÑEZ JR`; no middle name → no initial. Shrinks a step when longer than 24 characters.
4. "LRN {lrn}", "{grade label} – {SECTION NAME}" (e.g. `Grade 8 – ARTISTIC`).
5. A signature line captioned "Learner's signature".
6. **Footer band** (blue): `LEARNER · S.Y. {start}–{end}`.

### Back

1. "In case of emergency, please notify:" — the **guardian** (name and relationship) if set, else
   the **mother** (`Mother` + maiden name from SF1 fields), else the **father**; then the contact
   number. The block is left out when there is neither a name nor a number.
2. "Address:" barangay, city, province (joined with ", ", empty parts skipped); left out when all
   three are empty. Never the street.
3. **QR code** of the LRN (just the 12 digits), about 18 mm square.
4. The school head's name (the principal assigned for the school year, upper case) above a blank
   line captioned "School Head"; a blank line alone when there is none.
5. "Valid until {end date, F j, Y}" from the school year's end date; left out without one.
6. Footer: "This card is the property of {school name}. If found, please return to the school"
   followed by " · {address}" and " · {contact number}" when the school has them.

Empty optional values are left out, never printed as "—".

## ID photo

- Stored as the learner's login account photo, `User::photoPath`, through the existing
  `ImageProcessor::storePhoto()` (crops to 350 × 450, saves `.webp`, removes the old file).
- Upload and remove on the learner's show page in an "ID photo" card: principal/admin of the school
  and the section's adviser — `LearnerVoter::EDIT` plus the learner being enrolled here this year
  (`requireLearner($scope, $id, false)`). POST only, CSRF-protected; upload uses
  `ImageUploadFormType` like the profile photo, errors as flash messages.
- Routes: `POST /schools/{id}/learners/{learner}/photo` → `school_learners_photo`,
  `POST /schools/{id}/learners/{learner}/photo/remove` → `school_learners_photo_remove`,
  `POST /teacher/class/{learner}/photo` → `teacher_class_photo`,
  `POST /teacher/class/{learner}/photo/remove` → `teacher_class_photo_remove`.
- Logged as "Updated ID photo of {lrn}" / "Removed ID photo of {lrn}".
- The student dashboard shows the photo in "My information", read-only.
- The class list shows a "No photo" badge (`badge badge--amber`, in the SF1 column) for learners
  without one.

## Printing

One PDF, rendered with dompdf like the PDS. `IdLayout` enum:

- `sheet` (default) — **A4 portrait, 9 cards per page** (3 across × 3 down, 54 × 86 mm each,
  4 mm gaps: 170 × 266 mm, centred) with corner cut marks. (2 × 4 = 8 does not fit: four CR80
  cards stacked are 344 mm, A4 is 297 mm.) Pages alternate: fronts of up to 9 learners, then their
  backs. On the back page each row of three is reversed, so printing double-sided with
  "flip on long edge" puts every back behind its front.
- `card` — **page size exactly 54 × 86 mm**, no margins: front page then back page per learner, for
  PVC ID printers.

`?layout=` takes `sheet` or `card`; anything else falls back to `sheet`.

Downloads (content type `application/pdf`, attachment):

| What | Who | Route | File name |
|---|---|---|---|
| A section | principal/admin; adviser for own class | `GET /schools/{id}/sections/{section}/ids` → `school_learners_ids`; `GET /teacher/class/ids` → `teacher_class_ids` | `IDs_{SY start}_{grade label} - {SECTION}.pdf` |
| One learner | `LearnerVoter::EDIT`, enrolled here this year | `GET /schools/{id}/learners/{learner}/id` → `school_learners_id`; `GET /teacher/class/{learner}/id` → `teacher_class_id` | `ID_{lrn}.pdf` |

Section access is the class list's: `requireSection()` + `LearnerVoter::VIEW_CLASS`. Learners print in
class-list order (boys, then girls, by name). An empty section gives a one-page PDF saying
"No learners in {section}." The section and learner pages get "Print IDs" / "Print ID" buttons with
the two layouts.

`/`, `\` and `%` in a section name become `-` in the file name (as SF1 does).

## Components

- `App\LearnerId\IdCard` — readonly DTO of the printed values: school name, school ID, region,
  division, school address, school contact, logo data URI or null, seal data URI, learner display
  name, LRN, grade and section label, photo data URI or null, emergency label (e.g. `Guardian (Aunt)`,
  `Mother`, `Father`), emergency name, emergency number, address line, QR data URI, school head name
  or null, school year label, valid-until date or null.
- `App\LearnerId\IdCardBuilder` — `forSection(Section): list<IdCard>`,
  `forEnrollment(Enrollment): IdCard`. Picks the emergency contact, formats the name, inlines the
  images as data URIs (dompdf may not load files or URLs, as in `PdsPdfRenderer`), looks up the
  principal once per call.
- `App\LearnerId\QrCode` — `png(string $text): string` data URI, wrapping `chillerlan/php-qrcode`.
- `App\LearnerId\IdLayout` — enum `Sheet = 'sheet'`, `Card = 'card'`.
- `App\LearnerId\IdCardPdf` — `render(list<IdCard>, IdLayout): string` PDF bytes;
  `sheetPages(list<IdCard>): list<array{front: list<IdCard|null>, back: list<IdCard|null>}>` does the
  paging and back-mirroring (public, so it is testable without parsing the PDF).
- Templates: `learners/id/_front.html.twig`, `learners/id/_back.html.twig`,
  `learners/id/sheet.html.twig`, `learners/id/card.html.twig`.
- DepEd seal image: `assets/images/deped-seal.png` read from disk by the builder (from Wikimedia
  Commons, "Seal of the Department of Education of the Philippines").

Dependency: `composer require chillerlan/php-qrcode`.

## Testing

- Builder (KernelTestCase): display name with/without middle name and extension; emergency contact
  guardian → mother → father → none; address without street and with empty parts; principal name or
  null; valid-until from the SY end date; null photo when missing, data URI when present.
- `QrCode::png()` returns a `data:image/png;base64,` URI whose decoded bytes start with the PNG
  signature. (Decoding the QR back to the LRN needs a reader library; not worth adding.)
- `IdCardPdf`: `sheetPages()` — 9 learners → 1 sheet (front + back), 10 → 2; each back row of
  three reversed (`[c, b, a, f, e, d, …]`), a lone last card lands on the mirrored side; `render()` returns bytes starting
  `%PDF` for both layouts and for an empty list.
- HTTP (WebTestCase): principal, admin and adviser download section and single IDs (200,
  `application/pdf`, file name); other school's principal, a non-adviser teacher and a student get
  403/404; `?layout=foo` still 200; photo upload stores a file and sets `photoPath`, remove clears
  it, both refused without CSRF token and for another school's learner; class list shows "No photo";
  student dashboard shows the photo.
