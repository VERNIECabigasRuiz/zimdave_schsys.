# Service Record Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The principal keeps each employee's DepEd service record (rows of service) for their teachers and themselves, and prints it as a long-bond PDF; the admin can only download it.

**Architecture:** Two new Doctrine entities (`ServiceRecordEntry` rows and a `ServiceRecord` header holding the BP number) owned by the employee `User`, four signatory columns on `School`, a `ServiceRecordVoter` on `School`, one `ServiceRecordController` under `/schools/{id}/service-records/{employee}`, and a dompdf renderer like `PdsPdfRenderer`.

**Tech Stack:** Symfony 8.1, PHP 8.5, Doctrine ORM + migrations, Symfony Form/Validator, Twig, dompdf, PHPUnit 13 (`php bin/phpunit`).

**Spec:** `docs/superpowers/specs/2026-11-02-service-record-design.md`

## Global Constraints

- Only a principal edits; they edit people assigned to **their school this S.Y.** (themselves included). Admin: PDF only, never changes. Teachers: no access at all (403), own record included.
- The record belongs to the person (`User`), not the school; a transfer keeps every row.
- At most one open row (`toDate` null = "Present"); rows never overlap; `fromDate ≤ toDate`; LWOP from/to both or neither, from ≤ to.
- Status suggestions, in this order: `Regular/Permanent`, `Provisional`, `Substitute`, `Contractual`, `Casual`, `Temporary` (free text allowed). Branch: `National` or `Local`.
- PDF: portrait **8.5 × 13 in** (`[0, 0, 612, 936]` points), file name `Service record - SURNAME, GIVEN.pdf`, dates `m/d/Y`, salary `number_format(2)`, open row "Present", then `x-x-x NOTHING FOLLOWS x-x-x`.
- "Certified correct" falls back to the school's principal this S.Y. with designation `School Principal`.
- Schema only through `php bin/console make:migration` + `doctrine:migrations:migrate`. Never run two `php bin/phpunit` at once (shared test DB).
- Style: attributes for routes/validation/voters, constructor promotion, thin controllers, `#[IsCsrfTokenValid]` on POST actions that aren't Symfony forms.

## Review Focus

