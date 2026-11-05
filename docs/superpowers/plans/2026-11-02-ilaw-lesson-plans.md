# ILAW Lesson Plans Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A teacher types a topic and competencies; the app drafts a whole week's ILAW lesson plan with Claude, the teacher edits it, and prints it as a PDF.

**Architecture:** `LessonPlan` (one per teaching load + week, days in JSON) built by a `LessonWeek` service from the timetable and school calendar; a `LessonPlanDrafter` interface with an Anthropic SDK implementation (structured JSON output) and a test fake; a `LessonPlanAi` service that enforces the monthly per-plan limit and logs `AiDraft` rows; teacher pages, a principal list, and a dompdf PDF.

**Tech Stack:** Symfony 8.1, Doctrine ORM + migrations, Twig, dompdf, `anthropic-ai/sdk` (official PHP SDK), PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-02-ilaw-lesson-plans-design.md`

## Global Constraints

- No test ever calls the real API. The interface `App\LessonPlan\LessonPlanDrafter` is aliased in `when@test` (config/services.yaml) to the public service `App\Tests\LessonPlan\FakeLessonPlanDrafter`.
- `ANTHROPIC_API_KEY` empty and `AI_MODEL=claude-opus-5-5` in `.env` (committed defaults); the real key only in `.env.local`. Read via `#[Autowire(env: ...)]`.
- Model call: `$client->messages->create(model: $model, maxTokens: 16000, messages: [...], system: [...], outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => ...]])`; read the first `text` block and `json_decode` it; check `stopReason` (`refusal`, `max_tokens` → failure). Catch `Anthropic\Core\Exceptions\APIStatusException` and connection errors → failure. Set a request timeout (find the option in `vendor/anthropic-ai/sdk`; 90 s).
- Failure message to the teacher (flash `error`): "The AI draft didn't work this time (<reason>). Nothing was changed; you can try again or write the plan yourself." A failure writes no `AiDraft`.
- Per-day text fields: `objectives, learnerContext, preLesson, flow, resources, integration, formativeAssessment, extendedLearning, reflections`; stored one bullet per line.
- Limit: `Plan::aiDraftsPerTeacherMonthly` (default 0). Used = the user's `AiDraft` rows since the 1st of the current month (SchoolClock).
- Declaration text: "AI (Claude, <model>) was used to draft the learning objectives, learner context, learning experiences, resources, integration, assessment, extended learning, reflections and references from the topic and competencies given by the teacher. The teacher reviewed and edited the draft."
- Subjects in Filipino: subject name or code contains "Filipino" or "Araling Panlipunan" (case-insensitive) → the AI writes in Filipino.
- Tests: `php bin/phpunit`; never two runs at once. Add `AiDraft` and `LessonPlan` to `SchoolFixturesTrait::clearSchoolData` (before `TeachingLoad`/`User`).

## Review Focus

- A week where every meeting day is a holiday: plan saves, Draft with AI says there are no school days to draft, no API call, no AiDraft.
- The AI returns fewer/more day objects than asked, or a field missing: treated as failure, nothing overwritten.
- Redraft keeps competencies, topic and class notes; overwrites only AI cells.
- A teacher opening another teacher's plan id (edit, draft, PDF, delete) → 403/404.
- Very long cells in the PDF break across pages without overlapping.

---

### Task 1: Storage and settings

**Files:**
- Create: `src/Entity/LessonPlan.php`, `src/Entity/AiDraft.php`, `src/Repository/LessonPlanRepository.php`, `src/Repository/AiDraftRepository.php`, migration
- Modify: `src/Entity/Plan.php` + `src/Form/PlanFormType.php` + `templates/admin/plans/form.html.twig` (`aiDraftsPerTeacherMonthly`, label "AI lesson plan drafts per teacher per month", help "0 turns the AI off for this plan."); `src/Entity/School.php` + `src/Form/SchoolProfileFormType.php` + `templates/principal/school_profile.html.twig` (`lpCheckedByName`, `lpCheckedByPosition`, legend "Lesson plans", label "Checked by"); `tests/SchoolFixturesTrait.php`
- Test: `tests/Controller/Admin/PlanControllerTest.php` (or the existing plan test file), `tests/Controller/Principal/SchoolProfileTest.php`, `tests/LessonPlan/LessonPlanEntityTest.php`

