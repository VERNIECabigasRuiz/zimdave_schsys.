"""Builds the ERD and data-model PDF from the parsed entity metadata."""
import json
import re
import sys
import tempfile
from datetime import date
from pathlib import Path

from common import esc, graph, page, to_pdf
from dot_erd import COLORS, enroll_dot, er_dot, grade_state_dot, key_html, module_map_dot, overview_dot, year_flow_dot

# build files (HTML, browser profile, entities.json) go to a temp folder, never next to the docs
SCRATCH = Path(tempfile.gettempdir()) / "zim-docs-build"
SCRATCH.mkdir(exist_ok=True)
OUT = Path(sys.argv[1])
data = json.loads((SCRATCH / "entities.json").read_text(encoding="utf-8"))
ENT = {e["name"]: e for e in data["entities"]}
ENUMS = {e["name"]: e for e in data["enums"]}
MODULE_OF = {e["name"]: e["module"] for e in data["entities"]}


def snake(name):
    return re.sub(r"(?<=[a-z0-9])([A-Z])", r"_\1", name).lower()


# Descriptions for entities whose class has no docblock, and plain-language notes for all.
NOTES = {
    "User": "Every sign-in account: administrator, principal, teacher or student. Holds exactly one role, the hashed password, whether the password must be changed at the next sign-in, and an optional profile photo.",
    "ActivityLog": "One line of the admin's \"Recent activity\" feed. The username is copied as text (not a foreign key) so history survives account changes.",
    "Plan": "What a school bought: how many teachers and students each seat allows, and whether the plan includes a principal (Basic does, Individual does not).",
    "Announcement": "A notice the administrator posts for an audience (everyone, principals, teachers or students). The author is kept as a link that becomes empty if the account is deleted.",
    "School": "A DepEd school (6-digit School ID). Carries its plan, number of seats and plan expiry, its region/division/district as text, logo and ID signature, and the names that print on SF8, service records and lesson plans.",
    "SchoolYear": "A school year shared by all schools (e.g. 2026-2027). Exactly one is current. Uses either terms or quarters (GradingSystem) and owns its grading periods.",
    "GradingPeriod": "A term or quarter of a school year (number 1..n) with its dates. Each school opens it for grading on its own.",
    "Division": "A schools division and its Superintendent (SDS), whose name and signature print on principals' IDs. Matched to schools by region + division name.",
    "SchoolAssignment": "Who works at which school in which school year, and as what (principal or teacher). One assignment per user per school year.",
    "SchoolDayOff": "A weekday without classes at a school (holiday, suspension). Used by attendance, lesson plans and the calendar.",
    "Section": "A class (e.g. Grade 8 - ARTISTIC) of a school in one school year, with an optional adviser (a teacher may advise only one section per year). SHS sections carry a track and strand.",
    "Subject": "A subject a school offers to one grade level in a school year. A subject with a parent is a component (e.g. Music, Arts of MAPEH); components carry the grades, the parent's grade is computed.",
    "ClassPeriod": "A time slot of the school day (07:30-08:30) for a school and year. A slot with a break label (Recess, Lunch) is not a class period.",
    "TeachingLoad": "A teacher assigned to teach one subject in one section. Grades, the timetable and lesson plans all hang from it.",
    "ScheduleEntry": "One meeting of a teaching load on the timetable: weekday + class period (+ term for semester subjects). Unique per section slot and per teacher slot, so no double booking.",
    "Learner": "A child, kept for life across schools and years, identified by the 12-digit LRN. Owns a student login account (username = LRN).",
    "Enrollment": "A learner's place in one section for one school year (one per learner per year), with the SF1 learning modality and remarks.",
    "TransferRequest": "A receiving school's request to move an enrolled learner into one of its sections. The school being left approves or declines; the receiver may cancel.",
    "RollCall": "A section's attendance for one school day. Only learners who were not simply present get a mark.",
    "AttendanceMark": "One learner absent, late or cutting classes in a roll call.",
    "Sf2Submission": "The adviser's SF2 (Daily Attendance) for one month handed to the principal. While it exists, that month is locked.",
    "Grade": "A learner's whole-number grade (60-100) in one subject for one grading period. A blank grade has no row.",
    "GradeOpening": "The school's switch: while it exists, teachers can record grades for that period.",
    "GradeLock": "The principal's lock on a grading period: while it exists no grade of the school can change for that period.",
    "GradeUnlock": "The principal's permission to correct one class's (section + subject) grades in a locked period.",
    "GradeChangeRequest": "A teacher's request to correct grades of a locked period, approved or declined by the principal (or withdrawn by the teacher).",
    "GradeChangeLine": "One learner's grade in a change request: what it was and what it should be.",
    "CardSlipComment": "The adviser's chosen comments (built-in codes plus one typed comment) for a learner's card slip in one term.",
    "NutritionAssessment": "A section's baseline or endline weighing: the date it happened.",
    "NutritionMeasurement": "One learner's weight and height in a weighing (remarks may stand alone, e.g. absent).",
    "Sf8Submission": "The adviser's SF8 (Nutritional Status) for one weighing period handed to the principal. While it exists, that period is locked.",
    "LessonPlan": "A teacher's weekly ILAW lesson plan for one teaching load. The day-by-day cells are stored as JSON.",
    "PersonalDataSheet": "The Personal Data Sheet (CS Form 212) of a principal or teacher, one per account. Single-value items are columns; repeating items are child tables; skills/distinctions/memberships are JSON lists.",
    "PdsChild": "PDS item 23: one child.",
    "PdsEducation": "PDS item 26: one school attended.",
    "PdsEligibility": "PDS item 27: one civil service eligibility, board/bar or licence.",
    "PdsWorkExperience": "PDS item 28: one job, government or private.",
    "PdsTraining": "PDS item 29: one training program.",
    "PdsVoluntaryWork": "PDS item 30: one voluntary work.",
    "PdsReference": "PDS item 41: one reference (three on the form).",
    "ServiceRecord": "The parts of an employee's service record that are not rows: the GSIS BP number.",
    "ServiceRecordEntry": "One row of an employee's service record (designation, status, salary, station, leave without pay, separation). Belongs to the person, not the school, so it follows them.",
    "StaffIdDetails": "What a staff ID needs that the PDS lacks: emergency contact, GSIS policy number, call name.",
}

