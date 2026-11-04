# Libmen — Library Management System

This version uses a **plain HTML, CSS, and JavaScript frontend**. The browser renders every screen and makes `fetch()` requests to one small PHP JSON endpoint.

PHP is intentionally limited to MySQL connection and database operations in `api.php`; there are no PHP-rendered pages, templates, or server-side UI components.

## Features

- Dashboard totals and recent activity
- Add, edit, search, and remove books
- Register, edit, search, and remove members
- Issue / return flow with copy-limit and member-status checks
- Automatic overdue fine calculation (₹10/day)
- Report cards, category usage bars, and overdue follow-ups
- No build step, npm, framework, or external dependency

## Project structure

```
index.html                 HTML application shell
assets/css/style.css       Responsive interface styles
assets/js/app.js           All UI behavior and API calls
api.php                    PHP MySQL JSON API only
config/database.example.php  Copy to database.php and configure
database/schema.sql        MySQL database schema
```

## Deploy

1. Create a MySQL database in your hosting panel.
2. Import `database/schema.sql` using phpMyAdmin.
3. Copy `config/database.example.php` to `config/database.php` and enter the database credentials. Do not commit this file.
4. Upload the project to your PHP hosting `htdocs` folder and open `index.html` (or the folder URL).

The app requires PHP 7.4+ with `pdo_mysql` and MySQL 5.7+.
