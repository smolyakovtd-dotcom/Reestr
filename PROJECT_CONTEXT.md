# PROJECT_CONTEXT (memory for next session)

Updated: 2026-03-01
Branch in work: `feature/scans-upload-role-scanner`
Remote: `origin https://github.com/smolyakovtd-dotcom/Reestr`

## 1) What was implemented

- Reworked auth to role-based passwords:
  - `warehouse` => `summer` (full rights)
  - `viewer` => `winter` (read only)
  - `scanner` => `spring` (read + scan upload/delete only)
- Added scan feature:
  - DB table: `registry_scans`
  - Upload scan for registry row
  - Delete scan
  - Download scan
  - UI column in registry table with file list and upload form
- Added scans config:
  - `SCANS_STORAGE_PATH`
  - `SCANS_MAX_FILE_SIZE`
- Added docs:
  - `SCANS_SETUP.md` with network folder setup

## 2) Critical production issues encountered and fixes

### A) HTTP 500 on production (`reestr.tw1.ru`)

Root causes found:

1. `strict_types` fatal due to UTF-8 BOM at file start.
   - Error example from diagnostics:
     - `strict_types declaration must be the very first statement`
   - This happened for `index.php`, later also `config.php`.
2. Potential fatal from `CREATE TABLE` at runtime without DB permissions.
   - `ensureScansTable()` initially could crash whole app.

Fixes done:

- Removed BOM from entry files (`index.php`, `config.php`, `diagnostics.php`).
- Wrapped scans table creation in `try/catch` and disabled scans safely if unavailable.
- Added safe behavior:
  - if scans are unavailable, app should not crash, scans actions return controlled response.

## 3) Important commits in this branch

- `e27f480` Add scanner-only scan upload/delete feature and setup docs
- `b07293e` Prevent HTTP 500 when scans table creation is unavailable
- `32f1d1c` Replace arrow functions for wider PHP compatibility
- `2cc29a3` Remove UTF-8 BOM from index.php to fix strict_types fatal
- `b22f1bd` Restore broken Cyrillic text encoding in UI and messages
- `06e851f` Remove UTF-8 BOM from PHP entry files

Current head at the time of writing this file may be newer; check:

```bash
git log --oneline -n 10
```

## 4) STILL OPEN / known issues

Important: encoding is mostly repaired, but there are still leftovers.

Known confirmed mojibake line:

- `index.php:663`
  - currently shows broken text in print page period line:
  - `Р·Р° РїРµСЂРёРѕРґ ...`
  - should be replaced with: `за период ...`

Notes:

- There may be additional isolated encoding leftovers not yet found.
- If UI shows "кракозябры", inspect affected line and patch directly.

## 5) Diagnostics file

Added helper file:

- `diagnostics.php`

Purpose:

- shows PHP version/extensions
- checks config and scans path
- tries loading `index.php` and prints fatal details

Security note:

- keep only for debugging, remove from production after stabilization.

## 6) Deployment notes (very important)

### Encoding/BOM rule

For all PHP files:

- save as `UTF-8` **without BOM**.

If BOM appears, production can fail immediately because of `declare(strict_types=1)`.

### Cache

After deployment:

- hard reload browser (`Ctrl+F5`).

### Quick check after deploy

1. Open login page and verify normal Cyrillic.
2. Login as `scanner`:
   - upload scan
   - delete scan
3. Login as `viewer`/`warehouse`:
   - ensure no upload/delete buttons for scans.
4. Open print page and verify period line text (currently known broken line).

## 7) New computer start checklist

On another PC:

```bash
git clone https://github.com/smolyakovtd-dotcom/Reestr.git
cd Reestr
git checkout feature/scans-upload-role-scanner
git pull
git log --oneline -n 15
```

If branch already exists locally:

```bash
git fetch origin
git checkout feature/scans-upload-role-scanner
git pull
```

## 8) Security/tech debt reminders

- Sensitive credentials are in `config.php` (DB/SMTP/passwords).
- For safer production:
  - move secrets to env vars/outside web root config
  - disable `display_errors` on production
  - restrict access to diagnostics files

## 9) Immediate next fix recommended

Patch `index.php:663` from mojibake to normal Russian and redeploy.
