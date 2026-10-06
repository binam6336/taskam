# AGENT.md — Task Manager Project

## 1. Project Identity

This repository is a small Persian (RTL) **Task Manager web application** written with:

- PHP (raw PHP, no framework)
- MySQL
- PDO
- HTML/CSS
- Vanilla JavaScript
- PHP Sessions
- Fetch/AJAX for some task/note operations
- Apache `.htaccess` rules

The application is intended to let users:

1. Register and log in with a mobile number and password.
2. Create, edit, delete and complete tasks.
3. Assign tasks to subjects/categories.
4. Set task priority and due date.
5. Add progress reports/notes to individual tasks.
6. View task statistics.
7. Manage their profile and password.
8. Allow administrators to approve/deactivate/delete users.

The current implementation is deliberately simple and procedural. It is **not a full MVC framework**, even though it contains a few classes under `core/`.

---

## 2. Critical Agent Rules

When modifying this project, follow these rules unless the user explicitly asks otherwise.

### General

- Do not introduce Laravel, Symfony, WordPress, React, Vue, Node.js, or another framework unless explicitly requested.
- Prefer raw PHP + PDO + HTML/CSS + Vanilla JS.
- Keep the existing Persian RTL UI language.
- Keep UTF-8/UTF-8MB4 support intact.
- Do not expose database credentials in source code, documentation, logs, API responses, or frontend code.
- Use prepared statements for user-controlled SQL values.
- Preserve ownership checks: a normal user must only be able to access/modify their own tasks, subjects, and notes.
- Do not allow normal users to access admin functionality.
- Escape database/user-generated content when rendering HTML.
- Be careful when embedding PHP data inside JavaScript. Prefer safe JSON encoding rather than manually concatenating strings.
- Do not silently change database structure without documenting the migration.
- Avoid creating duplicate functionality in a new file when an existing endpoint/page already handles it.

### Security

The current code is functional but has security weaknesses. When touching related code, improve security rather than copying the weakness.

Important security requirements:

- Use `password_hash()` and `password_verify()` for passwords.
- Use session-based authentication.
- Regenerate the session ID after successful login.
- Add CSRF protection to state-changing POST requests when practical.
- Do not perform destructive actions through GET requests in new code.
- Validate IDs as integers and validate enum-like values such as `priority` and `status`.
- Always enforce ownership in SQL (`WHERE user_id = ?`) for user-owned records.
- Admin operations must verify the authenticated user's role server-side.
- Do not trust hidden form fields for authorization.
- Do not return sensitive user fields through public APIs.
- Do not reveal raw PDO/database errors to end users in production.

---

# 3. Current Repository Structure

```text
admin/
└── index.php
    Admin panel / user management

auth/
└── login.php
    Login + registration page and handlers

api/
└── tasks.php
    JSON API for authenticated task listing and task completion toggle

core/
├── Router.php
│   Placeholder/minimal router class file; currently not used as the main routing mechanism.
├── Auth.php
│   Authentication helper/class
└── Debug.php
    Placeholder/minimal debug core

database/
└── Database.php
    PDO singleton database connection

config/
├── config.php
│   Application constants, BASE_URL, debug mode, session initialization
└── database.php
    Database connection configuration

profile/
└── index.php
    User profile, email update, password change and task statistics

tasks/
└── index.php
    Main user task manager page, task CRUD, subjects, notes/reports,
    AJAX handlers and dashboard/statistics

storage/
└── logs/
    Intended location for application logs; currently no substantial logging layer is implemented.

assets/
├── css/
└── js/
    Asset directories currently exist but most UI styling/JS is embedded directly
    inside PHP pages.

index.php
    Main application entry point and role-based redirect

setup.php
    One-off/helper page related to UTF-8MB4 and note AJAX troubleshooting

.htaccess
    Apache rewrite configuration
```

---

# 4. Application Flow

## 4.1 Main entry point

`/index.php`

Flow:

```text
Request
  ↓
Load config
  ↓
Load Auth
  ↓
Check session
  ↓
Not authenticated → /auth/login.php
  ↓
Authenticated
  ↓
role == admin → /admin/index.php
role != admin → /tasks/index.php
```

