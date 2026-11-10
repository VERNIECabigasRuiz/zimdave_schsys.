"""Use cases: Grading."""
from uc_model import UC, View

TITLE = "Grading: grade sheets, opening and locking periods, change requests and card slips"
FILE = "Use-Cases-06-Grading"
MODULES = "Grading"
INTRO = """<p>Teachers record each learner's whole-number grade (60-100) per subject and grading period on a <b>grade
sheet</b>. The school controls when grades may change: the principal or an administrator <b>opens</b> and
<b>closes</b> a period for grading at the school; the principal <b>locks</b> it when grades are final, and may
<b>unlock</b> a single class for corrections. Once a period is locked, a teacher who needs a correction sends a
<b>grade change request</b>, which the principal approves (grades change at once) or declines.</p>
<p>Only Grade 1-10 sections of the current school year are graded here, and only subjects without components (a parent
subject such as MAPEH gets the average of its components). Advisers write the <b>card slip</b> comments of their class
and print card slips (report cards) with grades, attendance and the descriptor of the general average.</p>
<p><b>Who may do what</b> (<code>GradePolicy</code>): only a load's current teacher enters grades; the adviser, the
principal and administrators view; only the principal locks and unlocks; the principal and administrators open and
close; only the sender withdraws a request and only the school's principal decides it. <b>Locks</b>: a sheet save takes
<code>grades-&lt;section&gt;-&lt;subject&gt;</code> plus a shared hold on <code>grade-lock-&lt;school&gt;</code>; the principal's
period changes take <code>grade-lock-&lt;school&gt;</code> exclusively, so no sheet can be saved while a period is being locked.</p>"""

DIAGRAM = """flowchart LR
    TCH(["Subject teacher"])
    ADV(["Adviser"])
    PRN(["Principal"])
    ADM(["Administrator"])
    TCH --> G1["Save a grade sheet"] & G2["Request a grade change"] & G3["Withdraw a change request"]
    PRN & ADM --> O1["Open grading of a period"] & O2["Close grading of a period"]
    PRN --> L1["Lock a period"] & L2["Unlock a period"] & L3["Unlock one class"] & L4["Lock a class again"]
    PRN --> C1["Approve a change request"] & C2["Decline a change request"]
    ADV --> K1["Save card slip comments"] & K2["Reset a learner's comments to the suggestions"] & K3["Print card slips"]
    PRN & ADM --> K3
"""

FLOWS = [("Status of a class's grades for a period", """stateDiagram-v2
    [*] --> Closed
    Closed --> Open : open grading (school)
    Open --> Closed : close grading
    Open --> Locked : principal locks the period
    Closed --> Locked : lock (only if grades exist)
    Locked --> Unlocked : principal unlocks this class
    Unlocked --> Locked : principal locks the class again
    Locked --> Open : principal unlocks the period
    note right of Open : teachers can save sheets
    note right of Unlocked : this class's teacher can save the sheet
    note right of Locked : teachers send change requests
"""), ("Grade change request", """sequenceDiagram
    autonumber
    participant T as Teacher
    participant S as System
    participant P as Principal
    T->>S: Open change form for a locked period
    S-->>T: refused if not locked, not graded here, or a request is pending
    T->>S: Send corrected grades + reason (10+ chars)
    S->>S: one line per learner whose value differs (from -> to)
    S-->>P: appears under Grade changes (pending)
    alt approve
        P->>S: Approve
        S->>S: check grades are unchanged since the request
        S->>S: write the new grades at once
    else decline
        P->>S: Decline with a note (5+ chars)
    else withdraw
        T->>S: Withdraw while pending
    end
""")]

