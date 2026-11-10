"""Use cases: Dashboards (read only)."""
from uc_model import View

TITLE = "Dashboards: the home page of each role"
FILE = "Use-Cases-10-Dashboards"
MODULES = "Dashboards"
INTRO = """<p>Dashboards is the top module. It has no tables and no changes of its own: each page is one query that
reads the other modules through their public APIs. After sign-in, <code>app_home</code> (<code>/</code>) sends each user
to their role's dashboard. The principal's menu shortcuts (<code>principal_teachers</code>, <code>principal_sections</code>,
<code>principal_learners</code>, <code>principal_attendance</code>, <code>principal_nutrition</code>,
<code>principal_grades</code>, <code>principal_subjects</code>, <code>principal_class_periods</code>,
<code>principal_service_record</code>) find the school the principal heads this year and redirect to that school's page;
a principal without a school sees the \"not assigned\" page.</p>"""

DIAGRAM = """flowchart LR
    ADM(["Administrator"]) --> D1["Admin dashboard"]
    PRN(["Principal"]) --> D2["Principal dashboard"] & D3["Classes right now"] & D4["Menu shortcuts"]
    TCH(["Teacher"]) --> D5["Teacher dashboard"] & D6["Your class now"]
    STU(["Student"]) --> D7["Student dashboard"]
"""

FLOWS = [("Home redirect", """flowchart TB
    A["/ (app_home)"] --> B{"Role"}
    B -- admin --> C["/admin"]
    B -- principal --> D["/principal"]
    B -- teacher --> E["/teacher"]
    B -- student --> F["/student"]
""")]

USE_CASES = []

VIEWS = [
    View("Admin dashboard", "Administrator", "admin_dashboard /admin", "Plans ending soon, the current year's principal figures (active schools, principals assigned, principals not yet assigned, schools without a principal), account counts and Recent activity.", "GetAdminDashboardCommand, GetRecentActivityCommand"),
    View("Principal dashboard", "Principal", "principal_dashboard /principal", "Teachers and learners counts vs the plan, why the plan blocks changes (if it does), pending transfers out, today's attendance, pending grade change requests, failing learners of the default grading period.", "GetPrincipalDashboardCommand"),
    View("Classes right now", "Principal", "principal_now /principal/now", "What is being taught at the school at this moment (from the timetable and the school clock).", "GetPrincipalNowCommand"),
    View("Principal menu shortcuts", "Principal", "principal_teachers ... principal_service_record", "Redirect to the principal's school pages.", "GetPrincipalSchoolCommand"),
    View("Teacher dashboard", "Teacher", "teacher_dashboard /teacher", "The teacher's school and advisory, plan block notice, pending transfers out of the advisory.", "GetTeacherDashboardCommand"),
    View("Your class now", "Teacher", "teacher_now /teacher/now", "The class the teacher has at this moment.", "GetTeacherNowCommand"),
    View("Student dashboard", "Student", "student_dashboard /student", "The student's enrolment this year (section, remarks) and the section's timetable per term.", "GetStudentDashboardCommand"),
]