The main index is therefore primarily a redirect/entry controller rather than a rendered page.

---

# 5. Authentication

## File

`auth/login.php`

This page handles both:

- Login
- Registration

### Login form

Fields:

- `mobile`
- `password`

POST action:

```text
action_login
```

It calls:

```php
Auth::login($mobile, $password)
```

Successful login redirects:

```text
admin → ../admin/index.php
user  → ../tasks/index.php
```

### Registration form

Fields:

- `mobile`
- `email` (optional)
- `password`

POST action:

```text
action_register
```

It calls:

```php
Auth::register($mobile, $password, $email)
```

New users are created as:

```text
status = pending
role   = user
```

### Important current behavior

The current `Auth::login()` implementation blocks only:

```text
deactivated
```

It does **not** currently block `pending` users.

This is potentially inconsistent with the registration message, which says the account becomes active after administrator approval.

If changing this behavior, explicitly decide whether:

```text
pending → cannot log in
active  → can log in
deactivated → cannot log in
```

is the intended rule.

---

# 6. Authentication Core

## File

`core/Auth.php`

Class:

```php
class Auth
```

Methods:

### `login($mobile, $password)`

- Finds user by mobile.
- Verifies password with `password_verify()`.
- Blocks deactivated accounts.
- Stores these session values:

```text
$_SESSION['user_id']
$_SESSION['user_mobile']
$_SESSION['user_role']
$_SESSION['user_status']
```

Returns an array containing:

```text
success
role
status
message (on failure)
```

### `register($mobile, $password, $email = null)`

- Checks duplicate mobile.
- Hashes password using `password_hash()`.
- Inserts user as:
  - `status = pending`
  - `role = user`

### `check()`

Returns whether:

```php
$_SESSION['user_id']
```

exists.

### `logout()`

Calls:

```php
session_unset();
session_destroy();
```

---

# 7. Database Layer

## File

`database/Database.php`

Class:

```php
class Database
```

It implements a simple PDO singleton:

```php
Database::getInstance()
```

Connection configuration is loaded from:

```text
config/database.php
```

DSN uses:

```text
mysql
utf8mb4
```

PDO options currently include:

- `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
- `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`
- `PDO::ATTR_EMULATE_PREPARES => false`

### Encoding requirement

The project contains Persian text, therefore:

```text
utf8mb4
```

must remain enabled at the database connection level.

The task page additionally executes:

```sql
SET NAMES 'utf8mb4'
SET CHARACTER SET utf8mb4
SET character_set_connection = utf8mb4
```

This is defensive/redundant. The preferred long-term solution is to make the PDO connection configuration correct once rather than repeatedly fixing encoding inside individual pages.

---

# 8. Configuration

## `config/config.php`

Responsible for:

- `BASE_URL`
- debug mode
- PHP session startup
- session cookie settings

Current base URL is configured for:

```text
/tapin/task
```

Agents must not hard-code this path throughout the application. Use `BASE_URL` where appropriate.

### Debug mode

The current configuration enables:

```text
DEBUG_MODE = true
```

This is acceptable during development but should be disabled in production.

---

## `config/database.php`

Contains the database connection settings.

This file currently contains credentials in the repository.

### Agent rule

Never copy the actual database password into new documentation, examples, logs, commits, responses, or generated code.

For a production-quality revision, move secrets to environment variables or a server-side secret configuration outside version control.

---

# 9. User Data Model

The code indicates a `users` table with at least these fields:

```text
id
mobile
password
email
status
role
created_at
```

Known role values:

```text
admin
user
```

Known status values:

```text
pending
active
deactivated
```

Passwords are stored as password hashes, not plaintext.

---

# 10. Task Data Model

The code expects a `tasks` table with at least:

```text
id
user_id
subject_id
title
description
priority
due_date
is_completed
created_at
```

Known priority values:

```text
low
medium
high
```

`is_completed` is treated as a boolean/integer flag:

```text
0 = not completed
1 = completed
```

Ownership is determined by:

```text
tasks.user_id
```

---

# 11. Subject Data Model

The code expects a `subjects` table with at least:

```text
id
user_id
title
```

Subjects belong to individual users.

A user should not be able to assign a task to another user's subject.

---

# 12. Task Notes Data Model

The application creates the `task_notes` table from `tasks/index.php` if it does not exist.

Current structure:

```sql
CREATE TABLE task_notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4;
```

The code attempts to use:

```text
utf8mb4_persian_ci
```

and falls back to:

```text
utf8mb4
```

if that collation is unavailable.

### Ownership

Notes are scoped by:

```text
task_id
user_id
```

When reading/deleting notes, the current implementation checks the authenticated user's `user_id`.

---

# 13. Main Task Manager Page

## File

`tasks/index.php`

This is currently the largest and most important file.

It acts as both:

1. Page/controller
2. HTML view
3. POST handler
4. GET/AJAX handler
5. Database query layer
6. JavaScript host

This is a known architectural limitation.

---

# 14. Task Manager Pages / Views

The task page uses a query parameter:

```text
?page=...
```

Known page states include:

### Dashboard

```text
tasks/index.php?page=dashboard
```

Displays task analytics.

Known metrics:

- Total tasks
- Completed tasks
- Pending tasks
- Progress rate
- High priority tasks
- Medium priority tasks
- Low priority tasks
- Total subjects
- Tasks created today
- Most-used/popular subject

### Task list

The default task page behaves as the task list.

It has two client-side tabs:

```text
انجام نشده
انجام شده
```

The tabs are not separate server routes; JavaScript switches visibility.

### Subjects

```text
tasks/index.php?page=subjects
```

Provides:

- Add subject
- List user's subjects

### Task creation

A modal/popup is used.

Fields:

```text
title
subject_id
priority
due_date
description
```

### Task editing

A modal/popup is used.

Fields are the same as task creation.

### Task reports/notes

Each task can open a modal showing:

- Existing reports
- Report creation form
- Delete report action

---

# 15. Task CRUD

## Create

POST:

```text
add_task
```

Inserts:

```text
user_id
subject_id
title
description
priority
due_date
```

New tasks are initially not completed according to the database default/expected schema.

---

## Update

POST:

```text
update_task
```

Requires:

```text
task_id
```

Update query is ownership-aware:

```sql
UPDATE tasks
SET subject_id = ?, title = ?, description = ?, priority = ?, due_date = ?
WHERE id = ? AND user_id = ?
```

This ownership check must remain.

---

## Delete

Current implementation uses a GET-style action:

```text
index.php?delete={task_id}
```

It deletes the task only when:

```text
id = task_id
AND user_id = current_user
```

It also removes associated task notes.

### Future improvement

Move deletion to POST/AJAX with CSRF protection.

---

## Toggle completion

There are two mechanisms:

### Browser-side task page

JavaScript calls:

```text
index.php?toggle={task_id}
```

The PHP handler updates:

```text
is_completed
```

only for the current user.

### JSON API

`api/tasks.php` supports:

```text
POST
action = toggle
task_id
```

and performs:

```sql
UPDATE tasks
SET is_completed = NOT is_completed
WHERE id = ? AND user_id = ?
```

The two mechanisms overlap.

Do not create a third toggle implementation. Prefer consolidating these in a future refactor.

---

# 16. Task Notes / Reports

The task page supports three operations.

## Add note

POST to:

```text
tasks/index.php
```

with:

```text
add_task_note
note_task_id
note_text
```

AJAX requests receive JSON.

---

## Load notes

GET:

```text
tasks/index.php?get_notes=1&task_id={id}
```

Returns JSON similar to:

```json
{
  "status": "success",
  "notes": []
}
```

Only notes belonging to the current user are returned.

---

## Delete note

GET:

```text
tasks/index.php?delete_note=1&note_id={id}
```

The SQL checks:

```text
note.id
AND note.user_id
```

### Important

Destructive GET requests are a technical debt. New implementation should use POST/DELETE semantics with CSRF protection.

---

# 17. Frontend JavaScript

JavaScript is currently embedded in:

```text
tasks/index.php
```

Important functions:

### `switchTab(tabName, btn)`

Switches between:

```text
uncompleted
completed
```

tabs.

### `toggleTask(id)`

Sends an AJAX request to toggle completion and reloads the page.

### `openModal(id)`

Opens a modal.

### `closeModal(id)`

Closes a modal.

### `openEditModal(task)`

Populates the edit form with task data.

### `openNotesModal(taskId, taskTitle)`

Opens notes modal and loads reports.

### `loadNotes(taskId)`

Fetches notes via AJAX.

### `submitTaskNote(e)`

Submits a note through `fetch()` + `FormData`.

### `deleteNote(noteId, taskId)`

Deletes a note and refreshes the note list.

### `escapeHtml(text)`

Escapes HTML before rendering note content into `innerHTML`.

---

# 18. Admin Panel

## File

`admin/index.php`

Access requires:

```text
Auth::check() === true
AND
$_SESSION['user_role'] === 'admin'
```

The admin page currently manages users.

### User list

Displays:

- ID
- Mobile
- Email
- Role
- Status
- Registration date
- Actions

### Change status

POST:

```text
action_status
user_id
```

Allowed statuses:

```text
active
pending
deactivated
```

The SQL prevents modifying another admin:

```text
role != 'admin'
```

### Delete user

POST:

```text
action_delete
user_id
```

Again, admins cannot be deleted through this interface.

### Admin UI

The current admin interface is a simple table with:

- Gold primary color
- White cards
- Light beige background
- RTL Persian layout
- Status badges
- Action buttons

---

# 19. Profile Page

## File

`profile/index.php`

Requires authentication.

### Features

#### Update profile

POST:

```text
update_profile
email
```

Updates current user's email.

#### Change password

POST:

```text
change_password
current_password
new_password
```

The current password is verified using:

```php
password_verify()
```

Then the new password is hashed using:

```php
password_hash()
```

#### Statistics

The profile page shows:

- Total tasks
- Completed tasks
- Pending tasks

Statistics are limited to the current user.

---

# 20. API

## File

`api/tasks.php`

Returns:

```text
Content-Type: application/json; charset=utf-8
```

Authentication is mandatory.

Unauthenticated request:

```text
HTTP 401
```

### GET

Returns the authenticated user's tasks:

```sql
SELECT t.*, s.title AS subject_title
FROM tasks t
LEFT JOIN subjects s ON t.subject_id = s.id
WHERE t.user_id = ?
ORDER BY t.created_at DESC
```

Response shape:

```json
{
  "success": true,
  "data": []
}
```

### POST

Currently supports:

```text
action = toggle
task_id
```

Returns a success JSON response.

### Unsupported methods/actions

Return:

```text
HTTP 400
```

---

# 21. Router

## File

`core/Router.php`

Currently this file is essentially a placeholder and is not the main routing system.

The project currently relies mostly on:

- physical PHP files
- query parameters
- Apache `.htaccess`
- direct redirects

Do not assume there is an MVC router just because this file exists.

If introducing proper routing later, do it deliberately and update this document.

---

# 22. Apache Rewrite

## `.htaccess`

Current rule:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?route=$1 [QSA,L]
```