USE_CASES = [
    UC("GRD-01", "Save a grade sheet", "Subject teacher", "teacher_grades_sheet /teacher/grades/{load}/{period}",
       "Enter or change the grades of one class (section + subject) for a grading period.",
       ["The teacher is the load's current teacher; the class is gradable; the period is Open, or the class is Unlocked."],
       ["The teacher opens the sheet from My grades; it lists the learners who count in the section.",
        "Types grades (blank = no grade yet) and saves.",
        "The system takes the class lock and a shared hold on the school's grade lock, re-reads the grades and saves only changed values.",
        "\"Saved &lt;period&gt; grades of &lt;class&gt;\" is logged."],
       ["\"You cannot enter grades for this class.\"", "\"&lt;period&gt; isn't open for grading.\"",
        "\"&lt;period&gt; is locked by the principal.\"", "\"Enter a grade from 60 to 100.\"",
        "Busy: \"Another change is being saved. Please try again.\""],
       "Grade rows created or changed (updated_by, updated_at).", "SaveGradeSheetCommand → GradeRecorder::saveSheet"),
    UC("GRD-02", "Open or close grading of a period", "Principal, Administrator", "school_grades_open / school_grades_close (/schools/{id}/grades/{period}/...)",
       "Let the school's teachers enter grades for a period, or stop them.",
       ["Manages the school; the period belongs to the current school year; it is not locked."],
       ["On the school's Grades page the principal presses Open (or Close) for the period.",
        "The system takes the school's grade lock and adds (or removes) the GradeOpening; an admin's log line names the school."],
       ["\"&lt;period&gt; is already open.\" / \"&lt;period&gt; is already closed.\"", "\"&lt;period&gt; is locked. Unlock it first.\""],
       "GradeOpening created or removed.", "OpenGradingPeriodCommand, CloseGradingPeriodCommand"),
    UC("GRD-03", "Lock a period", "Principal", "school_grades_lock /schools/{id}/grades/{period}/lock",
       "Make a period's grades final for the whole school.",
       ["Principal of the school; the period is open, or the school already has grades in it."],
       ["The principal reviews the progress of every class and confirms Lock.",
        "The system removes any class unlocks, adds the GradeLock and logs \"Locked &lt;period&gt; grades\"."],
       ["\"&lt;period&gt; is already locked.\"", "\"&lt;period&gt; hasn't been opened for grading yet.\""],
       "GradeLock created; GradeUnlock rows of the period removed.", "LockGradingPeriodCommand"),
    UC("GRD-04", "Unlock a period", "Principal", "school_grades_unlock /schools/{id}/grades/{period}/unlock",
       "Undo a lock (e.g. locked too early).", ["The period is locked."],
       ["The principal presses Unlock; the GradeLock is removed. The period is Open or Closed again depending on its opening."],
       ["\"&lt;period&gt; is not locked.\""], "GradeLock removed.", "UnlockGradingPeriodCommand"),
    UC("GRD-05", "Unlock one class, or lock it again", "Principal", "school_grades_unlock_class / school_grades_lock_class",
       "Let one class's teacher fix grades in a locked period without unlocking the whole school.",
       ["The period is locked; the class is gradable."],
       ["On a section's grades the principal unlocks the subject; the teacher can now save that sheet.",
        "When done, the principal locks the class again."],
       ["\"&lt;period&gt; is not locked.\"", "\"This class is already unlocked.\"", "\"This class is not unlocked.\""],
       "GradeUnlock created or removed.", "UnlockClassGradesCommand, LockClassGradesCommand"),
    UC("GRD-06", "Request a grade change", "Subject teacher", "teacher_grades_change /teacher/grades/{load}/{period}/change",
       "Ask the principal to correct grades of a locked period.",
       ["The teacher's class; the period is locked (and the class not unlocked); no pending request for the class."],
       ["The teacher opens the change form, enters the corrected grades and a reason.",
        "The system keeps one line per learner whose value differs (from, to) and saves the request as pending."],
       ["\"This class isn't graded here.\"", "\"&lt;period&gt; isn't locked - change the grades on the sheet.\"",
        "\"You already have a pending change request for this class.\"",
        "\"Write the reason for the change (at least 10 characters).\"", "\"Enter a grade from 60 to 100.\"",
        "\"Enter at least one corrected grade.\""],
       "GradeChangeRequest (pending) + GradeChangeLine rows.", "RequestGradeChangeCommand → GradeChangeManager::request"),
    UC("GRD-07", "Approve a grade change", "Principal", "school_grade_change_approve /schools/{id}/grade-changes/{request}/approve",
       "Accept the correction; the grades change at once.",
       ["Principal of the school; the request is pending."],
       ["The principal opens Grade changes, reviews from/to values and the reason, and approves.",
        "The system takes the class lock and the school's grade lock, checks the grades have not changed since the request, writes the new values and marks it approved."],
       ["\"Grades have changed since this request was sent. Decline it so the teacher can send a new one.\"",
        "\"This request has already been decided.\""],
       "Grade values; GradeChangeRequest.status = approved, decided_by, decided_at.", "ApproveGradeChangeCommand"),
    UC("GRD-08", "Decline a grade change", "Principal", "school_grade_change_decline /schools/{id}/grade-changes/{request}/decline",
       "Refuse the correction with a note for the teacher.", ["The request is pending."],
       ["The principal writes a note and declines."],
       ["\"Write a note for the teacher (at least 5 characters).\"", "\"This request has already been decided.\""],
       "GradeChangeRequest.status = declined, decision_note.", "DeclineGradeChangeCommand"),
    UC("GRD-09", "Withdraw a grade change request", "Subject teacher (the sender)", "teacher_grades_change_withdraw /teacher/grades/changes/{request}/withdraw",
       "Take back a request that is still waiting.", ["The teacher sent it; it is pending."],
       ["The teacher presses Withdraw on the sheet."], ["\"This request has already been decided.\""],
       "GradeChangeRequest.status = withdrawn.", "WithdrawGradeChangeCommand"),
    UC("GRD-10", "Save card slip comments", "Adviser", "teacher_class_card_slips /teacher/class/card-slips",
       "Choose the comments printed on each learner's card slip for a term.",
       ["The teacher advises a section this school year."],
       ["The adviser opens Card slips for a term; each learner has suggested comments (from grades and attendance: excellent / very good / good performance, improvement, needs to improve in &lt;subjects&gt;, incomplete grades, perfect attendance, frequent absences, often late ...).",
        "The adviser ticks built-in comments and may type one own comment per learner, then saves.",
        "A learner left on the suggestions with no typed comment is not stored (a saved choice is removed), so their slip keeps following the suggestions as the grades change."],
       ["\"No such grading period.\"", "Not the adviser (403)."],
       "CardSlipComment rows (codes JSON + custom text).", "SaveCardSlipCommentsCommand → CardSlipCommentRecorder::save"),
    UC("GRD-11", "Reset a learner's card slip comments", "Adviser", "teacher_class_card_slip_reset /teacher/class/card-slips/{period}/{enrollment}/reset",
       "Go back to the suggested comments for one learner.", ["The learner is in the adviser's class."],
       ["The adviser presses Reset; the stored choice is removed."], ["\"No such learner in your class.\""],
       "CardSlipComment removed.", "ResetCardSlipCommentCommand"),
]

