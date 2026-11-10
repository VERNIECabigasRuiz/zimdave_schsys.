"""Graphviz (DOT) diagrams for the ERD document, colour coded by module."""
import html

COLORS = {
    "Core": "#6b7280", "Access": "#2563eb", "Billing": "#0f766e", "Schools": "#7c3aed",
    "Classes": "#ea580c", "Learners": "#16a34a", "Attendance": "#db2777", "Grading": "#dc2626",
    "Nutrition": "#4d7c0f", "LessonPlans": "#a16207", "Staff": "#0891b2", "Announcements": "#4f46e5",
    "Dashboards": "#1f2937",
}
TINTS = {m: c + "1f" for m, c in COLORS.items()}  # 12% tint for fills

GRAPH = ('graph [fontname="Arial", pad=0.3, nodesep=0.8, ranksep=1.1, splines=true, newrank=true, '
         'bgcolor="transparent"];\n  node [fontname="Arial"];\n  edge [fontname="Arial", penwidth=1.8, arrowsize=1.1];')


def e(s):
    return html.escape(str(s), quote=True)


def key_html(modules, note="Line colour = the module of the table the line points to (its 1 side)."):
    """A row of coloured module chips shown under a diagram."""
    chips = "".join(f"<span class='chip' style='border-color:{COLORS[m]};color:{COLORS[m]}'>"
                    f"<span class='dot' style='background:{COLORS[m]}'></span>{m}</span>" for m in modules)
    return f"<div class='key'>{chips}<span class='muted small'>{note}</span></div>"


def table_label(name, module, cols, stub=False, more=0):
    c = COLORS[module]
    rows = []
    for col in cols:
        key = col["key"]
        kc = {"PK": "#b45309", "FK": "#1d4ed8", "UK": "#6d28d9"}.get(key, "#111827")
        # an empty <b></b> makes Graphviz drop the whole label, so a cell without a key holds a space
        key_cell = f'<font color="{kc}"><b>{key}</b></font>' if key else " "
        rows.append(f'<tr><td align="left"><font color="#6b7280">{e(col["short"])}</font></td>'
                    f'<td align="left">{e(col["col"])}</td><td>{key_cell}</td></tr>')
    if more:
        rows.append(f'<tr><td colspan="3" align="left"><font color="#6b7280"><i>+ {more} more columns</i></font></td></tr>')
    style = ' style="dashed"' if stub else ""
    head = f'<tr><td colspan="3" bgcolor="{c}"><font color="white"><b>{e(name)}</b></font></td></tr>'
    sub = f'<tr><td colspan="3"><font color="{c}" point-size="9">{module}</font></td></tr>' if stub else ""
    return (f'<<table border="1" cellborder="1" cellspacing="0" cellpadding="4" color="{c}"{style}>'
            f'{head}{sub}{"".join(rows)}</table>>')


def ends(nullable, one_to_one):
    """Crow's-foot shapes and multiplicity labels: (arrowtail at parent, tail label, arrowhead at child, head label)."""
    tail, tlabel = ("teeodot", "0..1") if nullable else ("teetee", "1")
    head, hlabel = ("teeodot", "0..1") if one_to_one else ("crowodot", "*")
    return tail, tlabel, head, hlabel


def edge(parent, child, color, label, nullable, one_to_one, big=False):
    tail, tl, head, hl = ends(nullable, one_to_one)
    size = 18 if big else 17
    return (f'  "{parent}" -> "{child}" [dir=both, penwidth=2.2, arrowtail={tail}, arrowhead={head}, color="{color}", '
            f'fontcolor="{color}", fontsize={12 if big else 13}, label=< <i>{e(label)}</i> >, '
            f'taillabel=<<b>{tl}</b>>, headlabel=<<b>{hl}</b>>, labelfontcolor="{color}", '
            f'labelfontsize={size}, labeldistance=2.4, labelangle=35];')


def er_dot(names, ent, module_of, rows, max_attrs=14):
    """A module chapter diagram: full tables for `names`, small dashed boxes for tables of other modules."""
    out = ["digraph G {", "  " + GRAPH, "  node [shape=plaintext];"]
    shown, edges = set(names), []
    for n in names:
        for r in rows(ent[n]):
            if r.get("target"):
                edges.append((r["target"], n, r))
                shown.add(r["target"])
    for n in sorted(shown):
        if n in names:
            rs = rows(ent[n])
            label = table_label(n, module_of[n], rs[:max_attrs], more=max(0, len(rs) - max_attrs))
        else:
            label = table_label(n, module_of[n], [{"short": "int", "col": "id", "key": "PK"}], stub=True)
        out.append(f'  "{n}" [label={label}];')
    for parent, child, r in edges:
        out.append(edge(parent, child, COLORS[module_of[parent]], r["prop"], r["null"], r["rel"] == "OneToOne"))
    out.append("}")
    mods = sorted({module_of[n] for n in shown}, key=list(COLORS).index)
    return "\n".join(out), mods


