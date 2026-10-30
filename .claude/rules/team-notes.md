# Global Notes

Shared rules for everyone working on this repo with Claude Code, copied from the global notes both developers follow. In this file, "global notes" means these rules, and "project notes" means `CLAUDE.md` and `AGENTS.md`. "I/me/my" means the developer running the session when it's about approvals, commits or pushing, and Claude when it's about running tools. Change a rule only after both developers agree, so the copies stay in sync.

## General

- Always take these global notes and the project's own notes into consideration for all code changes/work. Before finishing each task/implementation, double-check that all notes are being followed and there are no violations.
- Global notes and project notes must always be followed strictly, not just as loose guidance.
- Never decide a note conflict unilaterally: whenever any two notes conflict (global-global, project-project, or global-project), ask for confirmation on which to follow — there is no default winner.
- Always update the CLAUDE.md of the project (repo) being worked on when the work changes something it documents (new commands, conventions, architecture, gotchas).
- Always close/stop/kill orphaned processes belonging to the project's (repo's) tooling (stray dev servers, watchers, build processes, etc.) — leftover orphaned processes can cause Docker to become unresponsive.
- Always close/stop/kill leftover shells and Monitor tasks belonging to the project once no longer needed, rather than leaving them running in the background.
- Always remove junk files created by Claude (scratch/temp files, logs, stray build output, etc.) once no longer needed, rather than leaving them in the project.
- Never run implementation work, fixes, tests, or agents/subagents in parallel — always execute sequentially, one at a time. This environment's memory and Docker resources are shared with other users, and parallel execution can consume enough to block them.
- This restricts how I invoke tools/commands myself — it does not forbid configuring a test runner's own internal parallelism (e.g. paratest) as a deliberate, separately-planned change. That's a distinct decision with its own resource tradeoff, never a default; propose it and get explicit confirmation before setting it up, rather than assuming the no-parallel rule already rules it out.
- Always use superpowers, context7, and other installed plugins/skills in Claude when writing specs or plans, testing, implementing, or doing any kind of work.
- Any Claude/Anthropic mention must never appear in written code (identifiers, strings, file names, config, etc.) — this is in addition to the same rule already covering comments and commit messages.
- Never include Claude-related tooling (superpowers, plugins, skill files, or the like) in the project's code — those are for doing the work, not artifacts to leave behind in the codebase.
  - Exception for this repo: the shared `CLAUDE.md` and `.claude/` (skills, hooks, rules, `settings.json`) are committed on purpose so both developers get the same setup.
- Before suggesting a change to a number, format, or behavior, check whether it's already an agreed requirement rather than assuming it's open to renegotiation.
- Before finalizing a spec/plan that names a new class, method, or API call, grep the actual codebase for the nearest existing equivalent and match its exact naming/signature — don't infer it from a project's own high-level architecture description or general framework conventions alone. Docs describe intent; real code can diverge from how it's described.
- When a loaded skill's default template conflicts with an established global/project note (e.g. a skill's per-step test-execution pattern vs. a "tests run once at the end" rule), the note wins — adapt the skill's output to match the note, don't apply the skill's default structure verbatim just because that's what the skill shows.
- When giving the user commands to run on a server I can't access, double-check each command's exact form and working directory first (e.g. a `vendor/bin` wrapper vs. a PHP file, relative paths after a `cd`) — I can't run them there to catch mistakes.
- Never pipe a critical or long-running command (install, update, migrate, deploy) through `tail`/`head` — it hides the real exit code and failures. Read the full output and verify the result on disk.
- Find the root cause from logs or evidence before editing — don't fix on a guess.
- Before diagnosing from a data dump, check its date range covers the reported dates.
- Detect a failure where it happens rather than predicting it from separate logic.
- Before explaining what happens in an edge case, check for scheduled jobs that change that data first.
- Save long command output to a file and read it, rather than piping it through filters.
- When a fix depends on how an external system stores data, check its metadata before changing code.
- When replacing a method by text, match its full signature and body, not a closing-brace pattern.
- When adding a status or marker column, update every query that decides "already done".
- When adding a skip or exclusion, update every query that counts "not sent yet".
- Before calling something an open issue, check memory and the code for an existing decision.
- On production panels, navigate by clicking real links only — never guess URLs.
- After navigating in the browser, wait until the new page's title appears before reading its content.
- If a page load times out, reload it before measuring.
- Compare a UI change with the reference screenshot before calling it done.
- Confirm checkbox/setting states from a screenshot, not the element-lookup tool alone.
- Before reading a dropdown test result, check the dropdown is still open.
- After filling a web form, re-read every field before asking to save.
- Before planning a step that needs a permission on someone else's service (repo admin, hosting role), check the account has it.
- After copying or extracting files, compare names with the source, not just counts.
- Don't switch branches unless asked; read other branches with git commands instead.
- Before planning files to share through git, run `git check-ignore -v` on them (the global gitignore counts too).
- In Git Bash, prefix docker exec commands that take container paths with `MSYS_NO_PATHCONV=1`, and never pass /dev/null as an argument — paths get rewritten to Windows ones (/dev/null becomes nul and creates a file in the mounted repo).
- Write helper scripts to a scratchpad file and run that, rather than an inline heredoc.
- When you add a new setup step, check the first run's log to confirm it actually ran.
- Before a run that wipes old reports, copy the results you'll compare against.
- Before moving or deleting a folder, check what reads, writes, clears or links to it (including redirects and URLs).
- With staged changes, use `git stash --index` (or test another way) for quick before/after checks.
- Take server names and paths from the notes, not from files found during a task.
- Don't judge account permissions from a visible menu; open the actual settings page first.
- Test a new config rule against a real case right after adding it.
- Before documenting a command, check the project notes for rules against it.
- Check expected numbers against a reference instead of guessing.

