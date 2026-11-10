"""Use cases: LessonPlans."""
from uc_model import UC, View

TITLE = "Lesson plans: weekly ILAW plans"
FILE = "Use-Cases-08-LessonPlans"
MODULES = "LessonPlans"
INTRO = """<p>A teacher writes one <b>lesson plan per class (teaching load) per week</b> in the ILAW format (Daily Lesson
Log - ILAW). The plan has a topic, competencies, class notes, references and an AI-use declaration, plus a column per
meeting day with the ILAW rows: objectives, learner context, pre-lesson, lesson flow, resources, integration, formative
assessment, extended learning and reflections.</p>
<p>The days of the week are set when the plan is started (<code>LessonWeek</code>): the weekdays the class meets on the
timetable (for the semester the week falls in) or, without a timetable, the subject's meetings per week from Monday. A
school day off stays in the plan as a holiday. The plan belongs to its teacher: only they edit it; their school's
principal this school year may read and print it (<code>LessonPlanPolicy</code>). The PDF prints the principal's
position (from the Staff service record) and the school's lesson plan \"checked by\" names. Changes take no lock.</p>"""

DIAGRAM = """flowchart LR
    TCH(["Teacher"])
    PRN(["Principal"])
    TCH --> L1["Start / open the plan for a class and week"] & L2["Save the plan"] & L3["Delete the plan"]
    TCH & PRN --> L4["View and print (PDF)"]
    PRN --> L5["List the school's lesson plans"]
"""

FLOWS = [("Starting a week's plan", """flowchart TB
    A["Teacher: New lesson plan<br/>pick one of their classes + any date of the week"] --> B["Week = Monday of that date"]
    B --> C{"Plan exists for this class and week?"}
    C -- yes --> D["Open it"]
    C -- no --> E["Build the days:<br/>timetable meeting days (semester-aware)<br/>or meetings per week from Monday"]
    E --> F["Days off become holidays (no cells)"]
    F --> G["Empty plan saved, editor opens"]
""")]

USE_CASES = [
    UC("LSP-01", "Start or open a week's lesson plan", "Teacher", "teacher_lesson_plan_new /teacher/lesson-plans/new",
       "Get the plan of one of the teacher's classes for a given week.",
       ["The teacher has teaching loads this school year; the school year has start and end dates."],
       ["The teacher picks a class and a date.", "If a plan exists for that class and week it opens; otherwise a new one is started with the days set from the timetable and the days off."],
       ["Not the teacher's class: not found / 403.", "\"The school year has no start date.\" / \"The school year has no end date.\""],
       "LessonPlan (load, week_start, days JSON).", "StartLessonPlanCommand → LessonWeek::start"),
    UC("LSP-02", "Save the lesson plan", "Teacher", "teacher_lesson_plan_edit /teacher/lesson-plans/{id}",
       "Write the plan's content.", ["The teacher owns the plan."],
       ["The teacher fills the topic, competencies, notes, references, AI declaration and each day's ILAW cells.",
        "Saves; every day keeps its date and holiday, only the text changes."],
       ["Not the owner (403)."], "LessonPlan text columns and days JSON; updated_at.", "SaveLessonPlanCommand (form bound to LessonPlanContent)"),
    UC("LSP-03", "Delete the lesson plan", "Teacher", "teacher_lesson_plan_delete /teacher/lesson-plans/{id}/delete",
       "Remove a plan.", ["The teacher owns the plan."], ["The teacher confirms Delete."], ["Not the owner (403)."],
       "LessonPlan removed.", "DeleteLessonPlanCommand"),
]

VIEWS = [
    View("My lesson plans", "Teacher", "teacher_lesson_plans /teacher/lesson-plans", "The teacher's plans this school year.", "GetTeacherLessonPlansCommand"),
    View("New plan form", "Teacher", "teacher_lesson_plan_new", "The teacher's classes this school year and today's date.", "GetLessonPlanStartCommand, GetLessonPlanIdCommand"),
    View("View a plan", "Teacher (owner), Principal of the school", "lesson_plan_show /lesson-plans/{id}", "The plan, read only.", "GetLessonPlanCommand"),
    View("Lesson plan PDF", "Teacher (owner), Principal of the school", "lesson_plan_pdf /lesson-plans/{id}.pdf", "The printable ILAW plan with signatories.", "GetLessonPlanPdfCommand"),
    View("School's lesson plans", "Principal", "principal_lesson_plans /principal/lesson-plans", "Every plan of the school this school year.", "GetSchoolLessonPlansCommand"),
]
