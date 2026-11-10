"""Use cases: Schools."""
from uc_model import UC, View

TITLE = "Schools: schools, school years, divisions, principals, teachers and the calendar"
FILE = "Use-Cases-02-Schools"
MODULES = "Schools"
INTRO = """<p>The Schools module sets up <b>where</b> and <b>when</b> work happens. The administrator keeps the list of
divisions, the schools (with their plan and seats) and the school years with their grading periods, and assigns each
school's principal per school year. The principal (or an administrator on the same pages) keeps the school's teachers,
its calendar of days off and its own profile.</p>
<p><b>Plan limits</b> apply everywhere: a school's teachers and students are capped by <i>plan x seats</i>; an inactive
school or an expired plan makes the school's pages view-only (\"The plan of &lt;school&gt; expired on &lt;date&gt;. Contact the
administrator to renew.\"). Who may manage a school is <code>SchoolVoter::MANAGE</code>: administrators for any school,
principals for their own school in the current year. Teacher changes take the <code>school-teachers-&lt;id&gt;</code> lock.</p>"""

DIAGRAM = """flowchart LR
    ADM(["Administrator"])
    PRN(["Principal"])
    ADM --> D1["Add / edit division"] & S1["Add / edit school"] & S2["Activate / deactivate school"]
    ADM --> Y1["Add / edit school year + grading periods"] & Y2["Make school year current"]
    ADM --> P1["Assign / remove principal"] & P2["Copy last year's principals"]
    ADM --> T1["Move a teacher account to a school"] & T2["Take a teacher off its school"]
    PRN --> R1["Edit school profile"]
    PRN --> C1["Add days off"] & C2["Remove a day off"]
    PRN --> TT1["Create teacher"] & TT2["Add existing teacher"] & TT3["Edit teacher"] & TT4["Deactivate / reactivate teacher"] & TT5["Reset teacher password"] & TT6["Bring back last year's teachers"]
    ADM -. "same school pages" .-> C1 & TT1
"""

FLOWS = [("Setting up a new school year", """flowchart TB
    A["Admin: add school year<br/>(name 2026-2027, dates, terms or quarters)"] --> B["Grading periods are built from the dates"]
    B --> C["Admin: adjust grading period dates (edit school year)"]
    C --> D["Admin: make it current<br/>(the previous one stops being current)"]
    D --> E["Admin: copy last year's principals"]
    E --> F["Principal: bring back last year's teachers<br/>(within the plan's teacher seats)"]
    F --> G["Principal: add days off for the year"]
    G --> H["Classes module: bring back sections and subjects"]
"""), ("Adding a teacher within the plan", """flowchart TB
    A["Principal opens Teachers"] --> B{"Seat left?<br/>(plan teachers per seat x seats)"}
    B -- no --> X["'The &lt;plan&gt; plan allows N teacher(s) (N used). Contact the administrator to upgrade.'"]
    B -- yes --> C{"New person?"}
    C -- yes --> D["Create teacher: name + username<br/>account created with a temporary password (shown once)"]
    C -- no --> E["Add existing teacher by username<br/>(must be an active teacher account)"]
    D --> F["SchoolAssignment(school, current year, teacher)"]
    E --> F
""")]

