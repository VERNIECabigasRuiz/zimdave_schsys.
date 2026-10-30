# SF8 — Learner's Basic Health and Nutrition Report — Design

Date: 2026-10-30 · Branch: `feature/sf8` (off `feature/sf2`, whose roster and submit/lock pattern it reuses)

## Goal

The section adviser records each learner's weight and height twice a school year (baseline and
endline). The system computes age, BMI, BMI-for-age category and height-for-age category from the
WHO 5–19 growth reference and builds DepEd's School Form 8 as an Excel file in the layout of the
user's template (`C:\Users\ZiM\Downloads\SF8-NUTRITIONAL-STATUS-SY26-27.xlsx`, sheet
"Nutritional Status"). The adviser submits each period; the principal and admin view every
section's SF8 and the school-wide summary, and may reopen a submitted period.

## Decisions (from the user)

- Only the **section adviser** fills SF8. Principal and admin **view** it.
- **Two periods per school year: Baseline and Endline**, each with its own date of weighing and its own SF8.
- **Same workflow as SF2**: adviser saves drafts, submits (locks the period); the principal or the
  admin may reopen it (SF2's `ATTENDANCE_REOPEN` rule).
- **"Certified Correct By"** name and position are a school setting the principal edits once.
- WHO tables ship as a **fixed PHP data file** (approach A); categories are computed on read, never stored.

## Data (each through `make:migration` + `doctrine:migrations:migrate`)

- **`NutritionPeriod`** enum (string-backed): `Baseline = 'baseline'`, `Endline = 'endline'`; `label()`.
- **`NutritionAssessment`** — section, period (`NutritionPeriod`), weighedOn (`date_immutable`),
  createdBy (User), updatedAt. Unique (section, period). Created on the adviser's first save.
  `measurements`: one-to-many `NutritionMeasurement`, cascade persist, orphan removal.
- **`NutritionMeasurement`** — assessment, enrollment, weightKg (decimal 5,2), heightM (decimal 3,2),
  remarks (string 100, nullable). Unique (assessment, enrollment). Deleting an enrollment deletes its
  measurements (`onDelete: CASCADE`). A learner with no row is *not weighed*. A row may hold only
  remarks (e.g. "absent on weighing day") with both numbers empty.
- **`Sf8Submission`** — section, period, submittedBy, submittedAt. Unique (section, period).
  Its existence locks that period. Reopening deletes it.
- **`School`**: `sf8CertifierName` (string 100, nullable), `sf8CertifierPosition` (string 100, nullable).

## WHO reference — `NutritionClassifier`

- `resources/who/bmi_for_age.php` and `resources/who/height_for_age.php`: arrays keyed by sex then
  age in months (60–228), extracted once from the template's "BMI Tables" sheet by a throwaway
  script (not committed). Each entry holds the category upper bounds exactly as the sheet:
  BMI — severely wasted ≤, wasted to, normal to, overweight to; HFA (cm) — severely stunted ≤,
  stunted to, normal to.
- **Age in months** = completed months from birthdate to the date of weighing. Displayed age =
  completed years (as the template's Age column).
- **BMI** = weight ÷ height², shown to 2 decimals; classification uses the unrounded value.
- **BMI category** (same comparisons as the template's formulas): BMI > overweight-to → Obese;
  > normal-to → Overweight; > wasted-to → Normal; > severely-wasted-≤ → Wasted; else Severely Wasted.
- **HFA category** on height in cm: > normal-to → Tall; > stunted-to → Normal; > severely-stunted-≤
  → Stunted; else Severely Stunted.
- Age < 60 or > 228 months, or no birthdate: both categories are *not computed* (shown as "—"), and
  the learner is left out of the summary counts. Not weighed learners are also left out.
- Enums `BmiCategory` (5 cases) and `HfaCategory` (4 cases) with `label()` matching the template text.
  `classify(Learner, weighedOn, weightKg, heightM): NutritionResult` (readonly: ageMonths, bmi, bmiCategory?, hfaCategory?).

## SF8 learner list — `Sf8Roster`

Learners counted in the section on the date of weighing: `Sf2Roster::forMonth(section, weighedOn)`
filtered by `RosterEntry::counts(weighedOn)`. So a learner transferred out after the baseline is on
the baseline but not the endline. Males first, then females, by name (as SF2). Names formatted as SF1.
Before a date is set, the roster uses today. Measurements of learners no longer on the roster (date
changed) stay in the database and are ignored everywhere.

## Rules — `NutritionRules` and `NutritionVoter`

- **FILL** (`NUTRITION_FILL`): the user is the section's adviser, the section is in the current
  school year, and the period is not submitted.
- **VIEW** (`NUTRITION_VIEW`): the adviser, the school's principal, or an admin.
- **REOPEN** (`NUTRITION_REOPEN`): the school's principal or an admin.
- **Date of weighing**: required; inside the school year (`startDate`..`endDate` when set); not after
  today (`SchoolClock`); the endline date must be after the baseline date when a baseline exists.
- **Measurement validation** (Validator attributes on the form model): weight 5–200 kg, height
  0.50–2.50 m, both or neither.
- **Submit**: needs the date of weighing; if any learner on the roster has no weight/height, the
  adviser confirms ("3 learners not weighed — submit anyway?"), like SF2's missing-days confirm.

## Screens

**Adviser** — `/teacher/class/nutrition` (`TeacherNutritionController`)
- `teacher_class_nutrition` (GET): two cards, Baseline and Endline — status (not started / draft /
  submitted), date of weighing, "32 of 35 weighed", BMI and HFA counts.
- `teacher_class_nutrition_period` (GET/POST `/{period}`): date of weighing, then one table (males,
  then females): LRN, name, birthdate, age, **weight**, **height**, **remarks** inputs, and read-only
  BMI, BMI category, HFA. A Symfony Form collection; one Save stores the whole table. Results show
  after saving (server-side; no JS calculation). Read-only with "Submitted on …" once submitted.
- `teacher_class_nutrition_submit` (POST `/{period}/submit`), CSRF-protected.
- `teacher_class_sf8` (GET `/{period}/sf8`): the .xlsx, also for drafts.

**Principal and admin** — `/schools/{id}/nutrition` (`SchoolNutritionController`)
- `school_nutrition` (GET, `?period=baseline|endline&compare=1`): every section — grade, section,
  adviser, status, weighed / on roster, counts per BMI and HFA category — with a school total row.
  *Compare* shows baseline and endline counts side by side per category.
- `school_nutrition_section` (GET `/sections/{section}/nutrition/{period}`): the adviser's table, read-only.
- `school_nutrition_sf8` (GET `…/sf8`) and `school_nutrition_reopen` (POST `…/reopen`, CSRF).
- The principal's school profile (`principal_school`) gains the two certifier fields.

**Menus**: "SF8 Nutrition" next to SF2 in the adviser's class menu, the principal's layout and the
admin school pages.

## Excel — `Sf8Writer`

- `templates/forms/sf8-template.xlsx`: the user's file reduced to the "Nutritional Status" sheet
  (helper sheets, defined names `NSMALE`/`HFAMALE`/… and sample rows removed, broken `#REF!`
  formulas gone), prepared once by hand-run script and committed.
- Filled with **values, not formulas**: E5 school name, I5 district, L5 division, O5 region, C7
  school ID, grade, section, L7 track/strand ("N/A" if none), O7 school year; A4 becomes
  "(For All Grade Levels) — Baseline" / "— Endline". Exact grade/section value cells are pinned by
  the writer test.
- Learner rows: males from row 12 (50 rows), females from row 63 (50 rows), columns A No., B LRN,
  C name, G birthdate, H age, I weight, J height, K height², L BMI, M BMI category, N HFA, O remarks.
  Over 50 in a group: rows are inserted, never cut off.
- Summary rows 118–120 (MALE, FEMALE, TOTAL) per BMI and HFA category, from `Sf8Summary`.
- Signature block: A124 date of weighing, E124/E125 adviser name and "Adviser", I124/I125 certifier
  name and position (blank if unset), M124/M125 principal name and position.
- Filename: `SF8_{school id}_{grade}-{section}_{period}_{school year}.xlsx`.

## Tests

- **Unit — `NutritionClassifier`**: rows sampled from the "BMI Tables" sheet for boys and girls,
  values on and just past each boundary, age 59/60/228/229 months, completed-month counting across
  month ends.
- **Functional (`WebTestCase`)**: adviser saves a draft and sees computed categories; validation errors
  (one of weight/height, out of range, date after today, endline before baseline); submit with
  confirm for unweighed learners; locked period refuses saves; another teacher gets 403; student
  gets 403; principal views overview, compare and section page and reopens; admin views and reopens;
  principal edits certifier fields.
- **Roster**: learner transferred out between baseline and endline appears only on the baseline.
- **Excel**: header, rows, summary totals and signature cells hold the expected values; >50 males inserts rows.

## Out of scope

Feeding-program (SBFP) beneficiary lists, learners under 5 (WHO 0–5 standards), per-learner
growth history charts, and printing SF8 as PDF.