GROUPS = [
    ("Platform: accounts, plans, activity and announcements", ["Access", "Core", "Billing", "Announcements"],
     "The foundation every other module stands on. <b>User</b> is the single account table for all four roles. "
     "<b>Plan</b> (Billing) sets how many teachers and students a school may have. <b>ActivityLog</b> (Core) is the "
     "audit feed shown on the admin dashboard, and <b>Announcement</b> holds notices the admin posts."),
    ("Schools: schools, school years, assignments and the calendar", ["Schools"],
     "Defines <i>where</i> and <i>when</i>. A <b>School</b> runs on a <b>Plan</b>; a <b>SchoolYear</b> (shared by every "
     "school, one is current) owns its <b>GradingPeriods</b>. <b>SchoolAssignment</b> says who is the principal or a "
     "teacher of a school in a given year; <b>SchoolDayOff</b> marks holidays. <b>Division</b> holds the Superintendent "
     "who signs principals' IDs."),
    ("Classes: sections, subjects, teaching loads and the timetable", ["Classes"],
     "Defines <i>what is taught, to whom, by whom and when</i>. Each school, per school year, has <b>Sections</b>, "
     "<b>Subjects</b> (with optional components such as MAPEH), and <b>ClassPeriods</b> (the bell schedule). A "
     "<b>TeachingLoad</b> pairs a section with a subject and a teacher; its <b>ScheduleEntries</b> place it on the "
     "weekly timetable."),
    ("Learners: learners, enrollments and transfers", ["Learners"],
     "A <b>Learner</b> is permanent (identified by the LRN) and owns a student account. Each school year the learner "
     "gets one <b>Enrollment</b> in a section. A <b>TransferRequest</b> moves an enrolled learner to another school "
     "once the school being left approves."),
    ("Attendance: roll calls and SF2", ["Attendance"],
     "The adviser takes a <b>RollCall</b> per school day; only exceptions are stored as <b>AttendanceMarks</b> (absent, "
     "late, cutting). At month end the adviser submits the SF2; the <b>Sf2Submission</b> locks that month until the "
     "principal reopens it."),
    ("Grading: grades, openings, locks, change requests and card slips", ["Grading"],
     "<b>Grades</b> are per enrollment, subject and grading period. The school controls the period's state: a "
     "<b>GradeOpening</b> lets teachers enter grades; a <b>GradeLock</b> freezes them; a <b>GradeUnlock</b> re-opens "
     "one class for corrections. After a lock, teachers send a <b>GradeChangeRequest</b> (with <b>GradeChangeLines</b>) "
     "that the principal approves or declines. <b>CardSlipComment</b> holds the adviser's comments for report cards."),
    ("Nutrition: weighings and SF8", ["Nutrition"],
     "Twice a year (baseline and endline) the adviser records a <b>NutritionAssessment</b> with a "
     "<b>NutritionMeasurement</b> per learner. BMI and nutritional status are computed from WHO tables at report time "
     "(not stored). Submitting creates an <b>Sf8Submission</b>, which locks the period."),
    ("Lesson plans", ["LessonPlans"],
     "A <b>LessonPlan</b> is one week of a teaching load in the ILAW format. Its days (dates, holidays, per-day "
     "cells) are a JSON column, built from the timetable and the school's days off."),
    ("Staff: Personal Data Sheet, service record and staff ID", ["Staff"],
     "Personnel records for principals and teachers. The <b>PersonalDataSheet</b> (one per account) has seven child "
     "lists. The <b>ServiceRecord</b> and its <b>ServiceRecordEntries</b> are kept by the principal. "
     "<b>StaffIdDetails</b> completes what the staff ID card needs."),
]