USE_CASES = [
    UC("SCH-01", "Add or edit a division", "Administrator", "admin_divisions_new /admin/divisions/new; admin_divisions_edit",
       "Keep the list of schools divisions and their Superintendent (who signs principals' IDs).",
       ["Signed in as administrator."],
       ["The admin picks the region and the division from the fixed Philippine list.",
        "Enters the Superintendent's name and position (prefilled \"Schools Division Superintendent\") and may upload a signature.",
        "The system saves the division and logs \"Added division ...\" / \"Saved division ...\"."],
       ["Already listed: \"This division is already on the list.\"",
        "Missing fields: \"Choose a region.\", \"Choose a division.\", \"Enter the Superintendent's ...\""],
       "Division row; signature image stored as WebP.", "CreateDivisionCommand, UpdateDivisionCommand (DivisionDetails)"),
    UC("SCH-02", "Add or edit a school", "Administrator", "admin_schools_new /admin/schools/new; admin_schools_edit",
       "Register a DepEd school and its subscription.",
       ["Signed in as administrator; plans exist."],
       ["The admin enters the 6-digit School ID, name, type, region, division, district and address.",
        "Chooses the plan (Basic is preselected), number of seats and the date the plan is valid until.",
        "The system saves and logs \"Created school ...\" / \"Updated school ...\"."],
       ["School ID not 6 digits: \"School ID must be exactly 6 digits.\"; taken: \"This School ID already exists.\"",
        "Moving to a plan without a principal while the school has one: \"Remove the principal of &lt;school&gt; for &lt;year&gt; before choosing the &lt;plan&gt; plan.\""],
       "School row.", "CreateSchoolCommand, UpdateSchoolCommand (SchoolDetails)"),
    UC("SCH-03", "Activate or deactivate a school", "Administrator", "admin_schools_toggle /admin/schools/{id}/toggle",
       "Stop (or resume) a school's use of the system without losing data.",
       ["Signed in as administrator."],
       ["The admin presses the toggle on the school list.", "The system flips School.active and logs it."],
       ["-"],
       "School.active toggled. An inactive school's pages become view-only (\"This school is inactive.\").", "ToggleSchoolActiveCommand"),
    UC("SCH-04", "Add or edit a school year", "Administrator", "admin_school_year_new; admin_school_year_edit (/admin/school-years/...)",
       "Define a school year, its dates and whether it uses terms or quarters.",
       ["Signed in as administrator."],
       ["The form suggests the next year's name and dates.",
        "The admin sets name (2026-2027), start and end dates and the grading system.",
        "The grading periods are built from the dates; when editing, each period's dates can be set.",
        "The first school year created becomes current automatically. The change is logged."],
       ["Bad name: \"Use the format 2026-2027.\"; taken: \"This school year already exists.\"",
        "End before start: \"The end date must be after the start date.\"",
        "Missing period dates: \"Enter the dates of &lt;period&gt;.\"",
        "Switching terms/quarters while grading is open anywhere: \"Close grading in every school before switching between terms and quarters.\"",
        "Switching would delete a period that has grades: \"&lt;period&gt; has grades. Switching to &lt;n&gt;s would delete them.\""],
       "SchoolYear and GradingPeriod rows.", "CreateSchoolYearCommand, UpdateSchoolYearCommand (SchoolYearDetails, GradingPeriodInput)"),
    UC("SCH-05", "Make a school year current", "Administrator", "admin_school_year_make_current /admin/school-years/{id}/make-current",
       "Switch the whole platform to a new school year.",
       ["Signed in as administrator; the year exists."],
       ["The admin presses Make current.", "The system clears the old current year, sets this one and logs it."],
       ["-"],
       "Exactly one SchoolYear.current = true. Every page and change now works on this year; earlier years become read-only history.",
       "MakeSchoolYearCurrentCommand"),
    UC("SCH-06", "Assign or remove a school's principal", "Administrator", "admin_schools_principal /admin/schools/{id}/principal; admin_schools_principal_remove",
       "Put a principal in charge of a school for a school year.",
       ["Signed in as administrator. The school's plan includes a principal."],
       ["The admin opens the school's Principal page (it lists active principals not yet assigned that year).",
        "Picks a principal and saves.",
        "The system creates the principal's SchoolAssignment for that year (or removes it)."],
       ["Account not a principal: \"Only accounts with the Principal role can be assigned.\"",
        "Plan without principal: \"The &lt;plan&gt; plan does not include a principal.\"",
        "\"This account is inactive.\" / \"This school is inactive.\" / \"The school already has a principal for &lt;year&gt;.\""],
       "SchoolAssignment (role principal) created or removed.", "AssignPrincipalCommand, RemovePrincipalCommand → PrincipalAssigner"),
    UC("SCH-07", "Copy last year's principals", "Administrator", "admin_schools_copy_principals /admin/schools/copy-principals",
       "Carry every school's principal into the new school year in one step.",
       ["Signed in as administrator; a previous school year exists."],
       ["On the school list for a year, the admin presses Copy principals.",
        "The system assigns each school's previous principal for this year where the rules allow."],
       ["\"There is no previous school year to copy from.\""],
       "SchoolAssignment rows (principal) for the year.", "CopyPrincipalsCommand"),
    UC("SCH-08", "Move a teacher account to a school, or take it off", "Administrator", "admin_account_school /admin/accounts/{id}/school; admin_account_school_remove",
       "Set which school a teacher account works at this school year.",
       ["Signed in as administrator; the account has the teacher role; current school year exists."],
       ["From the account, the admin opens School and picks an active school.",
        "The system takes the school's teacher lock, checks the seat limit and assigns the teacher (or removes the assignment).",
        "When the teacher leaves a school, the Classes module (TeacherDepartureInterface) clears the section they advised there and logs \"Removed &lt;user&gt; as adviser of &lt;section&gt;\"."],
       ["\"Only teacher accounts are assigned to a school here.\"",
        "Teaching loads left: \"&lt;name&gt; still has N loads this school year.\" (remove them from the timetable first)",
        "Seat limit reached (plan message)."],
       "SchoolAssignment (role teacher) created, moved or removed.", "AssignTeacherSchoolCommand, RemoveTeacherSchoolCommand → SchoolTeacherManager"),
    UC("SCH-09", "Edit the school profile", "Principal", "principal_school /principal/school",
       "Keep the school's contact details, logo, ID signature and the names that print on forms.",
       ["Signed in as the school's principal this year."],
       ["The principal edits address, contact number, e-mail, Facebook page.",
        "Sets the SF8 certifier, the service record 'prepared by' and 'certified by', and the lesson plan 'checked by' names and positions.",
        "May upload a new logo and an ID signature (stored as WebP).",
        "The system saves and logs \"Updated school profile ...\"."],
       ["Unreadable or too large image (image service messages)."],
       "School profile columns.", "UpdateSchoolProfileCommand"),
    UC("SCH-10", "Add days off", "Principal, Administrator", "school_calendar /schools/{id}/calendar",
       "Mark a holiday, suspension or a whole break as days without classes.",
       ["Manages the school; current school year exists."],
       ["The principal enters a date (or a from-to range) and a reason.",
        "The system adds every weekday in the range that is not already a day off, and logs it.",
        "If sections already took attendance on those days, the page tells how many (from the Attendance module)."],
       ["\"Pick a date.\", \"Give a reason.\"",
        "\"The end date must be on or after the start date.\"",
        "\"Days off must be inside the school year (&lt;start&gt; - &lt;end&gt;).\"",
        "\"Add at most N days at a time.\""],
       "SchoolDayOff rows. Days off are skipped by roll calls, SF2 school days and lesson plan weeks.", "AddDaysOffCommand"),
    UC("SCH-11", "Remove a day off", "Principal, Administrator", "school_calendar_remove /schools/{id}/calendar/{dayOff}/remove",
       "Turn a day off back into a school day.",
       ["Manages the school."],
       ["The principal presses Remove next to the day.", "The system deletes it and logs it."],
       ["\"The day off was not found.\""],
       "SchoolDayOff row removed.", "RemoveDayOffCommand"),
    UC("SCH-12", "Create a teacher", "Principal, Administrator", "school_teachers_new /schools/{id}/teachers/new",
       "Give a new teacher an account and a place at the school this year.",
       ["Manages the school; a teacher seat is free; the school is active and its plan valid."],
       ["The principal enters full name and username.",
        "The system takes the school's teacher lock, checks the seat limit, creates the account with a temporary password and assigns it to the school for the current year.",
        "The temporary password is shown once."],
       ["Username taken: \"This username is already taken.\"",
        "No seat: \"The &lt;plan&gt; plan allows N teacher(s) (N used). Contact the administrator to upgrade.\"",
        "Double submit: \"This was already saved by another request...\""],
       "User (teacher) + SchoolAssignment.", "CreateTeacherCommand (TeacherDetails) → SchoolTeacherManager::create"),
    UC("SCH-13", "Add an existing teacher", "Principal, Administrator", "school_teachers_add_existing /schools/{id}/teachers/add-existing",
       "Bring a teacher who already has an account (e.g. moved from another school) into this school.",
       ["Manages the school; seat free."],
       ["The principal types the teacher's username.", "The system checks the account and the seat limit and assigns it."],
       ["\"No teacher account with that username.\"", "\"This account is inactive. Ask the administrator.\"", "Seat limit."],
       "SchoolAssignment.", "AddExistingTeacherCommand"),
    UC("SCH-14", "Edit a teacher", "Principal, Administrator", "school_teachers_edit /schools/{id}/teachers/{teacher}/edit",
       "Correct a teacher's name or username.",
       ["Manages the school; the teacher belongs to it this year."],
       ["The principal edits and saves; the change is logged."],
       ["\"This teacher does not belong to the school this school year.\"", "Username taken."],
       "User updated.", "UpdateTeacherCommand"),
    UC("SCH-15", "Deactivate or reactivate a teacher", "Principal, Administrator", "school_teachers_toggle /schools/{id}/teachers/{teacher}/toggle",
       "Stop a teacher from signing in, or let them back within the plan.",
       ["Manages the school."],
       ["The principal presses the toggle.", "Reactivation checks the seat limit first."],
       ["Seat limit reached on reactivation."],
       "User.active toggled.", "ToggleTeacherActiveCommand"),
    UC("SCH-16", "Reset a teacher's password", "Principal, Administrator", "school_teachers_reset_password /schools/{id}/teachers/{teacher}/reset-password",
       "Help a teacher who forgot their password.",
       ["Manages the school; the teacher belongs to it this year."],
       ["The principal presses Reset password.", "A temporary password is generated, shown once, and must be changed at next sign-in."],
       ["-"], "User.password, must_change_password.", "ResetTeacherPasswordCommand"),
    UC("SCH-17", "Bring back last year's teachers", "Principal, Administrator", "school_teachers_bring_back /schools/{id}/teachers/bring-back",
       "Re-assign last year's teachers to the new school year in one step.",
       ["Manages the school; a previous school year exists."],
       ["The Teachers page lists last year's teachers not yet back.", "The principal presses Bring back; teachers are added until the seats run out."],
       ["\"There is no previous school year to bring teachers back from.\"", "Seat limit."],
       "SchoolAssignment rows for the current year.", "BringBackTeachersCommand"),
]

