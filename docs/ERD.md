# Entity Relationship Diagram

The database as the migrations actually build it: 28 domain tables, plus 8 that
belong to the framework (`sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations`) and are
left out of the diagrams below.

Every table uses a named primary key — `exhibit_id`, `visitor_id`, `staff_id` —
rather than a bare `id`. That is deliberate: a join reads as
`scans.exhibit_id = exhibits.exhibit_id` with no ambiguity about which `id` is
meant, and the same name carries through to the API and the CSV exports.

To put these diagrams in the manuscript, paste a block into
<https://mermaid.live> and export PNG or SVG. VS Code previews them inline with
the Markdown Preview Mermaid extension; so does GitHub.

---

## The whole picture

Relationships only. `||--o{` reads *one to zero-or-many*; `||--o|` is
*one to zero-or-one*.

```mermaid
erDiagram
    museum_halls  ||--o{ exhibits                : "holds"
    categories    ||--o{ exhibits                : "classifies"
    exhibits      ||--o{ exhibit_translations    : "is written in"
    exhibits      ||--o{ exhibit_images          : "is pictured by"
    exhibits      ||--o{ exhibit_training_images : "is recognised by"
    exhibits      ||--o{ scans                   : "is scanned as"
    exhibits      ||--o{ bookmarks               : "is saved as"

    visit_groups  ||--o{ visitors                : "contains"
    visit_groups  ||--o| tours                   : "is toured as"
    visitors      ||--o{ attendances             : "checks in as"
    visitors      ||--o{ scans                   : "performs"
    visitors      ||--o{ bookmarks               : "saves"
    visitors      ||--o{ feedback                : "submits"
    visitors      ||--o{ tours                   : "is toured as"
    visitors      ||--o{ visitors                : "brings as companions"
    visitors      ||--o{ visits                  : "comes on"
    visit_groups  ||--o{ visits                  : "brings"
    visitors      ||--o| visitor_email_verifications : "confirms email with"
    visitors      ||--o| visitor_password_resets : "resets password with"

    admission_discounts ||--o{ visitors           : "is claimed by"
    visitors      ||--o{ admission_payments      : "pays"
    visit_groups  ||--o{ admission_payments      : "pays for"
    visits        ||--o{ admission_payments      : "is paid by"
    staff         ||--o{ admission_payments      : "records"

    tours            ||--o{ feedback             : "is rated by"
    feedback         ||--o{ feedback_answers     : "answers with"
    survey_questions ||--o{ feedback_answers     : "is answered by"

    staff ||--o{ staff_attendances      : "clocks"
    staff ||--o{ staff_schedules        : "works"
    staff ||--o{ attendance_corrections : "requests"
    staff ||--o{ staff_attendance_days  : "opens"
    staff ||--o{ tours                  : "guides"
    staff ||--o{ visitors               : "registers"
    staff ||--o{ visit_groups           : "registers"
    staff ||--o{ feedback               : "is rated in"

    staff_attendance_days ||--o{ staff_attendances : "is the day of"
```

`museum_info`, `logs` and `notifications` are standalone tables with no foreign
keys in either direction, so they do not appear above. `logs` having no key is
itself a design decision — see **Deliberate non-relationships**.

---

## 1. Exhibits and the collection

What the visitor app reads. An exhibit sits in a hall, belongs to a category,
and carries one row per language plus a gallery and a recognition training set.

```mermaid
erDiagram
    museum_halls {
        int hall_id PK
        varchar name
        varchar floor "ground, second, grounds"
        varchar description
        varchar icon
        int sort_order
    }
    categories {
        int category_id PK
        varchar name
        text description
    }
    exhibits {
        int exhibit_id PK
        varchar exhibit_code UK "the string inside the QR code"
        varchar name
        text description
        text fun_facts
        int category_id FK
        int hall_id FK
        varchar authors
        varchar languages "which translations exist"
        varchar source_language
        int storyline_order "position on the guided path"
        varchar image "cover filename"
        varchar qr_file
        decimal map_x "floor-plan pin, 0-100 percent"
        decimal map_y
        bool status "published or hidden"
        date date_published
    }
    exhibit_translations {
        int translation_id PK
        int exhibit_id FK
        varchar language_code "en, fil, es"
        varchar language_label
        varchar title
        text description
        text fun_facts
        varchar audio_file "narration, if generated"
        datetime audio_made_at
        varchar audio_text_hash "detects text edited after narration"
    }
    exhibit_images {
        int image_id PK
        int exhibit_id FK
        varchar filename
        varchar caption
        int sort_order
    }
    exhibit_training_images {
        int training_image_id PK
        int exhibit_id FK
        varchar filename "one class in the recognition model"
    }
    museum_halls ||--o{ exhibits : "holds"
    categories   ||--o{ exhibits : "classifies"
    exhibits ||--o{ exhibit_translations    : "is written in"
    exhibits ||--o{ exhibit_images          : "is pictured by"
    exhibits ||--o{ exhibit_training_images : "is recognised by"
```

