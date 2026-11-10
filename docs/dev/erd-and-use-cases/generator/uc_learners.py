"""Use cases: Learners."""
from uc_model import UC, View

TITLE = "Learners: enrollment, learner records, transfers, SF1 and learner IDs"
FILE = "Use-Cases-04-Learners"
MODULES = "Learners"
INTRO = """<p>A <b>learner</b> is kept for life and identified by the 12-digit <b>LRN</b>; each school year the learner gets
one <b>enrollment</b> in a section. The school's pages (<code>/schools/{id}/learners</code>, used by the principal and
administrators) and the adviser's class pages (<code>/teacher/class</code>) share the same use cases; on the adviser's
pages the section is always their own.</p>
<p><b>Who may do what</b> (<code>LearnerPolicy</code>): the school's principal may do everything for the school this year;
the section's adviser may enroll into, edit, reset passwords and unenroll in their own section, but may not change an
LRN or move learners; administrators may do anything this school year and transfer learners directly. Nobody may change
anything without a current school year, and past years are read-only.</p>
<p>Every change takes the <code>learner-&lt;id&gt;</code> lock (and <code>learners-school-&lt;id&gt;</code> where seats are counted)
before reading anything. Student counts are capped by the plan; a learner with a pending transfer can't be changed.</p>"""

DIAGRAM = """flowchart LR
    PRN(["Principal"])
    ADV(["Adviser"])
    ADM(["Administrator"])
    PRN & ADV --> E1["Enroll a learner by LRN"]
    PRN & ADV --> E2["Edit learner details"] & E3["Update SF1 record (modality, remarks)"] & E4["Set / remove ID photo"] & E5["Reset learner's password"] & E6["Unenroll"]
    PRN --> M1["Move to another section"] & C1["Change LRN"]
    PRN --> T1["Request a transfer in"] & T2["Cancel own request"]
    PRN --> T3["Approve / decline a transfer out"]
    ADV -. "Individual plan only" .-> T3
    ADM --> M2["Transfer to another school at once"]
    ADM -. "anything this year" .-> E1 & M1
"""

FLOWS = [("Enrolling by LRN", """flowchart TB
    A["Enter the LRN (12 digits)"] --> B{"Who owns this LRN?"}
    B -- "nobody" --> N["New learner form<br/>details + section"] --> N2["Learner + student account (username = LRN, temporary password shown once) + Enrollment"]
    B -- "learner not enrolled this year" --> X["Pick a section"] --> X2["Enrollment for the current year"]
    B -- "already enrolled here" --> H["Link to the learner's page"]
    B -- "enrolled at another school" --> O{"Principal?"}
    O -- yes --> R["Request a transfer (see next flow)"]
    O -- "adviser / admin" --> O2["Shown where the learner is; admin can transfer directly"]
    N2 & X2 --> L{"Plan allows another student<br/>and the section isn't full?"}
    L -- no --> P["'The &lt;plan&gt; plan allows N student(s) (N used). Contact the administrator to upgrade.'"]
"""), ("Transfer between schools", """sequenceDiagram
    autonumber
    participant R as Receiving principal
    participant S as System
    participant L as Leaving school (principal, or adviser on Individual plan)
    R->>S: Request transfer (LRN, target section)
    S->>S: checks: enrolled elsewhere this year, no pending request, seats at receiver
    S-->>L: request appears under Transfers - Outgoing
    alt approve
        L->>S: Approve
        S->>S: lock learner + receiving school, re-check seats
        S->>S: Enrollment moves to the new section and school
        S-->>R: SF1 remark becomes "T/I <school> <date>"
    else decline
        L->>S: Decline (nothing moves)
    else cancel
        R->>S: Cancel own pending request
    end
""")]