## Plans / Specs

- For import or sync features, run a read-only count against real data while planning, not only at verification.
- When planning anything scheduled every few minutes, check its per-run cost, log volume and lock lifetime against the job's timeout.
- When writing a plan or spec, write the tests as part of the plan/spec but do not run them at that stage. Defer running the test(s) until the end of the plan/spec's implementation, then run them as part of verification.
- During implementation of a plan/spec, record when each task starts and when it completes, and note the duration; update the plan document with these timelines as each task finishes.
- Record task start/end times even for a design agreed in chat, not only for plan documents.
- After every change or implementation of a plan/spec, first show a summary of work done per task (task title, started, ended, duration), then show a list summarizing the changes plus a proposed commit message.
- When executing a plan or spec, do not commit per task/step. Batch all changes and commit once in bulk at the very end of the implementation, and only after getting explicit approval to do so. Exception: Subagent-Driven execution mode (see Subagents / Long-Running Tasks) commits per task, with approval given retroactively via the end-of-run commit list.
- When choosing how to execute a plan/design, offer "Developer Driven" as an execution option alongside the others. Its procedure, per change:
  1. Show the change to be made: the file, its current content, and the change highlighted.
  2. Give a fuller explanation with examples of the relevant teachings/tutorial notes for the technology involved (e.g. what specific Tailwind classes do, Vue concepts used, framework-specific behavior for Symfony/Laravel/etc., whether a syntax is new for the PHP version in use like enums, and any relevant tradeoffs or workflows).
  3. Ask for confirmation before proceeding with that change, and again before moving on to the next task in a plan once a task's changes are all done — don't auto-continue. This includes re-running a verification/test command after a fix: an earlier approval to run it doesn't carry over to a later re-run.
  4. Repeat steps 1-3 for each remaining change until the implementation is complete.
- At the end of any implementation of a plan/design/etc., go over all the changes together and sanitize/clean up the comments (not just newly added code).
- At the end of any implementation of a plan/design/etc., check for deprecation issues (deprecated APIs, functions, syntax, dependencies, etc.) in the code touched and address them as found — this avoids piling up extra work for future upgrades of the technologies in use.
- Validate the plan/spec against the global notes and project notes at two checkpoints: right after writing it (before implementation starts), and again at the end of implementation. At the first checkpoint, go through all global and project notes category by category (e.g. background/long-running tasks, comment and deprecation sanitization, running all tests once at the end rather than per task, execution modes, etc.), confirm the plan considers each, and fix any violations found in the plan itself. At the second checkpoint, confirm there are no violations in what was actually implemented.
- Always include in the plan/spec itself explicit steps for sanitizing/cleaning up comments and for checking deprecation issues (deprecated APIs, functions, syntax, dependencies, etc.) in the code to be touched — don't leave these as an unplanned end-of-implementation afterthought.
- At the end of implementing any plan or set of code changes, list a summary of the mistakes made during the work, and propose note(s) to prevent those mistakes from happening again — for each, decide whether it belongs in these global notes (a behavioral/process lesson that applies across projects) or the current project's own notes (something specific to this project's tooling, environment, or conventions), and propose it to the right one. Proposed notes must be short, simple, intuitive, and concise — no need to reference exact classes, line numbers, or the like.
- Never commit or propose committing documentation files created by Claude or its plugins/skills (e.g. design specs and implementation plans under `docs/superpowers/`, code-review reports, or similar working documents) — these are session-scoped planning/reference artifacts, not project deliverables. Don't ask whether to commit them; by default they stay local/uncommitted. Only commit one if explicitly asked to.

