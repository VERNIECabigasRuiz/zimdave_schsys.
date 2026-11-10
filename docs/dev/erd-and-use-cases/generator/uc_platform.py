"""Use cases: Access, Billing, Announcements and Core (the platform)."""
from uc_model import UC, View

TITLE = "Platform: accounts, sign-in, plans and announcements"
FILE = "Use-Cases-01-Platform"
MODULES = "Access, Billing, Announcements, Core"
INTRO = """<p>The platform use cases manage <b>who can sign in</b> (accounts and passwords), <b>what a school may have</b>
(plans) and <b>what the administrator tells everyone</b> (announcements). Every change in the system is written to the
<b>activity log</b> (Core), which the admin dashboard shows as \"Recent activity\".</p>
<p>Accounts have exactly one role. Admins create principal and teacher accounts here (principals and admins can also
create teacher accounts from a school's Teachers page); student accounts are created automatically when a learner is
enrolled (username = LRN). Every new account must set its own password at first sign-in.</p>"""

DIAGRAM = """flowchart LR
    ADM(["Administrator"])
    ANY(["Any signed-in user"])
    STAFF(["Principal / Teacher"])
    ADM --> A1["Create account"] & A2["Edit account / role"] & A3["Deactivate / reactivate account"] & A4["Reset an account's password"]
    ADM --> B1["Create / edit / delete plan"]
    ADM --> N1["Post / edit / delete announcement"]
    ADM --> L1["Search accounts"]
    ANY --> S1["Sign in / sign out"] & S2["Change own password"]
    STAFF --> P1["Change / remove profile photo"]
"""

FLOWS = [("Sign-in and first password", """flowchart TB
    A["User opens /login"] --> B{"Username + password valid?"}
    B -- no --> A
    B -- yes --> C{"Account active?"}
    C -- no --> D["'Your account is disabled. Please contact your administrator.'"]
    C -- yes --> E["Record sign-in: last login time + activity log"]
    E --> F{"Must change password?"}
    F -- yes --> G["/change-password (forced)"] --> H["Home redirect by role"]
    F -- no --> H
    H --> I["/admin, /principal, /teacher or /student"]
""")]

