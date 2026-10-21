# Profiles & PDS — Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Principals get "My Profile" (photo + PDS items 1–25, PDF download) and "School Profile" (logo, address, contacts) in their sidebar.

**Architecture:** New `PersonalDataSheet` (1:1 User) and `PdsChild` entities; `ImageProcessor` service re-encodes uploads with GD; `PdsPdfRenderer` renders a Twig replica of CS Form 212 (Revised 2026) with Dompdf. Thin controllers + Symfony Form types, following the existing Principal controllers.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM + migrations, Twig, Symfony Form/Validator, GD, dompdf/dompdf, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-10-16-profiles-pds-design.md`

## Global Constraints

- No git repo: skip every "commit" step; instead run the full suite at the end of each task.
- Run tests with `php bin/phpunit <path>`; MariaDB must be running (see project memory).
- Schema changes only via `php bin/console make:migration` + `doctrine:migrations:migrate` (and `--env=test`).
- Install packages only with `composer require` (Dompdf: `composer require dompdf/dompdf`).
- Phase 1 restricts `/profile` to `ROLE_PRINCIPAL`; teachers come in phase 3 with their layout (the spec's "principal or teacher" lands then).
- Photo: centre-crop 7:9, 350×450 WebP q80. Logo: fit within 400×400, keep transparency, WebP q85. Accept JPEG/PNG/WebP, max 5 MB, MIME detected from content.
- Stored paths are relative to `public/` (e.g. `uploads/photos/<32 hex>.webp`), rendered with `asset()`.
- Upload root is parameter `app.upload_root` = `%kernel.project_dir%/public`, overridden in `when@test` to `%kernel.project_dir%/var/test_public` so tests never touch `public/uploads`.
- Display name rule: `First M. Surname Ext.` — middle initial only if middle name present; extension only if present (e.g. "Juan A. Dela Cruz Jr.").
- Empty optional PDS values print as `N/A` in the PDF.
- Mobile `^09\d{9}$`; ZIP `^\d{4}$`; height 0.50–2.50 m; weight 10–300 kg; blood types A+, A-, B+, B-, AB+, AB-, O+, O-.
- Principal sidebar order: Dashboard · Teachers · School Profile · My Profile.
- Activity log messages: `Updated own profile`, `Changed profile photo`, `Removed profile photo`, `Updated school profile <School name>`.

## Review Focus

1. Re-submitting the Personal form with `permSameAsResidential` checked after editing the residential address must overwrite the permanent address again (not keep stale values) — test in Task 4.
2. A principal whose assignment ended (no current-SY assignment) opening `/principal/school` gets the not-assigned page, not a 500 — test in Task 3.
3. Uploading a file renamed `.jpg` that is really a PDF/text is rejected with a form error and the old photo stays — test in Task 5.
4. Opening the PDF before ever saving the PDS (no PersonalDataSheet row yet) still returns a valid PDF — test in Task 6.
5. Removing a child row and saving deletes it from the DB (orphanRemoval), and an empty children list is valid — test in Task 4b.

---

### Task 1: Entities, enums, migration

**Files:**
- Create: `src/Entity/PersonalDataSheet.php`, `src/Entity/PdsChild.php`, `src/Entity/SexAtBirth.php`, `src/Entity/CivilStatus.php`, `src/Entity/Citizenship.php`, `src/Entity/DualCitizenshipBy.php`, `src/Repository/PersonalDataSheetRepository.php`, `migrations/Version<generated>.php`
- Modify: `src/Entity/User.php` (add `photoPath`), `src/Entity/School.php` (add `logoPath`, `contactNumber`, `email`, `facebookUrl`)
- Test: `tests/Entity/PersonalDataSheetTest.php`

**Interfaces:**
- Produces:
  - Backed string enums: `SexAtBirth {MALE,FEMALE}`, `CivilStatus {SINGLE,MARRIED,WIDOWED,SEPARATED,OTHERS}`, `Citizenship {FILIPINO,DUAL}`, `DualCitizenshipBy {BIRTH,NATURALIZATION}`; each with `label(): string` ("Male", "Widowed", "Other/s", "by birth", …).
  - `PersonalDataSheet`: `__construct(User $user)`; getters/setters for every spec field (names exactly as spec); `getChildren(): Collection<PdsChild>`, `addChild(PdsChild)`, `removeChild(PdsChild)`; `displayName(): ?string` (null if surname or firstName empty); `syncPermanentAddress(): void` (copies `res*`→`perm*` when `permSameAsResidential`); `touch(): void` sets `updatedAt`. Validation constraints (`#[Assert\...]`) on properties per spec, including `#[Assert\Callback]` for conditional `civilStatusOther` and dual-citizenship fields.
  - `PdsChild`: `fullName`, `dateOfBirth`, `position`, `sheet` (ManyToOne, `inversedBy: children`; parent side `orphanRemoval: true`, `cascade: ['persist']`, `OrderBy(['position' => 'ASC'])`).
  - `PersonalDataSheetRepository::findOrCreateFor(User $user): PersonalDataSheet` (persists but does not flush a new one).
  - `User::getPhotoPath()/setPhotoPath(?string)`; `School::getLogoPath()/setLogoPath()`, `getContactNumber()/set…`, `getEmail()/set…`, `getFacebookUrl()/set…` with `#[Assert\Email]` on email and `#[Assert\Url]` on facebookUrl.

