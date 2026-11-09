# Modular architecture, Phase 9c (front-end) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans (or the Developer Driven procedure from the team notes) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the last legacy front-end code into the modules: the 11 Stimulus controllers, the two stylesheets, the images and the Vite entry files. Bring all of it under ESLint, Stylelint and the 100% Vitest coverage gate, with a Vitest test per controller. Nothing a user would notice changes: same pages, same behaviour, same built CSS.

**Architecture:**
- **Controllers** move to the module whose templates use them, into `src/<Module>/Presentation/assets/controllers/`, each with `import.meta.stimulusIdentifier = '<today's name>'` so its Stimulus name stays:
  - Attendance: `attendance_mark`, `day_picker`; Grading: `comment_picker`; Schools: `grading_periods`, `region_division`; Staff: `collection`; Dashboards: `refresh`; Core (used by several modules): `clipboard`, `image_preview`.
  - `hello_controller.js` (Symfony skeleton, used nowhere) is deleted.
- **CSRF script:** `assets/controllers/csrf_protection_controller.js` is not a Stimulus controller; `bootstrap.js` imports it for its side effect. It moves to `src/Core/Presentation/assets/csrf_protection_controller.js`, outside any `controllers/` folder, so the controller glob never picks it up (no exclusion pattern needed). Its file name and its `data-controller="csrf-protection"` marker stay.
- **Stylesheets:** `assets/styles/{app,admin}.css` → `src/Core/Presentation/assets/styles/` (they style every layout). Their content does not change.
- **Images:** `deped-seal.png` (read by Learners and Staff) → `resources/images/deped-seal.png`, beside `resources/who`; the three staff ID backgrounds → `src/Staff/Infrastructure/StaffId/` next to `StaffIdPdf`.
- **Entry files:** `assets/{app,bootstrap}.js` → `src/Core/Presentation/assets/`; `vite.config.js` points the `app` entry there; the globs inside are rewritten relative to the new place.
- `assets/controllers.json` stays where it is: Flex writes to it when a UX package is installed, and `vite-plugin-symfony` is pointed at it (`stimulus: './assets/controllers.json'`). It is the only file left in `assets/`.

**Tech Stack:** Vite 8 (`pentatrion/vite-bundle`, `vite-plugin-symfony`), Stimulus, Turbo 8, Vue 3 (Options API), Tailwind 4.3, ESLint 10, Stylelint 18 (`stylelint-config-standard`), Vitest + jsdom.

**Spec:** `docs/superpowers/specs/2026-11-06-modular-architecture-design.md` (section 7 "existing Stimulus controllers get Vitest tests (needed for the JS coverage gate)", section 8 "at the end everything is covered").
- **Owner decisions (2026-11-09):** 9c is the front-end part of Phase 9 and includes the Vitest tests; Stylelint rules are tuned to the existing CSS rather than rewriting it; the seal goes to `resources/images/`, the staff ID images to Staff; the entry files move into Core; the owner merges into `develop` after 9c.
- **Scope:** structure only. The plan is committed with the phase.

**Branch:** `feature/modular-architecture` (9b committed as `d96e3bf`, notes as `0d4066b`).

## Timeline

| Task | Started | Ended | Duration |
|---|---|---|---|
| 0. Planning | 2026-11-09 22:31:09 | 22:36:10 | 5m 1s |
| 1. Entry files, stylesheets and images | 22:36:10 | 22:43:17 | 7m 7s |
| 2. Controllers into their modules, with Vitest tests | 22:43:17 | 23:00:16 | 16m 59s |
| 3. Lint and coverage scopes | 23:00:16 | 23:04:15 | 3m 59s |
| 4. Docs, comments, deprecations | 23:04:15 | 23:04:53 | 38s |
| 5. Verification | 23:04:53 | 01:03:49 (11-10) | 1h 58m 56s |

## Global Constraints

