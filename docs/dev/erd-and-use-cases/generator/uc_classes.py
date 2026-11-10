"""Use cases: Classes."""
from uc_model import UC, View

TITLE = "Classes: sections, subjects, class periods, teaching loads and the timetable"
FILE = "Use-Cases-03-Classes"
MODULES = "Classes"
INTRO = """<p>The Classes module answers <b>what is taught, to whom, by whom and when</b> in the current school year.
The principal (or an administrator) creates the school's <b>sections</b> (with an adviser), the <b>subjects</b> per grade
level (optionally the standard DepEd list, and components such as the four parts of MAPEH) and the <b>class periods</b>
(the bell schedule). On each section's <b>timetable</b> page they pick the teacher of every subject (the teaching load)
and place its weekly meetings on the grid.</p>
<p>On the <b>Individual plan</b> (no principal) the school's single teacher creates their own section and keeps its
subjects from <code>/teacher/section</code> and <code>/teacher/subjects</code>.</p>
<p>All writes are refused while the school is inactive or its plan expired. Class period and timetable changes take
the <code>school-timetable-&lt;id&gt;</code> lock so a period can't become a break, or be removed, while a class is being
placed in it. The managers check every edit before applying it.</p>"""

DIAGRAM = """flowchart LR
    PRN(["Principal / Administrator"])
    SOLO(["Individual-plan teacher"])
    PRN --> S1["Create / edit / delete section"] & S2["Bring back last year's sections"]
    PRN --> J1["Create / edit / delete subject"] & J2["Add DepEd subjects for a grade"] & J3["Bring back last year's subjects"]
    PRN --> P1["Create / edit / delete class period"]
    PRN --> T1["Set a subject's teacher (teaching load)"] & T2["Plot a class on the timetable"] & T3["Remove a class from the timetable"]
    SOLO --> O1["Create own section"]
    SOLO --> J1 & J2 & J3
"""

FLOWS = [("From section to timetable", """flowchart TB
    A["Class periods<br/>(07:30-08:30 ..., breaks labelled)"] --> E
    B["Section (grade, name, adviser)"] --> D
    C["Subjects of the grade level<br/>(meetings per week, components)"] --> D
    D["Teaching load = section + subject + teacher"] --> E["Schedule entries<br/>weekday x period (x term for SHS semesters)"]
    E --> F{"Clash?"}
    F -- "section slot taken" --> X1["Refused"]
    F -- "teacher busy in another section" --> X2["Refused, clashes listed"]
    F -- "no" --> G["Placed on the grid; teacher's load page and 'Your class now' card update"]
""")]

