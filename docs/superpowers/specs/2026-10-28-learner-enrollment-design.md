# Learner Enrollment — Design

Date: 2026-10-28
Status: design approved in chat; awaiting written-spec review

## Goal

Schools enroll their learners into class sections, one learner at a time. Each learner is a
lifelong record identified by their LRN, with a student login account. Each school year the
learner has at most one enrollment (school + section). A learner moving to another school mid-year
goes through a transfer request that the school they leave must approve.

This is piece 2 and the enrolment half of piece 3 from `2026-10-16-plans-principal-teachers-design.md`
("Student accounts with LRN"; "Teacher enrols students"), and it enforces the student limit stored
on `Plan` since then.

## Out of scope

- Re-enrolling a whole section or "bring back last year's class": **not wanted**, now or later.
  Every learner is enrolled one at a time.
- CSV/LIS import, learner statuses (dropped, transferred out) and the SF1 register.
- Contact details, address and the other questions of the Basic Education Enrollment Form.
- Deleting learners.
- Grades and other student pages beyond the dashboard below.

## Data

### `Learner` (new): one per child, kept for life

| Field | Type / rule |
|---|---|
| `lrn` | string(12), exactly 12 digits, unique |
| `lastName` | string(50), required |
| `firstName` | string(50), required |
| `middleName` | ?string(50) |
| `extension` | ?string(10) (Jr., Sr., II, III…) |
| `sex` | `SexAtBirth` enum, required |
| `birthDate` | date_immutable, required, before today |
| `user` | OneToOne `User`, required, unique |
| `createdAt` | datetime_immutable |

- The login account is created together with the learner: `ROLE_STUDENT`, username = LRN, a
  random temporary password shown once, `mustChangePassword = true` (same as teacher accounts).
- `User.fullName` is kept in step with the name parts: `Last, First Middle Ext`
  (e.g. `Dela Cruz, Juan Santos Jr.`), so every existing page that shows `fullName` keeps working.
- Changing the LRN also changes the account's username.

### `Enrollment` (new): one per learner per school year

| Field | Type / rule |
|---|---|
| `learner` | ManyToOne Learner, required |
| `schoolYear` | ManyToOne SchoolYear, required |
| `school` | ManyToOne School, required |
| `section` | ManyToOne Section, required; must belong to `school` and `schoolYear` |
| `enrolledBy` | ManyToOne User, required |
| `enrolledAt` | datetime_immutable |

- Unique index `(learner_id, school_year_id)`.
- Moving to another section of the same school changes `section`. An approved transfer changes
  `school` and `section` of the same row; the approved `TransferRequest` keeps where they came from.
- Past years' enrollments are read-only history.

### `TransferRequest` (new)

| Field | Type / rule |
|---|---|
| `learner` | ManyToOne Learner, required |
| `enrollment` | ManyToOne Enrollment (the one being left), required |
| `fromSchool`, `fromSection` | ManyToOne, required; copied when the request is made (history) |
| `toSection` | ManyToOne Section, required (gives the new school) |
| `status` | enum `TransferStatus`: `pending`, `approved`, `declined`, `cancelled` |
| `requestedBy` | ManyToOne User, required |
| `createdAt` | datetime_immutable |
| `decidedBy` | ?ManyToOne User |
| `decidedAt` | ?datetime_immutable |

- At most one `pending` request per learner, checked under the learner lock (see Concurrency).

### Limits

Checked on enroll, and again on approving a transfer:

- **School:** this school year's `Enrollment` count for the school must stay within
  `School::getStudentLimit()` (students per seat × seats).
- **Section, on plans without a principal** (`Plan::includesPrincipal = false`): a section's
  enrollment count must stay within `Plan::studentsPerSeat`.
- **View-only plan:** every write is refused while `PlanLimits::blockedReason($school)` is not null.
- Lowering a plan below usage never unenrolls anyone; it only blocks new enrollments.

`PlanLimits` gains `countStudents(School, SchoolYear)` and `canAddStudent(School, SchoolYear, Section)`
next to the teacher methods.

## Who can do what

Everything works on the **current school year** (`SchoolYearRepository::findCurrent()`), like
sections and subjects. An **adviser** is the teacher set as `Section::adviser` in the current school year.