**Structure only.**
- Every Stimulus identifier stays (`attendance-mark`, `day-picker`, `comment-picker`, `grading-periods`, `region-division`, `collection`, `refresh`, `clipboard`, `image-preview`); templates do not change.
- The built CSS has the same content: same `app-*.css` hash as before the move (`dd1ff82a…`, `app-6wVu3iE_.css`), or a diff that shows only ordering.
- The PDFs and ID cards that draw the seal and the staff ID backgrounds are the same size and type.
- Routes match HEAD; `doctrine:migrations:diff` stays empty.

**Stylelint (owner):** the CSS is not rewritten. In `config/stylelint.config.cjs`:
- `selector-class-pattern`: allow BEM (`block`, `block__element`, `block--modifier`, lowercase kebab parts);
- keep the existing notation: `color-function-notation: 'legacy'`, `alpha-value-notation: 'number'`, `color-function-alias-notation: null`, `media-feature-range-notation: 'prefix'`;
- `no-descending-specificity: null`, with a comment saying why (reordering rules would change the cascade);
- fix by hand only findings that cannot change rendering (keyword case, hex length, an empty line before a comment); any other finding goes to the ledger with its decision before changing CSS.

**Vitest.** One test file per controller in `tests/<Module>/Javascript/<name>_controller.test.js` (CSRF script: `tests/Core/Javascript/csrf_protection_controller.test.js`), in the style of `tests/Classes/Javascript/*.test.js`: register the controller on a fresh `Application`, render HTML into `document.body`, `// Initialize`, `// Execute`, `// Assert`. 100% lines, branches, functions and statements for every file under `src/*/Presentation/assets/`, except the two entry files (`app.js`, `bootstrap.js`: wiring that only runs inside Vite, checked by the build and the browser tests), which are excluded from coverage by path.

**New and moved code.** ESLint clean (4-space indent, single quotes, semicolons, trailing commas), no `console` output, plain comments with no Claude/Anthropic mention.

**Moving files and helper scripts.**
- After moving, grep the old paths (`assets/controllers`, `assets/styles`, `assets/images`, `assets/app.js`, `assets/bootstrap.js`) in `src`, `tests`, `config`, `docker`, `vite.config.js`, `package.json`, `.claude`, `CLAUDE.md`, `AGENTS.md`, and the folder's bare name `assets/` in docs and config comments.
- Run checkers on every file a rewrite touched (whole ESLint and Stylelint scopes).
- Helper scripts in the scratchpad, Write tool, LF, `python3`. No inline heredocs, no `<<`, no command left reading input, no `sed` for text with backslashes, no `git add -N`. `git -C` for the worktree.
- Before the first change: copy the built `public/build/assets/app-*.css` and `.js`, and save the HEAD route dump.

**Running checks.** Tests are written per task and run once in Task 5, one at a time, in Docker. Read each output. Clear `reports/phpstan/.cache` before a PHPStan run that counts. deptrac through `make test deptrac`. Rerun the other checkers after fixing one. Log task ends and starts together; compute durations with a command.

**Live-browser check.** Headless, without asking (owner). Worktree `.superpowers/sdd/2026-11-07-modular-architecture-phase2d-schools/head` to `0d4066b`. Servers started without removals in the same command; dev data reset per side and restored after.

**Commit.** One commit at the end after approval, through the Base hook, including this plan (`git add -f`).

## Review Focus

1. **Controller names.** A moved controller registered under a different name silently stops working (the page still renders). Every template's `data-controller` value must match an identifier. Pinned by a Vitest test that loads every module controller file and checks the identifiers against the list above, and by the live check's interactions.
2. **The CSRF cookie on submit.** Forms and login stop working if the CSRF script is registered as a controller or no longer imported. Pinned by its Vitest test (token generation, header for Turbo, cookie removal) and by the browser suite's sign-ins.
3. **Same CSS.** Moving the stylesheets must not change their order in the bundle (`app.css`, then `admin.css`, then Tailwind). Pinned by comparing the built CSS with the saved copy.
4. **PDF images.** The seal and staff ID backgrounds are read by path from PHP; a wrong path fails only when that PDF is printed. Pinned by the existing Learners and Staff PDF tests, which render with the real images.
5. **Vue and module globs after the entry moves.** `createVueComponentResolver` and the controller glob must still find every module's files from the new entry location. Pinned by the build output (controller count) and the browser suite.