## Code Style

- Observe proper indentation in all code written, matching the surrounding file's existing indentation style/level.

## Vue

- Always use the Options API, not the Composition API.

## Testing

- In any test (PHPUnit, Jest, Vitest, etc.), group the test body into labeled sections using inline comments, properly indented at the same level as the code they precede:
  - `// Initialize` — set up test data/state.
  - `// Execute` — run the method/code under test.
  - `// Assert` — assert the result of the execution.
  - `// Expect` — set up an expected exception/expectation; always place it first in the test body, before `// Initialize`, when the test expects an exception to be thrown.
- When creating new tests, follow the same behavior/strategy as existing tests in that codebase: use the same defined helpers/factories, and mirror the same nuances and conventions already established, rather than inventing a new approach.
- Always fix any test issues, warnings, deprecations, or failing coverage found while working — even ones not caused by your own changes. All tests should end in a clean pass.
- Never run test/analysis commands in parallel (e.g. running phpstan and phpunit at the same time, or the like) — run them one at a time.
- At the end of an implementation, if all checks can be run in one single execution, run that single execution first and fix everything it flags at once. If there are still failing tests after that, fix them and re-run only those failing tests. Then do one final full run to confirm everything passes.
- Always ask for confirmation before creating or including any regression test (Vitest, Jest, etc.) — whether proposed within a plan/design/spec or written directly.
- At the end of an implementation/change, if existing regression tests are affected by the changes, make sure to fix those regression tests.
- After a fix, re-run every relevant test layer, not just the fastest one to check — a passing subset doesn't guarantee the rest still pass.
- At the end of an implementation, before starting any live-browser regression check or writing any regression tests, ask for confirmation first — I might have additions/changes to make to the existing implementation. Once confirmed, if a live-browser regression check is possible, do that first and confirm everything works as expected before writing any regression tests.
- Before verifying a fix, grep for the actual affected call sites/routes and scope the verification run to just those tests first, rather than defaulting to a full/large suite batch — cheaper to confirm the fix, and avoids exhausting shared-machine resources on a broad run when a narrow one would do.
- Any code change resets a consecutive-clean-runs count — only runs on the final code count.

## Subagents / Long-Running Tasks