- **Closing the open row onto a date before its own start** (new row starts on or before the open row's `fromDate`): must be a form error, nothing saved → test in Task 4.
- **Editing a row into overlap with a neighbour** (not only adding): rejected → test in Task 1 (validator excludes the row itself, catches the others).
- **A teacher who transferred in this S.Y.**: new principal sees and edits the rows the old school entered; old principal gets 404/403 → test in Task 3.
- **Person with no PDS or a half-filled one**: page warns, PDF still renders with blank lines → test in Task 5.
- **Entry id from another person in the URL** (`/service-records/{A}/rows/{entryOfB}/edit`): 404, not an edit of B's row → test in Task 4.

---

### Task 1: Entities, school signatories, validation, migration

**Files:**
- Create: `src/Entity/ServiceRecordEntry.php`, `src/Repository/ServiceRecordEntryRepository.php`
- Create: `src/Entity/ServiceRecord.php`, `src/Repository/ServiceRecordRepository.php`
- Create: `src/Validator/ValidServiceRow.php`, `src/Validator/ValidServiceRowValidator.php`
- Modify: `src/Entity/School.php` (four columns after `sf8CertifierPosition`, same `PROFILE` group pattern, `blankToNull` setters)
- Modify: `tests/SchoolFixturesTrait.php` (`clearSchoolData()`: add `ServiceRecordEntry::class, ServiceRecord::class` before `SchoolAssignment::class`)
- Create: `migrations/Version<generated>.php` (via make:migration)
- Test: `tests/ServiceRecord/ServiceRecordEntryTest.php` (KernelTestCase + `SchoolFixturesTrait`)

**Interfaces:**
- Produces:
  - `ServiceRecordEntry::__construct(User $employee, \DateTimeImmutable $fromDate, User $updatedBy, \DateTimeImmutable $updatedAt)`; getters/fluent setters for `fromDate`, `toDate` (?), `designation`, `status`, `annualSalary` (string decimal, as Doctrine returns it), `station`, `branch`, `lwopFrom`, `lwopTo`, `separationDate`, `separationCause`, `remarks`; `getEmployee()`, `getUpdatedBy(): ?User`, `getUpdatedAt()`, `touch(User $by, \DateTimeImmutable $at): static`, `isOpen(): bool`, and the unmapped `public bool $closeOpen = false` (meaning and validator rule in Task 4's Interfaces; implement both here, Task 4 only uses them).
  - Constants `ServiceRecordEntry::STATUSES` (list above) and `ServiceRecordEntry::BRANCHES = ['National', 'Local']`.
  - `ServiceRecordEntryRepository::findForEmployee(User): list<ServiceRecordEntry>` (by `fromDate` ASC), `findCurrent(User): ?ServiceRecordEntry` (the open row).
  - `ServiceRecord::__construct(User $employee)`, `getBpNumber(): ?string`, `setBpNumber(?string)` (blank → null, max 30); `ServiceRecordRepository::findOrNew(User): ServiceRecord` (not persisted when new).
  - `School::getSrPreparedByName()/setSrPreparedByName()`, `…PreparedByDesignation`, `…CertifiedByName`, `…CertifiedByDesignation` (all `?string`, length 100).

Mapping notes: `employee` ManyToOne `onDelete: 'CASCADE'`; `updatedBy` ManyToOne nullable `onDelete: 'SET NULL'`; `annualSalary` `Types::DECIMAL, precision: 12, scale: 2`; `ServiceRecord.employee` OneToOne unique, cascade delete. Field lengths per spec table. `#[Assert\NotBlank]` on designation, status, station, branch; `#[Assert\Choice(ServiceRecordEntry::BRANCHES)]` on branch; `#[Assert\PositiveOrZero]` on salary; class-level `#[ValidServiceRow]`.

`ValidServiceRowValidator` (autowired with `ServiceRecordEntryRepository`) compares in PHP against `findForEmployee()` minus the row itself (identity or same id) so rows changed in memory but not flushed count with their new dates. Messages (exact, on the `fromDate` path unless noted):
- `toDate`: `The end date is before the start date.`
- `lwopTo`: `Give both leave without pay dates, the end not before the start.`
- `This service overlaps the row from {{ from }}.` (`m/d/Y`)
- `toDate`: `There is already a row up to the present. Close it first.`

- [ ] **Step 1: Write the failing tests** in `ServiceRecordEntryTest` — build rows with a helper `row(string $from, ?string $to, ?ServiceRecordEntry $persisted = true)` and validate with the container's `ValidatorInterface`:
  - `testValidRowHasNoViolations`
  - `testEndBeforeStart` → one violation, message `The end date is before the start date.`
  - `testOverlapWithAnotherRow` — persisted `01/01/2020–12/31/2020`, new `06/01/2020–06/30/2021` → `This service overlaps the row from 01/01/2020.`
  - `testOpenRowOverlapsEverythingAfterIt` — persisted open from `07/20/2026`, new `01/01/2027–` closed → overlap message.
  - `testSecondOpenRow` — persisted open, new open later → `There is already a row up to the present. Close it first.` (and no overlap message duplicated: assert count 1 on the `toDate` path).
  - `testEditingARowIgnoresItself` — persisted row re-validated after changing its remarks → no violations; then move its `fromDate` into a neighbour → overlap.
  - `testLwopNeedsBothDates`
  - `testCloseOpenLetsALaterRowIn` — persisted open from `01/01/2026`; new open row from `07/20/2026` with `closeOpen = true` → no violations; same with `fromDate` `01/01/2026` → overlap message.
  - `testFindCurrentReturnsTheOpenRow` and `testFindForEmployeeIsOldestFirst`.
- [ ] **Step 2: Run** `php bin/phpunit tests/ServiceRecord/ServiceRecordEntryTest.php` — expected: errors, classes not found.
- [ ] **Step 3: Implement** the entities, repositories, constraint/validator and School columns above; add both classes to `clearSchoolData()`.
- [ ] **Step 4: Migrate:** `php bin/console make:migration --no-interaction`, check the SQL is only the two new tables + four School columns, set `getDescription()` to `Service records: rows, BP number, school signatories`, then `php bin/console doctrine:migrations:migrate --no-interaction` and the same with `--env=test`.
- [ ] **Step 5: Run** the test file — expected: PASS. Then `php bin/console doctrine:schema:validate` — expected: mapping and database OK.
- [ ] **Step 6: Commit** — `feat: service record entities, school signatories, row validation`.

---

### Task 2: Signatories on the school profile

**Files:**
- Modify: `src/Form/SchoolProfileFormType.php` (four `TextType` fields after `sf8CertifierPosition`, `required: false`, labels `Prepared by — name`, `Prepared by — designation`, `Certified correct — name`, `Certified correct — designation`, help on the name: `Left empty, the principal signs.`)
- Modify: `templates/principal/school_profile.html.twig` (a "Service record signatories" block like the SF8 one at lines ~45–48)
- Test: `tests/Controller/Principal/SchoolProfileTest.php` (existing)

**Interfaces:**
- Consumes: Task 1 School getters/setters.

- [ ] **Step 1: Failing test** `testSavesServiceRecordSignatories`: submit the profile form with `school_profile_form[srPreparedByName]` = `JANET T. BIHAG`, `…Designation` = `Teacher III`, certified name `EDGAR C. ESPINA JR., DevEdD`, designation `Administrative Officer V`; reload the School and assert all four; then submit blanks and assert null.
- [ ] **Step 2: Run** it — expected FAIL (unknown field).
- [ ] **Step 3: Implement** the form fields and template block.
- [ ] **Step 4: Run** it — PASS.
- [ ] **Step 5: Commit** — `feat: service record signatories on the school profile`.

---

### Task 3: Access and the service record page (header + BP number)

**Files:**
- Create: `src/Security/Voter/ServiceRecordVoter.php`
- Create: `src/Controller/ServiceRecordController.php`
- Create: `templates/service_record/show.html.twig`
- Test: `tests/Controller/ServiceRecordControllerTest.php` (extends `App\Tests\Controller\Admin\AdminTestCase`, `ClockSensitiveTrait`, `mockTime('2026-11-02 10:00:00')`, S.Y. 2026 current, school `Pagsabungan NHS`, principal `rosa`, teacher `tess`; `tearDown` → `clearSchoolData()`)

**Interfaces:**
- Consumes: Task 1 repositories; `SchoolAssignmentRepository::findForUser(User, SchoolYear): ?SchoolAssignment`, `findSchoolOf(...)`; `PersonalDataSheetRepository::findOneBy(['user' => …])`; `SchoolYearRepository::findCurrent()`.
- Produces:
  - `ServiceRecordVoter::VIEW = 'SERVICE_RECORD_VIEW'`, `::EDIT = 'SERVICE_RECORD_EDIT'`, subject `School`: VIEW = admin or the school's principal this S.Y.; EDIT = the school's principal this S.Y. only. (Subject is the School; the controller checks the employee belongs to it — deviation from the spec's "subject = User", same rule.)
  - Controller class route `#[Route('/schools/{id}/service-records/{employee}', requirements: ['id' => '\d+', 'employee' => '\d+', 'entry' => '\d+'])]`, private `requireEmployee(School $school, int $employee): User` → 404 unless `findForUser($user, $currentYear)?->getSchool()` is this school (any role).
  - Routes: `service_record_show` GET `''` (EDIT); `service_record_bp` POST `/bp` (EDIT, `#[IsCsrfTokenValid('service-record-bp', tokenKey: '_token')]`).
  - `principal_service_record` GET `/principal/service-record` (in `Principal\DashboardController`, `ROLE_PRINCIPAL`) → redirect to `service_record_show` for themselves; 404 when not assigned.
  - Template vars: `school`, `employee`, `sheet` (?PersonalDataSheet), `missing` (list of labels among `Surname`, `First name`, `Date of birth`, `Place of birth`, `Agency employee no.`), `record` (ServiceRecord), `rows`, `can_edit`.

- [ ] **Step 1: Failing tests:**
  - `testPrincipalOpensATeachersRecord` — 200; `h1` contains `Service record`; header shows PDS name and `Agency employee no.` value set in the fixture PDS.
  - `testMissingPdsDataIsListed` — teacher without PDS → `.alert--warning` contains `Surname` and `Date of birth`.
  - `testPrincipalOpensOwnRecordFromTheMenu` — GET `/principal/service-record` redirects to `/schools/{id}/service-records/{rosa}`.
  - `testSavesBpNumber` — POST `/bp` with `bp_number=2003334189` + token → redirect; repository holds it; blank clears it.
  - `testAdminGets403OnThePageAndBp`.
  - `testTeacherGets403OnOwnRecord`.
  - `testOtherSchoolsPrincipalGets403` and `testPersonOfAnotherSchoolIs404` (rosa asks for a teacher assigned elsewhere).
  - `testTransferredTeacherFollowsTheNewSchool` — tess has a row; her S.Y. assignment is moved to school B; B's principal sees the row; rosa gets 404.
- [ ] **Step 2: Run** — FAIL (no route).
- [ ] **Step 3: Implement** voter, controller actions, menu route, template (PDS header, warning, BP form, rows table read-only for now, "Download PDF" placeholder link omitted until Task 5). Principal menu: add `{{ _self.nav_link('My Service Record', path('principal_service_record'), route starts with 'service_record') }}` after My Profile in `templates/principal/_layout.html.twig`.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat: service record page with access rules and BP number`.

---

### Task 4: Add, edit, delete rows

**Files:**
- Create: `src/Form/ServiceRecordEntryFormType.php`
- Create: `src/ServiceRecord/ServiceRecordKeeper.php`
- Modify: `src/Controller/ServiceRecordController.php`, `templates/service_record/show.html.twig`
- Create: `templates/service_record/row_form.html.twig`
- Test: `tests/Controller/ServiceRecordControllerTest.php`

**Interfaces:**
- Consumes: Task 1 entity/repo; Task 3 voter, `requireEmployee()`.
- Produces:
  - `ServiceRecordKeeper::draft(User $employee, School $school): ServiceRecordEntry` — pre-filled from `findForEmployee()`'s last row: same designation, status, station, branch; `fromDate` = its `toDate + 1 day`, or today when it is open or there is no row. No row: station = the city part of `School::getAddress()` after its last comma (trimmed), else empty; branch `National`.
  - `ServiceRecordEntry::$closeOpen` — a public, **not ORM-mapped** `bool` (default `false`) added to the Task 1 entity: "close the person's open row on the day before my fromDate when saving". `ValidServiceRowValidator` honours it: when set and another row is open and starts **before** this row's `fromDate`, that open row counts as ending on `fromDate − 1 day` (so no overlap / second-open error); when the open row starts on or after `fromDate`, the normal overlap error stays. One validation pass, inside `$form->isValid()`.
  - `ServiceRecordKeeper::save(ServiceRecordEntry $entry, User $by): void` — if `$entry->closeOpen`, sets the open row's `toDate` to `fromDate − 1 day` and `touch()`es it; `touch()`es the entry; persist + flush.
  - Form `ServiceRecordEntryFormType` (data_class `ServiceRecordEntry`): `fromDate`, `toDate` (DateType `widget: single_text`, `input: datetime_immutable`), `designation`, `status` (TextType with `attr: {list: 'sr-statuses'}`), `annualSalary` (NumberType scale 2), `station`, `branch` (ChoiceType of `BRANCHES`), `lwopFrom`, `lwopTo`, `separationDate`, `separationCause`, `remarks`; option `can_close_open` (bool, true on "new" when the person has an open row) adds `closeOpen` CheckboxType (`required: false`), label `Close the current row on the day before`; the controller sets `$entry->closeOpen = true` on the draft so it starts ticked.
  - Routes: `service_record_row_new` GET|POST `/rows/new`; `service_record_row_edit` GET|POST `/rows/{entry}/edit`; `service_record_row_delete` POST `/rows/{entry}/delete` (`#[IsCsrfTokenValid(new Expression('"service-row-" ~ args["entry"]'), tokenKey: '_token')]`). All EDIT; an entry whose employee isn't `{employee}` → 404. Flashes: `Row added.`, `Row saved.`, `Row deleted.`.

- [ ] **Step 1: Failing tests:**
  - `testAddFirstRowPrefillsStationFromTheSchool` — school address `Z. Estreras, Pagsabungan, Mandaue City`; GET new → `fromDate` = `2026-11-02`, station `Mandaue City`, branch `National`.
  - `testAddRowPrefillsFromTheLastRow` — last row `01/01/2025–12/31/2025 Teacher III Regular/Permanent 420,588.00` → form shows designation `Teacher III`, `fromDate` `2026-01-01`.
  - `testAddingAfterAnOpenRowClosesIt` — open row from `01/01/2026`; post new from `07/20/2026` salary `443028` with `closeOpen` ticked → two rows; the first ends `2026-07-19`; second open.
  - `testUntickedCloseKeepsTheErrorAndSavesNothing` — same post with `closeOpen` unticked → 422, `There is already a row up to the present. Close it first.`, still one row.
  - `testClosingOntoAnEarlierDateIsRefused` — open row from `07/20/2026`, new row from `07/01/2026` ticked → 422 with the overlap message, nothing changed.
  - `testEditRow` — change salary/remarks → saved, `updatedBy` = rosa, page shows `Last changed by Rosa Cruz`.
  - `testEditIntoOverlapIsRefused`.
  - `testDeleteRow` (with token) → gone; bad token → nothing deleted.
  - `testEntryOfAnotherPersonIs404` — edit and delete.
  - `testPrincipalEditsOwnRecord`.
  - `testAdminCannotAddEditOrDelete` — 403 on all three.
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** keeper, form, routes and templates (show page: Add row button, per-row Edit and Delete with `data-turbo-confirm="Delete the row from {{ row.fromDate|date('m/d/Y') }}?"`; `<datalist id="sr-statuses">`).
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** — `feat: add, edit and delete service record rows`.

---

### Task 5: The PDF

**Files:**
- Create: `src/ServiceRecord/ServiceRecordPdf.php`
- Create: `templates/service_record/pdf.html.twig`
- Modify: `src/Controller/ServiceRecordController.php`, `templates/service_record/show.html.twig` (Download PDF button, `data-turbo="false"`)
- Test: `tests/ServiceRecord/ServiceRecordPdfTest.php` (KernelTestCase) and `tests/Controller/ServiceRecordControllerTest.php`

**Interfaces:**
- Consumes: Tasks 1, 3; `SchoolAssignmentRepository::findPrincipal(School, SchoolYear): ?SchoolAssignment`; `assets/images/deped-seal.png` (embed as data URI, as `PdsPdfRenderer` does with photos; remote off).
- Produces:
  - `ServiceRecordPdf::render(User $employee, School $school): string` (bytes), `::html(User $employee, School $school): string`, `::filename(User $employee): string` → `Service record - DELA CRUZ, JUAN.pdf` from the PDS surname/first name uppercased; `Service record.pdf` when both are empty.
  - Paper: `$dompdf->setPaper([0, 0, 612, 936])`.
  - Route `service_record_pdf` GET `/service-record.pdf` (VIEW — admin allowed), `Content-Type: application/pdf`, attachment disposition with `filename()`.
- Layout and copy: exactly spec § PDF items 1–8 (copy the quoted sentences verbatim). Table `thead` repeats on each page (`display: table-header-group`). Signatories: prepared-by from school settings (blank lines when empty); certified-correct name/designation from settings, else the principal's full name and `School Principal`; caption `(Chief or Head of Office)`; date = today `F j, Y`.

- [ ] **Step 1: Failing tests** (`ServiceRecordPdfTest`, on `html()`):
  - `testRowsAndNothingFollows` — two rows; HTML contains `07/20/2026`, `Present`, `443,028.00`, `SG13-4 NBC-601`, `NOTHING FOLLOWS`, the EO 54 sentence.
  - `testHeaderFromPdsAndBpNumber` — surname, given, middle, birth date `April 25, 1991`-style (`F j, Y`), birthplace, employee no., BP number.
  - `testSignatoriesFromSettings` and `testCertifiedCorrectFallsBackToThePrincipal` (`School Principal`).
  - `testNoPdsStillRenders` — empty PDS: no exception, `NOTHING FOLLOWS` present.
  - `testFilename` — `Service record - DELA CRUZ, JUAN.pdf`.
  - Controller: `testDownloadPdf` (principal, `application/pdf`, disposition filename), `testAdminDownloadsPdf`, `testTeacherCannotDownloadOwnPdf` (403).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** renderer, template, route, button.
- [ ] **Step 4: Run** — PASS. Also render one PDF for a visual check: save `render()` output to the scratchpad and read it; check the table fits the width and headings repeat on page 2 with 40 rows.
- [ ] **Step 5: Commit** — `feat: service record PDF on long bond`.

---

### Task 6: Links for the principal and the admin

**Files:**
- Modify: `templates/school_teachers/index.html.twig` (after the PDS link, line ~78): `Service record` → `service_record_show` when `is_granted('SERVICE_RECORD_EDIT', school)`, else `Service record (PDF)` → `service_record_pdf` with `data-turbo="false"`
- Modify: `templates/admin/schools/index.html.twig` (principal cell, ~line 59+): `Service record (PDF)` for the assigned principal
- Test: `tests/Controller/ServiceRecordControllerTest.php`

- [ ] **Step 1: Failing tests:** `testTeachersPageLinksToTheRecord` (principal sees `a[href=".../service-records/{tess}"]`), `testAdminTeachersPageLinksToThePdf`, `testAdminSchoolListLinksToThePrincipalsPdf`, `testMenuHasMyServiceRecord` (principal sidebar link, active on the page).
- [ ] **Step 2: Run** — FAIL.
- [ ] **Step 3: Implement** the links.
- [ ] **Step 4: Run** the whole suite once: `php bin/phpunit` — expected `OK`. Then `php bin/console lint:container` and `php bin/console lint:twig templates/`.
- [ ] **Step 5: Commit** — `feat: service record links for principal and admin`.
