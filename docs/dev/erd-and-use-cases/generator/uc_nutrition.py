"""Use cases: Nutrition."""
from uc_model import UC, View

TITLE = "Nutrition: baseline and endline weighings and the SF8"
FILE = "Use-Cases-07-Nutrition"
MODULES = "Nutrition"
INTRO = """<p>Twice a school year (<b>baseline</b> and <b>endline</b>) the adviser weighs the class: the date of weighing
plus each learner's weight (kg) and height (m), or a remark such as \"absent\". From these the SF8 report (School Form 8,
Learner's Basic Health and Nutrition Report) computes each learner's BMI and classifies <b>BMI-for-age</b> and
<b>height-for-age</b> with the WHO growth tables (<code>resources/who</code>); the classification is computed when the
report is built, not stored.</p>
<p>Submitting the SF8 hands it to the principal and locks the period; the principal or an administrator can reopen it.
Who counts is the section's roster on the date of weighing. Every change takes the <code>nutrition-section-&lt;id&gt;</code>
lock. Who may do what is <code>NutritionPolicy</code>: only the adviser fills it (current year only); the principal and
administrators view it and reopen it. The SF8 prints the school's SF8 certifier from the school profile.</p>"""

DIAGRAM = """flowchart LR
    ADV(["Adviser"])
    PRN(["Principal / Administrator"])
    ADV --> N1["Save the weighing (date + measurements)"] & N2["Submit the SF8"]
    PRN --> N3["Reopen a submitted SF8"]
    ADV & PRN --> V1["View the weighing sheet, download SF8"]
    PRN --> V2["School nutrition overview, compare baseline and endline"]
"""

FLOWS = [("A weighing period", """flowchart TB
    A["Adviser sets the date of weighing<br/>(inside the school year; baseline before endline)"] --> B["Enters weight + height per learner<br/>(or a remark)"]
    B --> C["SF8 figures computed: BMI, BMI-for-age, height-for-age (WHO)"]
    C --> D{"Everyone weighed?"}
    D -- "no" --> E["Confirm that the rest stay unweighed"]
    D -- "yes" --> F["Submit SF8: period locked"]
    E --> F
    F --> G["Principal sees it in the overview;<br/>may reopen for corrections"]
""")]

USE_CASES = [
    UC("NUT-01", "Save the weighing", "Adviser", "teacher_class_nutrition_period /teacher/class/nutrition/{period}",
       "Record the date of weighing and each learner's weight and height for the baseline or endline.",
       ["The teacher advises a section this school year; the period is not submitted."],
       ["The adviser opens the period, sets the date of weighing and fills the measurements (remarks may stand alone).",
        "Saves. The system takes the section lock, checks the date and stores the assessment and its measurements.",
        "Learners who don't count on the date of weighing are ignored."],
       ["\"Weigh inside the school year (&lt;range&gt;).\"",
        "\"The endline must be after the baseline (&lt;date&gt;).\" / \"The baseline must be before the endline (&lt;date&gt;).\"",
        "\"&lt;period&gt; is submitted and locked.\"", "\"You can no longer weigh this section.\"",
        "Busy: \"Another change is being saved. Please try again.\""],
       "NutritionAssessment (section, period, weighed_on) and NutritionMeasurement rows.", "SaveWeighingCommand (MeasurementInput) → NutritionRecorder::save"),
    UC("NUT-02", "Submit the SF8", "Adviser", "teacher_class_nutrition_submit /teacher/class/nutrition/{period}/submit",
       "Hand the period's SF8 to the principal.",
       ["The weighing is saved; not yet submitted."],
       ["The adviser presses Submit; if some learners have no weight and height, they confirm.",
        "The period is locked and \"Submitted SF8 of ...\" is logged."],
       ["\"Set the date of weighing and save first.\"", "\"&lt;period&gt; is already submitted.\"",
        "\"N learners have no weight and height. Confirm to submit anyway.\""],
       "Sf8Submission (section, period).", "SubmitSf8Command"),
    UC("NUT-03", "Reopen a submitted SF8", "Principal, Administrator", "school_nutrition_reopen /schools/{id}/sections/{section}/nutrition/{period}/reopen",
       "Let the adviser correct a submitted period.", ["Manages the school; the period is submitted."],
       ["The principal presses Reopen on the section's sheet; \"Reopened SF8 ...\" is logged."],
       ["\"&lt;period&gt; is not submitted.\""], "Sf8Submission removed.", "ReopenSf8Command"),
]

VIEWS = [
    View("Nutrition index", "Adviser", "teacher_class_nutrition /teacher/class/nutrition", "The section with both periods' state and summary.", "GetNutritionIndexCommand"),
    View("Weighing sheet", "Adviser, Principal", "teacher_class_nutrition_period; school_nutrition_section", "A period's measurements and classifications, and what the user may do.", "GetNutritionPeriodCommand"),
    View("School nutrition overview", "Principal, Administrator", "school_nutrition /schools/{id}/nutrition (principal_nutrition)", "Every section's SF8 for a period, or baseline vs endline side by side.", "GetNutritionOverviewCommand"),
    View("SF8 file (.xlsx)", "Adviser, Principal", "teacher_class_sf8 and the school's SF8 download", "DepEd SF8 filled into sf8-template.xlsx.", "GetSf8FileCommand"),
]
