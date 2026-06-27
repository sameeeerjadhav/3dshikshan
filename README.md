# 3D Shikshan Portal Login

## Stack
- PHP 8+ (Apache)
- MySQL
- Bootstrap 5 + custom CSS (mobile-first)

## Admin Predefined Login (working now)
- Login ID: `admin@3dshikshan.com`
- Password: `Admin@123`

## Deploy on shared hosting (cPanel / similar)

1. **Upload** all project files to `public_html` (or a subfolder such as `public_html/3dshikshan`).
2. **Create MySQL database** in the hosting panel (e.g. name `3dshikshan` in the UI).
3. **Assign** the user to the database with **ALL PRIVILEGES**.
4. Note the **full MySQL names** from the panel (often prefixed), e.g. `u587292075_3dshikshan` for both database and user — put those exact values in `.env` as `DB_NAME` and `DB_USER`.
5. **Import tables** in phpMyAdmin:
   - Click the **prefixed database** in the **left sidebar** (e.g. `u587292075_3dshikshan`).
   - **Import** → choose `sql/schema_hosting.sql` (this file has no `USE` line on purpose).
6. **Configure `.env`** in the project root (copy from `.env.example` if needed):

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=u587292075_3dshikshan
DB_USER=u587292075_3dshikshan
DB_PASS="your_password_with_special_chars"
```

Use quotes around `DB_PASS` if it contains `$`, `@`, or spaces.

7. **PHP version**: select PHP 8.0 or newer in the hosting panel.
8. **Razorpay**: replace test keys in `.env` with live keys before accepting real payments.
9. Open your site URL (e.g. `https://yourdomain.com/`).

> **#1044 Access denied** on import usually means the SQL file used `USE 3dshikshan` but your real database is `u587292075_3dshikshan`. Select the correct DB in the left sidebar, then import `schema_hosting.sql` again.

## Setup on XAMPP (local)

1. Put project in `htdocs/3D_Shikshan`.
2. Start **Apache** and **MySQL** in XAMPP.
3. For local dev, set in `.env`: `DB_HOST=127.0.0.1`, `DB_USER=root`, `DB_PASS=` (empty), `DB_NAME=3dshikshan`.
4. Open phpMyAdmin and run `sql/schema.sql`.
5. Open in browser: `http://localhost/3D_Shikshan/`.

## Environment Variables
- `config.php` reads from `.env` in the project root.
- Razorpay, database, and admin credentials are configured there.
- `.env` is ignored by git — upload it manually when deploying.

## Security on hosting
- Root `.htaccess` blocks web access to `.env` and the `sql/` folder.
- Never commit `.env` to a public repository.

## Role Auto Recognition
- No role dropdown is shown on UI.
- Backend auto-detects role:
  - Admin: predefined credentials in `.env` / `config.php`
  - Coordinator/Student: from `users.role` in MySQL