def col_type(p):
    c = p.get("column", "") or ""
    t = re.search(r"type: '(\w+)'", c)
    t = t.group(1) if t else None
    ln = re.search(r"length: (\d+)", c)
    en = re.search(r"enumType: (\w+)::class", c)
    if en:
        e = ENUMS.get(en.group(1))
        backing = "SMALLINT" if t == "smallint" or en.group(1) in ("GradeLevel", "Weekday") else f"VARCHAR({ln.group(1) if ln else 255})"
        return f"{backing} enum {en.group(1)}", "enum"
    if t == "decimal":
        pr = re.search(r"precision: (\d+), scale: (\d+)", c)
        return f"DECIMAL({pr.group(1)},{pr.group(2)})", "decimal"
    if t in ("date_immutable",):
        return "DATE", "date"
    if t in ("time_immutable",):
        return "TIME", "time"
    if t in ("datetime_immutable",):
        return "DATETIME", "datetime"
    if t in ("json",) or p["type"] == "array":
        return "JSON", "json"
    if t == "text":
        return "TEXT", "text"
    if t == "smallint":
        return "SMALLINT", "smallint"
    pt = p["type"]
    if pt == "int":
        return "INT", "int"
    if pt == "bool":
        return "BOOLEAN", "bool"
    if pt == "string":
        return f"VARCHAR({ln.group(1) if ln else 255})", "varchar"
    if pt == "DateTimeImmutable":
        return "DATETIME", "datetime"
    return pt, pt.lower()


def is_nullable(p):
    c = p.get("column", "") or ""
    if "relation" in p:
        return "nullable: false" not in p.get("join", "") and not ("OneToOne" == p["relation"] and "nullable: false" in p.get("join", ""))
    return "nullable: true" in c


def uniques_for(e):
    out = []
    for u in e["uniques"]:
        cols = re.search(r"columns: \[([^\]]*)\]", u)
        name = re.search(r"name: '(\w+)'", u)
        out.append((name.group(1) if name else "(unnamed)", cols.group(1).replace("'", "") if cols else u))
    return out


def unique_raw(name):
    # read multi-line unique constraints straight from source
    e = ENT[name]
    src = Path(sys.argv[2]) / "src" / e["module"] / "Domain" / "Model" / f"{name}.php"
    t = src.read_text(encoding="utf-8")
    res = []
    for m in re.finditer(r"#\[ORM\\UniqueConstraint\((.*?)\)\]", t, re.S):
        body = m.group(1)
        cols = re.search(r"columns: \[([^\]]*)\]", body)
        nm = re.search(r"name: '(\w+)'", body)
        res.append((nm.group(1) if nm else "(unnamed)", cols.group(1).replace("'", "").replace("\n", " ").strip() if cols else ""))
    return res


def rows(e):
    """Owning-side columns of an entity (inverse collections are skipped)."""
    out = []
    for p in e["props"]:
        rel = p.get("relation")
        if rel in ("OneToMany", "ManyToMany") or p["type"] == "Collection":
            continue
        if rel:
            col = snake(p["name"]) + "_id"
            jn = re.search(r"name: '(\w+)'", p.get("join", ""))
            if jn:
                col = jn.group(1)
            od = re.search(r"onDelete: '(\w+ ?\w*)'", p.get("join", ""))
            out.append({"col": col, "type": "INT", "short": "int", "key": "FK", "null": is_nullable(p),
                        "note": f"→ {p['target']}" + (f" (on delete {od.group(1)})" if od else ""), "target": p["target"],
                        "rel": rel, "prop": p["name"]})
        else:
            c = p.get("column", "") or ""
            nm = re.search(r"name: '(\w+)'", c)
            col = nm.group(1) if nm else snake(p["name"])
            full, short = col_type(p)
            key = "PK" if p.get("id") else ("UK" if "unique: true" in c else "")
            df = re.search(r"'default' => ([^\]]+)\]", c)
            out.append({"col": col, "type": full, "short": short, "key": key, "null": is_nullable(p) and not p.get("id"),
                        "note": (f"default {df.group(1)}" if df else ""), "prop": p["name"]})
    return out


def mermaid_er(names, stubs=True, max_attrs=14, attrs=True):
    lines = ["erDiagram"]
    shown = set(names)
    rels = []
    for n in names:
        for r in rows(ENT[n]):
            if r.get("target"):
                rels.append((n, r))
                shown.add(r["target"])
    for n in sorted(shown):
        if not attrs:
            lines.append(f"    {n} {{\n    }}")
            continue
        if n not in names:
            lines.append(f"    {n} {{\n        int id PK\n    }}")
            continue
        rs = rows(ENT[n])
        body = []
        for r in rs[:max_attrs]:
            k = f" {r['key']}" if r["key"] else ""
            body.append(f"        {r['short']} {r['col']}{k}")
        if len(rs) > max_attrs:
            body.append(f"        more cols_{len(rs) - max_attrs}_more")
        lines.append(f"    {n} {{\n" + "\n".join(body) + "\n    }")
    for child, r in rels:
        parent = r["target"]
        lines.append(f'    {parent} {cardinality(r)} {child} : "{r["prop"]}"')
    return "\n".join(lines)


def cardinality(r):
    """Crow's-foot ends: the parent side is 1 (or 0..1 when the key is nullable), the child side many (or 0..1 for one-to-one)."""
    parent_end = "||" if not r["null"] else "|o"
    child_end = "o|" if r["rel"] == "OneToOne" else "o{"
    return f"{parent_end}--{child_end}"