## 2. Visitors, groups and the door

The admission path. A walk-in is a `visitors` row; a school or tour bus is one
`visit_groups` row carrying a `join_code` with many `visitors` under it.
`attendances` is the geofenced check-in; `scans` is one row per exhibit opened.

A `visitors` row is the person plus their *current* visit, and a return visit
overwrites the visit half. `visits` keeps one row per visitor per day, written
only for today, so the history grows but is never rewritten. Free companions a
visitor brings (a senior, a PWD, a small child) are nameless `visitors` rows of
their own, pointing back through `companion_of`, so every count includes them
without a report having to know they exist.

`visitor_email_verifications` and `visitor_password_resets` hold at most one
pending 6-digit code per visitor, stored hashed, never as the digits.

```mermaid
erDiagram
    visit_groups {
        int group_id PK
        varchar group_name
        enum group_type "Family, Group, School, Tour"
        varchar contact_name
        varchar contact_phone
        enum visitor_type "Local, Tourist, Foreign"
        varchar city
        varchar province
        varchar country
        int headcount
        int local_count
        json discounts "how many claimed each category, as a snapshot"
        int paying_count
        decimal total_fee
        enum payment_status "Free, Unpaid, Paid"
        datetime paid_at
        decimal refunded_amount
        datetime refunded_at
        int refunded_by FK
        varchar join_code "what the group types on their phones"
        date visit_date
        int registered_by FK
        text notes
    }
    visitors {
        int visitor_id PK
        varchar first_name "null for express entries and companions"
        varchar last_name "null for express entries and companions"
        varchar middle_name
        int age
        varchar sex
        enum visitor_type "Local, Tourist, Foreign"
        enum visit_type "Solo, Group, School, Family, Walk-in"
        varchar city
        varchar barangay
        varchar province
        varchar country
        varchar email
        datetime email_verified_at "set by the emailed code or by Google"
        varchar password "null for guests"
        varchar api_token "bearer token for the visitor guard"
        datetime token_expires_at
        varchar auth_provider
        varchar google_id UK "set by Google sign-in"
        varchar explore_mode
        decimal admission_fee
        int discount_id FK "the free or discounted category claimed"
        varchar discount_name "snapshot of the category's name"
        tinyint discount_percent "snapshot of its percentage"
        varchar payment_status
        datetime paid_at
        bool id_verified "discount ID checked at the desk"
        datetime verified_at
        int verified_by FK
        varchar source "app, kiosk, desk, recovered, express"
        int registered_by FK
        int group_id FK
        int companion_of FK "the visitor who brought them"
        datetime last_visit
    }
    visits {
        int visit_id PK
        int visitor_id FK "unique per visitor per day"
        date visit_date
        datetime arrived_at
        int group_id FK
        datetime joined_group_at "when they joined the party with its code"
        smallint headcount "the visitor plus today's companions"
        json companions "companion categories and how many"
        varchar visitor_type
        varchar visit_type
        int discount_id "copied, not a foreign key"
        varchar discount_name
        tinyint discount_percent
        decimal admission_fee
        varchar payment_status
        datetime paid_at
        bool id_verified
        datetime verified_at
        varchar source
        bool backfilled "rebuilt from older records"
    }
    visitor_email_verifications {
        int id PK
        int visitor_id FK "unique"
        varchar code_hash "6-digit code, hashed"
        tinyint attempts
        datetime expires_at
        datetime sent_at
    }
    visitor_password_resets {
        int id PK
        int visitor_id FK "unique"
        varchar code_hash "6-digit code, hashed"
        varchar token_hash "issued once the code is right"
        tinyint attempts
        datetime expires_at
    }
    attendances {
        int attendance_id PK
        int visitor_id FK
        varchar visitor_name "kept if the visitor row is cleared"
        decimal latitude
        decimal longitude
        int accuracy "metres reported by the phone"
        varchar method "qr or manual"
        date visit_date
        datetime exited_at
        int duration_mins
    }
    scans {
        int scan_id PK
        int exhibit_id FK
        int visitor_id FK
        varchar language_code
        varchar scan_type "qr or camera recognition"
        datetime scanned_at
    }
    bookmarks {
        int bookmark_id PK
        int visitor_id FK
        int exhibit_id FK
    }
    visit_groups ||--o{ visitors : "contains"
    visitors ||--o{ visitors    : "brings as companions"
    visitors ||--o{ attendances : "checks in as"
    visitors ||--o{ scans       : "performs"
    visitors ||--o{ bookmarks   : "saves"
    visitors ||--o{ visits      : "comes on"
    visit_groups ||--o{ visits  : "brings"
    visitors ||--o| visitor_email_verifications : "confirms email with"
    visitors ||--o| visitor_password_resets     : "resets password with"
```