**Interfaces:**
- Produces: `LessonPlan::__construct(TeachingLoad $load, \DateTimeImmutable $weekStart, \DateTimeImmutable $now)`; getters/setters `topic`, `competencies`, `classNotes`, `references`, `aiDeclaration`, `days` (`list<array<string, string|null>>`), `draftedAt`, `updatedAt`; `const DAY_FIELDS = ['objectives', 'learnerContext', 'preLesson', 'flow', 'resources', 'integration', 'formativeAssessment', 'extendedLearning', 'reflections']`; `sessionCount(): int` (days without `holiday`); `static bullets(?string): list<string>` (non-empty trimmed lines). Unique (load, weekStart).
- Produces: `AiDraft::__construct(User $user, School $school, string $model, int $inputTokens, int $outputTokens, \DateTimeImmutable $at)`; `AiDraftRepository::countSince(User $user, \DateTimeImmutable $since): int`; `LessonPlanRepository::findForTeacher(User, SchoolYear): list<LessonPlan>` (week desc), `findForSchool(School, SchoolYear): list<LessonPlan>`, `findOneFor(TeachingLoad, \DateTimeImmutable $weekStart): ?LessonPlan`.
- Produces: `Plan::getAiDraftsPerTeacherMonthly(): int`; `School::getLpCheckedByName()/Position()`.

- [ ] **Step 1: Failing tests** — `testBulletsSplitLines` ("a\n\n b \n" → ['a','b']); `testSessionCountSkipsHolidays`; admin saves `aiDraftsPerTeacherMonthly` 20; principal saves Checked by name/position.
- [ ] **Step 2: Run** the three files — Expected: FAIL.
- [ ] **Step 3: Implement**, `make:migration`, migrate dev and `--env=test`.
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Commit** `feat: lesson plan storage, AI draft limit and checker setting`.

### Task 2: The week — LessonWeek

**Files:**
- Create: `src/LessonPlan/LessonWeek.php`
- Test: `tests/LessonPlan/LessonWeekTest.php` (KernelTestCase + fixtures)