USE_CASES = [
    UC("LRN-01", "Enroll a learner", "Principal, Adviser, Administrator", "school_learners_enroll /schools/{id}/learners/enroll; teacher_class_enroll /teacher/class/enroll",
       "Put a new or returning learner into a section for the current school year.",
       ["May enroll into the section (principal of the school, adviser of the section, or admin); current school year."],
       ["The user types the LRN; the page looks it up (new / not enrolled / enrolled here / enrolled elsewhere).",
        "New learner: fill in the learner details (names, sex, birth date, address, parents, guardian, contact) and choose the section.",
        "Returning learner: choose the section only.",
        "The system takes the learner and school locks, checks the plan's student limit and section capacity, and saves.",
        "A new learner gets a student account (username = LRN) with a temporary password that is shown once."],
       ["\"The LRN must be exactly 12 digits.\" / \"LRN &lt;lrn&gt; belongs to &lt;name&gt;.\"",
        "\"Birthdate must be before today.\"",
        "\"The username &lt;lrn&gt; is already taken by another account. Ask the administrator.\"",
        "\"Choose a section of the current school year.\"",
        "Plan limit: \"The &lt;plan&gt; plan allows N student(s) (N used). Contact the administrator to upgrade.\"",
        "Busy: \"Another change is being saved. Please try again.\""],
       "Learner + User (student) for a new learner; Enrollment; activity log \"Enrolled &lt;LRN&gt; in &lt;section&gt;\".",
       "EnrollLearnerCommand (LearnerDetails) → LearnerEnrollment::enrollNew / enrollExisting"),
    UC("LRN-02", "Edit learner details (and the LRN)", "Principal, Adviser, Administrator", "school_learners_edit; teacher_class_edit",
       "Correct the learner's personal data.",
       ["The learner is enrolled in a section the user may edit; no pending transfer."],
       ["The user edits the details and saves.", "Only principals and admins see the LRN field; the student's username follows a changed LRN."],
       ["LRN format / LRN taken.", "Username taken by another account.", "Pending transfer or plan blocked."],
       "Learner (and User.username when the LRN changes).", "UpdateLearnerCommand"),
    UC("LRN-03", "Update this year's SF1 record", "Principal, Adviser, Administrator", "school_learners_enrollment; teacher_class_enrollment",
       "Record the learning modality and the SF1 remark (e.g. dropped, late enrollment, CCT, Balik-Aral, SNED, accelerated).",
       ["The learner is enrolled this year and editable."],
       ["The user picks the modality and, if needed, a remark code with details and date.", "Saved on the enrollment."],
       ["Details without a code: \"Choose a remark for these details.\""],
       "Enrollment.learning_modality, remark_code, remark_details, remark_date.", "UpdateEnrollmentRecordCommand"),
    UC("LRN-04", "Set or remove the learner's ID photo", "Principal, Adviser, Administrator", "school_learners_photo / _photo_remove; teacher_class_photo / _photo_remove",
       "Add the photo that prints on the learner ID card.",
       ["The learner is editable."],
       ["The user uploads an image; it is stored as WebP (or removed)."],
       ["Image not readable or too large."], "Learner's account photo.", "UpdateLearnerPhotoCommand"),
    UC("LRN-05", "Reset a learner's password", "Principal, Adviser, Administrator", "school_learners_reset_password; teacher_class_reset_password",
       "Help a student who forgot their password.",
       ["The learner is editable."],
       ["The user presses Reset password; a temporary password is shown once."],
       ["-"], "User.password, must_change_password.", "ResetLearnerPasswordCommand"),
    UC("LRN-06", "Unenroll a learner", "Principal, Adviser, Administrator", "school_learners_unenroll; teacher_class_unenroll",
       "Take a learner off the class list (e.g. enrolled by mistake).",
       ["The learner is enrolled this year; no pending transfer."],
       ["The user confirms Unenroll.", "The enrollment of the current year is removed; the learner, the account and past years stay."],
       ["Pending transfer / plan blocked."], "Enrollment removed.", "UnenrollLearnerCommand"),
    UC("LRN-07", "Move a learner to another section of the same school", "Principal, Administrator", "school_learners_move /schools/{id}/learners/{learner}/move",
       "Change a learner's section within the school.",
       ["Principal of the school or admin; target section in the same school and year."],
       ["The principal picks the new section.", "The system checks capacity and moves the enrollment."],
       ["\"Choose a section of the same school.\"", "Section full / plan limit."],
       "Enrollment.section_id.", "MoveLearnerCommand"),
    UC("LRN-08", "Transfer a learner to another school directly", "Administrator", "school_learners_transfer /schools/{id}/learners/{learner}/transfer",
       "Move a learner to a section of another school at once (no request needed).",
       ["Administrator; the target section is at another school this year."],
       ["The admin picks the school and the section on the learner's page.",
        "The system takes the learner and receiving school locks, checks the receiving plan and moves the enrollment."],
       ["\"This section is not at another school this school year.\"", "\"Choose a section of another school.\"", "Receiving school's plan limit."],
       "Enrollment.section_id and school_id.", "TransferLearnerCommand"),
    UC("LRN-09", "Request a transfer in", "Principal (receiving school)", "school_transfers_request /schools/{id}/transfers/request",
       "Ask the learner's current school to let them move into one of your sections.",
       ["Principal (not admin) of the receiving school; the LRN belongs to a learner enrolled at another school this year; no pending request."],
       ["On the enroll page the LRN shows 'enrolled at &lt;school&gt;'; the principal chooses a section and sends the request.",
        "The system re-reads the enrollment under the learner lock and checks the receiving school's seats."],
       ["\"This learner is no longer enrolled at another school.\" (changed meanwhile)",
        "\"Choose a section of another school.\"", "Plan limit at the receiving school.", "Already a pending transfer."],
       "TransferRequest (status pending).", "RequestTransferCommand → TransferRequests::request"),
    UC("LRN-10", "Approve or decline a transfer out", "Principal (school being left); adviser on the Individual plan; Administrator", "school_transfers_approve / _decline (/schools/{id}/transfers/{transfer}/...)",
       "Decide whether a learner may leave for the requesting school.",
       ["The request is pending and from this school's section this year."],
       ["The user opens Transfers - Outgoing and presses Approve or Decline.",
        "Approve: the system locks the learner and the receiving school, re-checks the receiver's seats, moves the enrollment to the requested section and marks the request approved.",
        "Decline: the request is marked declined; nothing moves."],
       ["\"This request was already decided.\"", "\"This learner is no longer enrolled.\"", "Receiving plan limit at approval time."],
       "TransferRequest.status, decided_by, decided_at; on approval Enrollment moves (SF1 remark T/I at the new school).",
       "ApproveTransferCommand, DeclineTransferCommand"),
    UC("LRN-11", "Cancel a transfer request", "Principal (receiving school)", "school_transfers_cancel /schools/{id}/transfers/{transfer}/cancel",
       "Withdraw a request the school sent.",
       ["The request is pending and was sent to this school."],
       ["The principal presses Cancel under Transfers - Incoming."],
       ["\"This request was already decided.\""], "TransferRequest.status = cancelled.", "CancelTransferCommand"),
]

