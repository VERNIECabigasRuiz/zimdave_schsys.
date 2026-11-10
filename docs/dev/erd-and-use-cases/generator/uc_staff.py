"""Use cases: Staff."""
from uc_model import UC, View

TITLE = "Staff: Personal Data Sheet, service record and staff ID"
FILE = "Use-Cases-09-Staff"
MODULES = "Staff"
INTRO = """<p>The Staff module keeps the personnel records of principals and teachers.</p>
<ul><li><b>Personal Data Sheet</b> (PDS, CS Form 212): each principal or teacher fills their own under
<code>/profile</code>, tab by tab (I Personal information, II Family background, III-VII lists: education, eligibility,
work experience, training, voluntary work, Other information, Questions 34-40, References and government ID). The sheet is
started on the first visit. It prints to PDF; the principal or an administrator can download a teacher's PDS.</li>
<li><b>Service record</b>: the principal keeps the rows (designation, status, salary, station, branch, leave without pay,
separation) of the people of their school, their own included; an administrator may only print. Rows belong to the
person, so they follow them to another school. Guarded by <code>ServiceRecordVoter</code>.</li>
<li><b>Staff ID</b>: a teacher's or principal's ID card (Big or Small template). Name and numbers come from the PDS, the
position from the service record's Present row, the signature from the school's principal (for a teacher) or the
division's Superintendent (for a principal).</li></ul>
<p>Changes take no lock.</p>"""

DIAGRAM = """flowchart LR
    ME(["Principal / Teacher (own records)"])
    PRN(["Principal"])
    ADM(["Administrator"])
    ME --> P1["Open profile (start the PDS)"] & P2["Save a PDS tab"] & P3["Download own PDS"]
    ME --> I1["Save staff ID details"] & I2["Print own staff ID"]
    PRN --> S1["Add / edit a service record row"] & S2["Delete a row"] & S3["Save the GSIS BP number"]
    PRN & ADM --> S4["Print a service record"] & D1["Download a teacher's PDS"]
"""

FLOWS = [("Service record row rules", """flowchart TB
    A["Principal: add row"] --> B["Drafted from the last row<br/>(designation, status, salary, station)"]
    B --> C{"Dates valid?"}
    C -- "end before start" --> X1["'The end date is before the start date.'"]
    C -- "overlaps another row" --> X2["'This service overlaps the row from &lt;date&gt;.'"]
    C -- "second open row" --> X3["'There is already a row up to the present. Close it first.'"]
    C -- ok --> D["Saved; the open (Present) row is closed when a new one starts"]
    D --> E["Present row's designation = position on the staff ID and lesson plan PDF"]
""")]