Meaning:

- Existing files/directories are served normally.
- Unknown paths are sent to `index.php` with a `route` query parameter.

However, the current `index.php` does not implement a complete route dispatcher.

Therefore, do not assume arbitrary pretty URLs are currently functional.

---

# 23. Setup / Encoding Troubleshooting

## File

`setup.php`

This is a troubleshooting/helper page created around UTF-8MB4 and AJAX note handling.

Its purpose is mainly related to fixing Persian text becoming:

```text
???
```

or otherwise corrupted.

It recommends:

```text
utf8mb4
```

at the PDO connection and table level.

It also mentions converting `task_notes`:

```sql
ALTER TABLE task_notes
CONVERT TO CHARACTER SET utf8mb4
COLLATE utf8mb4_persian_ci;
```

### Important

`setup.php` is not part of the normal application workflow.

It should not become a permanent public production endpoint. If it is no longer needed, remove it or restrict access.

---

# 24. UI / Design Language

The current UI uses:

```text
language: Persian
direction: RTL
font: Tahoma / system-compatible
```

General design:

- Warm beige/light background
- White cards
- Gold primary accent
- Dark gold headings
- Rounded cards/buttons
- Light borders
- Subtle shadows
- Status badges
- Modal dialogs
- Simple dashboard cards
- Persian labels