USE_CASES = [
    UC("PLT-01", "Sign in", "Any user", "app_login /login",
       "Get into the system with a username and password.",
       ["The account exists and is active."],
       ["The user enters username and password on the sign-in page.",
        "Symfony Security checks the password hash and the account's active flag (UserChecker).",
        "The system records the sign-in (RecordSignInCommand): sets the last login time and logs \"Signed in\".",
        "If the account must change its password, the user is sent to Change password first.",
        "Otherwise the home page (app_home) redirects to the dashboard of the user's role."],
       ["Wrong username or password: the sign-in page shows an error.",
        "Inactive account: \"Your account is disabled. Please contact your administrator.\"",
        "Forgot password: the page tells the user to ask the administrator to reset it (there is no e-mail reset)."],
       "User.last_login_at updated; ActivityLog row.", "RecordSignInCommand → RecordSignInHandler"),
    UC("PLT-02", "Change own password", "Any signed-in user", "app_change_password /change-password",
       "Set a new personal password (forced at first sign-in or after a reset).",
       ["The user is signed in."],
       ["The user enters the current password and the new one twice.",
        "The form checks the current password.",
        "The system hashes and saves the new password, clears the must-change flag and logs \"Changed password\"."],
       ["The new password equals the current one: \"Your new password must be different from your current one.\""],
       "User.password replaced, must_change_password = false.", "ChangePasswordCommand"),
    UC("PLT-03", "Create an account", "Administrator", "admin_account_new /admin/accounts/new",
       "Give a new person (admin, principal, teacher or student) a sign-in.",
       ["Signed in as administrator."],
       ["The admin fills in role, full name, username and a temporary password.",
        "The system checks the username format and that it is free.",
        "The account is saved with the temporary password hashed and must-change-password set.",
        "The activity log records \"Created account: &lt;username&gt;\"."],
       ["Username taken: \"This username is already taken.\"",
        "Bad characters: \"Use only letters, numbers, dots, dashes and underscores.\""],
       "New User row.", "CreateAccountCommand. Console alternative: app:user:create"),
    UC("PLT-04", "Edit an account (including its role)", "Administrator", "admin_account_edit /admin/accounts/{id}/edit",
       "Correct a person's name or username, change their role, or (de)activate them.",
       ["Signed in as administrator; the account exists."],
       ["The admin opens the account (GetAccountCommand shows the learner it belongs to, if any).",
        "The admin changes the fields and saves.",
        "Before a role change, other modules are asked through hooks whether it is allowed.",
        "The account is saved and \"Updated account\" is logged."],
       ["Username taken.",
        "Role change refused, each reason shown under the role field, e.g. \"This account belongs to learner &lt;LRN&gt;.\" (Learners) or \"Remove &lt;name&gt; as principal of &lt;school&gt; (&lt;year&gt;) before changing the role.\" (Schools).",
        "Reactivation refused by another module (e.g. no teacher seat left on the plan)."],
       "User updated.", "UpdateAccountCommand; hooks in Access/Domain/Service/Account"),
    UC("PLT-05", "Deactivate or reactivate an account", "Administrator", "admin_account_toggle_active /admin/accounts/{id}/toggle-active",
       "Block or restore someone's access without deleting their history.",
       ["Signed in as administrator."],
       ["The admin presses Deactivate (or Activate) on the account list.",
        "The system flips the active flag and logs it."],
       ["Own account: \"You cannot deactivate your own account.\"",
        "Reactivation refused by a module hook (e.g. teacher seat limit)."],
       "User.active toggled. Deactivated accounts can no longer sign in.", "ToggleAccountActiveCommand"),
    UC("PLT-06", "Reset an account's password", "Administrator", "admin_account_reset_password /admin/accounts/{id}/reset-password",
       "Give someone who forgot their password a new temporary one.",
       ["Signed in as administrator; not the admin's own account."],
       ["The admin presses Reset password.",
        "The system generates a temporary password, saves its hash, sets must-change-password and logs it.",
        "The temporary password is shown once on the page (pages are never prefetched, so it is not consumed early)."],
       ["Own account: \"Use \\\"Change password\\\" to change your own password.\""],
       "User.password replaced; must_change_password = true.", "ResetAccountPasswordCommand"),
    UC("PLT-07", "Change or remove the profile photo", "Principal, Teacher", "profile_photo /profile/photo; profile_photo_remove",
       "Show a photo on the account (also used on the staff ID).",
       ["Signed in as principal or teacher."],
       ["The user uploads an image.",
        "The image service re-encodes it to WebP and stores it under public/uploads (replacing the old file).",
        "The path is saved and \"Changed profile photo\" is logged. Removing deletes the file and clears the path."],
       ["Not an image / unreadable: \"The file is not a readable image.\"",
        "Too large: \"The image is too large. Use one under 25 megapixels.\""],
       "User.photo_path set or cleared.", "ChangeProfilePhotoCommand, RemoveProfilePhotoCommand"),
    UC("PLT-08", "Create, edit or delete a plan", "Administrator", "admin_plans_new, admin_plans_edit, admin_plans_delete (/admin/plans/...)",
       "Define what schools can buy: teachers per seat, students per seat, whether a principal is included.",
       ["Signed in as administrator. The Basic and Individual plans are seeded by migrations."],
       ["The admin opens Plans, which lists each plan with how many schools use it.",
        "Create/Edit: enter name, teachers per seat, students per seat and 'includes principal'; save.",
        "Delete: allowed only when no school uses the plan.",
        "Every change is logged."],
       ["Name taken: \"This plan already exists.\"",
        "Delete while used: \"&lt;plan&gt; is used by N school(s) and cannot be deleted.\""],
       "Plan row created, updated or removed.", "CreatePlanCommand, UpdatePlanCommand, DeletePlanCommand"),
    UC("PLT-09", "Post, edit or delete an announcement", "Administrator", "admin_announcement_new / _edit / _delete (/admin/announcements/...)",
       "Publish a notice for everyone, principals, teachers or students.",
       ["Signed in as administrator."],
       ["The admin writes a title, body and audience (Everyone, Principals, Teachers, Students).",
        "The system saves it with the admin as author and the time, and logs it.",
        "Edit changes the text or audience; Delete removes it."],
       ["Announcement not found (deleted meanwhile): \"The announcement was not found.\""],
       "Announcement row created, updated or removed.",
       "PostAnnouncementCommand, UpdateAnnouncementCommand, DeleteAnnouncementCommand. Note: announcements are listed on the admin screens; no other module reads them yet."),
]

VIEWS = [
    View("Account list (search, filter by role and active)", "Administrator", "admin_accounts /admin/accounts, admin_accounts_by_role /admin/{slug}", "Paged accounts with each one's school this year.", "SearchAccountsCommand"),
    View("One account", "Administrator", "admin_account_edit", "The account and, for a student, the learner's LRN.", "GetAccountCommand"),
    View("Plans", "Administrator", "admin_plans /admin/plans", "Every plan with the number of schools on it.", "ListPlansCommand"),
    View("Announcements", "Administrator", "admin_announcements /admin/announcements", "Every announcement, newest first.", "ListAnnouncementsCommand"),
    View("Recent activity", "Administrator", "admin_dashboard /admin", "The newest lines of the activity log.", "GetRecentActivityCommand"),
]