VIEWS = [
    View("School's learners", "Principal, Administrator", "school_learners /schools/{id}/learners (principal_learners shortcut)", "Paged learners this year, search by LRN or name, filter by section.", "ListLearnersCommand"),
    View("Class list", "Principal, Adviser", "school_learners_class; teacher_class /teacher/class", "A section's learners, boys first.", "GetClassListCommand, GetAdvisoryCommand"),
    View("Enroll page", "Principal, Adviser", "school_learners_enroll; teacher_class_enroll", "Lookup result for an LRN and the sections to choose.", "GetEnrollPageCommand"),
    View("Learner page", "Principal, Adviser, Administrator", "school_learners_show; teacher_class_show", "Details, this year, history, transfers, and what the user may do.", "GetLearnerCommand, GetLearnerFormCommand, GetEnrollmentRecordCommand"),
    View("Transfers (incoming / outgoing)", "Principal, Adviser", "school_transfers /schools/{id}/transfers", "Requests this year; an adviser sees only their section's outgoing ones.", "ListTransfersCommand, GetTransferCommand, GetTransferRequestFormCommand"),
    View("SF1 School Register (.xls)", "Principal, Adviser", "school_learners_sf1; teacher_class_sf1", "The section's SF1 filled from the enrollments (PhpSpreadsheet).", "GetSf1FileCommand"),
    View("Learner ID cards (PDF)", "Principal, Adviser", "school_learners_ids / _id; teacher_class_ids / _id", "One card or a section's cards with photo and QR code.", "GetSectionIdCardsCommand, GetLearnerIdCardCommand"),
    View("Admin learner search", "Administrator", "admin_learners /admin/learners", "Learners across schools by LRN or name, with their current or last enrollment.", "ListAdminLearnersCommand"),
]
