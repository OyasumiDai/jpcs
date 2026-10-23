# Student Masterlist

Advisers and officers upload each section's official student list for a batch (school year).
The list is then used across the system:

| Where | What the masterlist does |
|---|---|
| Student sign-up (`student_register.php`) | Once the masterlist has any students, only listed Student IDs can create an account. The name and course/year are taken from the list. While the masterlist is empty, sign-up works as before. |
| Event registration (`registration.php`) | A signed-in student's form is filled in from their own masterlist entry. Registrations from listed students are confirmed straight away (control number, QR code, pass and email), but only for the student's own ID or when an officer/adviser registers them. |
| Pending review (`pending.php`) | Each pending registration shows **On masterlist** or **Not on masterlist**. If the typed name differs, the listed name is shown under it. |

Lookups use each student's newest batch.

## Setup

1. **Database.** The tables are created automatically the first time someone opens
   *Student Masterlist*. To create them yourself, run `database/student_masterlist.sql`
   in phpMyAdmin (database `jpcs_event_system`).
2. **PDF reading.** PDFs are read with [smalot/pdfparser](https://github.com/smalot/pdfparser),
   installed with Composer into `vendor/`:
   ```
   php composer.phar install
   ```
   If `vendor/` is missing, PDF uploads show a message asking for Excel/CSV instead;
   Excel and CSV need no extra libraries (PHP's built-in `zip`, `SimpleXML` and `fileinfo`).
   Commit `vendor/` if the server cannot run Composer, or run `composer install` after each pull.
3. **Officer access.** An adviser opens *Student Masterlist › Section access* and ticks the
   sections each officer may manage. Officers with no sections see a message instead of the upload form.
4. **Email.** Auto-confirmation sends the same confirmation email as pending review. The Gmail
   sender lives in `includes/mail_config.php`; set `JPCS_GMAIL_ADDRESS` and
   `JPCS_GMAIL_APP_PASSWORD` as environment variables to keep the App Password out of the code.

### Optional: OCR for scanned PDFs

Scanned PDFs have no text layer. To read them, install
[Poppler](https://github.com/oschwartz10612/poppler-windows/releases) (`pdftoppm`) and
[Tesseract](https://github.com/UB-Mannheim/tesseract/wiki), then set, for Apache in XAMPP
(`httpd.conf`) or the system environment:

```
JPCS_PDFTOPPM=C:\poppler\Library\bin\pdftoppm.exe
JPCS_TESSERACT=C:\Program Files\Tesseract-OCR\tesseract.exe
```

OCR output is less reliable than a text PDF, so always review the preview carefully.
This path has not been tested on this machine yet.

## Using it

1. **Upload**: choose the batch, year level and section, then a PDF, Excel (.xlsx) or CSV file (max 10 MB, 5,000 rows).
2. **Preview**: every row is shown with its status — *New*, *Will update*, *Duplicate ID*,
   *Invalid ID*, *Missing name* or *Other section*. Edit any cell, or untick *Keep* to leave a row out.
   Problem rows are left out until fixed.
3. **Confirm & Save**: rows are re-checked on the server and saved in one transaction.
   The result (added / updated / skipped, with reasons) is logged in *Upload history*.
4. **Masterlist** tab: students grouped by batch › year level › section, alphabetical, with
   filters, search, edit and delete; eight sections per page.

## Data rules

- **Student ID No.** is stored as digits only, so `2024-00253` and `202400253` are the same student.
  It must be 7–12 digits. Change `MASTERLIST_ID_PATTERN` in `includes/masterlist.php` if your school uses another format.
- **Names** are trimmed and title-cased: `DELA CRUZ,  juan m` → `Dela Cruz, Juan M.`. Names without a comma keep their order.
- **Batches** run June to May (October 2026 is the 2026-2027 batch). A student has one row per batch;
  uploading the same ID again in the same batch updates it.
- **Sections** are `BSIT {1st–4th} Year - {A–Z}`. Courses and year levels are set by
  `MASTERLIST_COURSES` and `MASTERLIST_YEAR_LEVELS`; the event registration form uses the same list.

## Edge cases

| Case | Behaviour |
|---|---|
| Scanned PDF | Rejected with a message (or read by OCR when configured). |
| PDF table cells on separate lines | An ID line followed by a name line is joined into one row. Row numbers, trailing course/section and sex columns are stripped. |
| Excel IDs stored as numbers (`2.02400253E8`) | Converted back to the full ID. |
| Excel with "Last Name / First Name / MI" columns | Combined into `Last, First M.` |
| `.xls` (old Excel) | Rejected with instructions to save as `.xlsx`. |
| Duplicate ID inside one file | The first row is kept; later ones are flagged and skipped. |
| ID already listed in a section the officer does not manage | Shown as *Other section* and never changed. |
| Large files | Limited to 10 MB / 5,000 rows; the preview posts as a single field so PHP's `max_input_vars` (1000) is not exceeded. |
| Forged requests | Every action re-checks the CSRF token, the user's role and their sections on the server. |
