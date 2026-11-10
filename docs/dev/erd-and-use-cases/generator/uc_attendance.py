"""Use cases: Attendance."""
from uc_model import UC, View

TITLE = "Attendance: daily roll calls and the monthly SF2"
FILE = "Use-Cases-05-Attendance"
MODULES = "Attendance"
INTRO = """<p>The section's <b>adviser</b> takes a roll call for each school day. Only exceptions are stored: a learner
who is absent, late or cutting classes gets a mark; everyone else counts as present. At the end of the month the adviser
<b>submits the SF2</b> (School Form 2, Daily Attendance Report) to the principal, which locks the month. The principal
(or an administrator) can <b>reopen</b> a submitted month so the adviser can correct it.</p>
<p>Who counts on a day comes from the Learners API (<code>SectionRosterInterface</code>): learners who joined later or
left by transfer only count on their days. School days come from the Schools calendar (weekdays inside the school year
that are not days off). Every change takes the <code>attendance-section-&lt;id&gt;</code> lock. Who may do what is
<code>AttendancePolicy</code>: the adviser takes and submits (current year only); the principal and administrators view
and reopen.</p>"""

DIAGRAM = """flowchart LR
    ADV(["Adviser"])
    PRN(["Principal / Administrator"])
    ADV --> A1["Take or update the day's roll call"] & A2["Submit the month's SF2"]
    ADV --> V1["View month, download SF2"]
    PRN --> R1["Reopen a submitted month"]
    PRN --> V2["School attendance overview, a section's month, SF2 download"]
"""

FLOWS = [("A month of attendance", """stateDiagram-v2
    [*] --> Open
    Open --> Open : adviser takes / edits roll calls (school days up to today)
    Open --> Submitted : adviser submits SF2 after the month's last school day
    Submitted --> Open : principal or admin reopens (Sf2Submission removed)
    Submitted --> [*]
""")]

USE_CASES = [
    UC("ATT-01", "Take the day's roll call", "Adviser", "teacher_class_attendance /teacher/class/attendance/{date}",
       "Record who was absent, late or cutting classes on a school day.",
       ["The teacher advises a section this school year; the date is a school day up to today; the month is not submitted."],
       ["The adviser opens the roll call (today by default) and sees the learners who count that day.",
        "Marks each exception: Absent, Late or Cutting; everyone else is present.",
        "Saves. The system takes the section lock, ignores marks for learners who don't count that day, creates or updates the roll call and replaces its marks.",
        "The activity log records \"Took attendance of ...\" or \"Updated attendance of ...\"."],
       ["\"That date is outside the school year.\"", "\"No classes on weekends.\"", "\"No classes: &lt;day off reason&gt;.\"",
        "\"You can't take attendance for a future date.\"", "\"&lt;Month Year&gt; is submitted and locked.\"",
        "\"You do not advise a section this school year.\" / \"You can no longer take attendance for this section.\""],
       "RollCall (one per section and day) and its AttendanceMark rows.", "TakeRollCallCommand → AttendanceRecorder::takeRollCall"),
    UC("ATT-02", "Submit the month's SF2", "Adviser", "teacher_class_attendance_submit /teacher/class/attendance/month/{month}/submit",
       "Hand the month's attendance to the principal.",
       ["The month's last school day (or the school year) has passed; not yet submitted."],
       ["The adviser opens the month page, which shows the SF2 figures and any school days without a roll call.",
        "If days are missing, the adviser ticks the confirmation that everyone was present on them.",
        "Submits. The month is locked and \"Submitted SF2 of &lt;section&gt; for &lt;month&gt;\" is logged."],
       ["\"&lt;Month Year&gt; is already submitted.\"", "\"You can submit &lt;Month&gt; once its last school day has passed.\"",
        "\"N school days have no attendance. Confirm that everyone was present on those days.\""],
       "Sf2Submission (section, month).", "SubmitSf2Command → AttendanceRecorder::submit"),
    UC("ATT-03", "Reopen a submitted month", "Principal, Administrator", "school_attendance_reopen /schools/{id}/sections/{section}/attendance/{month}/reopen",
       "Let the adviser correct a month that was already submitted.",
       ["Manages the school; the month is submitted."],
       ["From the section's month page the principal presses Reopen.", "The submission is removed and \"Reopened SF2 ...\" logged."],
       ["Not allowed by AttendancePolicy (403)."], "Sf2Submission removed.", "ReopenSf2Command"),
]

VIEWS = [
    View("Roll call page", "Adviser", "teacher_class_attendance", "Who counts on the day, marks so far, and whether it can be taken.", "GetRollCallPageCommand"),
    View("Month page", "Adviser, Principal", "teacher_class_attendance_month; school_attendance_section", "The month with SF2 figures, missing days, lock state.", "GetAttendanceMonthCommand"),
    View("School attendance overview", "Principal, Administrator", "school_attendance /schools/{id}/attendance (principal_attendance)", "Today's attendance and every section's month, with follow-ups.", "GetAttendanceOverviewCommand"),
    View("SF2 file (.xlsx)", "Adviser, Principal", "SF2 download links on the month pages (teacher_class_sf2)", "DepEd SF2 filled into sf2-template.xlsx.", "GetSf2FileCommand"),
]
