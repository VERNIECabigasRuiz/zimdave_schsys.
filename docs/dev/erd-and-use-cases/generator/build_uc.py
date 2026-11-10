"""Builds the use case PDFs: one overview plus one per module area."""
import importlib
import sys
from datetime import date
from pathlib import Path

from common import diagram, esc, graph, page, to_pdf
from build_erd import sectioned
from dot_erd import key_html
from dot_uc import actor_key, map_dot, state_dot, themed

OUT = Path(sys.argv[1])
ONLY = sys.argv[2:]  # optional module file names to build
MODULES = ["uc_platform", "uc_schools", "uc_classes", "uc_learners", "uc_attendance", "uc_grading",
           "uc_nutrition", "uc_lessonplans", "uc_staff", "uc_dashboards"]


def li(items):
    return "<ul>" + "".join(f"<li>{i}</li>" for i in items) + "</ul>" if items else "<span class='muted'>-</span>"


def ol(items):
    return "<ol>" + "".join(f"<li>{i}</li>" for i in items) + "</ol>"


def uc_card(u):
    return f"""<div class='uc'><div class='head'><span class='id'>{u.id}</span>{esc(u.name)}</div><div class='body'><table>
<tr><th>Primary actor</th><td>{u.actors}</td></tr>
<tr><th>Goal</th><td>{u.goal}</td></tr>
<tr><th>Where (route)</th><td><code>{esc(u.route)}</code></td></tr>
<tr><th>Preconditions</th><td>{li(u.pre)}</td></tr>
<tr><th>Main flow</th><td>{ol(u.flow)}</td></tr>
<tr><th>Alternatives and refusals</th><td>{li(u.alt)}</td></tr>
<tr><th>Result (data changed)</th><td>{u.post}</td></tr>
<tr><th>In the code</th><td class='small'><code>{esc(u.tech)}</code></td></tr>
</table></div></div>"""


def views_table(views):
    rows = "".join(f"<tr><td><b>{esc(v.name)}</b></td><td>{v.actors}</td><td><code>{esc(v.route)}</code></td>"
                   f"<td>{v.shows}</td><td class='small'><code>{esc(v.query)}</code></td></tr>" for v in views)
    return ("<table><tr><th style='width:20%'>Page / file</th><th style='width:12%'>Who</th><th style='width:24%'>Route</th>"
            f"<th>What it shows</th><th style='width:16%'>Query</th></tr>{rows}</table>")


# the module each area's use cases belong to; the platform document mixes three
AREA_MODULE = {
    "uc_schools": "Schools", "uc_classes": "Classes", "uc_learners": "Learners", "uc_attendance": "Attendance",
    "uc_grading": "Grading", "uc_nutrition": "Nutrition", "uc_lessonplans": "LessonPlans", "uc_staff": "Staff",
    "uc_dashboards": "Dashboards",
}
PLATFORM_CASES = {"B1": "Billing", "N1": "Announcements"}
OVERVIEW_CASES = {
    "P1": "Access", "P2": "Schools", "P3": "Schools", "S1": "Schools", "S2": "Classes", "S3": "Learners",
    "S4": "Attendance", "S5": "Grading", "S6": "Staff", "S7": "LessonPlans", "T1": "Grading", "T2": "LessonPlans",
    "T3": "Classes", "A1": "Learners", "A2": "Attendance", "A3": "Nutrition", "A4": "Grading", "I1": "Classes",
    "F1": "Staff", "D1": "Dashboards",
}


def area_module(m):
    return AREA_MODULE.get(m.__name__, "Access")


def case_module(m):
    if m.__name__ == "uc_platform":
        return lambda cid: PLATFORM_CASES.get(cid, "Access")
    return lambda cid: area_module(m)


def map_block(src, title, module_of_case, note):
    dot, actors, mods = map_dot(src, title, module_of_case)
    return graph(dot) + actor_key(actors) + key_html(mods, note)