Primary CSS variables commonly used:

```text
--primary: #c9a84c
--primary-dark: #b8923a
--bg: #f8f6f0
--card: #ffffff
--text: #2c2c2c
--border: #e8e0d4
```

Other semantic colors include green for success, yellow/orange for warnings and red for danger.

When adding new UI, keep the same visual language unless the user asks for a redesign.

---

# 25. Architecture — Current State

Current architecture is approximately:

```text
Browser
   │
   ├── /index.php
   │      └── redirects based on authentication/role
   │
   ├── /auth/login.php
   │      └── Auth
   │            └── Database
   │
   ├── /tasks/index.php
   │      ├── Authentication
   │      ├── POST handlers
   │      ├── GET/AJAX handlers
   │      ├── SQL queries
   │      ├── HTML view
   │      └── JavaScript
   │
   ├── /profile/index.php
   │      ├── Authentication
   │      ├── SQL
   │      └── HTML
   │
   ├── /admin/index.php
   │      ├── Admin authorization
   │      ├── SQL
   │      └── HTML
   │
   └── /api/tasks.php
          ├── Authentication
          ├── JSON responses
          └── SQL

All database access
        ↓
Database::getInstance()
        ↓
PDO
        ↓
MySQL
```

---

# 26. Architectural Technical Debt

The project works, but several parts should eventually be refactored.

## High priority

1. `tasks/index.php` is too large and has too many responsibilities.
2. SQL queries are mixed directly into presentation code.
3. Authentication and authorization are scattered.
4. GET is used for destructive actions.
5. CSRF protection is missing.
6. Session ID regeneration after login is missing.
7. Database credentials are stored directly in configuration.
8. Debug mode should not be enabled in production.
9. `api/tasks.php` and `tasks/index.php` duplicate task-toggle logic.
10. Assets are mostly embedded inside PHP rather than separated into CSS/JS files.
11. `Router.php` is not actually used as a proper application router.
12. The application creates `task_notes` at runtime from a page request; schema management should eventually use migrations/setup SQL instead.
13. User input validation is fairly minimal.
14. Some JavaScript/PHP interpolation patterns could be made safer.

---

# 27. Preferred Future Architecture

If the user asks to improve architecture without changing the technology stack, gradually move toward:

```text
/
├── app/
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Repositories/
│   └── Middleware/
│
├── core/
│   ├── Router.php
│   ├── Auth.php
│   ├── Database.php
│   └── ...
│
├── config/
│
├── database/
│   └── migrations/
│
├── public/
│   ├── index.php
│   ├── assets/
│   └── ...
│
├── views/
│   ├── auth/
│   ├── tasks/
│   ├── profile/
│   └── admin/
│
└── storage/
    └── logs/
```

But do **not** perform a large architecture migration automatically when the user only asks for a small bug fix.

---

# 28. Recommended Controller Responsibilities

If controllers are introduced, use responsibilities similar to:

```text
AuthController
├── login()
├── register()
└── logout()

TaskController
├── index()
├── create()
├── update()
├── delete()
└── toggle()

SubjectController
├── index()
└── create()

TaskNoteController
├── index()
├── store()
└── delete()

ProfileController
├── index()
├── update()
└── changePassword()

AdminUserController
├── index()
├── updateStatus()
└── delete()
```

Controllers should not contain large blocks of HTML or complex SQL.

---

# 29. Recommended Model/Repository Responsibilities

Potential future classes:

```text
User
Task
Subject
TaskNote
```

Potential repositories:

```text
UserRepository
TaskRepository
SubjectRepository
TaskNoteRepository
```

Examples:

```php
TaskRepository::findByUser($userId)
TaskRepository::create(...)
TaskRepository::updateOwnedTask(...)
TaskRepository::deleteOwnedTask(...)
TaskRepository::toggleOwnedTask(...)
```

The word **Owned** is intentional: authorization/ownership must remain part of the data-access operation.

