# ILAW lesson plans with AI drafts — design

Date: 2026-11-02 · Branch: `feature/lesson-plans` (from `main`)

## Goal

A teacher types only the topic and the learning competencies; the app drafts a whole week's DepEd
ILAW lesson plan (Daily Lesson Log – ILAW format, DO 16 s. 2026) with AI. The teacher edits any
cell, saves, and prints the PDF. Template: `C:\Users\ZiM\Downloads\ilaw ttemplate.pdf`.

## Decisions (from the user)

- Form: ILAW (replaces the old weekly log idea). The AI drafts the whole week, including
  activities and assessment.
- Learner Context and Reflections are drafted by the AI too; the teacher edits them.
- One Anthropic API key (the owner's), in the server's `.env.local`; usage is limited per school
  plan: AI drafts per teacher per month, set by the admin.
- Model: Claude Opus 5.5 by default, switchable to another model through server config.

## What fills each ILAW part

| ILAW part | Filled by |
|---|---|
| Name of Lesson | the topic |
| Learning Area/s | subject name and grade, from the teaching load |
| Designed by Teacher/s | the teacher |
| Grade Level and Section | from the teaching load |
| No. of Sessions | the app (see Sessions), editable |
| References | AI, editable |
| Declaration of AI use | the app: names the model, what it drafted, and that the teacher reviewed and edited it (DO 3 s. 2024, Annex A); editable |
| Learning Competency | the teacher (one per line, codes allowed) |
| Per day: Learning Objectives, Learner Context, Pre-Lesson, Flow, Learning Resources, Opportunities for Integration, Formative Assessment, Extended Learning Opportunities, Reflections | AI, editable |
| Prepared by / Checked by / Noted by | the teacher (+ their service record position, else "Teacher"); School Profile "Lesson plans checked by" name + position; the school's principal (+ position from their Present row, else "School Principal") |

## Sessions and days

- A plan is for one teaching load (teacher + section + subject) and one week (its Monday).
- Days = the weekdays that load meets in the timetable for the term containing that week; with no
  timetable (e.g. Individual plan), the first `Subject.meetingsPerWeek` weekdays, else Monday–Friday.
- A school day off (`SchoolDayOff`) on a meeting day stays in the plan as a holiday day:
  the column shows "HOLIDAY (<reason>)" and the AI drafts nothing for it.
- No. of Sessions = meeting days that are not holidays. Day columns are labelled "Day 1"… in order,
  with the date under each.

## AI draft

- One request per draft through the official Anthropic PHP SDK (`anthropic-ai/sdk`), structured
  output (JSON schema): `references` (list) and one object per non-holiday day with every per-day
  field as a list of short bullet strings.
- Input: school level, grade, subject, section, topic, competencies, the teacher's optional notes
  about the class, the days (with dates), quarter/term.
- Instructions follow ILAW and the Learning Design Principles: objectives covering knowledge,
  skills and values with measurable verbs; clear objectives; guided before independent practice;
  checks of well-being and understanding; links to past lessons; collaboration; reflection on why
  it matters; inclusion of varied abilities; plausible learner context for the grade; reflections
  written as a teacher's notes. Stay within the given competencies. Write in Filipino for Filipino
  and Araling Panlipunan, else English.
- Model from config (`%env(AI_MODEL)%`, default `claude-opus-5-5`); key from `ANTHROPIC_API_KEY`
  (in `.env.local`, never committed). Time limit on the request.
- Redraft replaces all AI-drafted cells (asks for confirmation); teacher text in Competency and
  notes is kept.

## Limits

- `Plan` gets `aiDraftsPerTeacherMonthly` (int, default 0 = no AI), edited in admin Plans.
- Each successful draft writes an `AiDraft` row (user, school, created at, model, input and output
  tokens). The month's count is the user's rows in the current calendar month.
- The edit page shows "N of M AI drafts left this month"; at 0 the button is disabled.
- A failed draft (no key, timeout, API error, refusal, invalid output) writes no row, shows a plain
  message, and keeps everything typed.

## Storage

- `LessonPlan`: teaching load, week start (Monday), topic, competencies (text), class notes (text,
  nullable), references (text), AI declaration (text, nullable), days (JSON: list of
  `{date, holiday: ?string, objectives, learnerContext, preLesson, flow, resources, integration,
  formativeAssessment, extendedLearning, reflections}`, each text field one bullet per line),
  drafted at (nullable), created/updated at. Unique per teaching load + week start.
- `AiDraft` as above.
- School gets `lpCheckedByName`, `lpCheckedByPosition` (School Profile, "Lesson plans").

## Pages and access

- Teacher menu **My Lesson Plans**: list (newest week first), New plan (pick a teaching load and a
  week; opens the existing plan if there is one), Edit (all cells as textareas, one bullet per
  line; Draft with AI / Redraft; Save), PDF, Delete (confirm).
- Principal menu **Lesson Plans**: read-only list of the school's plans this S.Y. by week and
  teacher, with view and PDF.
- Only the plan's teacher edits/deletes; the teacher and their school's principal view/print
  (voter). Admin: only the plan limit.

## PDF

dompdf, letter landscape, laid out like the template: header table; then the four blocks
(Intentions, Learning Experience, Assessment, Ways Forward), each with the grey instruction
column and one column per day; table headers repeat on page breaks; each line of a cell is a
bullet; signatories at the end. File name `ILAW - <subject> - <section> - <week start>.pdf`.

## Out of scope

Principal approval/"checked" workflow, a built-in MELC/MATATAG competency list, drafting several
weeks at once, streaming the draft into the page.

## Tests (no real API calls)

- The AI client sits behind an interface; tests use a fake that returns a fixed sample, or fails.
- Sessions: timetable days, holidays from SchoolDayOff, fallback with no timetable.
- Draft fills every non-holiday cell and the declaration; holiday days stay empty.
- Limits: allowed under the limit, refused at it, 0 = no AI; failed drafts don't count.
- Access: teacher sees own only; principal of the school sees, others 403; admin plan field saves.
- PDF contains each block, the day columns, the bullets and the signatories.
- Manual check at the end with the real key: one draft, cost reported to the user.