def module_doc(m, number):
    today = date.today().isoformat()
    b = [f"""<div class='cover'><h1>ZIM School - Use Cases</h1><div class='sub'>{number:02d}. {esc(m.TITLE)}</div>
<div class='meta'>Modules: {m.MODULES}<br/>{len(m.USE_CASES)} use cases (changes) · {len(m.VIEWS)} views and reports<br/>
Written from the use case commands, handlers, policies and rules in <code>src/</code> on {today}.</div></div>"""]
    b.append(f"<h2>1. What this area does</h2>{m.INTRO}")
    b.append("<div class='page'></div><h2>2. Use case map</h2><p>A standard use case diagram: the actors on the left, each use case as an ellipse inside the system frame, and a line for each use case an actor starts. A dashed line has a note on when it applies.</p>" + map_block(m.DIAGRAM, m.TITLE.split(":")[0], case_module(m), "Ellipse colour = the module the use case belongs to."))
    for k, (title, src) in enumerate(m.FLOWS, 1):
        mod = area_module(m)
        drawn = graph(state_dot(src, mod), "flow") if src.lstrip().startswith("stateDiagram") else diagram(themed(src, mod), "flow")
        b.append(f"<div class='page'></div><h2>3.{k} Flow: {esc(title)}</h2>" + drawn
                 + key_html([area_module(m)], "Drawn in the colour of the module this flow belongs to."))
        if hasattr(m, "FLOW_NOTES") and title in m.FLOW_NOTES:
            b.append(m.FLOW_NOTES[title])
    if m.USE_CASES:
        b.append("<div class='page'></div><h2>4. Use cases (changes)</h2>"
                 "<p>Each card is one use case command sent through the use case bus. It runs in one database "
                 "transaction: if any rule refuses, nothing is saved and the message in <i>Alternatives and refusals</i> "
                 "is shown to the user.</p>")
        b += [uc_card(u) for u in m.USE_CASES]
    else:
        b.append("<h2>4. Use cases (changes)</h2><p>None: this area only reads. Its pages are listed below.</p>")
    b.append("<div class='page'></div><h2>5. Views, downloads and reports (read only)</h2>"
             "<p>Each row is one query sent through the query bus. Queries change nothing.</p>" + views_table(m.VIEWS))
    if hasattr(m, "EXTRA"):
        b.append(m.EXTRA)
    return page(f"Use cases {number:02d}", sectioned("\n".join(b)))


OVERVIEW_ACTORS = """flowchart LR
    ADM(["Administrator"]) --> P1["Platform: accounts, plans, announcements"]
    ADM --> P2["Schools: schools, years, divisions, principals"]
    ADM -. "acts on any school" .-> P3["School screens"]
    PRN(["Principal"]) --> P3
    P3 --> S1["Teachers, calendar, class periods"] & S2["Sections, subjects, timetable"] & S3["Learners, transfers, SF1, IDs"] & S4["Attendance, nutrition overview, reopen SF2/SF8"] & S5["Grading: open, close, lock, change requests"] & S6["Service records, teachers' PDS"] & S7["Lesson plans (read)"]
    TCH(["Teacher"]) --> T1["Grade sheets, change requests"] & T2["Lesson plans"] & T3["Own load"]
    ADV(["Teacher as adviser"]) --> A1["Class list, enrollment"] & A2["Roll call, SF2"] & A3["Weighing, SF8"] & A4["Card slips"]
    SOLO(["Teacher on Individual plan"]) --> I1["Own section and subjects"]
    STF(["Principal / Teacher"]) --> F1["PDS, staff ID, photo"]
    STU(["Student"]) --> D1["Dashboard: enrolment and timetable"]
"""

LIFECYCLE = """sequenceDiagram
    autonumber
    participant C as Controller
    participant B as Use case bus
    participant V as Validator
    participant H as Handler
    participant L as Transaction lock
    participant P as Policy / rules
    participant DB as Database
    C->>B: handle(Command)
    B->>V: validate (Default group)
    V-->>B: violations? -> form errors
    B->>DB: begin transaction
    B->>H: __invoke(command)
    H->>L: acquire('learner-12', busy message)
    H->>DB: read fresh data
    H->>P: may this account do it? do the rules allow it?
    P-->>H: refuse -> throw DomainException
    H->>DB: write changes + ActivityLog
    H-->>B: Result::success(data)
    B->>DB: commit (or roll back on any exception)
    B-->>C: Result (success, or the DomainException's code and message)
"""