---

# 30. Data Ownership Rules

These are critical business rules.

## User

A normal user can:

- View their own profile.
- Update their own email.
- Change their own password.
- View their own tasks.
- Create their own tasks.
- Edit their own tasks.
- Delete their own tasks.
- Complete/uncomplete their own tasks.
- Manage their own subjects.
- View/add/delete their own task notes.

A normal user must never be able to operate on another user's records by changing an ID in the request.

## Admin

An admin can:

- View users.
- Approve/activate users.
- Deactivate users.
- Delete non-admin users.

The current admin panel does not provide task management across all users.

Do not assume admin has unrestricted CRUD over every table unless explicitly implemented.

---

# 31. Validation Rules

When adding validation, use server-side validation even if HTML uses `required`.

### Mobile

- Trim whitespace.
- Validate expected mobile format according to project requirements.
- Do not rely only on frontend validation.

### Email

- Optional.
- If supplied, validate as an email.

### Password

- Never store plaintext.
- Use `password_hash()`.
- Verify using `password_verify()`.

### Task title

- Required.
- Trim whitespace.
- Reject empty values.

### Description

- Optional.
- Treat as plain text unless rich text is deliberately introduced.

### Priority

Only:

```text
low
medium
high
```

### Status

Only:

```text
pending
active
deactivated
```

---

# 32. Encoding Rules

This project contains Persian text and must consistently use:

```text
UTF-8
utf8mb4
```

HTML:

```html
<meta charset="UTF-8">
```

JSON:

```http
Content-Type: application/json; charset=utf-8
```

Database connection:

```text
charset=utf8mb4
```

Tables containing Persian user content should use:

```text
DEFAULT CHARSET=utf8mb4
```

Avoid `utf8` in MySQL where `utf8mb4` is available.

When debugging Persian text corruption, check the entire pipeline:

```text
Browser
 ↓
HTML charset
 ↓
HTTP request
 ↓
PHP string
 ↓
PDO connection
 ↓
MySQL table/column
 ↓
JSON response
 ↓
Browser
```

Do not blindly add more `SET NAMES` statements without checking the actual database/table/connection configuration.

---

# 33. AJAX / JSON Conventions

JSON endpoints should:

- Set the correct `Content-Type`.
- Return valid JSON only.
- Use meaningful HTTP status codes.
- Return a predictable structure.

Preferred pattern:

```json
{
  "success": true,
  "message": "عملیات با موفقیت انجام شد.",
  "data": {}
}
```

Error:

```json
{
  "success": false,
  "message": "پیام خطا"
}
```

Do not output PHP warnings/notices before JSON.

---

# 34. Error Handling

Development:

```text
DEBUG_MODE = true
```

Production:

```text
DEBUG_MODE = false
display_errors = 0
```

Errors should be logged server-side instead of displayed to users.

The existing:

```text
core/Debug.php
```

is currently only a placeholder and can be expanded if a proper logging/error system is requested.

---

# 35. Common Agent Tasks

When asked to fix a bug:

### Step 1
Identify the exact layer:

```text
UI
↓
JavaScript
↓
PHP handler
↓
Auth
↓
SQL/PDO
↓
MySQL
```

### Step 2
Do not rewrite unrelated parts.

### Step 3
Preserve:

- RTL
- Persian text
- existing URLs
- current database field names
- user ownership checks
- current UI theme

unless the requested change requires otherwise.

### Step 4
If changing SQL/schema, explain the required migration.

### Step 5
If changing an endpoint, keep backwards compatibility when possible.

---

# 36. Common Bug Areas

## Persian text becomes `???`

Check:

1. File encoding is UTF-8.
2. HTML charset is UTF-8.
3. PDO DSN uses `utf8mb4`.
4. Database/table/column use `utf8mb4`.
5. Connection collation/charset.
6. JSON response charset.
7. Existing corrupted data cannot be magically repaired by changing encoding afterward.

## Login redirect problem

Check:

```text
session_start()
$_SESSION['user_id']
$_SESSION['user_role']
BASE_URL
```

## User sees another user's task

This is a critical security bug.

Every task query must be scoped to:

```text
user_id = authenticated user
```

## Admin access problem

Do not rely on frontend hiding.

Always check:

```php
Auth::check()
$_SESSION['user_role'] === 'admin'
```

server-side.

---

# 37. Important Existing URLs

Assuming `BASE_URL` points to the project root:

```text
/
    Main entry

/auth/login.php
    Login/register

/tasks/index.php
    Task manager

/tasks/index.php?page=dashboard
    Dashboard

/tasks/index.php?page=subjects
    Subjects

/profile/index.php
    Profile

/admin/index.php
    Admin user management

/api/tasks.php
    Task JSON API

/setup.php
    Encoding troubleshooting utility
```

---

# 38. Business Terminology

Use these Persian concepts consistently:

```text
Task       = وظیفه / تسک
Subject    = موضوع
Note       = گزارش / یادداشت
User       = کاربر
Admin      = مدیر
Priority   = اولویت
Due date   = تاریخ سررسید
Completed  = انجام شده
Pending    = در انتظار
Active     = فعال
Deactivated = غیرفعال
```

The UI currently tends to use:

```text
وظیفه
موضوع
گزارش
```

so new UI should preferably use the same terminology.

---

# 39. Agent Decision Rules

When an AI agent is asked to implement a feature:

### Small change

Modify the existing page/handler if the change is small and local.

### Medium feature

Create a dedicated class/file rather than making `tasks/index.php` even larger.

### Large feature

First establish:

```text
route
controller
service
repository/model
view
JavaScript
database changes
```

and keep each responsibility separate.

### Database changes

If a feature requires a new table/column:

1. Document the schema.
2. Provide migration SQL.
3. Do not rely on a random page visit to silently alter production schema unless explicitly requested.

---

# 40. Things an Agent Must NOT Assume

Do not assume:

- `Router.php` is a functioning router.
- The project uses MVC.
- `setup.php` is required for normal operation.
- `pending` users are definitely blocked from login; current code does not enforce this.
- Admins can manage all tasks.
- Subjects are globally shared; they are user-owned.
- Notes are globally shared; they are user-owned.
- The frontend is the security boundary.
- GET actions are safe to use for destructive operations.
- Debug mode is appropriate for production.
- Database credentials can be copied into code or documentation.
- Existing corrupted Persian data will be fixed simply by changing charset.

---

# 41. Refactoring Priority

If the project is going to be professionally improved, recommended order:

### Phase 1 — Security
- CSRF
- session regeneration
- authorization middleware/helper
- move destructive actions away from GET
- stronger validation
- production error handling
- protect/remove `setup.php`
- remove secrets from repository

### Phase 2 — Separation of concerns
- Extract controllers
- Extract repositories/models
- Extract views
- Move JS/CSS into `assets`

### Phase 3 — Routing/API
- Implement a real router
- Normalize API endpoints
- Standardize JSON responses

### Phase 4 — Database
- Add migrations
- Foreign keys
- indexes
- proper collations
- explicit defaults

### Phase 5 — Quality
- PHPUnit tests
- logging
- reusable validation
- reusable response helpers
- reusable authorization helpers

Do not perform all phases automatically for a bug-fix request.

---

# 42. Final Mental Model

An agent working on this repository should think of it as:

```text
                    TASK MANAGER
                         │
              ┌──────────┴──────────┐
              │                     │
          Authentication          Roles
              │                     │
        ┌─────┴─────┐          ┌────┴────┐
        │           │          │         │
      User        Session     User      Admin
        │
        ├── Profile
        │
        ├── Subjects
        │      │
        │      └── Tasks
        │             │
        │             ├── Priority
        │             ├── Due Date
        │             ├── Completion
        │             └── Notes/Reports
        │
        └── Statistics

Everything persists through:

PHP
 ↓
PDO
 ↓
MySQL utf8mb4
```

The most important invariant is:

> **Every user-owned operation must be authorized on the server using the authenticated user's identity, not merely an ID supplied by the browser.**

The second most important invariant is:

> **Persian content must remain UTF-8/utf8mb4 throughout the complete request → PHP → PDO → MySQL → JSON/HTML pipeline.**