VIEWS = [
    View("School list (per school year)", "Administrator", "admin_schools /admin/schools", "Schools with principal, teacher count, plan and validity; switch year with ?sy=.", "ListSchoolsCommand"),
    View("School form", "Administrator", "admin_schools_new / _edit", "The school and the plans to choose from.", "GetSchoolFormCommand"),
    View("Principal assignment", "Administrator", "admin_schools_principal", "Current principal and assignable principals.", "GetPrincipalAssignmentCommand"),
    View("Divisions", "Administrator", "admin_divisions /admin/divisions", "Every division by region.", "ListDivisionsCommand, GetDivisionCommand"),
    View("School years", "Administrator", "admin_school_years /admin/school-years", "Years newest first, how many schools have each period open.", "ListSchoolYearsCommand, GetSchoolYearFormCommand"),
    View("Teacher's school", "Administrator", "admin_account_school", "The teacher's school this year and schools to move to.", "GetTeacherSchoolCommand"),
    View("School profile", "Principal", "principal_school /principal/school", "The principal's school this year.", "GetSchoolProfileCommand"),
    View("Teachers", "Principal, Administrator", "school_teachers /schools/{id}/teachers", "Teachers this year, seats used, who can be brought back.", "ListSchoolTeachersCommand, GetSchoolTeacherCommand, GetTeacherSeatCommand"),
    View("Calendar", "Principal, Administrator", "school_calendar /schools/{id}/calendar", "Days off this school year.", "GetSchoolCalendarCommand"),
]