## 3. Admission and payments

Who enters free and who pays less is a museum setting, not code:
`museum_info.resident_scope` says which residents are free (Baler only, or all
of Aurora), and `admission_discounts` lists the other categories with what the
desk checks and how much comes off. A visitor or party copies the category's
name and percentage when it is claimed, so renaming a category later does not
rewrite what past visitors paid.

`admission_payments` is the money ledger: one row per payment or refund, each
with its own transaction number (`reference`). A refund is a row of its own
rather than an edit to the payment, so the day's takings can always be rebuilt.

```mermaid
erDiagram
    admission_discounts {
        int discount_id PK
        varchar name "Senior citizen, PWD, Child, ..."
        varchar proof "what the desk checks, e.g. Senior citizen ID"
        tinyint percent_off "100 means free"
        tinyint min_age "optional age range"
        tinyint max_age
        bool active
        int sort_order
    }
    admission_payments {
        int payment_id PK
        varchar reference UK "transaction no., e.g. MDB-20261004-0001"
        enum kind "payment or refund"
        enum payer "individual or group"
        int visitor_id FK
        int visit_id FK
        int group_id FK
        varchar payer_name
        varchar visitor_type
        int headcount
        json visitor_ids "everyone this payment covered"
        decimal amount
        json breakdown "price per person and discount"
        datetime recorded_at
        int recorded_by FK "staff"
        bool backfilled
    }
    admission_discounts ||--o{ visitors : "is claimed by"
    visitors     ||--o{ admission_payments : "pays"
    visit_groups ||--o{ admission_payments : "pays for"
    visits       ||--o{ admission_payments : "is paid by"
    staff        ||--o{ admission_payments : "records"
```

## 4. Tours and the ARTA survey

`survey_questions` holds the ARTA Client Satisfaction Measurement instrument as
data, not code, so Tourism can reword a question without a deployment.
`feedback_answers.code` is copied at submission time, which is why a report can
still be produced after a question is retired.

```mermaid
erDiagram
    tours {
        int tour_id PK
        int guide_staff_id FK
        enum tour_type "Requested, Foreign, Educational"
        int visitor_id FK
        int group_id FK
        int headcount
        datetime started_at
        datetime ended_at
        text notes
        int created_by FK
    }
    feedback {
        int feedback_id PK
        int visitor_id FK
        int tour_id FK
        int staff_id FK "the guide being rated"
        varchar first_name "if submitted without signing in"
        varchar last_name
        varchar middle_name
        int rating "overall, 1-5"
        int guide_rating
        text comment
        varchar attributed_by "how the respondent was identified"
        varchar client_type "ARTA: citizen, business, government"
        varchar region
        datetime submitted_at
    }
    survey_questions {
        int question_id PK
        varchar code UK "CC1, SQD0 to SQD8"
        varchar section
        enum scale "5-point agree scale or choice list"
        text text_fil
        text text_en
        varchar hint_fil
        varchar hint_en
        text options "JSON, for choice questions"
        text show_if "JSON branching rule"
        bool allow_na
        bool default_na
        bool required
        bool locked "an ARTA question staff cannot delete"
        bool is_active
        int sort_order
    }
    feedback_answers {
        int answer_id PK
        int feedback_id FK
        int question_id FK "null once a question is deleted"
        varchar code "snapshot: survives the question"
        int value
    }
    tours ||--o{ feedback : "is rated by"
    feedback ||--o{ feedback_answers : "answers with"
    survey_questions ||--o{ feedback_answers : "is answered by"
```

## 5. Staff, the DTR and the museum record

`staff_attendance_days` issues one rotating `day_secret` per working day. The
wall QR encodes that secret, so yesterday's photograph of the poster will not
clock anyone in today.

