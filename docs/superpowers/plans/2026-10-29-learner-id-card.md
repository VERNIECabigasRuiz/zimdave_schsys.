# Learner ID Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Print learner school IDs (CR80, front + back) as a PDF for a section or one learner, in an A4 9-up duplex sheet layout or a card-printer layout, with an ID photo uploaded by principal/admin/adviser.

**Architecture:** A small `App\LearnerId` namespace: `QrCode` (LRN → PNG data URI), `IdCardBuilder` (enrollments → readonly `IdCard` DTOs with every printed value and inlined images), `IdCardPdf` (cards + `IdLayout` → dompdf bytes via Twig templates sharing `_front`/`_back` partials). Photo upload reuses `ImageProcessor::storePhoto()` on `User::photoPath`, saved through `LearnerEnrollment` like other learner writes. Controllers reuse `AbstractLearnerController` + `LearnerScope`.

**Tech Stack:** Symfony 8.1, PHP 8.4, Twig, dompdf 3 (installed), `chillerlan/php-qrcode` (new), GD/webp, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-29-learner-id-card-design.md`

## Global Constraints

- Branch `feature/learner-id`. Commit messages end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Card: CR80 54 × 86 mm portrait; palette blue `#0b3d91`, gold `#f2b705`; font DejaVu Sans; dompdf with `setIsRemoteEnabled(false)`, every image as a data URI.
- Printed text never shows "—" for missing data; empty optional blocks are left out.
- No birthdate, no street address on the card.
- Access: section downloads = `requireSection()` + `LearnerVoter::VIEW_CLASS`; single-learner download and photo upload/remove = `requireLearner($scope, $id, false)` + `LearnerVoter::EDIT`. Current school year only.
- `?layout=` is `sheet` (default) or `card`; anything else → `sheet`.
- File names: section `IDs_{SY start}_{grade label} - {SECTION}.pdf` (with `/`, `\`, `%` → `-`), learner `ID_{lrn}.pdf`.
- PHPUnit on this laptop: run through the scratchpad runner `pu.ps1` (PowerShell `Start-Process`; Avast suspends piped php.exe). Commands below say `php bin/phpunit …` for brevity.

## Review Focus

1. **Names with Ñ and long names** (`MA. KRISTINE JOY B. DELA CRUZ-VILLANUEVA`): must render in DejaVu and stay inside the card → test in Task 2 (display name) and Task 3 (render with a 40-char name doesn't add pages).
2. **Learner with no photo / no parents / no contact / school without logo or address**: the card still renders, blocks are left out, no "—" → tests in Task 2 and Task 3.
3. **10 learners (a partly empty last sheet)**: back mirroring of a short row puts the lone back on the right → test in Task 3.
4. **Photo upload of a non-image or huge image**: flash error, old photo kept, no 500 → test in Task 4.
5. **Photo upload for a learner of another school or by a student, and without CSRF token**: refused, file unchanged → test in Task 4.

---

### Task 1: QR code helper and DepEd seal

**Files:**
- Create: `src/LearnerId/QrCode.php`
- Create: `assets/images/deped-seal.png`
- Modify: `composer.json`, `composer.lock` (via composer)
- Test: `tests/Service/LearnerIdQrCodeTest.php`

**Interfaces:**
- Produces: `App\LearnerId\QrCode::png(string $text): string` → `data:image/png;base64,…` (error-correction M, scale so the PNG is ≥ 150 px, quiet zone 2).

- [ ] **Step 1:** `composer require chillerlan/php-qrcode` → installed without errors.
- [ ] **Step 2:** Download the seal from Wikimedia Commons (`https://commons.wikimedia.org/wiki/Special:FilePath/Seal_of_the_Department_of_Education_of_the_Philippines.png?width=300`) to `assets/images/deped-seal.png` (put the download in an empty scratch dir first, check it is a PNG with `getimagesize`, then copy). If it can't be fetched, ledger a ruling and continue without the seal (builder returns null for it).
- [ ] **Step 3: Failing test** `testPngDataUri`: `QrCode::png('120017170001')` starts with `data:image/png;base64,`; `base64_decode` of the rest starts with `"\x89PNG"`; `getimagesizefromstring()` width ≥ 150 and width == height.
- [ ] **Step 4:** Run `php bin/phpunit tests/Service/LearnerIdQrCodeTest.php` → FAIL (class missing).
- [ ] **Step 5:** Implement `QrCode` (plain service class, `QROptions` with PNG output via GD, base64 data URI).
- [ ] **Step 6:** Run → PASS.
- [ ] **Step 7: Commit** — `feat: QR code helper and DepEd seal for learner IDs`

---

### Task 2: IdCard DTO and builder

**Files:**
- Create: `src/LearnerId/IdCard.php`, `src/LearnerId/IdCardBuilder.php`
- Test: `tests/Service/IdCardBuilderTest.php`

**Interfaces:**
- Consumes: `QrCode::png()`; `ImageProcessor::absolutePath()`; `SchoolAssignmentRepository::findPrincipal()`; `EnrollmentRepository::findClassList()`; `#[Autowire('%kernel.project_dir%')]` for the seal path.
- Produces:

```php
final readonly class IdCard
{
    public function __construct(
        public string $schoolName, public string $schoolId, public string $region, public string $division,
        public ?string $schoolAddress, public ?string $schoolContact, public ?string $logo, public ?string $seal,
        public string $name, public string $lrn, public string $gradeSection, public ?string $photo,
        public ?string $emergencyLabel, public ?string $emergencyName, public ?string $emergencyNumber,
        public ?string $address, public string $qr, public ?string $schoolHead,
        public string $schoolYear /* '2025–2026' */, public ?\DateTimeImmutable $validUntil,
    ) {}
}
```

- `IdCardBuilder::forSection(Section $section): list<IdCard>` (class-list order; principal, logo and seal looked up once), `IdCardBuilder::forEnrollment(Enrollment $enrollment): IdCard`.
- Name rule: `mb_strtoupper(first.' '.(middle ? mb_substr(middle,0,1).'. ' : '').last.(ext ? ' '.ext : ''))`.
- Emergency rule: guardian name set → label `Guardian` or `Guardian ({relationship})`, name = guardian name; else mother (last+first or either set) → `Mother`, `getMotherSf1Name()`; else father → `Father`, `getFatherSf1Name()`; else label and name null. Number = `contactNumber` regardless.
- Address: barangay, city, province joined `', '`, null when all empty.
- Images: photo and logo (webp under upload root) re-encoded to PNG data URIs as `PdsPdfRenderer::photoDataUri()` does; seal read as PNG file → data URI; missing file → null.
- Grade/section: `{gradeLevel label} – {mb_strtoupper(section name)}`.

- [ ] **Step 1: Failing tests** (KernelTestCase + `SchoolFixturesTrait`, SY 2025 ends 2026-04-30, Grade 8 `Artistic`, principal `Rene Petancio`):
  - `testDisplayName`: `Gerald James`/`Molina`/`Arnol` → `GERALD JAMES M. ARNOL`; `Remuel`/`Bravo`/`Bañez`/`Jr` → `REMUEL B. BAÑEZ JR`; no middle → `JUSTINE BORJA`.
  - `testEmergencyContactOrder`: guardian `Nena Abad` + `Aunt` → (`Guardian (Aunt)`, `Nena Abad`); remove guardian → (`Mother`, `MOLINA, ANGELLE`); remove mother → (`Father`, `ARNOL, GERALD`); none → (null, null); number `09171234567` kept in every case.
  - `testAddressWithoutStreet`: street `Purok 3`, barangay `Tabok`, city null, province `Cebu` → `Tabok, Cebu`; all empty → null.
  - `testSchoolValues`: school name, ID, region, division, `schoolHead === 'RENE PETANCIO'`, `schoolYear === '2025–2026'`, `validUntil == 2026-04-30`, `gradeSection === 'Grade 8 – ARTISTIC'`, `qr` starts `data:image/png`.
  - `testMissingImagesAreNull`: no photo, no logo → `photo`, `logo` null; with a stored photo (write a 350×450 webp via `imagewebp` under the test upload root and set `photoPath`) → `photo` starts `data:image/png`.
  - `testNoPrincipal`: `schoolHead` null.
  - `testForSectionKeepsClassListOrder`: two boys then a girl.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement DTO and builder.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** — `feat: build learner ID card data`

---

### Task 3: ID card PDF (layouts and templates)

**Files:**
- Create: `src/LearnerId/IdLayout.php`, `src/LearnerId/IdCardPdf.php`
- Create: `templates/learners/id/_front.html.twig`, `_back.html.twig`, `sheet.html.twig`, `card.html.twig`
- Test: `tests/Service/IdCardPdfTest.php`

**Interfaces:**
- Consumes: `IdCard` (Task 2). Tests build `IdCard`s directly (QR via `QrCode::png`).
- Produces: `enum IdLayout: string { case Sheet = 'sheet'; case Card = 'card'; }`; `IdCardPdf::render(array $cards, IdLayout $layout): string`; `IdCardPdf::html(array $cards, IdLayout $layout): string`; `IdCardPdf::sheetPages(array $cards): list<array{front: list<?IdCard>, back: list<?IdCard>}>` — chunks of 9; `front` padded to 9 with null; `back` = each row of 3 reversed (`[f2,f1,f0,f5,f4,f3,f8,f7,f6]`).

Layout decisions:
- Sheet: `@page { size: A4 portrait; margin: 0 }`; a 3 × 3 grid of 54 × 86 mm cards with 4 mm gaps (170 × 266 mm), left offset 20 mm, top offset 15.5 mm. Cut marks: 3 mm hairlines at each card corner, outside the card.
- Card: `@page { size: 54mm 86mm; margin: 0 }`, one card per page, front then back.
- Absolute positioning in mm inside each card (dompdf handles absolutely positioned blocks reliably); name font 9pt, 8pt when the name is longer than 24 characters, 7pt above 32.
- Empty sheet list → template prints one page "No learners in this section."

- [ ] **Step 1: Failing tests** (plain `TestCase` for `sheetPages`; `KernelTestCase` for `render` to get Twig):
  - `testNineCardsMakeOneSheet`: 9 cards → 1 entry; `front` = cards 0–8; `back` = `[2,1,0,5,4,3,8,7,6]`.
  - `testTenCardsMirrorTheLoneCard` (Review Focus 3): 10 → 2 entries; second `front` = `[9,null×8]`; second `back` = `[null,null,9,null,null,null,null,null,null]`.
  - `testRenderSheetAndCard`: 3 cards → both layouts return bytes starting `%PDF`; card layout page count 6 (count page objects with `preg_match_all('#/Type\s*/Page[^s]#')`); sheet layout page count 2.
  - `testRenderEmpty`: `render([], IdLayout::Sheet)` starts `%PDF`, 1 page.
  - `testLongNameAndMissingDataStayOnOnePage` (Review Focus 1, 2): one card with a 40-char name, no photo/logo/seal/emergency/address/head/validUntil → card layout still 2 pages; and the rendered HTML (call the Twig template directly via a public `IdCardPdf::html()`) does not contain `—`.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement enum, `IdCardPdf` (`html(array $cards, IdLayout $layout): string` public; `render()` = dompdf on `html()`), and the four templates per the spec's Front/Back sections.
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Visual check** — write `scratch/id-sample.html` from `IdCardPdf::html()` with 3 realistic cards (one with photo, one long name, one without data), screenshot it with `msedge --headless --screenshot=... --window-size=1400,1000`, and also save the PDF; look at the screenshot; adjust spacing until the front and back look clean and nothing overflows. Note any compromises in the ledger.
- [ ] **Step 6: Commit** — `feat: render learner ID cards as PDF`

---

### Task 4: ID photo upload and remove

**Files:**
- Modify: `src/Service/LearnerEnrollment.php` (`updatePhoto`)
- Modify: `src/Controller/AbstractLearnerController.php` (`handlePhoto`, `handlePhotoRemove`; `renderShow` passes `photo_form`)
- Modify: `src/Controller/SchoolLearnerController.php`, `src/Controller/TeacherClassController.php` (routes)
- Modify: `templates/learners/show.html.twig` (ID photo card), `templates/learners/class_list.html.twig` ("No photo" badge), `templates/student/dashboard.html.twig` (photo)
- Test: `tests/Controller/SchoolLearnerControllerTest.php`, `tests/Controller/TeacherClassControllerTest.php`, `tests/Controller/StudentDashboardTest.php`

**Interfaces:**
- `LearnerEnrollment::updatePhoto(Learner $learner, ?File $file): void` — under `LearnerLock::forLearner` + `assertNotBlocked`: with a file → `ImageProcessor::storePhoto($file, old)` and log `Updated ID photo of {lrn}`; null → `ImageProcessor::remove(old)`, set null, log `Removed ID photo of {lrn}`; flush. Throws `ImageProcessingException|PlanLimitException|EnrollmentException`.
- Routes (POST): `school_learners_photo` `/schools/{id}/learners/{learner}/photo`, `school_learners_photo_remove` `…/photo/remove`, `teacher_class_photo` `/teacher/class/{learner}/photo`, `teacher_class_photo_remove` `…/photo/remove`. Upload validated by `ImageUploadFormType` (its own CSRF); remove by `#[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]` like unenroll. Both redirect to the show page with a flash (`Photo updated.` / `Photo removed.` / the error).
- Show page "ID photo" card: the photo (`asset(learner.user.photoPath)`) or a silhouette placeholder, the upload form (button `Upload photo`) and `Remove photo` button, shown when `can.record`.

- [ ] **Step 1: Failing tests** (reuse `ProfileControllerTest`'s `jpegFile()`/upload approach, copied into a small trait or the test classes):
  - `testPrincipalUploadsAndRemovesIdPhoto`: upload → redirect to show; `photoPath` matches `#^uploads/photos/[0-9a-f]{32}\.webp$#`, file is 350×450; activity `Updated ID photo of 111111111111`; `Remove photo` → `photoPath` null, file gone.
  - `testFakeImageKeepsOldPhoto` (Review Focus 4): text file named `photo.jpg` → flash `.alert--error`, `photoPath` unchanged.
  - `testPhotoOfOtherSchoolLearnerRefused` (Review Focus 5): POST to another school's learner → 403/404; `testRemoveWithoutTokenRefused` → 403 (or the IsCsrfTokenValid status), photo kept.
  - `testAdviserUploadsIdPhoto` via `/teacher/class/{id}/photo`.
  - `testClassListShowsNoPhoto`: learner without photo → `No photo` badge; with photo → none.
  - `StudentDashboardTest::testShowsIdPhoto`: learner with `photoPath` set → `img[src*="uploads/photos/"]` exists.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run, then `php bin/phpunit tests/Controller` → PASS.
- [ ] **Step 5: Commit** — `feat: ID photos for learners`

---

### Task 5: ID downloads

**Files:**
- Modify: `src/Controller/AbstractLearnerController.php` (`downloadIds(Section, IdLayout)`, `handleIdDownload(LearnerScope, int, IdLayout)`)
- Modify: `src/Controller/SchoolLearnerController.php`, `src/Controller/TeacherClassController.php`
- Modify: `templates/learners/class_list.html.twig` ("Print IDs" with Paper sheets / ID printer links), `templates/learners/show.html.twig` ("Print ID" links)
- Test: `tests/Controller/SchoolLearnerControllerTest.php`, `tests/Controller/TeacherClassControllerTest.php`

**Interfaces:**
- Consumes: `IdCardBuilder::forSection/forEnrollment`, `IdCardPdf::render`, `IdLayout`.
- Routes (GET): `school_learners_ids` `/schools/{id}/sections/{section}/ids`, `teacher_class_ids` `/teacher/class/ids` (declared before `/{learner}`), `school_learners_id` `/schools/{id}/learners/{learner}/id`, `teacher_class_id` `/teacher/class/{learner}/id`. Layout: `#[MapQueryParameter] string $layout = 'sheet'` → `IdLayout::tryFrom($layout) ?? IdLayout::Sheet`.
- Response like the PDS: `new Response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => HeaderUtils::makeDisposition(attachment, $name, ascii fallback)])`.

- [ ] **Step 1: Failing tests:**
  - `testPrincipalPrintsSectionIds`: class list has links `Paper sheets` and `ID printer`; following `Paper sheets` → 200, `application/pdf`, body `%PDF`, disposition `filename="IDs_2026_Grade 7 - SAMPAGUITA.pdf"`.
  - `testPrintSingleIdAsCard`: show page link `ID printer` → 200, `filename="ID_111111111111.pdf"`.
  - `testBadLayoutFallsBackToSheet`: `?layout=foo` → 200.
  - `testAdminPrintsEmptySection` → 200.
  - `testIdsOfOtherSchoolRefused` (403 other school, 404 foreign section), `testStudentCannotPrintIds` → 403.
  - Teacher: `testAdviserPrintsClassIds` (`/teacher/class/ids`) → 200; no advisory → 403.
- [ ] **Step 2:** Run → FAIL.
- [ ] **Step 3:** Implement handlers, routes, buttons (`data-turbo="false"` on download links).
- [ ] **Step 4:** Run → PASS.
- [ ] **Step 5: Commit** — `feat: print learner IDs for a section or one learner`

---

### Task 6: Whole-branch verification

- [ ] **Step 1:** `php bin/console lint:container`, `lint:twig templates/`, `doctrine:schema:validate` → OK (no schema change expected).
- [ ] **Step 2:** Full suite `php bin/phpunit tests` → all PASS (report count).
- [ ] **Step 3:** Clear cache; `/login` returns 200; generate a real section PDF from the dev DB through the app (logged-in request not possible from the CLI, so render via a scratch script with the builder on a real section) and screenshot page 1 and 2 with Edge headless printing → look for overflow.
- [ ] **Step 4:** Commit any fixes — `chore: learner ID lint and layout fixes`.