def overview_er():
    edges = [
        ("Plan", "School", "plan"), ("School", "SchoolAssignment", "school"),
        ("SchoolYear", "SchoolAssignment", "schoolYear"), ("User", "SchoolAssignment", "principal / teacher"),
        ("SchoolYear", "GradingPeriod", "schoolYear"), ("School", "Section", "school"),
        ("SchoolYear", "Section", "schoolYear"), ("User", "Section", "adviser"),
        ("School", "Subject", "school"), ("School", "ClassPeriod", "school"),
        ("Section", "TeachingLoad", "section"), ("Subject", "TeachingLoad", "subject"),
        ("User", "TeachingLoad", "teacher"), ("TeachingLoad", "ScheduleEntry", "load"),
        ("ClassPeriod", "ScheduleEntry", "period"), ("User", "Learner", "student account"),
        ("Learner", "Enrollment", "one per year"), ("Section", "Enrollment", "section"),
        ("Enrollment", "Grade", "enrollment"), ("Subject", "Grade", "subject"),
        ("GradingPeriod", "Grade", "gradingPeriod"), ("Section", "RollCall", "one per day"),
        ("Section", "NutritionAssessment", "baseline / endline"), ("TeachingLoad", "LessonPlan", "one per week"),
        ("User", "PersonalDataSheet", "own sheet"),
    ]
    one_to_one = {("User", "Learner"), ("User", "PersonalDataSheet")}
    optional = {("User", "Section")}
    lines = ["%%{init: {'er': {'fontSize': 20, 'minEntityWidth': 150, 'minEntityHeight': 50}}}%%", "erDiagram"]
    for a, b, label in edges:
        rel = {"null": (a, b) in optional, "rel": "OneToOne" if (a, b) in one_to_one else "ManyToOne"}
        lines.append(f'    {a} {cardinality(rel)} {b} : "{label}"')
    return "\n".join(lines)


MODULE_MAP = """flowchart TB
    subgraph TOP[" "]
      DASH["Dashboards<br/><small>home pages, 'now' cards</small>"]
    end
    subgraph FEAT["Feature modules"]
      ATT["Attendance<br/><small>roll calls, SF2</small>"]
      GRD["Grading<br/><small>grades, locks, card slips</small>"]
      NUT["Nutrition<br/><small>weighings, SF8</small>"]
      LP["LessonPlans<br/><small>weekly ILAW plans</small>"]
      STF["Staff<br/><small>PDS, service record, staff ID</small>"]
      ANN["Announcements"]
    end
    LRN["Learners<br/><small>learners, enrollments, transfers, SF1, IDs</small>"]
    CLS["Classes<br/><small>sections, subjects, loads, timetable</small>"]
    SCH["Schools<br/><small>schools, years, periods, assignments, calendar</small>"]
    ACC["Access<br/><small>accounts, sign-in</small>"]
    BIL["Billing<br/><small>plans</small>"]
    CORE["Core<br/><small>bus, locks, activity log, PDF, images, clock</small>"]
    DASH --> ATT & GRD & LRN & CLS & SCH & ACC
    GRD --> ATT
    ATT & GRD & NUT --> LRN
    ATT & GRD & NUT & LP --> CLS
    LRN --> CLS
    LP & STF --> SCH
    ATT & GRD & NUT & LRN --> SCH
    CLS --> SCH
    ANN --> ACC
    SCH --> ACC & BIL
    CLS & LRN --> BIL
    ACC & BIL -.-> CORE
"""

YEAR_FLOW = """flowchart LR
    A["1. Admin<br/>setup"] --> B["2. Principal<br/>setup"] --> C["3. Classes"] --> D["4. Enrollment"]
    D --> E["5. Daily<br/>work"] --> F["6. Grading"] --> G["7. Reports"]
    G -. next school year .-> A
"""

GRADE_STATE = """stateDiagram-v2
    [*] --> Closed
    Closed --> Open : open (+ GradeOpening)
    Open --> Closed : close (- GradeOpening)
    Open --> Locked : lock (+ GradeLock)
    Closed --> Locked : lock, if grades exist
    Locked --> Unlocked : unlock one class (+ GradeUnlock)
    Unlocked --> Locked : lock class again
    Locked --> Open : unlock period (- GradeLock)
"""

ENROLL_SEQ = """flowchart TB
    L["Learner<br/>(LRN, for life)"] -- "owns 1:1" --> U["User<br/>(role student, username = LRN)"]
    L -- "1 per school year" --> E["Enrollment"]
    E --> S["Section"] --> SC["School"]
    E --> SY["SchoolYear"]
    E -. "graded by" .-> G["Grade"]
    E -. "marked in" .-> AM["AttendanceMark"]
    E -. "weighed in" .-> NM["NutritionMeasurement"]
    E -. "commented in" .-> CC["CardSlipComment"]
    TR["TransferRequest"] -- "moves" --> E
"""


def mod_cell(m):
    return f"<b style='color:{COLORS[m]}'>&#9632; {m}</b>"


def overview_block():
    dot, mods = overview_dot(MODULE_OF)
    return graph(dot, "overview") + key_html(mods)


def enroll_block():
    dot, mods = enroll_dot(MODULE_OF)
    return graph(dot) + key_html(mods)


def er_block(names, max_attrs):
    dot, mods = er_dot(names, ENT, MODULE_OF, rows, max_attrs)
    return graph(dot) + key_html(mods)