**Interfaces:**
- Consumes: `ScheduleEntryRepository::findForLoad`, `SchoolDayOffRepository::findBetween`, `ScheduleEntry::getDay()/getTerm()`, `Subject::getMeetingsPerWeek()`.
- Produces: `LessonWeek::days(TeachingLoad $load, \DateTimeImmutable $weekStart): list<array{date: string, holiday: ?string}>` (dates `Y-m-d`, ascending); `LessonWeek::monday(\DateTimeImmutable $any): \DateTimeImmutable`; `LessonWeek::start(TeachingLoad $load, \DateTimeImmutable $weekStart, \DateTimeImmutable $now): LessonPlan` (new plan whose `days` hold the dates/holidays and empty fields).
- Term rule: entries count when `term` is 0 or equals the semester at that week (1 before the school year's midpoint, else 2 — same rule as `TimetableQuery::termAt`).

- [ ] **Step 1: Failing tests** — `testDaysFromTimetable` (entries Mon/Wed/Fri → 3 dates); `testHolidayStaysAsHoliday` (Wed day off "Bonifacio Day" → holiday "Bonifacio Day", sessionCount 2); `testNoTimetableUsesMeetingsPerWeek` (4 → Mon–Thu); `testNoTimetableNoMeetingsIsWholeWeek` (5 days); `testMondayOf` (a Thursday → that week's Monday); `testOtherSemesterEntriesIgnored`.
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement.** **Step 4: Run** — PASS.
- [ ] **Step 5: Commit** `feat: lesson plan week from timetable and calendar`.

### Task 3: AI draft — drafter, fake, limit

**Files:**
- Create: `src/LessonPlan/LessonPlanDrafter.php` (interface), `src/LessonPlan/DraftRequest.php` (readonly), `src/LessonPlan/DraftResult.php` (readonly), `src/LessonPlan/AiDraftFailed.php` (exception with user-facing reason), `src/LessonPlan/AnthropicLessonPlanDrafter.php`, `src/LessonPlan/LessonPlanAi.php`, `tests/LessonPlan/FakeLessonPlanDrafter.php`
- Modify: `composer.json` (`composer require anthropic-ai/sdk`), `.env` (`ANTHROPIC_API_KEY=`, `AI_MODEL=claude-opus-5-5`), `config/services.yaml` (`when@test` alias, fake public)
- Test: `tests/LessonPlan/LessonPlanAiTest.php`, `tests/LessonPlan/AnthropicLessonPlanDrafterTest.php` (schema/prompt only, no network)

**Interfaces:**
- Produces: `interface LessonPlanDrafter { public function draft(DraftRequest $request): DraftResult; }` throwing `AiDraftFailed`.
- `DraftRequest`: `level` (school type label), `grade`, `subject`, `section`, `quarter` (label or null), `topic`, `competencies` (list), `classNotes` (?string), `dates` (list of `Y-m-d`, holidays excluded), `filipino` (bool).
- `DraftResult`: `references` (list<string>), `days` (list of `array<field, list<string>>`, same count/order as `dates`), `model`, `inputTokens`, `outputTokens`.
- `AnthropicLessonPlanDrafter::schema(int $days): array` (JSON schema: `references` array of strings; `days` array, `minItems`=`maxItems`=$days, items object with every DAY_FIELD as array of strings, all required, `additionalProperties: false`); `::prompt(DraftRequest): string` (system instructions per spec + the request). Empty key → `AiDraftFailed('the AI is not set up on this server')`. Wrong day count or missing field → `AiDraftFailed('the AI answer was incomplete')`.
- `LessonPlanAi::remaining(User $teacher, School $school): int` (limit − used, ≥ 0); `LessonPlanAi::draft(LessonPlan $plan, User $teacher): void` — throws `AiDraftFailed` when limit is 0 / used up (`'no AI drafts left this month'`) or no school days (`'this week has no school days to draft'`) before calling the drafter; on success writes each non-holiday day's fields (lines joined by "\n"), `references`, `aiDeclaration` (Global Constraints text with the model), `draftedAt`, persists one `AiDraft`, flushes. Topic, competencies, classNotes untouched.
- Fake: `FakeLessonPlanDrafter::$fail` (?string) and `$calls` (int); returns a fixed sample sized to the request.

- [ ] **Step 1: Failing tests** — `testDraftFillsEveryCellAndDeclaration`; `testHolidayDaysStayEmpty`; `testCountsOneDraft`; `testLimitReachedRefusesWithoutCalling` (limit 1, one AiDraft this month → AiDraftFailed, fake `calls` 0); `testZeroLimitMeansNoAi`; `testLastMonthDoesNotCount`; `testFailedDraftChangesNothingAndCountsNothing` (fake fails → plan unchanged, no AiDraft); `testRedraftKeepsTeacherFields`; `testAllHolidayWeekRefuses`; drafter: `testSchemaAsksForExactDays`, `testPromptCarriesInputsAndLanguage` (Filipino flag → "Filipino"), `testNoKeyFails`, `testIncompleteAnswerFails` (feed a decoded array with a missing field to the parser method `::parse(array $json, int $days): array`).
- [ ] **Step 2: Run** — FAIL. **Step 3: Implement** (`composer require anthropic-ai/sdk` in PowerShell; read `vendor/anthropic-ai/sdk` for the client constructor, timeout option and exception classes before writing the call). **Step 4: Run** — PASS; `lint:container`.
- [ ] **Step 5: Commit** `feat: AI lesson plan drafts with a monthly limit`.

### Task 4: Teacher pages

**Files:**
- Create: `src/Controller/Teacher/LessonPlanController.php`, `src/Security/Voter/LessonPlanVoter.php` (VIEW: owner or the school's principal this S.Y.; EDIT: owner), `src/Form/LessonPlanStartFormType.php` (teaching load choice from `findForTeacher`, week date), `src/Form/LessonPlanFormType.php` (topic, competencies, classNotes, references, aiDeclaration, days via a `LessonPlanDayFormType` collection — dates/holidays not editable), templates `templates/lesson_plans/index.html.twig`, `start.html.twig`, `edit.html.twig`
- Modify: `templates/teacher/_layout.html.twig` (menu "My Lesson Plans" for teachers with `has_id`-style school check), area menu tests
- Test: `tests/Controller/TeacherLessonPlanControllerTest.php`

**Interfaces:**
- Routes: `teacher_lesson_plans` GET `/teacher/lesson-plans`; `teacher_lesson_plan_new` GET|POST `/teacher/lesson-plans/new` (existing plan for that load+week → redirect to it); `teacher_lesson_plan_edit` GET|POST `/teacher/lesson-plans/{id}`; `teacher_lesson_plan_draft` POST `/teacher/lesson-plans/{id}/draft` (CSRF `lesson-plan-draft-{id}`; saves the submitted form first, then drafts); `teacher_lesson_plan_delete` POST (CSRF `lesson-plan-delete-{id}`).
- Edit page: "N of M AI drafts left this month"; button "Draft with AI" (or "Redraft with AI" when `draftedAt`, with a confirm); disabled at 0 left.

- [ ] **Step 1: Failing tests** — list shows own plans only; new plan creates days from the timetable; same load+week opens the existing one; save edits; draft fills cells (fake) and flash success "Draft ready. Check every cell before you print."; draft failure flashes the error and keeps typed topic; other teacher's plan → 403 on edit/draft/delete; delete; menu item present for an assigned teacher.
- [ ] **Step 2–4:** FAIL → implement → PASS; `lint:twig`.
- [ ] **Step 5: Commit** `feat: teachers write ILAW lesson plans with AI drafts`.

### Task 5: PDF and principal list

**Files:**
- Create: `src/LessonPlan/LessonPlanPdf.php` (render/html/filename/asciiFilename, letter landscape `[0, 0, 792, 612]`), `templates/lesson_plans/pdf.html.twig`, `src/Controller/LessonPlanViewController.php` (`lesson_plan_pdf` GET `/lesson-plans/{id}.pdf`, `lesson_plan_show` GET `/lesson-plans/{id}` read-only, VIEW), `templates/lesson_plans/show.html.twig`, `templates/lesson_plans/school.html.twig`
- Modify: `src/Controller/Principal/DashboardController.php` (`principal_lesson_plans` GET `/principal/lesson-plans` → list for own school this S.Y.), `templates/principal/_layout.html.twig` (menu "Lesson Plans"), area menu tests
- Test: `tests/LessonPlan/LessonPlanPdfTest.php`, `tests/Controller/LessonPlanViewControllerTest.php`

**Interfaces:**
- Consumes: `LessonPlan`, `School::getLpCheckedBy*`, `ServiceRecordEntryRepository::findCurrent`, `SchoolAssignmentRepository::findPrincipal`.
- PDF content: title "DAILY LESSON LOG-ILAW FORMAT"; header table rows exactly as the template; blocks "Intentions.", "Learning Experience.", "Assessment.", "Ways Forward." with the template's grey instruction text per row; a column per day ("Day N" + date; holiday text instead of bullets); bullets from `LessonPlan::bullets`; signatories Prepared by (teacher upper-case, position from Present row else "Teacher"), Checked by (setting or blank), Noted by (principal upper-case + Present-row position else "School Principal"). Filename `ILAW - <subject> - <section> - <Y-m-d>.pdf`.

- [ ] **Step 1: Failing tests** — html has the four blocks, every day header, a bullet from each field, holiday text, signatories; render starts `%PDF-`; filename; principal sees the school's plans and PDF; principal of another school 403; teacher's own PDF 200.
- [ ] **Step 2–4:** FAIL → implement → PASS. Render a sample PDF (fake draft) to the scratchpad and look at it against the template.
- [ ] **Step 5: Full suite** in the background — Expected OK; fix menu-list expectations.
- [ ] **Step 6: Commit** `feat: ILAW lesson plan PDF and principal list`.