| Action | Admin | Principal | Adviser (teacher) | Teacher with no advisory |
|---|---|---|---|---|
| Enroll a new or returning learner | any school, any section | their school, any section | their own section only | ✗ |
| Edit learner details | ✓ | learners enrolled in their school | learners in their section | ✗ |
| Change a learner's LRN | ✓ | learners in their school | ✗ | ✗ |
| Reset a learner's password | ✓ | their school | their section | ✗ |
| Move to another section, same school | ✓ | ✓ | ✗ | ✗ |
| Unenroll (for mistakes; learner and account stay) | ✓ | ✓ | their section | ✗ |
| Request a transfer in from another school | – (transfers directly) | ✓ | ✗ | ✗ |
| Approve or decline a transfer out | ✓ | requests leaving their school | requests leaving their section, only when the school's plan has no principal | ✗ |
| Cancel a pending request | ✓ | requests their school sent | ✗ | ✗ |
| Transfer directly, no approval | ✓ | ✗ | ✗ | ✗ |

- A new `LearnerVoter` holds these rules; controllers use `#[IsGranted]` and services trust the
  caller (as with `SchoolVoter`). A teacher with no advisory gets no menu item and 403 on every page.
- When a teacher stops being an adviser, their pages close at once (403); their learners stay put.
- A learner with a pending transfer cannot be moved, unenrolled or sent another request.
- Ids from another school or school year → 404, as in the rest of the app.

## Enroll flow (one page, two steps)

1. **Type the LRN.**
2. What comes next depends on what is found:

| Found | Admin / principal see | Adviser sees |
|---|---|---|
| New LRN | learner form + section picker → **Enroll** | learner form → **Enroll** into their section |
| Learner not enrolled this year | details read-only (+ "Edit details") + section picker → **Enroll** | same, into their section |
| Enrolled at this school this year | `<name> is already enrolled in <section>.` + link to the learner | same |
| Enrolled at another school this year | principal: section picker → **Request transfer**; admin: section picker → **Transfer** | refusal (below) |

- The section picker lists the current year's sections of the school. An admin picks the school first.
- After creating a new learner, the sign-in details (username = LRN, temporary password) are shown
  once, the same way as for new teachers.

## Transfers

1. A principal (or admin) types the LRN of a learner enrolled at another school this year, picks
   one of their sections and sends **Request transfer**. The learner stays where they are.
2. The request shows at the receiving school as `Waiting for <school>`; that school may **Cancel** it.
3. The approver of the school being left sees it on their dashboard and on the Transfer requests page:
   the principal of that school, or, when its plan has no principal, the adviser of the learner's section.
   Admins may decide any request.
4. **Approve:** limits of the new school/section are checked again; the enrollment moves to the new
   school and section; the account carries over. If a limit is now reached, approval is refused and
   the request stays pending.
5. **Decline:** nothing moves; the receiving school sees it as declined.
6. An admin's **Transfer** moves the learner at once, with no request; it is still logged.

## Pages

| Page | Route | Who |
|---|---|---|
| Learners of a school (search by LRN/name, filter by section, **Enroll**) | `GET /schools/{id}/learners` | admin, principal |
| Enroll (LRN step, then form) | `GET\|POST /schools/{id}/learners/enroll` | admin, principal, adviser |
| Class list | `GET /schools/{id}/sections/{section}/learners` | admin, principal, the section's adviser |
| Learner page (details, enrollment history, transfer requests, allowed actions) | `GET /schools/{id}/learners/{learner}` | as the table above |
| Edit details / LRN | `GET\|POST /schools/{id}/learners/{learner}/edit` | as the table above |
| Move, unenroll, reset password | `POST /schools/{id}/learners/{learner}/{action}` | as the table above |
| Transfer requests (Incoming / Outgoing tabs) | `GET /schools/{id}/transfers` | admin, principal, adviser on a no-principal plan |
| Request / approve / decline / cancel | `POST /schools/{id}/transfers/...` | as the table above |
| Admin learner search by LRN, all schools | `GET /admin/learners` | admin |
| Student dashboard | `GET /student` | the learner |

Route names and exact paths follow the existing `school_*` controllers. Forms use Symfony Form and
Validator; POST actions use CSRF tokens.

- **Class list:** No., LRN, name, sex, birthdate. Boys first, then girls, each by last name then
  first name (DepEd class-list order). Header: enrolled count, plus the section limit on plans
  without a principal. Row actions as allowed.
- **Sections page:** gains an **Enrolled** column linking to the class list.
- **Principal:** new **Learners** menu item. The dashboard's **Students** card counts this year's
  enrollments against the limit; a **Transfer requests waiting: n** card shows when n > 0.