```mermaid
erDiagram
    staff {
        int staff_id PK
        varchar name
        varchar email UK
        varchar password "bcrypt"
        enum role "TourismHead or Administrator"
        bool status "active or disabled"
        bool must_change_password
        datetime password_changed_at
        varchar remember_token
    }
    staff_schedules {
        int schedule_id PK
        int staff_id FK
        int weekday "0 to 6"
        time shift_start
        time shift_end
        int grace_minutes
        bool is_rest_day
    }
    staff_attendance_days {
        date work_date PK
        varchar day_secret "rotates daily: defeats a photographed QR"
        int opened_by FK
    }
    staff_attendances {
        int staff_attendance_id PK
        int staff_id FK
        date work_date
        varchar type "in or out"
        datetime scanned_at
        decimal latitude
        decimal longitude
        int accuracy
        int distance_m "from the geofence centre"
        varchar method "qr or manual"
        int recorded_by FK "set when entered by hand"
        varchar ip_address
        varchar user_agent
    }
    attendance_corrections {
        int correction_id PK
        int staff_id FK
        date work_date
        varchar type
        time requested_time
        text reason
        int requested_by FK
        datetime requested_at
        varchar status "pending, approved, rejected"
        int reviewed_by FK
        datetime reviewed_at
        text review_note
    }
    museum_info {
        int info_id PK
        varchar name
        varchar tagline
        text story
        text story2
        varchar address
        varchar hours
        varchar closed_on
        varchar phone
        varchar email
        varchar admission "admission text shown in the app"
        decimal latitude "geofence centre"
        decimal longitude
        int geofence_radius_m
        decimal admission_fee
        varchar resident_scope "who enters free: baler or aurora"
        varchar report_logo
        varchar report_header_image "letterhead on exported reports"
    }
    logs {
        int log_id PK
        int user_id "NOT a foreign key: see below"
        varchar user_name "snapshot of who acted"
        varchar role
        varchar action
        text details
        varchar ip_address
    }
    notifications {
        int notif_id PK
        varchar title
        text body
        varchar type
        bool is_active
    }
    staff ||--o{ staff_schedules        : "works"
    staff ||--o{ staff_attendances      : "clocks"
    staff ||--o{ attendance_corrections : "requests"
    staff ||--o{ staff_attendance_days  : "opens"
    staff_attendance_days ||--o{ staff_attendances : "is the day of"
```

---

## Deliberate non-relationships

Four places where a foreign key is *absent* on purpose. Each is worth being
ready to explain, because each looks like an oversight until you say why.

**`logs.user_id` has no foreign key, and `logs.user_name` duplicates the name.**
An audit trail that cascades away when an account is deleted is not an audit
trail. The log keeps its own copy of who acted and in what role, so the record
of what a since-removed employee did survives their removal. Same reasoning for
`attendances.visitor_name`.

**`feedback_answers.code` duplicates `survey_questions.code`.** A survey answer
means whatever the question meant *at the time it was answered*. Copying the
code at submission lets Tourism retire or reword a question without rewriting
history, and lets a report still show a retired question's figures.
`question_id` goes null; `code` does not.

**`visitors` name columns are repeated on `feedback`.** Feedback can be
submitted without signing in. When it is, the name columns on `feedback` hold
what the respondent typed and `visitor_id` stays null.

**`visits.discount_id` has no foreign key, and the category's name and
percentage are copied beside it.** A visit is a record of what happened that
day. If the museum later removes or renames a category, the visit must still
say what the visitor claimed and at what percentage. The same reasoning covers
`visitors.discount_name`/`discount_percent`, `visit_groups.discounts` and
`admission_payments.breakdown`.

## Notes for the panel

- **Why `staff` and not Laravel's `users` table.** The authentication provider
  in `config/auth.php` points at `App\Models\Staff` against the `staff` table.
  Museum employees are not generic users: they carry a role, a schedule, a DTR
  and a password lifecycle. Visitors authenticate through an entirely separate
  guard (`visitor`, a bearer token on `visitors`). Two populations, two tables,
  two guards, so a visitor can never hold a staff session.
- **`bookmarks` has no Eloquent model.** It is a join row with no behaviour,
  read through the query builder in the visitor API and in the reports.
- **Decimal, not float, for money and coordinates.** `admission_fee`,
  `total_fee`, `latitude` and `longitude` are `decimal`, so a peso figure and a
  geofence edge do not drift.
- **Cascades.** Deleting an exhibit removes its translations, images, training
  images and bookmarks. It does *not* remove its `scans`: visit statistics
  outlive the exhibit. Deleting a visitor removes their bookmarks and any
  pending email or password code; their `visits`, `scans`, `attendances` and
  `admission_payments` stay, with `visitor_id` set to null, so the money and
  the visit counts remain complete.
- **`visits.headcount` is never added up.** Each companion is a visitor and a
  visit of their own, so summing `headcount` on top would count them twice. It
  records who came together, not a total.
- **Transaction numbers are stored, not computed.** `admission_payments.reference`
  is written once (`MDB-<date>-<sequence>`) and is unique, so a receipt number
  printed today means the same thing next year.