- [ ] **Step 1: Write failing tests** (`KernelTestCase`, pure object tests, no DB):
  - `testDisplayNameFormatsInitialAndExtension`: surname "Dela Cruz", first "Juan", middle "Abad", ext "Jr." → `"Juan A. Dela Cruz Jr."`; no middle/ext → `"Juan Dela Cruz"`; surname empty → `null`.
  - `testSyncPermanentAddressCopiesWhenSame`: set res fields, flag true, call sync → all 7 perm fields equal res; flag false → perm unchanged.
  - `testValidationRules` (use `validator` service): future `dateOfBirth`, mobile `"9171234567"`, ZIP `"60140"`, OTHERS with empty `civilStatusOther`, DUAL with empty country each yield a violation on the matching property path; a fully valid sheet yields 0.
- [ ] **Step 2: Run** `php bin/phpunit tests/Entity/PersonalDataSheetTest.php` → FAIL (class not found).
- [ ] **Step 3: Implement entities, enums, repository, User/School fields.**
- [ ] **Step 4: Generate & run migration:** `php bin/console make:migration --no-interaction`, review SQL (new tables `personal_data_sheet` with unique `user_id`, `pds_child`; nullable columns on `user`/`school`), then `php bin/console doctrine:migrations:migrate -n` and `--env=test`.
- [ ] **Step 5: Run** the test file → PASS; `php bin/console doctrine:schema:validate` → mapping OK, in sync.

### Task 2: ImageProcessor

**Files:**
- Create: `src/Service/ImageProcessor.php`, `src/Service/ImageProcessingException.php`
- Modify: `config/services.yaml` (parameter `app.upload_root` + `when@test` override)
- Test: `tests/Service/ImageProcessorTest.php`

**Interfaces:**
- Produces:
  - `ImageProcessor::__construct(#[Autowire('%app.upload_root%')] string $uploadRoot, Filesystem $fs)`
  - `storePhoto(UploadedFile|File $file, ?string $oldPath): string` → returns `uploads/photos/<hex>.webp`, deletes old.
  - `storeLogo(UploadedFile|File $file, ?string $oldPath): string` → `uploads/logos/<hex>.webp`.
  - `remove(?string $path): void`
  - `absolutePath(string $path): string`
  - Throws `ImageProcessingException` when GD cannot decode.

- [ ] **Step 1: Failing tests** (generate fixtures with GD into a temp dir):
  - `testPhotoIsCroppedTo350x450Webp`: 1000×600 JPEG → stored file exists under upload root, `getimagesize` = 350×450, MIME `image/webp`.
  - `testLogoKeepsAspectWithin400`: 800×200 PNG with alpha → 400×100 WebP, a transparent corner pixel stays transparent (`imagecolorat` alpha 127).
  - `testReplacingDeletesOldFile` and `testRemoveDeletesFile`.
  - `testUndecodableFileThrows`: text file → `ImageProcessingException`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** with `imagecreatefromstring`, `imagecopyresampled`, `imagewebp`; for logos call `imagealphablending(false)` + `imagesavealpha(true)` on the target.
- [ ] **Step 4: Run** → PASS.

### Task 3: School Profile page

**Files:**
- Create: `src/Controller/Principal/SchoolProfileController.php`, `src/Form/SchoolProfileFormType.php`, `templates/principal/school_profile.html.twig`
- Modify: `templates/principal/_layout.html.twig` (nav link "School Profile" → `principal_school`), `tests/Controller/Principal/PrincipalAreaTest.php` (expected nav list becomes `['Dashboard', 'Teachers', 'School Profile']`)
- Test: `tests/Controller/Principal/SchoolProfileTest.php` (extends `AdminTestCase`, setup like `PrincipalAreaTest`)

**Interfaces:**
- Consumes: `ImageProcessor::storeLogo`, `School` new fields, `ActivityLogger::log(string)`.
- Produces: route `principal_school` (`GET|POST /principal/school`). Form fields: `address`, `contactNumber`, `email`, `facebookUrl`, `logo` (unmapped `FileType` with `Image` constraint mimeTypes jpeg/png/webp, maxSize 5M). Read-only display of School ID, name, region, division, district.