- **Adviser:** new **My class** menu item (their class list with **Enroll**); none for a teacher
  with no advisory. On a plan without a principal, transfer requests for their section show on their dashboard.
- **Admin:** **Learners** link on each school's page; learner search by LRN; **Transfer** on the learner page.
- **Student dashboard:** school, grade and section, adviser, school year, and the section's
  timetable read-only (`TimetableQuery`, the current term). Not enrolled this year →
  `You are not enrolled this school year.` First sign-in forces a password change (existing).
  `HomeController` sends `ROLE_STUDENT` here instead of the empty home page.

## Services

- **`LearnerEnrollment`**: enroll (new or existing learner), edit details, change LRN, move,
  unenroll, reset password, admin transfer. Every write takes the per-school lock, checks
  `PlanLimits`, writes the activity log, and turns `UniqueConstraintViolationException` into the
  friendly message (pattern: `SchoolTeacherManager` + `SchoolTeacherLock`).
- **`TransferManager`**: request, approve, decline, cancel. Takes the learner lock, and on approve
  also the receiving school's lock.
- **`LearnerLookup`**: LRN → what the enroll page shows (new / not enrolled / here / elsewhere).
- Repositories: `LearnerRepository`, `EnrollmentRepository` (counts per school and section, class
  list order), `TransferRequestRepository` (pending per school, per section).

## Concurrency

- `symfony/lock` keys: `learners-school-<id>` for every enrollment write at a school, and
  `learner-<id>` for a learner's transfers. Approve takes the learner lock, then the school lock.
- If a lock is busy: `Another change is being saved. Please try again.`
- The unique index on `(learner, schoolYear)` is the last line of defence; a violation gives the same message.

## Protecting existing data

- A section with enrollments, or with pending transfers into it, cannot be deleted (`SectionManager`,
  same pattern as the timetable check): `<section> has <k> learners. Move or unenroll them first.` /
  `<section> has <k> pending transfers.`
- On the admin Accounts page, the role of a learner's account cannot be changed:
  `This account belongs to learner <LRN>.`

## Copy

`<name>` is `User::getFullName()`, `<section>` is `Section::getLabel()`. Every `<k> learners` /
`<k> pending transfers` uses the singular when k = 1.

- Validation: `The LRN must be exactly 12 digits.` · `LRN <lrn> belongs to <name>.` ·
  `The username <lrn> is already taken by another account. Ask the administrator.` ·
  `Birthdate must be before today.`
- Enroll: `<name> is already enrolled in <section>.` · adviser, other school:
  `<name> is enrolled at <school>. Ask your principal to request a transfer.` (plan without a
  principal: `… Ask the administrator to transfer them.`)
- Limits: `The <plan> plan allows <n> student(s) (<used> used). Contact the administrator to upgrade.` ·
  `<section> is full (<n> learners).`
- Transfers: `<name> has a transfer request waiting.` · `Waiting for <school>` ·
  `Approval refused: <limit message>`
- Activity log: `Enrolled <lrn> in <section>`, `Moved <lrn> from <section> to <section>`,
  `Unenrolled <lrn> from <section>`, `Updated learner <lrn>`, `Changed LRN <old> to <new>`,
  `Reset password of learner <lrn>`, `Requested transfer of <lrn> from <school>`,
  `Approved transfer of <lrn> to <school>`, `Declined transfer of <lrn> to <school>`,
  `Cancelled transfer request for <lrn>`, `Transferred <lrn> from <school> to <school>`.

## Testing

- `KernelTestCase` for `LearnerEnrollment`, `TransferManager`, `LearnerLookup` and the new
  `PlanLimits` methods.
- Unit tests for `LearnerVoter`, one per cell of the access table.
- `WebTestCase` for every page as admin, principal, adviser, teacher with no advisory and student,
  including 403 and 404 cases (pattern: `AdminTestCase`).
- Migration via `make:migration`, run for dev and test.

## Review Focus

1. **Two people enrolling the last free place** at the same time: one succeeds, the other gets the
   limit or "Another change is being saved" message, never both enrolled and never a 500.
2. **A transfer whose new section or school filled up** before approval is refused and stays pending.
3. **An adviser posting a section id that isn't theirs** (edited form) is refused (403/404), not enrolled.
4. **A teacher who stops being an adviser** with their pages open: the next request is 403.
5. **Deleting a section** with learners or pending transfers into it is refused.
