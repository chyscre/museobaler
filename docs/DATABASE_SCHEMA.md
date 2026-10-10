# Museo de Baler — Database Schema

Reference for drawing the system diagrams. Generated from the live database structure on October 5, 2026 (MySQL, database `museobaler`).

**Key:** PK = primary key · FK = foreign key · UK = unique · Null = may be left empty.

Not listed: the 8 tables Laravel itself uses (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations`).

## Tables (28)

- `museum_halls` (1. Exhibits and the collection)
- `categories` (1. Exhibits and the collection)
- `exhibits` (1. Exhibits and the collection)
- `exhibit_translations` (1. Exhibits and the collection)
- `exhibit_images` (1. Exhibits and the collection)
- `exhibit_training_images` (1. Exhibits and the collection)
- `visit_groups` (2. Visitors, groups and visits)
- `visitors` (2. Visitors, groups and visits)
- `visits` (2. Visitors, groups and visits)
- `visitor_email_verifications` (2. Visitors, groups and visits)
- `visitor_password_resets` (2. Visitors, groups and visits)
- `attendances` (2. Visitors, groups and visits)
- `scans` (2. Visitors, groups and visits)
- `bookmarks` (2. Visitors, groups and visits)
- `admission_discounts` (3. Admission and payments)
- `admission_payments` (3. Admission and payments)
- `tours` (4. Tours and the ARTA survey)
- `feedback` (4. Tours and the ARTA survey)
- `survey_questions` (4. Tours and the ARTA survey)
- `feedback_answers` (4. Tours and the ARTA survey)
- `staff` (5. Staff and attendance (DTR))
- `staff_schedules` (5. Staff and attendance (DTR))
- `staff_attendance_days` (5. Staff and attendance (DTR))
- `staff_attendances` (5. Staff and attendance (DTR))
- `attendance_corrections` (5. Staff and attendance (DTR))
- `museum_info` (6. Museum settings and records)
- `logs` (6. Museum settings and records)
- `notifications` (6. Museum settings and records)

---

## 1. Exhibits and the collection

### 1. `museum_halls`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| hall_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the hall |
| name | VARCHAR(255) |  | No |  | hall name shown on the map and exhibit pages |
| floor | VARCHAR(255) |  | Yes |  | which floor the hall is on, e.g. Ground Floor, 2nd Floor |
| description | VARCHAR(255) |  | Yes |  | short description of the hall |
| icon | VARCHAR(255) |  | Yes |  | icon shown beside the hall name |
| sort_order | INT |  | No | 0 | display order in lists |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 2. `categories`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| category_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the category |
| name | VARCHAR(255) |  | No |  | category name, e.g. Siege of Baler |
| description | TEXT |  | Yes |  | what the category covers |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 3. `exhibits`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| exhibit_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the exhibit |
| exhibit_code | VARCHAR(255) | UK | No |  | the code printed inside the exhibit QR, e.g. EXH-001 |
| name | VARCHAR(255) |  | No |  | exhibit title in the source language (on screen: Title) |
| description | TEXT |  | Yes |  | exhibit text in the source language |
| fun_facts | TEXT |  | Yes |  | extra facts shown under the description |
| category_id | BIGINT UNSIGNED | FK | Yes |  | → `categories.category_id` (emptied if it is deleted); the exhibit category |
| hall_id | BIGINT UNSIGNED | FK | Yes |  | → `museum_halls.hall_id` (emptied if it is deleted); the hall the exhibit stands in |
| map_x | DECIMAL(5,2) |  | Yes |  | pin position across the floor plan, 0-100 percent |
| map_y | DECIMAL(5,2) |  | Yes |  | pin position down the floor plan, 0-100 percent |
| authors | VARCHAR(255) |  | Yes |  | who wrote or researched the text |
| languages | VARCHAR(255) |  | No | Filipino,English | which translations exist |
| source_language | VARCHAR(10) |  | No | en | language the text was written in (en, fil, es) |
| storyline_order | INT |  | No | 0 | stop number on the guided storyline; empty if not on it (on screen: Storyline Stop) |
| image | VARCHAR(255) |  | Yes |  | file name of the cover photo |
| qr_file | VARCHAR(255) |  | Yes |  | file name of the generated QR code image |
| status | TINYINT(1) |  | No | 1 | 1 = published to visitors, 0 = archived |
| date_published | DATE |  | Yes |  | date the exhibit was published |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 4. `exhibit_translations`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| translation_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the translation |
| exhibit_id | BIGINT UNSIGNED | FK | No |  | → `exhibits.exhibit_id` (deleted with it); the exhibit translated |
| language_code | VARCHAR(10) |  | No |  | en, fil or es |
| language_label | VARCHAR(255) |  | No |  | language name shown to visitors, e.g. Filipino |
| title | VARCHAR(255) |  | Yes |  | exhibit title in this language |
| description | TEXT |  | Yes |  | exhibit text in this language |
| fun_facts | TEXT |  | Yes |  | extra facts in this language |
| audio_file | VARCHAR(255) |  | Yes |  | file name of the narrated audio guide, if made |
| audio_made_at | TIMESTAMP |  | Yes |  | when the audio guide was generated |
| audio_text_hash | VARCHAR(40) |  | Yes |  | fingerprint of the text the audio was made from; detects text edited after narration |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

*Unique together:* `exhibit_id` + `language_code`

### 5. `exhibit_images`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| image_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the photo |
| exhibit_id | BIGINT UNSIGNED | FK | No |  | → `exhibits.exhibit_id` (deleted with it); the exhibit pictured |
| filename | VARCHAR(255) |  | No |  | file name of the photo (WebP copies are made from it) |
| caption | VARCHAR(255) |  | Yes |  | caption shown under the photo |
| sort_order | INT |  | No | 0 | order in the exhibit gallery |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 6. `exhibit_training_images`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| training_image_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the training photo |
| exhibit_id | BIGINT UNSIGNED | FK | Yes |  | → `exhibits.exhibit_id` (deleted with it); the exhibit this photo teaches the camera to recognise |
| filename | VARCHAR(255) |  | No |  | file name of the training photo |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

---

## 2. Visitors, groups and visits

### 7. `visit_groups`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| group_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the group |
| join_code | VARCHAR(8) |  | Yes |  | code group members type on their phones to join (on screen: Group code) |
| group_name | VARCHAR(255) |  | Yes |  | name of the group, e.g. school or agency name |
| group_type | ENUM('Family', 'Group', 'School', 'Tour') |  | No | Group | kind of group |
| contact_name | VARCHAR(255) |  | Yes |  | person in charge of the group |
| contact_phone | VARCHAR(255) |  | Yes |  | contact number of the person in charge |
| visitor_type | ENUM('Local', 'Tourist', 'Foreign') |  | No | Tourist | where the group is from; Local enters free |
| city | VARCHAR(255) |  | Yes |  | city or town the group came from |
| province | VARCHAR(255) |  | Yes |  | province the group came from |
| country | VARCHAR(255) |  | Yes | Philippines | country the group came from |
| headcount | INT UNSIGNED |  | No | 1 | number of people in the group (on screen: People) |
| local_count | INT UNSIGNED |  | No | 0 | how many members are local residents (free) |
| discounts | JSON |  | Yes |  | how many members claimed each free or discounted category, saved as it was that day |
| paying_count | INT UNSIGNED |  | No | 0 | how many members pay |
| total_fee | DECIMAL(10,2) |  | No | 0.00 | total admission charged to the group, in pesos |
| payment_status | ENUM('Free', 'Unpaid', 'Paid') |  | No | Unpaid | whether the group has paid |
| paid_at | TIMESTAMP |  | Yes |  | when the group paid |
| refunded_amount | DECIMAL(10,2) |  | No | 0.00 | amount refunded to the group, in pesos |
| refunded_at | TIMESTAMP |  | Yes |  | when the refund was given |
| refunded_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who gave the refund |
| visit_date | DATE |  | No |  | date of the group visit |
| registered_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who registered the group |
| notes | TEXT |  | Yes |  | desk notes about the group |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 8. `visitors`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| visitor_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the visitor |
| group_id | BIGINT UNSIGNED | FK | Yes |  | → `visit_groups.group_id` (emptied if it is deleted); the group they came with today, if any |
| companion_of | BIGINT UNSIGNED | FK | Yes |  | → `visitors.visitor_id` (emptied if it is deleted); the visitor who brought them, if they are a free companion |
| first_name | VARCHAR(255) |  | Yes |  | first name; empty for express desk entries and companions |
| last_name | VARCHAR(255) |  | Yes |  | last name; empty for express desk entries and companions |
| middle_name | VARCHAR(255) |  | Yes |  | middle name |
| age | INT |  | Yes |  | age in years |
| sex | ENUM('Male', 'Female', 'Other', 'Prefer not to say') |  | Yes |  | sex as given by the visitor |
| visitor_type | ENUM('Local', 'Tourist', 'Foreign') |  | No | Local | where the visitor is from; Local (resident) enters free |
| admission_fee | DECIMAL(8,2) |  | No | 0.00 | admission charged for the current visit, in pesos |
| discount_id | BIGINT UNSIGNED | FK | Yes |  | → `admission_discounts.discount_id` (emptied if it is deleted); the free or discounted category claimed, e.g. Senior citizen |
| discount_name | VARCHAR(80) |  | Yes |  | name of that category, saved as it was at the time |
| discount_percent | TINYINT UNSIGNED |  | Yes |  | percentage off, saved as it was at the time |
| payment_status | ENUM('Free', 'Unpaid', 'Paid') |  | No | Free | whether the current visit is paid, unpaid or free |
| paid_at | TIMESTAMP |  | Yes |  | when the current visit was paid; the day it clears them for |
| id_verified | TINYINT(1) |  | No | 0 | whether the desk checked their discount or resident ID |
| verified_at | TIMESTAMP |  | Yes |  | when the ID was checked |
| verified_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who checked the ID |
| visit_type | ENUM('Solo', 'Group', 'School', 'Family', 'Walk-in') |  | No | Solo | how they are visiting |
| city | VARCHAR(255) |  | Yes |  | city or town of residence |
| barangay | VARCHAR(100) |  | Yes |  | barangay of residence |
| province | VARCHAR(255) |  | Yes |  | province of residence |
| country | VARCHAR(255) |  | Yes | Philippines | country of residence |
| email | VARCHAR(255) |  | Yes |  | email address used to sign in to the visitor app |
| email_verified_at | TIMESTAMP |  | Yes |  | when the email was confirmed, by the 6-digit code or by Google |
| password | VARCHAR(255) |  | Yes |  | hashed password; empty for desk-only and Google accounts |
| api_token | VARCHAR(64) |  | Yes |  | sign-in token of the visitor app (stored hashed) |
| token_expires_at | TIMESTAMP |  | Yes |  | when the app sign-in expires |
| auth_provider | VARCHAR(255) |  | No | manual | how the account signs in: manual (email and password) or google |
| google_id | VARCHAR(64) | UK | Yes |  | Google account number, set by Google sign-in |
| source | ENUM('app', 'kiosk', 'desk', 'recovered', 'express') |  | No | app | where the record was created |
| registered_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who registered them at the desk |
| explore_mode | VARCHAR(255) |  | No | Storyline | chosen tour style in the app: Storyline or Free |
| last_visit | TIMESTAMP |  | Yes |  | date and time of the latest visit |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 9. `visits`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| visit_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the visit |
| visitor_id | BIGINT UNSIGNED | FK | Yes |  | → `visitors.visitor_id` (emptied if it is deleted); the visitor; one row per visitor per day |
| visit_date | DATE |  | No |  | date of the visit |
| arrived_at | TIMESTAMP |  | Yes |  | when they arrived |
| group_id | BIGINT UNSIGNED | FK | Yes |  | → `visit_groups.group_id` (emptied if it is deleted); the group they came with that day, if any |
| joined_group_at | TIMESTAMP |  | Yes |  | when they joined the group with its code |
| headcount | SMALLINT UNSIGNED |  | No | 1 | the visitor plus that day's companions (a note, never added up) |
| companions | JSON |  | Yes |  | companion categories and how many, e.g. 1 Senior citizen |
| visitor_type | VARCHAR(20) |  | Yes |  | Local, Tourist or Foreign that day |
| visit_type | VARCHAR(20) |  | Yes |  | how they visited that day |
| discount_id | BIGINT UNSIGNED |  | Yes |  | category claimed that day (copied, not linked) |
| discount_name | VARCHAR(80) |  | Yes |  | name of the category claimed that day |
| discount_percent | TINYINT UNSIGNED |  | Yes |  | percentage off that day |
| admission_fee | DECIMAL(10,2) |  | Yes |  | admission charged that day, in pesos |
| payment_status | VARCHAR(20) |  | Yes |  | Paid, Unpaid or Free that day |
| paid_at | TIMESTAMP |  | Yes |  | when that day's admission was paid |
| id_verified | TINYINT(1) |  | Yes |  | whether the ID was checked that day |
| verified_at | TIMESTAMP |  | Yes |  | when the ID was checked that day |
| source | VARCHAR(20) |  | Yes |  | where that day's record came from (app, desk, express ...) |
| backfilled | TINYINT(1) |  | No | 0 | rebuilt from older records when this table was added |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

*Unique together:* `visitor_id` + `visit_date`

### 10. `visitor_email_verifications`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the pending code |
| visitor_id | BIGINT UNSIGNED | FK, UK | No |  | → `visitors.visitor_id` (deleted with it); the visitor confirming their email (one pending code each) |
| code_hash | VARCHAR(64) |  | No |  | the emailed 6-digit code, stored hashed |
| attempts | TINYINT UNSIGNED |  | No | 0 | wrong tries so far; the code stops working after too many |
| expires_at | TIMESTAMP |  | No |  | when the code stops working |
| sent_at | TIMESTAMP |  | No |  | when the code was emailed (limits resending) |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 11. `visitor_password_resets`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the reset request |
| visitor_id | BIGINT UNSIGNED | FK, UK | No |  | → `visitors.visitor_id` (deleted with it); the visitor resetting their password (one request each) |
| code_hash | VARCHAR(64) |  | Yes |  | the emailed 6-digit code, stored hashed |
| token_hash | VARCHAR(64) |  | Yes |  | one-time reset permission given once the code is right, stored hashed |
| attempts | TINYINT UNSIGNED |  | No | 0 | wrong tries so far |
| expires_at | TIMESTAMP |  | No |  | when the code or permission stops working |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 12. `attendances`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| attendance_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the check-in |
| visitor_id | BIGINT UNSIGNED | FK | Yes |  | → `visitors.visitor_id` (emptied if it is deleted); the visitor who checked in |
| visitor_name | VARCHAR(255) |  | Yes |  | visitor name copied at check-in, kept if the visitor is deleted |
| latitude | DECIMAL(10,7) |  | Yes |  | phone location at check-in |
| longitude | DECIMAL(10,7) |  | Yes |  | phone location at check-in |
| accuracy | INT |  | Yes |  | how accurate the phone location was, in metres |
| method | VARCHAR(255) |  | No | geofence | how they checked in |
| visit_date | DATE |  | No |  | date of the check-in |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |
| exited_at | TIMESTAMP |  | Yes |  | when they left |
| duration_mins | INT |  | Yes |  | minutes spent inside |

*Unique together:* `visitor_id` + `visit_date`

### 13. `scans`

Shown to staff as **Scan Records** and **Most Scanned Exhibits**, and to visitors as **Most Scanned**.

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| scan_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the scan |
| exhibit_id | BIGINT UNSIGNED | FK | No |  | → `exhibits.exhibit_id` (deleted with it); the exhibit scanned |
| visitor_id | BIGINT UNSIGNED | FK | Yes |  | → `visitors.visitor_id` (emptied if it is deleted); the visitor who scanned it |
| language_code | VARCHAR(10) |  | Yes |  | language the exhibit was read in |
| scan_type | ENUM('qr', 'image') |  | Yes |  | QR code or camera picture recognition |
| scanned_at | TIMESTAMP |  | No | CURRENT_TIMESTAMP | when it was scanned |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 14. `bookmarks`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| bookmark_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the bookmark |
| visitor_id | BIGINT UNSIGNED | FK | No |  | → `visitors.visitor_id` (deleted with it); the visitor who saved the exhibit |
| exhibit_id | BIGINT UNSIGNED | FK | No |  | → `exhibits.exhibit_id` (deleted with it); the exhibit saved |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

*Unique together:* `visitor_id` + `exhibit_id`

---

## 3. Admission and payments

### 15. `admission_discounts`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| discount_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the category |
| name | VARCHAR(80) |  | No |  | category name, e.g. Senior citizen, PWD, Child |
| proof | VARCHAR(150) |  | Yes |  | what the desk checks, e.g. Senior citizen ID |
| percent_off | TINYINT UNSIGNED |  | No |  | percentage off the fee; 100 means free |
| min_age | TINYINT UNSIGNED |  | Yes |  | lowest age that qualifies, if any |
| max_age | TINYINT UNSIGNED |  | Yes |  | highest age that qualifies, if any |
| active | TINYINT(1) |  | No | 1 | whether the desk can still choose it |
| sort_order | INT UNSIGNED |  | No | 0 | display order at the desk |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 16. `admission_payments`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| payment_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the payment |
| reference | VARCHAR(24) | UK | Yes |  | transaction number printed on the record, e.g. MDB-20261004-0001 (on screen: Transaction No.) |
| kind | ENUM('payment', 'refund') |  | No | payment | money in (payment) or money back (refund) (on screen: Kind) |
| payer | ENUM('individual', 'group') |  | No |  | paid by one visitor or by a group (on screen: Paid as) |
| visitor_id | BIGINT UNSIGNED | FK | Yes |  | → `visitors.visitor_id` (emptied if it is deleted); the visitor who paid, for an individual payment |
| visit_id | BIGINT UNSIGNED | FK | Yes |  | → `visits.visit_id` (emptied if it is deleted); the visit the payment was for |
| group_id | BIGINT UNSIGNED | FK | Yes |  | → `visit_groups.group_id` (emptied if it is deleted); the group that paid, for a group payment |
| payer_name | VARCHAR(255) |  | Yes |  | name of the payer, copied at the time (on screen: Paid by) |
| visitor_type | VARCHAR(20) |  | Yes |  | Local, Tourist or Foreign at the time |
| headcount | INT UNSIGNED |  | No | 1 | number of people the payment covered (on screen: People) |
| visitor_ids | JSON |  | Yes |  | list of every visitor the payment covered |
| amount | DECIMAL(10,2) |  | No |  | amount in pesos |
| breakdown | JSON |  | Yes |  | price per person and discounts applied |
| recorded_at | TIMESTAMP |  | No |  | when the money was taken or returned |
| recorded_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who recorded it |
| backfilled | TINYINT(1) |  | No | 0 | rebuilt from older records when this table was added |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

---

## 4. Tours and the ARTA survey

### 17. `tours`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| tour_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the tour |
| guide_staff_id | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); the staff member who guided |
| tour_type | ENUM('Requested', 'Foreign', 'Educational') |  | No |  | kind of tour |
| visitor_id | BIGINT UNSIGNED | FK | Yes |  | → `visitors.visitor_id` (emptied if it is deleted); the visitor toured, for a one-person tour |
| group_id | BIGINT UNSIGNED | FK | Yes |  | → `visit_groups.group_id` (emptied if it is deleted); the group toured, for a group tour |
| headcount | INT UNSIGNED |  | No | 1 | number of people on the tour |
| started_at | TIMESTAMP |  | Yes |  | when the tour started |
| ended_at | TIMESTAMP |  | Yes |  | when the tour ended |
| notes | TEXT |  | Yes |  | guide notes |
| created_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who logged the tour |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 18. `feedback`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| feedback_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the survey response |
| visitor_id | BIGINT UNSIGNED | FK | Yes |  | → `visitors.visitor_id` (emptied if it is deleted); the visitor who answered, if signed in |
| tour_id | BIGINT UNSIGNED | FK | Yes |  | → `tours.tour_id` (emptied if it is deleted); the tour being rated, if any |
| staff_id | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); the guide being rated, if any |
| first_name | VARCHAR(255) |  | Yes |  | name typed by a respondent who was not signed in |
| last_name | VARCHAR(255) |  | Yes |  | name typed by a respondent who was not signed in |
| middle_name | VARCHAR(255) |  | Yes |  | name typed by a respondent who was not signed in |
| rating | TINYINT |  | No | 5 | overall rating, 1 to 5 |
| guide_rating | TINYINT |  | Yes |  | rating of the guide, 1 to 5 |
| attributed_by | ENUM('none', 'visitor', 'duty') |  | No | none | how the respondent was identified |
| comment | TEXT |  | Yes |  | written comment |
| client_type | ENUM('citizen', 'business', 'government') |  | Yes |  | ARTA client type |
| region | VARCHAR(60) |  | Yes |  | region the respondent is from |
| submitted_at | TIMESTAMP |  | No | CURRENT_TIMESTAMP | when the response was submitted |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 19. `survey_questions`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| question_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the question |
| code | VARCHAR(32) | UK | No |  | ARTA question code, e.g. CC1, SQD0 to SQD8 |
| section | ENUM('cc', 'sqd', 'app') |  | No | app | survey section the question belongs to |
| scale | ENUM('agree5', 'choice') |  | No | agree5 | answer type: 5-point agree scale or a choice list |
| text_fil | TEXT |  | No |  | question in Filipino |
| text_en | TEXT |  | No |  | question in English |
| hint_fil | VARCHAR(255) |  | Yes |  | help text in Filipino |
| hint_en | VARCHAR(255) |  | Yes |  | help text in English |
| options | JSON |  | Yes |  | answer choices, for choice questions (JSON) |
| show_if | JSON |  | Yes |  | when to show the question, based on an earlier answer (JSON) |
| allow_na | TINYINT(1) |  | No | 1 | whether "Not applicable" may be chosen |
| default_na | TINYINT(1) |  | No | 0 | whether "Not applicable" is pre-selected |
| required | TINYINT(1) |  | No | 1 | whether an answer is required |
| locked | TINYINT(1) |  | No | 0 | an ARTA question staff cannot delete |
| is_active | TINYINT(1) |  | No | 1 | whether the question is currently asked |
| sort_order | INT UNSIGNED |  | No | 0 | order in the survey |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 20. `feedback_answers`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| answer_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the answer |
| feedback_id | BIGINT UNSIGNED | FK | No |  | → `feedback.feedback_id` (deleted with it); the survey response this answer belongs to |
| question_id | BIGINT UNSIGNED | FK | Yes |  | → `survey_questions.question_id` (emptied if it is deleted); the question answered; empty once the question is deleted |
| code | VARCHAR(32) |  | No |  | question code copied at the time, so old answers survive the question |
| value | TINYINT |  | Yes |  | the answer given |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

*Unique together:* `feedback_id` + `code`

---

## 5. Staff and attendance (DTR)

### 21. `staff`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| staff_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the staff account |
| name | VARCHAR(255) |  | No |  | full name |
| email | VARCHAR(255) | UK | No |  | email used to sign in to the admin panel |
| password | VARCHAR(255) |  | No |  | hashed password (bcrypt) |
| must_change_password | TINYINT(1) |  | No | 0 | whether they must set a new password at next sign-in |
| password_changed_at | TIMESTAMP |  | Yes |  | when the password was last changed |
| role | ENUM('TourismHead', 'Administrator') |  | No | Administrator | TourismHead (Tourism office) or Administrator (museum staff); on screen: Tourism Head or Museum Staff |
| status | TINYINT(1) |  | No | 1 | 1 = active, 0 = disabled |
| remember_token | VARCHAR(100) |  | Yes |  | framework sign-in token |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 22. `staff_schedules`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| schedule_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the schedule row |
| staff_id | BIGINT UNSIGNED | FK | No |  | → `staff.staff_id` (deleted with it); the staff member |
| weekday | TINYINT UNSIGNED |  | No |  | day of the week, 0 = Sunday to 6 = Saturday |
| shift_start | TIME |  | No |  | time the shift starts |
| shift_end | TIME |  | No |  | time the shift ends |
| grace_minutes | SMALLINT UNSIGNED |  | No | 15 | minutes allowed before arrival counts as late |
| is_rest_day | TINYINT(1) |  | No | 0 | whether this day is a day off |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

*Unique together:* `staff_id` + `weekday`

### 23. `staff_attendance_days`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| work_date | DATE | PK | No |  | the working day |
| day_secret | VARCHAR(64) |  | No |  | secret inside that day's check-in QR; changes daily so an old photo of the QR fails |
| opened_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who opened the day |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 24. `staff_attendances`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| staff_attendance_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the time record |
| staff_id | BIGINT UNSIGNED | FK | No |  | → `staff.staff_id` (deleted with it); the staff member clocking in or out |
| work_date | DATE |  | No |  | the working day |
| type | ENUM('in', 'out') |  | No |  | time in or time out |
| scanned_at | TIMESTAMP |  | No | CURRENT_TIMESTAMP | exact time recorded |
| latitude | DECIMAL(10,7) |  | Yes |  | phone location when recorded |
| longitude | DECIMAL(10,7) |  | Yes |  | phone location when recorded |
| accuracy | INT |  | Yes |  | how accurate the phone location was, in metres |
| distance_m | INT |  | Yes |  | distance from the museum, in metres |
| method | ENUM('qr', 'manual') |  | No | qr | scanned QR or entered by hand |
| recorded_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who entered it by hand, if so |
| ip_address | VARCHAR(255) |  | Yes |  | network address of the device |
| user_agent | VARCHAR(512) |  | Yes |  | browser and device used |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

*Unique together:* `staff_id` + `work_date` + `type`

### 25. `attendance_corrections`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| correction_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the correction request |
| staff_id | BIGINT UNSIGNED | FK | No |  | → `staff.staff_id` (deleted with it); staff member whose time record is corrected |
| work_date | DATE |  | No |  | the working day to correct |
| type | ENUM('in', 'out') |  | No |  | time in or time out |
| requested_time | TIME |  | No |  | the time it should have been |
| reason | TEXT |  | No |  | why the correction is needed |
| requested_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); staff member who asked |
| requested_at | TIMESTAMP |  | No | CURRENT_TIMESTAMP | when it was asked |
| status | ENUM('Pending', 'Approved', 'Rejected') |  | No | Pending | decision on the request |
| reviewed_by | BIGINT UNSIGNED | FK | Yes |  | → `staff.staff_id` (emptied if it is deleted); Tourism office account that decided |
| reviewed_at | TIMESTAMP |  | Yes |  | when it was decided |
| review_note | TEXT |  | Yes |  | note from the reviewer |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

---

## 6. Museum settings and records

### 26. `museum_info`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| info_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number (the table has one row) |
| name | VARCHAR(255) |  | No | Museo de Baler | museum name |
| tagline | VARCHAR(255) |  | Yes |  | short line under the name |
| story | TEXT |  | Yes |  | museum history, first part (About page) |
| story2 | TEXT |  | Yes |  | museum history, second part |
| address | VARCHAR(255) |  | Yes |  | street address |
| hours | VARCHAR(255) |  | Yes |  | opening hours |
| closed_on | VARCHAR(255) |  | Yes |  | days the museum is closed |
| phone | VARCHAR(255) |  | Yes |  | contact number |
| email | VARCHAR(255) |  | Yes |  | contact email |
| report_logo | VARCHAR(255) |  | Yes |  | logo printed on exported reports |
| report_header_image | VARCHAR(255) |  | Yes |  | letterhead image on exported reports |
| admission_fee | DECIMAL(8,2) |  | No | 50.00 | standard admission fee, in pesos |
| resident_scope | VARCHAR(20) |  | No | baler | who enters free as a resident: baler or aurora |
| admission | VARCHAR(255) |  | Yes |  | admission text shown in the visitor app |
| latitude | DECIMAL(10,7) |  | Yes |  | museum location, centre of the geofence |
| longitude | DECIMAL(10,7) |  | Yes |  | museum location, centre of the geofence |
| geofence_radius_m | INT |  | No | 150 | how close, in metres, counts as at the museum |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 27. `logs`
Shown to staff as the **Activity Log** (page, menu and export).

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| log_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the log entry |
| user_id | BIGINT UNSIGNED |  | Yes |  | staff member who acted; deliberately not linked, so the log survives account removal |
| user_name | VARCHAR(255) |  | Yes |  | name of who acted, copied at the time |
| role | VARCHAR(255) |  | Yes |  | their role at the time |
| action | VARCHAR(255) |  | No |  | what was done, e.g. Visitor Registered |
| details | TEXT |  | Yes |  | details of the action |
| ip_address | VARCHAR(255) |  | Yes |  | network address it came from |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

### 28. `notifications`

| Column | Data type | Key | Null | Default | Description |
|---|---|---|---|---|---|
| notif_id | BIGINT UNSIGNED | PK | No | auto-increment | unique number of the notice |
| title | VARCHAR(255) |  | No |  | notice title shown in the visitor app |
| body | TEXT |  | Yes |  | notice text |
| type | VARCHAR(255) |  | No | info | kind of notice, e.g. info, promo |
| is_active | TINYINT(1) |  | No | 1 | whether it is currently shown |
| created_at | TIMESTAMP |  | Yes |  | when the row was created |
| updated_at | TIMESTAMP |  | Yes |  | when the row was last changed |

---

## Relationships (every foreign key)

| Parent (one) | Child (many) | Child column | When the parent is deleted |
|---|---|---|---|
| `admission_discounts` | `visitors` | discount_id | column is emptied, row kept |
| `categories` | `exhibits` | category_id | column is emptied, row kept |
| `exhibits` | `bookmarks` | exhibit_id | child rows are deleted |
| `exhibits` | `exhibit_images` | exhibit_id | child rows are deleted |
| `exhibits` | `exhibit_training_images` | exhibit_id | child rows are deleted |
| `exhibits` | `exhibit_translations` | exhibit_id | child rows are deleted |
| `exhibits` | `scans` | exhibit_id | child rows are deleted |
| `feedback` | `feedback_answers` | feedback_id | child rows are deleted |
| `museum_halls` | `exhibits` | hall_id | column is emptied, row kept |
| `staff` | `admission_payments` | recorded_by | column is emptied, row kept |
| `staff` | `attendance_corrections` | requested_by | column is emptied, row kept |
| `staff` | `attendance_corrections` | reviewed_by | column is emptied, row kept |
| `staff` | `attendance_corrections` | staff_id | child rows are deleted |
| `staff` | `feedback` | staff_id | column is emptied, row kept |
| `staff` | `staff_attendance_days` | opened_by | column is emptied, row kept |
| `staff` | `staff_attendances` | recorded_by | column is emptied, row kept |
| `staff` | `staff_attendances` | staff_id | child rows are deleted |
| `staff` | `staff_schedules` | staff_id | child rows are deleted |
| `staff` | `tours` | created_by | column is emptied, row kept |
| `staff` | `tours` | guide_staff_id | column is emptied, row kept |
| `staff` | `visit_groups` | refunded_by | column is emptied, row kept |
| `staff` | `visit_groups` | registered_by | column is emptied, row kept |
| `staff` | `visitors` | registered_by | column is emptied, row kept |
| `staff` | `visitors` | verified_by | column is emptied, row kept |
| `survey_questions` | `feedback_answers` | question_id | column is emptied, row kept |
| `tours` | `feedback` | tour_id | column is emptied, row kept |
| `visit_groups` | `admission_payments` | group_id | column is emptied, row kept |
| `visit_groups` | `tours` | group_id | column is emptied, row kept |
| `visit_groups` | `visitors` | group_id | column is emptied, row kept |
| `visit_groups` | `visits` | group_id | column is emptied, row kept |
| `visitors` | `admission_payments` | visitor_id | column is emptied, row kept |
| `visitors` | `attendances` | visitor_id | column is emptied, row kept |
| `visitors` | `bookmarks` | visitor_id | child rows are deleted |
| `visitors` | `feedback` | visitor_id | column is emptied, row kept |
| `visitors` | `scans` | visitor_id | column is emptied, row kept |
| `visitors` | `tours` | visitor_id | column is emptied, row kept |
| `visitors` | `visitor_email_verifications` | visitor_id | child rows are deleted |
| `visitors` | `visitor_password_resets` | visitor_id | child rows are deleted |
| `visitors` | `visitors` | companion_of | column is emptied, row kept |
| `visitors` | `visits` | visitor_id | column is emptied, row kept |
| `visits` | `admission_payments` | visit_id | column is emptied, row kept |

Joined by value, not by a foreign key: `staff_attendances.work_date` → `staff_attendance_days.work_date`.