USE_CASES = [
    UC("CLS-01", "Create, edit or delete a section", "Principal, Administrator", "school_sections_new / _edit / _delete (/schools/{id}/sections/...)",
       "Keep the school's classes for the current school year.",
       ["Manages the school; current school year; school active and plan valid."],
       ["The principal chooses grade level, enters the section name and (for Grades 11-12) the track and strand.",
        "Optionally picks the adviser from the school's teachers.",
        "The system checks the edit and saves it. Delete removes a section that has nothing hanging from it."],
       ["\"This section name already exists in &lt;grade&gt; for this school year.\"",
        "\"Choose the track of this Senior High section.\" / \"Only Grade 11 and 12 sections have a track and strand.\"",
        "Adviser already advises another section this year (unique adviser per year).",
        "Delete refused: \"&lt;section&gt; has N learners. Move or unenroll them first.\", \"&lt;section&gt; has N pending transfers.\" or \"&lt;section&gt; has a timetable. Remove its classes and teachers first.\"",
        "Plan blocked: view-only message."],
       "Section row created, updated or deleted.", "CreateSectionCommand, UpdateSectionCommand, DeleteSectionCommand → SectionManager"),
    UC("CLS-02", "Bring back last year's sections", "Principal, Administrator", "school_sections_bring_back /schools/{id}/sections/bring-back",
       "Copy last year's sections that this year doesn't have yet, without their advisers.",
       ["Manages the school; previous school year exists."],
       ["The Sections page shows the candidates.", "The principal presses Bring back; missing sections are created for the current year."],
       ["\"There is no previous school year.\""],
       "Section rows (adviser empty).", "BringBackSectionsCommand"),
    UC("CLS-03", "Create, edit or delete a subject", "Principal, Administrator, Individual-plan teacher", "school_subjects_new / _edit / _delete; teacher_subjects_*",
       "Define the subjects offered to each grade level this school year.",
       ["The page's scope resolves: the school (principal/admin), or the teacher's own advisory (Individual plan)."],
       ["Enter grade level, code, name, position and meetings per week (SHS: semester, track, strand).",
        "Optionally make it a component of a parent subject, or give it components (e.g. MAPEH with Music, Arts, PE, Health).",
        "The system validates and saves."],
       ["\"The code &lt;code&gt; is already used this school year.\" / \"Use only letters, numbers and dashes in the code.\"",
        "\"Set how many times a week this subject meets.\"", "\"Choose the semester of this Senior High subject.\"",
        "Component rules: \"A subject cannot be a component of itself.\", \"A component must be in the same grade level as its subject.\", \"A component cannot have components of its own.\"",
        "\"Move or delete the components of &lt;subject&gt; before changing its grade level.\"",
        "Teacher with a principal: \"Your principal sets up the subjects of your school.\"; without a section yet: \"Create your section first.\""],
       "Subject rows (parent_id for components).", "CreateSubjectCommand, UpdateSubjectCommand, DeleteSubjectCommand → SubjectManager"),
    UC("CLS-04", "Add the DepEd subjects for a grade level", "Principal, Administrator, Individual-plan teacher", "school_subjects_deped /schools/{id}/subjects/deped; teacher_subjects_deped",
       "Fill a grade level with the standard DepEd subject list in one step.",
       ["The scope resolves; the grade has a built-in list."],
       ["The principal picks a grade and presses Add DepEd subjects.", "Only subjects the year doesn't have yet are added (with components)."],
       ["\"There is no DepEd subject list for this grade level here.\""],
       "Subject rows.", "AddDepEdSubjectsCommand"),
    UC("CLS-05", "Bring back last year's subjects", "Principal, Administrator, Individual-plan teacher", "school_subjects_bring_back; teacher_subjects_bring_back",
       "Copy last year's subjects (with components) that the current year doesn't have.",
       ["A previous school year exists."],
       ["The principal presses Bring back on the Subjects page."], ["\"There is no previous school year.\""],
       "Subject rows.", "BringBackSubjectsCommand"),
    UC("CLS-06", "Create, edit or delete a class period", "Principal, Administrator", "school_class_periods_new / _edit / _delete",
       "Keep the bell schedule of the school day.",
       ["Manages the school; takes the timetable lock."],
       ["The principal enters start and end time; ticks 'break' and gives a label (Recess, Lunch) for non-class slots.",
        "The system checks overlaps and saves. Periods are numbered in time order, skipping breaks."],
       ["\"Enter the start time.\" / \"The end time must be after the start time.\"",
        "\"This time overlaps &lt;label&gt; (&lt;range&gt;).\"",
        "Turning a used period into a break, or deleting it: \"&lt;period&gt; has classes in N sections. Remove them first.\"",
        "Busy: \"Another change is being saved. Please try again.\""],
       "ClassPeriod rows.", "CreateClassPeriodCommand, UpdateClassPeriodCommand, DeleteClassPeriodCommand → ClassPeriodManager"),
    UC("CLS-07", "Set the teacher of a subject in a section (teaching load)", "Principal, Administrator", "school_timetable_load /schools/{id}/sections/{section}/timetable/loads/{subject}",
       "Decide who teaches each subject of a section.",
       ["Manages the school; the teacher is at the school this year."],
       ["On the section's timetable page the principal picks a teacher for a subject (or clears it).",
        "The system creates, changes or removes the TeachingLoad."],
       ["\"This teacher is not in the school this school year.\"",
        "Clearing while classes are placed: \"Remove &lt;subject&gt; from the timetable before clearing its teacher.\"",
        "Changing teacher where the new one is busy in those slots: refused with each clash listed."],
       "TeachingLoad row (and its schedule entries follow the new teacher).", "SetTeachingLoadCommand → TimetableManager::setTeacher"),
    UC("CLS-08", "Plot a class on the timetable", "Principal, Administrator", "school_timetable_plot /schools/{id}/sections/{section}/timetable/entries",
       "Place one weekly meeting of a subject on a weekday and class period (the page uses drag and drop, via the timetable Stimulus controller and JSON calls).",
       ["The subject has a teacher and meetings per week; the period is a class period of the school this year."],
       ["The principal drops the subject on a slot.",
        "The system takes the timetable lock and checks the section's slot and the teacher's slot.",
        "The entry is saved and the grid refreshes."],
       ["\"Pick a teacher for &lt;subject&gt; first.\"",
        "\"Set meetings per week for &lt;subject&gt; on the Subjects page first.\"",
        "\"&lt;break&gt; is a break, not a class period.\"", "\"&lt;subject&gt; already has all N meetings this week.\"",
        "Slot taken or teacher busy elsewhere: \"&lt;teacher/section&gt; already has &lt;class&gt; on &lt;day, period&gt;.\"",
        "A subject with components: \"&lt;subject&gt; has components. Pick teachers for its components instead.\""],
       "ScheduleEntry row.", "PlotClassCommand → TimetableManager::plot"),
    UC("CLS-09", "Remove a class from the timetable", "Principal, Administrator", "school_timetable_remove /schools/{id}/sections/{section}/timetable/entries/{entry}",
       "Take one meeting off the grid.",
       ["The entry belongs to the section."],
       ["The principal removes the meeting; the system deletes it under the timetable lock."],
       ["\"This class is not in the section.\""],
       "ScheduleEntry removed.", "RemoveClassCommand"),
    UC("CLS-10", "Create own section (Individual plan)", "Individual-plan teacher", "teacher_section_new /teacher/section",
       "Let the only teacher of a school without a principal set up the section they advise.",
       ["Teacher on a plan without a principal; advises no section yet."],
       ["The teacher fills the section form (no adviser field: they are the adviser).", "The system creates the section for the current year."],
       ["\"Your principal creates the sections of your school.\"", "\"You already advise &lt;section&gt;.\""],
       "Section row with adviser = the teacher.", "CreateOwnSectionCommand (OwnSectionGate)"),
]

VIEWS = [
    View("Sections", "Principal, Administrator", "school_sections /schools/{id}/sections (principal_sections shortcut)", "Sections by grade with enrolled counts and bring-back candidates.", "ListSectionsCommand, GetSectionFormCommand"),
    View("Subjects", "Principal, Administrator, Individual-plan teacher", "school_subjects; teacher_subjects", "Subjects by grade (and semester) with components.", "ListSubjectsCommand, GetSubjectFormCommand, GetSubjectScopeCommand"),
    View("Class periods", "Principal, Administrator", "school_class_periods", "The school day in time order, with numbering.", "ListClassPeriodsCommand, GetClassPeriodFormCommand"),
    View("Section timetable", "Principal, Administrator", "school_timetable, school_timetable_state (JSON)", "The weekly grid per term with each subject's teacher and remaining meetings.", "GetSectionTimetableCommand"),
    View("Teacher load", "Teacher (own), Principal", "teacher_load /teacher/load; school_teacher_load", "A teacher's loads and weekly schedule (per semester term).", "GetTeacherLoadCommand"),
    View("Own section form", "Individual-plan teacher", "teacher_section_new", "Whether the teacher may create their section now.", "GetOwnSectionFormCommand"),
]