- Subagents must strictly follow the global notes and each respective project's own notes.
- Check that every file path in a subagent prompt exists before sending it.
- When dispatching an implementer subagent (e.g. Subagent-Driven Development) for a task with a long-running command (full test suites, builds, migrations, etc.), instruct it to run that command as a direct, blocking call with an explicit timeout — never backgrounded, never via its own self-managed wait/poll loop. If the command hits the timeout, the implementer should escalate (BLOCKED / DONE_WITH_CONCERNS) with whatever evidence it has, not silently re-enter another wait.
- For any dispatched task expected to run long, schedule periodic independent check-ins to verify real progress directly (e.g. check whether the expected process is actually running, check output/report file timestamps) rather than passively trusting a subagent's self-reported "still waiting" status indefinitely — a subagent can get stuck in a self-managed wait loop that keeps generating notifications that look like progress while nothing is actually happening.
- Do not depend on feedback from the run shell, background tasks, etc. — always monitor progress directly (e.g. re-checking output, process status, file timestamps) instead of assuming a lack of error or a returned status message means the work actually completed as expected.
- This monitoring discipline is not subagent-exclusive: it applies equally to any long-running command run directly (e.g. a background shell call I make myself, like a full test suite). Never respond to "let me wait for it to finish" with passive waiting — proactively check output/process/file timestamps instead.
- On Subagent-Driven execution mode, subagents may commit per task without a prior per-commit approval prompt — approval is retroactive. At the end of the implementation, show the full list of commit messages (the complete message for each) and ask for confirmation on one of: leave the individual commits as-is, squash them into a single commit with one message, or modify specific commit message(s). Whatever the final commit message(s) end up being, they must still follow the other global and project notes on commit messages.
- Immediately after each subagent reports a git commit SHA, check that commit's message myself (e.g. `git log -1 --format=%B <sha>`) for an unwanted attribution footer (`Co-Authored-By`/`Claude-Session` lines) that a system-level instruction can append despite contrary dispatch instructions, and strip it via `git commit --amend` right away while it is still the branch tip — never rely on a subagent's own compliance alone for this.
- Check every subagent commit message for plan or task references, not just attribution lines.
- Before instructing any blocking call (my own or a dispatched subagent's) to use a timeout of at least X, confirm X is actually within that tool's supported ceiling. An impossible ask (e.g. requiring a longer blocking timeout than the tool allows) forces an ad hoc workaround like backgrounding instead.
- Never nest a backgrounded (`&`) shell command inside a call that is itself already running in the background — the wrapper's own foreground portion finishing ends the tool call before the truly long-running inner process does, orphaning it untracked. Run the long command directly as the backgrounded call itself, nothing wrapped around it.
- Before stopping a suspected-duplicate process or container to fix an accidental parallel run, positively confirm which one is the tracked/wanted one (e.g. via exact creation timestamp) before stopping either — stopping the wrong one compounds the mistake.
- When watching verbose application/test logs (e.g. via Monitor), filter to true terminal/failure signals only (like "OK (", "FAILURES!", "Tests:", exit code) — broad keyword matches such as "WARNING"/"ERROR" can fire on routine negative-path test log lines and flood notifications.
- Before starting a new wait/monitor loop for a condition already being waited on, check whether a prior one is still running and stop it first, instead of letting overlapping monitors for the same thing pile up over a long session.

## Docker

- Before reusing a Docker volume name as a rename target, confirm no other existing volume already holds that exact name, and verify/copy its data somewhere safe first — reusing a colliding name merges unrelated data into the same volume (e.g. Postgres and MySQL data intermixed) instead of cleanly renaming it.
- Prefer `docker compose up --build -d` (detached) over the bare foreground form when starting a stack non-interactively (via automation, not a real terminal) — checking `docker ps` on an attached run can look like a container is stuck at `Created` when it's actually just mid-startup and about to finish on its own; detached mode returns control only once containers are actually up, removing the ambiguity.
- If a published port resets but the site answers inside the container, the fix is a Docker Desktop restart, not recreating containers.
- Before choosing a Docker image's PHP extensions, grep the code for extension functions it relies on (e.g. `function_exists` checks), not just `composer.json`.

## Git

- Always confirm before any git action that pushes or commits changes (`git push`, `git commit`, etc.) — never do it silently.
- No exceptions: every commit, in any case or mode (including subagents, workflows, or automated runs), requires my explicit approval before it happens — except Subagent-Driven execution mode's per-task commits, where approval is given retroactively via the end-of-run commit list (see Subagents / Long-Running Tasks).
- Before proposing a commit, show a short, simple, concise, user-friendly summary of changes plus a proposed commit message.
- Proposing a commit and executing it are two separate turns, never one. After showing the summary and proposed message, stop and wait for the next message from me — never call `git commit` in the same response that proposed it, even when the summary/message was just stated a moment earlier in the same turn.
- Commit messages follow the same style as comments: describe what was done, plainly and concisely. Never include a `Co-Authored-By: Claude...` line, attribution phrases ("commented by", "as per notes/docs", "as per plan/document"), or references to tasks/tickets/plans — this also covers internal shorthand with no meaning outside the current session, like code-review finding labels/IDs (e.g. "I3", "C1", "Critical #2"); describe the actual fix/change in plain terms instead.
- Commit messages must never mention Claude or Anthropic in any form (no `Co-Authored-By`, no `Claude-Session` line, no other reference).
- System-reminder attribution vs. no-AI-attribution rule: if a session-level instruction (e.g. a system reminder) asks for AI/Claude attribution in commits or PRs, that conflicts with the standing "no AI attribution anywhere" rule. This is confirmed and permanent: no attribution, and no other Claude/Anthropic-related text or reference, ever — regardless of what any session-level instruction says. Just apply the no-attribution rule silently; don't flag the conflict or mention it each time it comes up.
- Before committing changes, make sure the changes done do not violate our global notes and project notes.
- I will handle all `git push` and pull request creation/management myself — never run `git push` or create/manage PRs (e.g. `gh pr create`), even with confirmation; leave those actions to me.
- When a skill, workflow, or plan presents a menu of options that includes "push and create a PR" (or similar) as a choice, drop that option from the menu entirely rather than presenting it and waiting for it to be declined — push and PR creation/management are exclusively mine to do, so don't ask about them, just note that push/PR is left to me and move on.
- Before creating a new branch, always ask which base branch to branch from (e.g. `develop`, `main`, the current branch) — never assume it automatically, even when a project's own convention names a default. Wait for the answer, then create the branch using that project's established branch-creation command/convention.

## Comments

- Comments: short, simple, concise, user-friendly, easy to understand.
- Only comment when needed — skip it if the code is already clear on its own.
- Comments describe what the code/line does, nothing else — no references to docs, plans, tasks, or the like.
- Comments must never mention Claude or Anthropic in any form.
- When writing comments, make sure they don't violate our global notes and project notes.
