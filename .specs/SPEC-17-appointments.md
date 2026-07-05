# SPEC-17: Appointment booking (Phase 5.3 — MVP)

> READ `.specs/MASTER-PLAN.md` header first. FullCalendar exists at `plugins/fullcalendar/` — verify version & API before using.

## Task 1 — Module + tables
HMVC addon `application/modules/appointment_booking/controllers/Appointment_booking.php` (Addon Name: Appointment Booking / Unique Name: appointment_booking / Modules {"906":{...,"module_name":"Appointments"}} / Project ID: 906; no credential check). Tables (activate + live + migrations 2026-07-05-spec17.sql):
```sql
CREATE TABLE IF NOT EXISTS ab_services (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 name VARCHAR(120) NOT NULL, duration_min INT DEFAULT 30, price DECIMAL(10,2) DEFAULT 0,
 currency VARCHAR(10) DEFAULT 'USD', status ENUM('0','1') DEFAULT '1', created_at DATETIME) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ab_availability (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 weekday TINYINT NOT NULL, start_time TIME NOT NULL, end_time TIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8;
CREATE TABLE IF NOT EXISTS ab_appointments (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
 service_id INT NOT NULL, subscriber_id INT NULL, customer_name VARCHAR(120), customer_phone VARCHAR(40),
 customer_email VARCHAR(120), starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL,
 status ENUM('pending','confirmed','cancelled','done') DEFAULT 'pending', booking_key VARCHAR(32),
 source ENUM('chat','web','manual') DEFAULT 'web', created_at DATETIME,
 KEY idx_user_time (user_id, starts_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Task 2 — Admin UI
Services CRUD; weekly availability editor (7 rows × time ranges); appointments calendar (FullCalendar month/week; events feed endpoint JSON) + list view with status actions (confirm/cancel/done). Sidebar via activate().

## Task 3 — Public booking page (no session; CSRF-exclude `appointment_booking/book.*`)
`book/<user_hash>` (derive a stable public key — e.g. md5(user_id.salt) stored or computed): pick service → pick day → available slots (availability minus existing appointments, slot = service duration) → name/phone/email form → creates 'pending' appointment, shows confirmation + booking_key. Mobile-friendly minimal styling.

## Task 4 — Chat integration (only if Ai_tools.php exists)
Tool `book_appointment` {service_name, preferred_datetime, customer_name, customer_phone}: match service, check slot free, create pending appointment linked to subscriber, return confirmation text. Also tool `list_available_slots` {service_name, date}. Skip gracefully if no services configured.

## Task 5 — Reminders (reuse existing rails)
Cron endpoint `appointment_reminder($api_key)` in the module controller or Cron_job.php pattern: appointments starting in ~24h & confirmed → send Messenger message if subscriber_id (reuse send path/pattern from reminder engine) else skip; mark reminded (add column reminded ENUM('0','1') DEFAULT '0'). Document cron URL.

## Verify
Lint; smoke; booking page renders; slot computation unit-check via SQL/php -r with a seeded availability row (clean up). Commit: `feat: appointment booking module (services, availability, public booking, chat tool)`.