The "My Profile" nav link is added in Task 4 (its route doesn't exist yet), so in this task the expected nav is `['Dashboard', 'Teachers', 'School Profile']`.

- [ ] **Step 1: Failing tests:**
  - `testShowsLockedAndEditableFields`: GET 200; page shows "303456" and "Pagsabungan NHS"; form has no `name`/`schoolId` inputs.
  - `testSavesEditableFields`: submit address "Pagsabungan, Mandaue City", contact "032-123-4567", email "pnhs@deped.gov.ph", fb "https://facebook.com/pnhs" → redirect to `/principal/school`, values persisted, activity log contains `Updated school profile Pagsabungan NHS`.
  - `testTamperedLockedFieldsIgnored`: POST raw form data including `school_profile_form[name]=Hacked` → name unchanged (extra-field error or ignored, either way not saved).
  - `testLogoUpload`: upload generated PNG → `logoPath` set, file exists under test upload root.
  - `testInvalidEmailShowsError`.
  - `testNotAssignedPrincipalSeesNotAssignedPage` (Review Focus 2).
  - `testTeacherForbidden`: teacher → 403.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** controller (`#[IsGranted(User::ROLE_PRINCIPAL)]`, resolves school like `DashboardController::ownSchool`, then `denyAccessUnlessGranted(SchoolVoter::MANAGE, $school)`), form, template (logo preview via `asset()`), nav link.
- [ ] **Step 4: Run** test file + `PrincipalAreaTest` → PASS.

### Task 4: My Profile overview + Personal Information

**Files:**
- Create: `src/Controller/ProfileController.php`, `src/Form/PersonalInfoFormType.php`, `templates/profile/_layout.html.twig` (extends principal layout; photo card + tabs: Personal Information, Family Background, plus disabled tabs for phase 2/3 sections), `templates/profile/show.html.twig`, `templates/profile/personal.html.twig`
- Modify: `templates/principal/_layout.html.twig` (add "My Profile" link, active for routes starting `profile_`), `PrincipalAreaTest` nav expectation → full 4-item list
- Test: `tests/Controller/ProfileControllerTest.php`

**Interfaces:**
- Consumes: `PersonalDataSheetRepository::findOrCreateFor`, `PersonalDataSheet::displayName/syncPermanentAddress/touch`.
- Produces: `ProfileController` (`#[IsGranted(User::ROLE_PRINCIPAL)]`, `#[Route('/profile')]`) with `show` (`profile_show`, GET) and `personal` (`profile_personal`, GET|POST). On valid save: `syncPermanentAddress()`, `touch()`, `user->setFullName(displayName())` when non-null, flush, log `Updated own profile`, flash "Personal information saved.", redirect to `profile_personal`. Address fields are grouped in the template under "17. Residential Address" / "18. Permanent Address" with item numbers in labels (e.g. "1. Surname").

- [ ] **Step 1: Failing tests:**
  - `testShowCreatesSheetLazilyAndShowsTabs`.
  - `testSavePersonalInfoPersistsAndRenamesUser`: fill required + a few optional fields → redirect; sheet values persisted; `findUser('maria')->getFullName()` = "Maria C. Santos".
  - `testSameAsResidentialResyncsOnEverySave` (Review Focus 1): save with flag + res city "Mandaue City"; change res city to "Cebu City", save → perm city "Cebu City".
  - `testValidationErrorsShownInline`: empty surname + mobile "123" → 422, both errors rendered, nothing saved.
  - `testTeacherAndAnonymousBlocked`: teacher 403; anonymous redirect to `/login`.
  - `PrincipalAreaTest::testSharedPageUsesPrincipalLayout` expects `['Dashboard', 'Teachers', 'School Profile', 'My Profile']`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.** Form uses `EnumType` for enums (expanded radios for sex, civil status, citizenship), `ChoiceType` for blood type, `DateType` single_text for DOB.
- [ ] **Step 4: Run** → PASS.

### Task 4b: Family Background

**Files:**
- Create: `src/Form/FamilyBackgroundFormType.php`, `src/Form/PdsChildFormType.php`, `templates/profile/family.html.twig`, `assets/controllers/collection_controller.js` (Stimulus add/remove rows — check `assets/` for Stimulus first; if absent, a small inline script in the template)
- Modify: `src/Controller/ProfileController.php` (add `family`)
- Test: `tests/Controller/ProfileControllerTest.php`

**Interfaces:**
- Produces: route `profile_family` (GET|POST). `children` is a `CollectionType` (`allow_add`, `allow_delete`, `by_reference: false`, `entry_type: PdsChildFormType`); on save, positions are renumbered 0..n-1 in submitted order. Flash "Family background saved."

- [ ] **Step 1: Failing tests:**
  - `testSaveSpouseParentsAndChildren`: two children submitted → 2 `PdsChild` rows in order.
  - `testRemovingChildDeletesRow` + empty list valid (Review Focus 5).
  - `testChildNameRequiredAndBirthDateNotFuture`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 5: Profile photo upload/remove

**Files:**
- Create: `src/Form/ImageUploadFormType.php`
- Modify: `ProfileController` (`photo`, `photoRemove`), `templates/profile/_layout.html.twig` (photo card with upload form + remove button with CSRF token `remove-photo`), `templates/principal/_layout.html.twig` (sidebar avatar shows photo when set, else initials)
- Test: `tests/Controller/ProfileControllerTest.php`

**Interfaces:**
- Consumes: `ImageProcessor::storePhoto/remove`.
- Produces: `profile_photo` (POST /profile/photo), `profile_photo_remove` (POST /profile/photo/remove). Both redirect to the referring profile tab (fallback `profile_show`). Field `image` with `Image` constraint (jpeg/png/webp, 5M). Flash "Photo updated." / "Photo removed."; errors as flash `error` with the constraint message.

- [ ] **Step 1: Failing tests:**
  - `testUploadPhotoStores350x450`.
  - `testFakeJpgRejectedAndOldPhotoKept` (Review Focus 3): upload text content named `x.jpg` after a valid upload → error flash, `photoPath` unchanged, old file still exists.
  - `testRemovePhotoRequiresCsrfAndDeletesFile`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.

### Task 6: PDS PDF

**Files:**
- Run: `composer require dompdf/dompdf`
- Create: `src/Service/PdsPdfRenderer.php`, `templates/profile/pdf/pds.html.twig` (+ partials `_page1.html.twig` … `_page4.html.twig`, `_continuation_children.html.twig`)
- Modify: `ProfileController` (`pdf`), `templates/profile/_layout.html.twig` ("Download PDF" button)
- Test: `tests/Service/PdsPdfRendererTest.php`, `tests/Controller/ProfileControllerTest.php`

**Interfaces:**
- Consumes: `PersonalDataSheet`, `ImageProcessor::absolutePath`.
- Produces: `PdsPdfRenderer::render(User $user, PersonalDataSheet $sheet): string` (PDF bytes, A4 portrait, Dompdf `isRemoteEnabled=false`, photo embedded as `data:image/webp;base64,` — if Dompdf can't render WebP, convert to PNG in memory via GD); `PdsPdfRenderer::filename(PersonalDataSheet $sheet): string` → `PDS_DELA-CRUZ_JUAN.pdf` (uppercase, non-alphanumerics → `-`, fallback `PDS.pdf`). Route `profile_pdf` (GET /profile/pds.pdf) responds with `Content-Type: application/pdf` and `Content-Disposition: attachment; filename=…`.
- Layout: four pages mirroring sheets C1–C4 — section bars (I–VIII, items 34–42), item numbers and labels exactly as the xlsx, checkboxes as ☐/☒ for sex, civil status, citizenship; footer "CS FORM 212 (Revised 2026), Page n of 4"; phase-2/3 sections render their empty grids; signature/thumbmark/oath boxes blank. Children overflow → C7-style continuation page titled "II. FAMILY BACKGROUND (continuation)".

- [ ] **Step 1: Failing tests:**
  - `PdsPdfRendererTest::testRendersPdfWithName`: render → starts with `%PDF`; text extraction not required — instead assert the intermediate HTML (expose `renderHtml(): string`) contains "DELA CRUZ", "Page 1 of 4", "Page 4 of 4", and `N/A` for empty TIN.
  - `testFilenameSanitized`.
  - `testChildrenOverflowAddsContinuationPage`: 20 children → HTML contains "continuation".
  - `ProfileControllerTest::testPdfDownloadBeforeAnySave` (Review Focus 4): fresh principal → 200, `application/pdf`, body starts `%PDF`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Visual check:** generate a sample PDF for a filled profile into the scratchpad and compare page 1 against the xlsx layout (render PDF page to PNG with headless Edge and view it).

### Task 7: Final verification

- [ ] `php bin/console lint:container`, `lint:twig templates/`, `doctrine:schema:validate`.
- [ ] Full suite `php bin/phpunit` → all green (153 existing + new).
- [ ] Manual smoke as principal in the running app at http://localhost:8081: School Profile save + logo, My Profile save, photo, PDF download.
- [ ] Update memory (`schools-roadmap`) with phase-1 done and phase 2 next.