def overview_doc(mods):
    today = date.today().isoformat()
    total = sum(len(m.USE_CASES) for m in mods)
    views = sum(len(m.VIEWS) for m in mods)
    b = [f"""<div class='cover'><h1>ZIM School - Use Cases</h1><div class='sub'>00. Overview: actors, conventions and the full index</div>
<div class='meta'>{total} use cases (changes) · {views} views and reports · {len(mods)} module documents<br/>Generated {today}.</div></div>"""]
    b.append("""<h2>1. Purpose of this set</h2>
<p>These documents describe <b>what each kind of user can do</b> in ZIM School, step by step, with the rules that can
refuse an action and the data each action changes. Together with the ERD document they explain the functionality and
inner workings of the project. This overview introduces the actors and the conventions every use case follows; each
numbered document covers one area of the system.</p>
<h3>The documents</h3><table><tr><th>No.</th><th>Area</th><th>Modules</th><th>Use cases</th><th>Views</th></tr>""")
    for i, m in enumerate(mods, 1):
        b.append(f"<tr><td>{i:02d}</td><td>{esc(m.TITLE)}</td><td>{m.MODULES}</td><td>{len(m.USE_CASES)}</td><td>{len(m.VIEWS)}</td></tr>")
    b.append("</table>")
    b.append("""<h2>2. Actors</h2><table><tr><th>Actor</th><th>Who they are</th><th>Main screens</th></tr>
<tr><td><b>Administrator</b></td><td>Runs the platform. Role <code>ROLE_ADMIN</code>; the seeded account is <code>zim</code>.</td><td><code>/admin/...</code>, and every <code>/schools/{id}/...</code> page of any school</td></tr>
<tr><td><b>Principal</b></td><td>Head of one school in the current school year (a <code>SchoolAssignment</code> with role principal). Only on plans that include a principal (Basic).</td><td><code>/principal/...</code> and <code>/schools/{id}/...</code> of their own school</td></tr>
<tr><td><b>Teacher</b></td><td>Assigned to a school this school year. Teaches loads; may also be the <b>adviser</b> of one section.</td><td><code>/teacher/...</code></td></tr>
<tr><td><b>Adviser</b></td><td>A teacher who advises a section. Gets the class pages: class list, attendance, nutrition, card slips.</td><td><code>/teacher/class/...</code></td></tr>
<tr><td><b>Individual-plan teacher</b></td><td>The single teacher of a school on the Individual plan (no principal). Also creates their own section and subjects.</td><td><code>/teacher/section</code>, <code>/teacher/subjects</code></td></tr>
<tr><td><b>Student</b></td><td>A learner's account (username = LRN), created on enrollment.</td><td><code>/student</code></td></tr>
<tr><td><b>System</b></td><td>Automatic work: activity logging, sign-in recording, derived values (general averages, BMI, SF1 remarks).</td><td>-</td></tr>
</table>""")
    b.append("<div class='wide'></div><h2>3. Who does what</h2>" + map_block(OVERVIEW_ACTORS, "ZIM School", lambda cid: OVERVIEW_CASES.get(cid, "Core"), "Ellipse colour = the module that area belongs to."))
    b.append("""<div class='page'></div><h2>4. How every use case runs</h2>
<p>All changes follow the same path. Knowing it once explains the <i>In the code</i> row of every card.</p>""" + diagram(themed(LIFECYCLE, "Core")) + key_html(["Core"], "The bus, locks and activity log are Core services.") + """
<h3>Conventions</h3><table><tr><th>Concept</th><th>What it means for a use case</th></tr>
<tr><td><b>Command / handler</b></td><td>The command (<code>src/&lt;Module&gt;/Application/UseCase/Command</code>) holds the input and its validation; the handler (<code>.../Handler</code>, marked <code>#[AsUseCaseHandler]</code>) does the work. Forms bind directly to the command.</td></tr>
<tr><td><b>Query</b></td><td>A read (<code>.../Query/Command</code> + handler). It returns page data, a PDF or a spreadsheet and never changes anything. Some are cached and invalidated by tags.</td></tr>
<tr><td><b>Validation</b></td><td>Form-level checks (required fields, formats, uniqueness) run before the handler. A failure shows under the field.</td></tr>
<tr><td><b>Policy</b></td><td>Who may do it (<code>LearnerPolicy</code>, <code>GradePolicy</code>, <code>AttendancePolicy</code>, <code>NutritionPolicy</code>, <code>LessonPlanPolicy</code>, <code>SchoolVoter::MANAGE</code>, <code>ServiceRecordVoter</code>). A refusal is a 403 page or a message.</td></tr>
<tr><td><b>Rules</b></td><td>Whether the data allows it now (current school year, plan limits, period open/locked, month submitted, dates inside the year). A refusal throws a <code>DomainException</code> whose message is shown; the transaction rolls back.</td></tr>
<tr><td><b>Transaction lock</b></td><td>A named lock (e.g. <code>school-teachers-5</code>, <code>learner-12</code>, <code>grade-lock-5</code>) held until the transaction ends, so two people can't change the same data at once. If busy: \"Another change is being saved. Please try again.\"</td></tr>
<tr><td><b>Plan limits</b></td><td>An inactive school or an expired plan makes its pages view-only; teacher and student counts are capped by plan x seats.</td></tr>
<tr><td><b>Current school year</b></td><td>Almost every change works only in the current school year. Past years are read-only history.</td></tr>
<tr><td><b>Activity log</b></td><td>Most changes add a line (\"Created school ...\", \"Enrolled ...\") to the admin's Recent activity.</td></tr>
</table>""")
    b.append("<div class='page'></div><h2>5. Index of all use cases</h2><table><tr><th style='width:10%'>ID</th><th>Use case</th><th style='width:24%'>Actor</th><th style='width:8%'>Doc</th></tr>")
    for i, m in enumerate(mods, 1):
        for u in m.USE_CASES:
            b.append(f"<tr><td>{u.id}</td><td>{esc(u.name)}</td><td>{u.actors}</td><td>{i:02d}</td></tr>")
    b.append("</table>")
    return page("Use cases 00", sectioned("\n".join(b)))


if __name__ == "__main__":
    mods = [importlib.import_module(n) for n in MODULES if Path(Path(__file__).parent / f"{n}.py").exists()]
    names = [m.__name__ for m in mods]
    for i, m in enumerate(mods, 1):
        if ONLY and m.__name__ not in ONLY:
            continue
        number = MODULES.index(m.__name__) + 1
        to_pdf(module_doc(m, number), OUT / f"{m.FILE}.pdf")
        print("written", m.FILE)
    if not ONLY or "overview" in ONLY:
        to_pdf(overview_doc(mods), OUT / "Use-Cases-00-Overview.pdf")
        print("written overview", names)
