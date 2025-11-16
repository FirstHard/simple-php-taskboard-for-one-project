# TaskBoard – simple task management system (v0.9.0)

## About the project

TaskBoard is a lightweight task management system for a single project, built with  
**PHP 8**, **MySQL**, **Bootstrap 5**, and **vanilla JavaScript**.  
It includes user roles, a simple admin panel, a Kanban board, audit logging, and convenient UI features.

---

## Features

### 🔐 User system
- Registration & login.
- The first registered user becomes the **super-admin**.
- Super-admin can:
  - activate/deactivate accounts,
  - grant/revoke task access,
  - view the audit log.

### 📋 Task management
Users with task access can:
- create tasks,
- edit tasks,
- delete tasks,
- change task statuses.

Task statuses:
- `draft`
- `planned`
- `in_progress`
- `review`
- `done`

### 🗂 Kanban board (`index.php`)
- Each status is displayed as a column.
- Tasks can be **dragged and dropped** between columns.
- Clicking a task opens its edit page.

### 🛠 Admin panel (`admin/dashboard.php`)
Tabs:
1. **Task list**
   - Pagination
   - Select items per page: `All`, `5`, `10`, `20`, `50`, `100`, `500`
   - Saved individually in `localStorage`
   - Click description to toggle short/full text
2. **Users** (super-admin only)
   - Activate/deactivate users
   - Grant/revoke task access
3. **Recent user actions** (super-admin only)
   - Last N audit log entries
   - Select count: `5`, `10`, `20`, `50`, `100`
   - Also stored per user in `localStorage`
   - Button to open full audit log

### 📜 Full audit log (`admin/audit_log.php`)
- Pagination
- Select items per page: `5`, `10`, `20`, `50`, `100`, `500`
- Saved in `localStorage`

---

## Requirements

- PHP 8.1+ (recommended 8.3)
- MySQL 5.7+ or MariaDB
- Apache / Nginx
- PDO MySQL extension

---

## Installation

### 1. Get the project
Copy the project files into your web server directory, e.g.:

```
/var/www/taskboard
```

or

```
C:\xampp\htdocs\taskboard
```

---

## Configuration

Copy the configuration template:

```
config.php.dist → config.php
```

Edit:
- MySQL connection settings
- Base URL of the project

---

## Database setup

1. Create a database in MySQL.
2. Import one schema file:

```
taskboard_schema.sql (for MySQL 8+)
```

or

```
taskboard_schema_5.x.sql (for MySQL 5.x)
```

Tables created:
- `users`
- `tasks`
- `audit_log`

---

## First launch

Open:

```
http://localhost/taskboard/
```

You will be prompted to register.  
**The first user becomes the super-admin.**

Super-admin can:
- activate new accounts,
- grant task access,
- view activity logs,
- create/edit tasks.

---

## Working with tasks

### Creating a task
Go to:
```
/admin/dashboard.php → “Task list” → Create new task
```

### Task table features
- Short/expanded description toggle
- Pagination with stored preferences
- Edit/Delete buttons

### Kanban board (`index.php`)
Available only if:
- user is logged in,
- `can_access_tasks = 1`

Features:
- drag & drop between statuses,
- instant status update via AJAX,
- click task to edit it.

---

## Audit log

### Recent actions (dashboard bottom)
- Latest N entries
- Select count (saved in localStorage)
- “View all” button

### Full audit log (`admin/audit_log.php`)
- Pagination
- Select count
- Super-admin only

---

## Project structure

```
/taskboard
├── index.php
├── config.php.dist
├── config.php
├── taskboard_schema.sql
├── taskboard_schema_5.x.sql
├── /includes
│   ├── auth.php
│   ├── db.php
├── /admin
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── tasks.php
│   ├── audit_log.php
│   ├── ajax_update_task_status.php
```

---

## License

Free for personal and commercial use. Modify as needed.