def entity_section(n):
    e = ENT[n]
    out = [f"<h4><span style='color:{COLORS[e['module']]}'>&#9632;</span> {n} <span class='muted small'>table <code>{snake(n)}</code> · {e['module']}</span></h4>",
           f"<p>{esc(NOTES.get(n, e['desc']))}</p>",
           "<table><tr><th style='width:24%'>Column</th><th style='width:22%'>Type</th><th style='width:6%'>Key</th><th style='width:8%'>Null</th><th>Notes</th></tr>"]
    for r in rows(e):
        out.append(f"<tr><td><code>{r['col']}</code></td><td>{esc(r['type'])}</td><td>{r['key']}</td>"
                   f"<td>{'yes' if r['null'] else 'no'}</td><td>{esc(r['note'])}</td></tr>")
    out.append("</table>")
    u = unique_raw(n)
    if u:
        out.append("<p class='small'><b>Unique rules:</b> " + "; ".join(f"<code>{esc(c)}</code>" for _, c in u) + "</p>")
    keep = " class='keep'" if len(rows(e)) < 30 else ""
    return f"<div{keep}>" + "\n".join(out) + "</div>"


def build():
    today = date.today().isoformat()
    total_cols = sum(len(rows(e)) for e in data["entities"])
    b = [f"""<div class='cover'><h1>ZIM School</h1>
<div class='sub'>Entity Relationship Diagram and Data Model Guide</div>
<div class='meta'>{len(ENT)} entities (tables) · {len(ENUMS)} enumerations · {total_cols} columns · 13 modules<br/>
Generated from the Doctrine entity mappings in <code>src/*/Domain/Model</code> on {today}.</div></div>"""]

    b.append("""<h2>1. Purpose of this document</h2>
<p>This guide explains what ZIM School stores and why. It shows every database table (each one is a Doctrine entity
in a module's <code>Domain/Model</code> folder), how the tables connect, and how data flows through a school year,
from setting up a school to printing report cards.</p>
<p>Read it in three passes: (1) the big picture in sections 2-4, (2) the module chapters in section 5, each with its
own diagram and a column-by-column dictionary, and (3) the reference lists at the end (enumerations, delete
behaviour, glossary).</p>
<h3>How to read the diagrams</h3>
<p>Lines use the standard crow's-foot symbols, and each end also carries a label saying how many records
take part on that side.</p>
<table><tr><th style='width:22%'>Line end</th><th style='width:10%'>Label</th><th>Meaning</th></tr>
<tr><td>Two bars</td><td><b>1</b></td><td>Exactly one: the foreign key is required.</td></tr>
<tr><td>Bar + circle</td><td><b>0..1</b></td><td>Zero or one: the foreign key is optional, or the side of a one-to-one link that may be missing.</td></tr>
<tr><td>Crow's foot + circle</td><td><b>*</b></td><td>Zero or many.</td></tr>
</table>
<p>Read a line from one end to the other: <b>School 1 —— * Section</b> means one school has many sections and every
section belongs to exactly one school. <b>User 0..1 —— * Section</b> (adviser) means a section may have no adviser.
<b>User 1 —— 0..1 Learner</b> means each learner has exactly one account and an account belongs to at most one learner.</p>
<table><tr><th>Other symbol</th><th>Meaning</th></tr>
<tr><td>PK / FK / UK</td><td>Primary key / foreign key / unique column.</td></tr>
<tr><td>Label on a line</td><td>The property name on the child entity (the column is that name in snake_case + <code>_id</code>).</td></tr>
<tr><td>Dashed box with only <code>id</code></td><td>A table owned by another module (its name is under the title), shown so the links are visible.</td></tr>
<tr><td><i>+ N more columns</i></td><td>The table has N more columns than the diagram shows; see the dictionary table.</td></tr>
</table>
<h3>Colours</h3><p>Every module has its own colour. A table's title bar has its module's colour, and a relationship line has the colour of the module of the table it points to (its 1 or 0..1 side), so you can see at a glance which module each link reaches into. In the module map the arrow has the colour of the module that does the using. Each diagram lists the colours it uses.</p>""" + key_html(list(COLORS), "") + """
<div class='note'>All primary keys are auto-increment integers named <code>id</code>. Table names are the class names in
snake_case (e.g. <code>ScheduleEntry</code> → <code>schedule_entry</code>). Enumerations are stored as short strings
(or small integers for <code>GradeLevel</code> and <code>Weekday</code>).</div>""")

    b.append("""<h2>2. The system at a glance</h2>
<p><b>ZIM School</b> is a school grading platform for Philippine DepEd schools, built with Symfony 8.1 / PHP 8.4,
Doctrine ORM on MySQL, Twig, Stimulus/Turbo and some Vue. It covers the paperwork of a school year: enrollment and
the School Register (SF1), daily attendance (SF2), nutritional status (SF8), grades and report cards (card slips),
weekly lesson plans, learner and staff ID cards, the Personal Data Sheet (PDS) and the service record.</p>
<h3>Who uses it</h3>
<table><tr><th>Role</th><th>What they do</th></tr>
<tr><td><b>Administrator</b></td><td>Runs the platform: plans, divisions, schools, school years, accounts, principal assignments, announcements; can act on any school.</td></tr>
<tr><td><b>Principal</b></td><td>Runs one school per school year: profile, teachers, calendar, class periods, sections, subjects, timetable, enrollment, transfers, grading openings and locks, change requests, staff records.</td></tr>
<tr><td><b>Teacher</b></td><td>Teaches loads (grade sheets, lesson plans). As a section adviser: class list, attendance and SF2, weighings and SF8, card slips. On the Individual plan (no principal) the teacher also sets up their own section and subjects.</td></tr>
<tr><td><b>Student</b></td><td>Signs in with the LRN to see their enrollment and their section's timetable.</td></tr>
</table>
<h3>How the code is organised</h3>
<p>The application is a <b>modular monolith</b> with Domain-Driven Design layers. Each module in <code>src/</code> has
<code>Domain</code> (entities, repository interfaces, rules), <code>Application</code> (use cases, queries, services,
public API), <code>Infrastructure</code> (Doctrine repositories, PDF/spreadsheet writers) and <code>Presentation</code>
(controllers, forms, Twig templates, Stimulus/Vue). Every change goes through the <b>use case bus</b> (one command, one
handler, one database transaction, with row-level <i>transaction locks</i> so two people can't overwrite each other);
every page read goes through the <b>query bus</b> (optionally cached). Modules may reference each other's entities,
but call each other only through public API interfaces. Deptrac enforces these rules.</p>""")

    b.append("<div class='page'></div><h2>3. Modules and how they depend on each other</h2>"
             "<p>Arrows point from a module to the modules whose public API it uses (taken from the deptrac rules in "
             "<code>config/deptrac.yaml</code>; every module also uses Core and Access's <code>User</code>). Lower modules never call upper ones directly; when a lower "
             "module needs data owned by an upper one (e.g. Schools must ask Grading whether a period has grades) it "
             "defines a <i>hook interface</i> that the upper module implements.</p>" + graph(module_map_dot()) + key_html(list(COLORS), "Arrow colour = the module that uses the other one; dashed = Core, used by every module."))
    b.append("""<table><tr><th>Module</th><th>Entities</th><th>Responsibility</th></tr>
<tr><td>""" + mod_cell("Core") + """</td><td>ActivityLog</td><td>Bus, transaction locks, activity feed, school clock, image and PDF services, layouts.</td></tr>
<tr><td>""" + mod_cell("Access") + """</td><td>User</td><td>Accounts, sign-in, password changes, profile photos, admin account screens.</td></tr>
<tr><td>""" + mod_cell("Billing") + """</td><td>Plan</td><td>Subscription plans and their teacher/student limits per seat.</td></tr>
<tr><td>""" + mod_cell("Schools") + """</td><td>School, SchoolYear, GradingPeriod, SchoolAssignment, SchoolDayOff, Division</td><td>Schools, years, principals/teachers per year, calendar, plan limits.</td></tr>
<tr><td>""" + mod_cell("Classes") + """</td><td>Section, Subject, ClassPeriod, TeachingLoad, ScheduleEntry</td><td>Sections, subjects, bell schedule, teaching loads and timetable.</td></tr>
<tr><td>""" + mod_cell("Learners") + """</td><td>Learner, Enrollment, TransferRequest</td><td>Learners, enrollments, transfers, SF1, learner ID cards.</td></tr>
<tr><td>""" + mod_cell("Attendance") + """</td><td>RollCall, AttendanceMark, Sf2Submission</td><td>Daily roll calls and the monthly SF2.</td></tr>
<tr><td>""" + mod_cell("Grading") + """</td><td>Grade, GradeOpening, GradeLock, GradeUnlock, GradeChangeRequest, GradeChangeLine, CardSlipComment</td><td>Grades, grading control, change requests and card slips.</td></tr>
<tr><td>""" + mod_cell("Nutrition") + """</td><td>NutritionAssessment, NutritionMeasurement, Sf8Submission</td><td>Baseline/endline weighings and the SF8.</td></tr>
<tr><td>""" + mod_cell("LessonPlans") + """</td><td>LessonPlan</td><td>Weekly ILAW lesson plans and their PDF.</td></tr>
<tr><td>""" + mod_cell("Staff") + """</td><td>PersonalDataSheet + 7 PDS lists, ServiceRecord, ServiceRecordEntry, StaffIdDetails</td><td>PDS, service record and staff ID.</td></tr>
<tr><td>""" + mod_cell("Announcements") + """</td><td>Announcement</td><td>Admin announcements.</td></tr>
<tr><td>""" + mod_cell("Dashboards") + """</td><td>(none)</td><td>The four role dashboards, built from the other modules' public APIs.</td></tr>
</table>""")

    b.append("<div class='wide'></div><h2>4. The big picture</h2><h3>4.1 Core relationships (simplified)</h3>"
             "<p>The backbone of the model, without columns and without audit links (\"who did it\" users). Almost every "
             "record hangs from a <b>School</b> and a <b>SchoolYear</b>; learner data hangs from the <b>Enrollment</b>; "
             "teaching data hangs from the <b>TeachingLoad</b>. Dashed frames group the tables by module.</p>" + overview_block())
    b.append("<div class='page'></div><h3>4.2 The flow of a school year</h3>"
             "<p>The order in which data is created. Each step needs the records of the step before it.</p>"
             + graph(year_flow_dot()) + key_html(['Billing', 'Schools', 'Access', 'Classes', 'Learners', 'Attendance', 'LessonPlans', 'Nutrition', 'Grading', 'Staff'], 'Box colour = the main module of the step; the module names under each step say which modules hold its records.') + """
<table><tr><th>Step</th><th>Who</th><th>Records created</th></tr>
<tr><td>1. Admin setup</td><td>Administrator</td><td>Plan, Division, School, SchoolYear (+ GradingPeriods), User (principal), SchoolAssignment (principal)</td></tr>
<tr><td>2. Principal setup</td><td>Principal</td><td>School profile fields, User + SchoolAssignment (teachers), SchoolDayOff, ClassPeriod</td></tr>
<tr><td>3. Classes</td><td>Principal (or the teacher on the Individual plan)</td><td>Section (with adviser), Subject, TeachingLoad, ScheduleEntry</td></tr>
<tr><td>4. Enrollment</td><td>Principal or adviser</td><td>Learner + User (student), Enrollment, TransferRequest</td></tr>
<tr><td>5. Daily work</td><td>Adviser / teacher</td><td>RollCall + AttendanceMark, LessonPlan, NutritionAssessment + NutritionMeasurement</td></tr>
<tr><td>6. Grading</td><td>Principal and teachers</td><td>GradeOpening, Grade, GradeLock, GradeUnlock, GradeChangeRequest + lines, CardSlipComment</td></tr>
<tr><td>7. Reports and submissions</td><td>Adviser, principal</td><td>Sf2Submission, Sf8Submission; files (SF1/SF2/SF8 spreadsheets, PDFs) are generated on demand and not stored</td></tr>
</table>
<div class='note'>At the start of a new school year the admin creates the year, makes it current and copies the principals;
principals bring back last year's teachers, sections and subjects with one click each. Learners are permanent, so
returning learners are simply enrolled again by LRN.</div>""")
    b.append("<h3>4.3 Everything about a learner hangs from the enrollment</h3>"
             "<p>Grades, attendance marks, measurements and card slip comments point to the <b>Enrollment</b> (learner + year), "
             "not to the learner. That keeps each year's records separate and lets a transfer simply move the enrollment to "
             "another section.</p>" + enroll_block())
    b.append("<h3>4.4 The life of a grading period at one school</h3>"
             "<p>The period's state is not a column: it is derived from which control rows exist "
             "(<code>ClassGradeStatus</code> = Open, Closed, Locked or Unlocked). The principal or an admin opens and closes; only the principal locks and unlocks. After the period is unlocked it is Open or Closed again, depending on whether its GradeOpening still exists.</p>" + graph(grade_state_dot()) + key_html(['Grading'], 'All grading control rows belong to the Grading module.'))

    for i, (title, mods, intro) in enumerate(GROUPS, 1):
        names = [e["name"] for e in data["entities"] if e["module"] in mods]
        head = "<h2>5. Module chapters</h2>" if i == 1 else ""
        b.append(f"<div class='wide'></div>{head}<h3>5.{i} {esc(title)}</h3><p>{intro}</p>")
        if mods == ["Grading"]:
            parts = [("Grades and the period controls", ["Grade", "GradeOpening", "GradeLock", "GradeUnlock"]),
                     ("Change requests and card slip comments", ["GradeChangeRequest", "GradeChangeLine", "CardSlipComment"])]
            for k, (label, part) in enumerate(parts):
                if k:
                    b.append("<div class='wide'></div>")
                b.append(f"<h4>{esc(label)}</h4>" + er_block(part, 12))
        else:
            b.append(er_block(names, 12 if len(names) > 4 else 16))
        b.append("<div class='page'></div>")
        for n in names:
            b.append(entity_section(n))

    b.append("<div class='page'></div><h2>6. Enumerations</h2><p>Fixed lists stored in columns. The PHP enum is in the module's <code>Domain/Model</code>.</p>"
             "<table><tr><th>Enum</th><th>Module</th><th>Values</th><th>Used by</th></tr>")
    for en in sorted(data["enums"], key=lambda x: (x["module"], x["name"])):
        used = sorted({e["name"] for e in data["entities"] for p in e["props"] if f"enumType: {en['name']}::" in (p.get("column") or "")})
        vals = ", ".join(c["value"] or c["name"] for c in en["cases"]) or "-"
        if en["name"] == "GradeLevel":
            vals = "0 Kinder, 1-12 Grade 1 to Grade 12"
        if en["name"] == "Weekday":
            vals = "1 Monday ... 5 Friday"
        b.append(f"<tr><td><b>{en['name']}</b></td><td>{en['module']}</td><td>{esc(vals)}</td><td>{', '.join(used) or '(rules only, not stored)'}</td></tr>")
    b.append("</table>")

    b.append("<h2>7. What happens on delete</h2><p>Foreign keys say what the database does when the parent row is deleted:</p>"
             "<table><tr><th>Rule</th><th>Meaning</th><th>Where</th></tr>")
    groups = {"CASCADE": [], "RESTRICT": [], "SET NULL": [], "(none)": []}
    for e in data["entities"]:
        for r in rows(e):
            if r.get("target"):
                od = re.search(r"on delete (.+)\)", r["note"])
                groups[od.group(1) if od else "(none)"].append(f"{e['name']}.{r['prop']}")
    meaning = {"CASCADE": "Child rows are deleted with the parent (owned data).",
               "RESTRICT": "The parent cannot be deleted while children exist (history must stay).",
               "SET NULL": "The link is cleared; the child row stays (e.g. who decided, the author).",
               "(none)": "Database default: the parent cannot be deleted while children exist."}
    for k, v in groups.items():
        b.append(f"<tr><td><b>{k}</b></td><td>{meaning[k]}</td><td class='small'>{', '.join(v)}</td></tr>")
    b.append("</table><div class='note'>In practice records are rarely deleted: accounts and schools are <i>deactivated</i>, "
             "learners are <i>unenrolled</i> (the learner and past years stay), and teachers are taken off a school for the year.</div>")

    b.append("""<h2>8. Rules the database enforces (unique constraints)</h2><table><tr><th>Entity</th><th>Unique on</th><th>Business meaning</th></tr>""")
    meaning_u = {
        "Section": ["One name per grade level, school and year", "A teacher advises at most one section per year"],
        "Subject": ["One subject code per school and year"], "TeachingLoad": ["One teacher per subject in a section"],
        "ScheduleEntry": ["A section's slot holds one class", "A teacher can't be in two places at once"],
        "Enrollment": ["One enrollment per learner per school year"], "Grade": ["One grade per learner, subject and period"],
        "RollCall": ["One roll call per section per day"], "AttendanceMark": ["One mark per learner per roll call"],
        "Sf2Submission": ["One SF2 per section per month"], "Sf8Submission": ["One SF8 per section per period"],
        "NutritionAssessment": ["One weighing per section per period"], "NutritionMeasurement": ["One measurement per learner per weighing"],
        "GradeLock": ["One lock per school per period"], "GradeOpening": ["One opening per school per period"],
        "GradeUnlock": ["One unlock per class per period"], "GradeChangeLine": ["One line per learner per request"],
        "CardSlipComment": ["One comment set per learner per term"], "LessonPlan": ["One plan per teaching load per week"],
        "Division": ["Each region + division once"], "GradingPeriod": ["Period numbers are unique within a year"],
        "SchoolAssignment": ["One assignment per user per school year"], "SchoolDayOff": ["A date is a day off once per school"],
    }
    for n in ENT:
        for i, (_, cols) in enumerate(unique_raw(n)):
            m = meaning_u.get(n, [""])
            b.append(f"<tr><td>{n}</td><td><code>{esc(cols)}</code></td><td>{esc(m[i] if i < len(m) else '')}</td></tr>")
        for r in rows(ENT[n]):
            if r["key"] == "UK":
                b.append(f"<tr><td>{n}</td><td><code>{r['col']}</code></td><td>Unique value</td></tr>")
    b.append("</table>")

    b.append("""<h2>9. Glossary</h2><table><tr><th>Term</th><th>Meaning</th></tr>
<tr><td>LRN</td><td>Learner Reference Number: the 12-digit DepEd ID of a learner, also the student's username.</td></tr>
<tr><td>School ID</td><td>The 6-digit DepEd ID of a school.</td></tr>
<tr><td>SF1</td><td>School Form 1, the School Register (class list with learner details and remarks).</td></tr>
<tr><td>SF2</td><td>School Form 2, the Daily Attendance Report of Learners, submitted monthly.</td></tr>
<tr><td>SF8</td><td>School Form 8, the Learner's Basic Health and Nutrition Report (baseline and endline weighings).</td></tr>
<tr><td>Card slip</td><td>The learner's report card for a term: grades, attendance and the adviser's comments.</td></tr>
<tr><td>ILAW</td><td>The weekly lesson plan format (Daily Lesson Log, ILAW format) used for lesson plans.</td></tr>
<tr><td>PDS</td><td>Personal Data Sheet, CS Form 212, the civil service personal record of an employee.</td></tr>
<tr><td>Service record</td><td>The employer's history of an employee's positions, status, salary and station.</td></tr>
<tr><td>SDS</td><td>Schools Division Superintendent; signs principals' IDs.</td></tr>
<tr><td>Adviser</td><td>The teacher in charge of a section (class list, attendance, SF2, SF8, card slips).</td></tr>
<tr><td>Teaching load</td><td>A teacher + subject + section assignment.</td></tr>
<tr><td>MAPEH components</td><td>Subjects with a parent (Music, Arts, PE, Health); the parent's grade is their average.</td></tr>
<tr><td>SHS track / strand</td><td>Senior High (Grades 11-12) specialisation: Academic, TVL, Sports, Arts &amp; Design, plus a strand.</td></tr>
<tr><td>Seat / plan</td><td>A school buys seats on a plan; each seat allows a number of teachers and students.</td></tr>
<tr><td>Transaction lock</td><td>A named lock (e.g. <code>learner-12</code>) a use case takes so two saves on the same data can't overlap.</td></tr>
</table>""")
    return page("ZIM School ERD", sectioned("\n".join(b)))


def sectioned(body):
    """Turns the page and landscape markers into sections so each prints on its own page size."""
    body = body.replace("<div class='wide'></div>", "</section><section class='wide'>")
    body = body.replace("<div class='page'></div>", "</section><section class='page'>")
    body = f"<section>{body}</section>"
    return re.sub(r"<section class='(?:page|wide)'>\s*</section>", "", body)


if __name__ == "__main__":
    html_src = build()
    src = to_pdf(html_src, OUT)
    print("written", OUT, "html", src)