## Rulings in this plan

- `assets/controllers.json` stays at the root (Flex manages it); everything else leaves `assets/`.
- The entry files are excluded from JS coverage by path; they only run inside Vite.
- The CSRF script keeps Symfony's file name, so a later recipe update is easy to compare; it sits outside `controllers/` instead of being excluded by pattern.

---

### Task 1: Entry files, stylesheets and images

**Files:**
- Move: `assets/app.js`, `assets/bootstrap.js` → `src/Core/Presentation/assets/`; `assets/styles/{app,admin}.css` → `src/Core/Presentation/assets/styles/`; `assets/controllers/csrf_protection_controller.js` → `src/Core/Presentation/assets/csrf_protection_controller.js`.
- Move: `assets/images/deped-seal.png` → `resources/images/deped-seal.png`; `assets/images/staff-id-{big,small-front,small-back}.jpg` → `src/Staff/Infrastructure/StaffId/`.
- Modify: the imports and globs in the moved `app.js`/`bootstrap.js` (relative to `src/Core/Presentation/assets/`); `vite.config.js` (`app: './src/Core/Presentation/assets/app.js'`, `symfonyPlugin({ stimulus: './assets/controllers.json' })`); `src/Core/Presentation/assets/styles/tailwind.css` (drop the temporary `@source "../../../../../assets";`); the image paths in `IdCardImages`, `ServiceRecordPdf`, `StaffIdImages` (`/resources/images/deped-seal.png`) and `StaffIdPdf` (`__DIR__` for its three images, dropping the project-dir prefix if nothing else needs it).

- [ ] **Step 1:** Moves and edits with a scratchpad script.
- [ ] **Step 2: Setup checks:** `pnpm build` (no errors; same CSS content as the saved copy; the JS bundle registers the same number of controllers), `cache:clear`, `lint:container`, routes identical.

### Task 2: Controllers into their modules, with Vitest tests

**Files:**
- Move each controller to its module (Architecture), adding `import.meta.stimulusIdentifier`; delete `hello_controller.js` and `assets/controllers/`, `assets/styles/`, `assets/images/`.
- Tests (new), each covering every branch of its file:
  - `attendance_mark`: a chip replaces each select; clicking cycles '' → absent → late → cutting → ''; chip label, class and aria-label (with and without a `<label>`); the counts line, and no counts target.
  - `day_picker`: choosing a date calls `window.location.assign('<url>/<date>')` (stubbed); an empty value does nothing.
  - `comment_picker`: counts checked boxes by their labels, singular and plural, "+ typed" with a non-empty textarea, "none" when nothing is chosen, no textarea.
  - `grading_periods`: switching system draws rows split evenly between the year dates; returning to the saved system restores the saved rows and legend; changing year dates on the saved system keeps them; unparseable or reversed dates leave the dates empty; an unrelated field does nothing.
  - `region_division`: options follow the region; a value typed before the dropdowns existed is kept while its region is unchanged; changing region clears it; an unknown region gives only the placeholder.
  - `collection`: add inserts the prototype with the next index and focuses its first input; remove deletes the row; the empty message follows the row count; no empty target.
  - `refresh`: reloads the frame every interval (fake timers); no `reload` method does nothing; disconnect stops the timer and the listener; a missing frame visits the response URL (`window.Turbo.visit` stubbed).
  - `clipboard`: adds a Copy button when `navigator.clipboard` exists, none otherwise; copying writes the text and shows "Copied", then "Copy" again after 1.5 s; disconnect removes the button.
  - `image_preview`: no file hides both; a wrong type and a file over 5 MB show their messages with the error class; a good image shows the preview (`URL.createObjectURL` stubbed) and revokes the previous URL; disconnect revokes.
  - `csrf_protection_controller`: a submit with a marked field generates a token and cookie; Turbo submit adds the header; Turbo submit-end removes the cookie; fields without the marker, invalid names and invalid tokens are left alone (every branch).
  - `tests/Core/Javascript/controller_identifiers.test.js`: every `src/*/Presentation/assets/controllers/*_controller.js` declares the identifier listed in Global Constraints. The plugin reads `import.meta.stimulusIdentifier` from the source text at build time (it is not exported), so the test reads each file's source with `import.meta.glob(…, { query: '?raw', import: 'default', eager: true })` and matches the declaration.