OVERVIEW_EDGES = [
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


def overview_dot(module_of):
    one_to_one = {("User", "Learner"), ("User", "PersonalDataSheet")}
    optional = {("User", "Section")}
    nodes = []
    for a, b, _ in OVERVIEW_EDGES:
        for n in (a, b):
            if n not in nodes:
                nodes.append(n)
    out = ["digraph G {", "  " + GRAPH.replace("nodesep=0.8, ranksep=1.1", "nodesep=0.55, ranksep=1.25"),
           '  node [shape=box, style="rounded,filled", fontsize=13, margin="0.25,0.12", penwidth=2];']
    by_mod = {}
    for n in nodes:
        by_mod.setdefault(module_of[n], []).append(n)
    for m, ns in by_mod.items():
        c = COLORS[m]
        out.append(f'  subgraph "cluster_{m}" {{ label=<<b>{m}</b>>; fontcolor="{c}"; color="{c}"; style="rounded,dashed"; penwidth=1.5; fontsize=13; margin=18; labeljust="l";')
        for n in ns:
            out.append(f'    "{n}" [label=<<b>{n}</b>>, color="{c}", fillcolor="{TINTS[m]}"];')
        out.append("  }")
    for a, b, label in OVERVIEW_EDGES:
        out.append(edge(a, b, COLORS[module_of[a]], label, (a, b) in optional, (a, b) in one_to_one, big=True))
    out.append("}")
    return "\n".join(out), list(by_mod)


MODULE_DEPS = {
    "Dashboards": ["Attendance", "Grading", "Learners", "Classes", "Schools", "Access"],
    "Grading": ["Attendance", "Learners", "Classes", "Schools", "Access"],
    "Attendance": ["Learners", "Classes", "Schools", "Access"],
    "Nutrition": ["Learners", "Classes", "Schools", "Access"],
    "LessonPlans": ["Classes", "Schools", "Access"],
    "Staff": ["Schools", "Access"],
    "Announcements": ["Access"],
    "Learners": ["Classes", "Schools", "Billing", "Access"],
    "Classes": ["Schools", "Billing", "Access"],
    "Schools": ["Billing", "Access"],
    "Access": ["Core"], "Billing": ["Core"],
}
MODULE_INFO = {
    "Core": "bus, locks, activity log,<br/>PDF, images, clock", "Access": "accounts, sign-in",
    "Billing": "plans", "Schools": "schools, years, periods,<br/>assignments, calendar",
    "Classes": "sections, subjects,<br/>loads, timetable", "Learners": "learners, enrollments,<br/>transfers, SF1, IDs",
    "Attendance": "roll calls, SF2", "Grading": "grades, locks,<br/>card slips", "Nutrition": "weighings, SF8",
    "LessonPlans": "weekly ILAW plans", "Staff": "PDS, service record,<br/>staff ID", "Announcements": "admin notices",
    "Dashboards": "home pages,<br/>'now' cards",
}


def module_map_dot():
    out = ["digraph G {", "  " + GRAPH.replace("nodesep=0.8, ranksep=1.1", "nodesep=0.5, ranksep=0.9"),
           '  node [shape=box, style="rounded,filled", fontsize=13, margin="0.2,0.1", penwidth=2];',
           '  edge [arrowhead=vee, arrowsize=0.9, penwidth=1.6];']
    ranks = [["Dashboards"], ["Announcements", "Staff", "LessonPlans", "Nutrition", "Grading"], ["Attendance"],
             ["Learners"], ["Classes"], ["Schools"], ["Access", "Billing"], ["Core"]]
    for rank in ranks:
        out.append("  { rank=same; " + " ".join(f'"{m}";' for m in rank) + " }")
    for m, c in COLORS.items():
        out.append(f'  "{m}" [label=<<b>{m}</b><br/><font point-size="10">{MODULE_INFO[m]}</font>>, '
                   f'color="{c}", fillcolor="{TINTS[m]}", fontcolor="#111827"];')
    for src, targets in MODULE_DEPS.items():
        for t in targets:
            style = ', style=dashed' if t == "Core" else ""
            out.append(f'  "{src}" -> "{t}" [color="{COLORS[src]}"{style}];')
    out.append("}")
    return "\n".join(out)


YEAR_STEPS = [
    ("1. Admin setup", ["Billing", "Schools", "Access"], "plans, divisions, schools,<br/>school year + periods,<br/>principal accounts"),
    ("2. Principal setup", ["Schools"], "school profile, teachers,<br/>days off"),
    ("3. Classes", ["Classes"], "class periods, sections,<br/>subjects, loads, timetable"),
    ("4. Enrollment", ["Learners"], "learners by LRN,<br/>student accounts, transfers"),
    ("5. Daily work", ["Attendance", "LessonPlans", "Nutrition"], "roll calls, lesson plans,<br/>weighings"),
    ("6. Grading", ["Grading"], "open, enter, lock,<br/>change requests"),
    ("7. Reports", ["Learners", "Attendance", "Nutrition", "Grading", "Staff"], "SF1, SF2, SF8, card slips,<br/>IDs, PDS, service record"),
]


def year_flow_dot():
    # one step under the other, so the text stays large
    out = ["digraph G {", "  " + GRAPH.replace("nodesep=0.8, ranksep=1.1", "nodesep=0.6, ranksep=0.45"),
           '  node [shape=box, style="rounded,filled", fontsize=14, margin="0.2,0.12", penwidth=2, width=3.2];']
    for i, (title, mods, text) in enumerate(YEAR_STEPS):
        tags = " ".join(f'<font color="{COLORS[m]}"><b>{m}</b></font>' for m in mods)
        out.append(f'  s{i} [label=<<b>{title}</b><br/><font point-size="12">{text}</font><br/><font point-size="11">{tags}</font>>, '
                   f'color="{COLORS[mods[0]]}", fillcolor="{TINTS[mods[0]]}"];')
    for i in range(len(YEAR_STEPS) - 1):
        out.append(f'  s{i} -> s{i + 1} [color="{COLORS[YEAR_STEPS[i + 1][1][0]]}", arrowhead=vee];')
    out.append('  s6 -> s0 [style=dashed, color="#6b7280", constraint=false, label=< <i>next school year</i> >, fontsize=10, arrowhead=vee];')
    out.append("}")
    return "\n".join(out)


def enroll_dot(module_of):
    nodes = ["User", "Learner", "Enrollment", "Section", "School", "SchoolYear", "Grade", "AttendanceMark",
             "NutritionMeasurement", "CardSlipComment", "TransferRequest"]
    links = [("User", "Learner", "student account", False, True), ("Learner", "Enrollment", "one per school year", False, False),
             ("Section", "Enrollment", "section", False, False), ("School", "Section", "school", False, False),
             ("SchoolYear", "Enrollment", "schoolYear", False, False), ("Enrollment", "Grade", "graded", False, False),
             ("Enrollment", "AttendanceMark", "marked", False, False),
             ("Enrollment", "NutritionMeasurement", "weighed", False, False),
             ("Enrollment", "CardSlipComment", "commented", False, False),
             ("Enrollment", "TransferRequest", "moved by", True, False)]
    out = ["digraph G {", "  " + GRAPH.replace("nodesep=0.8, ranksep=1.1", "nodesep=0.45, ranksep=0.9"),
           '  node [shape=box, style="rounded,filled", fontsize=12, margin="0.2,0.1", penwidth=2];']
    for n in nodes:
        m = module_of[n]
        out.append(f'  "{n}" [label=<<b>{n}</b><br/><font point-size="9" color="{COLORS[m]}">{m}</font>>, color="{COLORS[m]}", fillcolor="{TINTS[m]}"];')
    for a, b, label, nullable, one in links:
        out.append(edge(a, b, COLORS[module_of[a]], label, nullable, one))
    out.append("}")
    return "\n".join(out), sorted({module_of[n] for n in nodes}, key=list(COLORS).index)


def grade_state_dot():
    c = COLORS["Grading"]
    out = ["digraph G {", "  " + GRAPH.replace("nodesep=0.8, ranksep=1.1", "nodesep=0.9, ranksep=0.8"),
           f'  node [shape=box, style="rounded,filled", fontsize=13, margin="0.25,0.12", penwidth=2, color="{c}", fillcolor="{TINTS["Grading"]}"];',
           f'  edge [color="{c}", fontcolor="#7f1d1d", fontsize=10, arrowhead=vee];',
           '  start [shape=circle, width=0.18, label="", style=filled, fillcolor="#111827", color="#111827"];',
           '  Closed [label=<<b>Closed</b><br/><font point-size="9">no GradeOpening</font>>];',
           '  Open [label=<<b>Open</b><br/><font point-size="9">teachers save sheets</font>>];',
           '  Locked [label=<<b>Locked</b><br/><font point-size="9">GradeLock; change requests</font>>];',
           '  Unlocked [label=<<b>Unlocked</b><br/><font point-size="9">GradeUnlock for one class</font>>];',
           "  start -> Closed;",
           '  Closed -> Open [label=< open (+ GradeOpening) >];',
           '  Open -> Closed [label=< close (- GradeOpening) >];',
           '  Open -> Locked [label=< lock (+ GradeLock) >];',
           '  Closed -> Locked [label=< lock, if grades exist >];',
           '  Locked -> Unlocked [label=< unlock one class >];',
           '  Unlocked -> Locked [label=< lock class again >];',
           '  Locked -> Open [label=< unlock period (- GradeLock) >, style=dashed];',
           "}"]
    return "\n".join(out)