USE_CASES = [
    UC("STF-01", "Open the profile (start the PDS)", "Principal, Teacher", "profile_show /profile",
       "See one's own Personal Data Sheet; it is created on the first visit.", ["Signed in as principal or teacher."],
       ["The user opens Profile.", "If they have no sheet yet, an empty one is created for their account."], ["-"],
       "PersonalDataSheet (one per account).", "OpenPersonalDataSheetCommand"),
    UC("STF-02", "Save a PDS tab", "Principal, Teacher", "profile_personal, profile_family, profile_education, profile_eligibility, profile_work, profile_training, profile_voluntary, profile_other, profile_questions, profile_references",
       "Fill in one part of the PDS.", ["The user's own sheet."],
       ["The user opens a tab; the form is filled from the sheet.",
        "Edits the fields (list tabs add, edit, reorder and remove rows) and saves.",
        "The tab's command validates and writes back to the sheet."],
       ["Personal info: \"Enter your surname.\", \"Date of birth must be in the past.\", \"Choose your civil status.\", \"ZIP code must be 4 digits.\", \"Mobile number must look like 09171234567.\", height/weight ranges, citizenship details.",
        "Lists: \"The end date cannot be before the start date.\", \"Enter a year like 2010.\", \"Enter the salary as a number, e.g. 30024.50.\", \"Use the format 00-0, e.g. 11-1.\", \"Hours must be more than 0.\"",
        "Family: \"List at most N children.\"; Questions: \"You answered YES: give the details.\"; References: \"List three references, or leave all three empty for now.\""],
       "PersonalDataSheet columns and its Pds* child rows.",
       "SavePersonalInfoCommand, SaveFamilyBackgroundCommand, SavePdsListCommand (Pds*Row), SaveOtherInfoCommand, SaveQuestionsCommand, SaveReferencesCommand"),
    UC("STF-03", "Add or edit a service record row", "Principal", "service_record_row_new / _edit (/schools/{id}/service-records/{employee}/rows/...)",
       "Keep an employee's service history.",
       ["Principal of the school; the employee belongs to the school this year (StaffScope)."],
       ["The principal opens the employee's service record and adds a row (drafted from the last) or edits one.",
        "Enters from/to dates, designation, status, annual salary, station, branch, leave without pay, separation, remarks.",
        "The date rules are checked against the person's other rows; the open row is closed when a new one starts."],
       ["\"The end date is before the start date.\"", "\"Give both leave without pay dates, the end not before the start.\"",
        "\"This service overlaps the row from &lt;date&gt;.\"", "\"There is already a row up to the present. Close it first.\"",
        "\"This person does not belong to the school this school year.\""],
       "ServiceRecordEntry rows (updated_by, updated_at).", "SaveServiceRowCommand → ServiceRecordKeeper (rule: ValidServiceRow)"),
    UC("STF-04", "Delete a service record row", "Principal", "service_record_row_delete", "Remove a wrong row.",
       ["As STF-03."], ["The principal confirms Delete."], ["\"No such row in this service record.\""],
       "ServiceRecordEntry removed.", "DeleteServiceRowCommand"),
    UC("STF-05", "Save the GSIS BP number", "Principal", "service_record_bp /schools/{id}/service-records/{employee}/bp",
       "Record the employee's GSIS BP number on the service record; blank clears it.", ["As STF-03."],
       ["The principal types the number and saves."], ["-"], "ServiceRecord.bp_number.", "SaveBpNumberCommand"),
    UC("STF-06", "Download a teacher's PDS", "Principal, Administrator", "school_teacher_pds /schools/{id}/teachers/{teacher}/pds.pdf",
       "Get a teacher's PDS as a PDF.", ["Manages the school; the teacher belongs to it this year."],
       ["From the Teachers page the principal downloads the PDS; the download is logged."],
       ["\"This teacher does not belong to the school this school year.\""], "ActivityLog line only (the PDF is generated).", "DownloadTeacherPdsCommand"),
    UC("STF-07", "Save staff ID details", "Principal, Teacher", "my_id /my-id",
       "Add what the ID needs that the PDS lacks: emergency contact, GSIS policy number, call name.",
       ["A teacher or principal with a school this school year."],
       ["The user fills the fields and saves; the card preview updates."],
       ["\"Only teachers and principals with a school this school year have an ID here.\""],
       "StaffIdDetails (one per account).", "SaveStaffIdDetailsCommand"),
]

VIEWS = [
    View("Profile / PDS tabs", "Principal, Teacher", "profile_* (/profile/...)", "The person's own PDS.", "GetPersonalDataSheetCommand"),
    View("Own PDS PDF", "Principal, Teacher", "profile_pdf /profile/pds.pdf", "CS Form 212 rendered with Dompdf.", "GetPdsPdfCommand"),
    View("Service record page", "Principal", "service_record_show (principal_service_record shortcut)", "PDS header, missing data, BP number and the rows.", "GetServiceRecordCommand, GetServiceRowFormCommand"),
    View("Service record PDF", "Principal, Administrator", "service_record_pdf .../service-record.pdf", "The DepEd service record with the school's prepared/certified by names.", "GetServiceRecordPdfCommand"),
    View("My ID page", "Principal, Teacher", "my_id /my-id", "Card preview, what is missing, whether it can be printed.", "GetMyIdCommand"),
    View("Staff ID PDF", "Principal, Teacher", "my_id_pdf /my-id/{size}.pdf", "The ID on the Big or Small template. Needs a photo and a Present service record row: \"Add your photo and a current (Present) service record row before printing your ID.\"", "GetStaffIdPdfCommand"),
]