- [ ] **Step 1:** Write the tests.
- [ ] **Step 2:** Move the controllers.
- [ ] **Step 3: Setup checks:** `pnpm build`; the templates' `data-controller` values all match a registered identifier (grep).

### Task 3: Lint and coverage scopes

- `docker/entrypoint_test.sh`: ESLint on `vite.config.js config 'src/*/Presentation/assets' 'tests/*/Javascript'` (no root `assets/`); Stylelint unchanged (`src/*/Presentation/assets/**/*.css` now includes the two stylesheets).
- `config/stylelint.config.cjs`: the rules in Global Constraints.
- `config/vitest.config.js`: exclude `src/Core/Presentation/assets/{app,bootstrap}.js` from coverage.
- `config/eslint.config.mjs`: nothing to change unless the moved files need the Turbo global (`refresh` uses `Turbo.visit`): declare it as a global for that file rather than changing code.

- [ ] **Step 1:** Config edits; run ESLint, Stylelint and Vitest with coverage; fix what they report (ledger any CSS change first).

### Task 4: Docs, comments, deprecations

- [ ] **Step 1:** `CLAUDE.md`: the front-end lines (entry and styles in `src/Core/Presentation/assets/`; controllers per module with `stimulusIdentifier`; the CSRF script's new place in the gotcha that warns to keep it out of the controller list; `assets/controllers.json` is the only root asset and Flex owns it; images: seal in `resources/images/`, staff ID backgrounds beside `StaffIdPdf`; the Tailwind gotcha no longer mentions the root `assets/`). `AGENTS.md` if it names the old paths.
- [ ] **Step 2:** Comment sweep over everything touched; `debug:container --deprecations`; `pnpm build` warnings.

### Task 5: Verification

- [ ] **Step 1: Scoped runs:** `make test vitest`, `make test eslint`, `make test stylelint`; the Learners and Staff PDF/ID tests (images).
- [ ] **Step 2: Guard breaks:** drop `stimulusIdentifier` from one moved controller and watch the identifier test fail; point `StaffIdPdf` at the old image path and watch its test fail; restore both.
- [ ] **Step 3: Full runs on the final code, one at a time:** `make test all`, `make test-feature` (100.00%), `make regression-test` (5/5). Schema valid, diff empty, routes identical.
- [ ] **Step 4: Fresh whole-change review** (most capable model, read-only); fix Critical/Important and note breaks with a failing test first; full runs again.
- [ ] **Step 5: Headless live check**, HEAD against the branch: the built CSS identical; on each side, the pages that use each controller, with one interaction each (roll call chip click and counts, card slip comment picker, school year grading periods switch, school form region/division, PDS list add/remove row, photo preview hint for a wrong file type, the "now" frame's refresh attribute); the learner ID and staff ID PDFs (size and type); restore the dev data.
- [ ] **Step 6: Report** and propose the commit (code and this plan). Commit only after approval:

```
Move the front-end code into the modules

Move the Stimulus controllers into the modules that use them, and the
stylesheets, the Vite entry files and the CSRF script into Core, keeping
every controller's name. Move the DepEd seal to resources and the staff
ID backgrounds into Staff. ESLint, Stylelint and the JS coverage gate now
cover all front-end code, with a Vitest test for each controller.
```