VIEWS = [
    View("My grades", "Subject teacher", "teacher_grades /teacher/grades", "Every class the teacher grades with each period's progress and status.", "GetTeacherGradesCommand"),
    View("Grade sheet", "Teacher, Adviser, Principal", "teacher_grades_sheet", "A class's sheet, its status, change requests and the section grid.", "GetGradeSheetCommand"),
    View("Change form", "Subject teacher", "teacher_grades_change", "The form, or why a request can't be sent now.", "GetGradeChangeFormCommand"),
    View("Class grades", "Adviser", "teacher_class_grades /teacher/class/grades", "All subjects of the advised section for a period.", "GetClassGradesCommand"),
    View("School grades overview", "Principal, Administrator", "school_grades /schools/{id}/grades (principal_grades)", "Every class's progress for a period, opening/lock state, pending requests.", "GetSchoolGradesCommand, GetGradeLockCommand"),
    View("A section's grades", "Principal, Administrator", "school_grades_section", "Every subject of a section for a period.", "GetSectionGradesCommand"),
    View("Failing learners", "Principal, Administrator", "school_grades_failing", "Learners who failed a period's general average.", "GetFailingLearnersCommand"),
    View("Grade change requests", "Principal", "school_grade_changes /schools/{id}/grade-changes", "Pending or decided requests this year.", "GetGradeChangesCommand, GetGradeChangeCommand"),
    View("Card slips page", "Adviser", "teacher_class_card_slips", "Each learner's slip preview and comments for a term.", "GetCardSlipsCommand"),
    View("Card slip PDF", "Adviser, Principal, Administrator", "teacher_class_card_slips_pdf, teacher_class_card_slip_pdf, school_section_card_slips_pdf", "Report cards: grades, general average and DepEd descriptor, attendance (from the Attendance API), comments.", "GetCardSlipPdfCommand"),
]
