# WHMCS ↔ PKNIC web-panel registrar (`pknicweb`)

A WHMCS 8.x registrar module for `.pk`, `.com.pk`, `.net.pk`, `.org.pk`, `.biz.pk`, `.web.pk` and `.fam.pk` domains.
PKNIC offers no API or SRS access, so the module logs in to the PKNIC **web panel** with cURL and submits the same forms you would fill in by hand.

- Customer requests are **never** sent to PKNIC live. Register, renew and nameserver changes are validated, saved and **queued**.
  A cron runner then works through the queue one job at a time, waiting 3–5 s between jobs.
- **Every action is verified** by reading the panel back:
  - Nameservers must match what was requested.
  - A registered domain must show up in the account.
  - A renewal must move the expiry date forward.
- Failures are retried with backoff. After *Max retries* the job is marked failed and you get a ticket, an admin To-Do and an email.
  If the login fails twice in a row, all processing pauses until you resume it.
- Requirements: PHP 8.1+, WHMCS 8.x, cURL, DOM. No Composer.

```
modules/registrars/pknicweb/         registrar module (+ hooks.php, cron/process.php, config/pknic_map.php)
modules/addons/pknicweb_admin/       "PKNIC Jobs" admin page
docs/CAPTURE.md                      how to fill pknic_map.php from Chrome DevTools
tests/                               offline tests against saved HTML
```

## 1. Install

1. Copy `modules/registrars/pknicweb` and `modules/addons/pknicweb_admin` into your WHMCS `modules/` directory.
2. **Setup → Apps & Integrations → Domain Registrars** (or *System Settings → Domain Registrars*) → **PKNIC (Web Panel Automation)** → Activate, then fill in:

   | Setting | Notes |
   |---|---|
   | PKNIC Panel Base URL | e.g. `https://…` (no trailing slash). Paths in `pknic_map.php` are relative to it. |
   | Username / Password | Your panel login. The password is a `password` field, so WHMCS stores it encrypted. |
   | Ticket Department ID | Department for failure tickets (an internal/staff-only department is best). Empty = no ticket. |
   | Admin alert email | Gets failure and pause alerts. Admins subscribed to *System* emails also get them via WHMCS. |
   | Max retries | Default 3. |
   | Debug logging | Keeps the full (masked) HTML of the last 50 panel responses in `<storage>/debug/` and logs job steps to the Activity Log. Turn it off once things work. |
   | Storage path | Optional. A directory **outside the web root** for the cookie jar. Default: `<attachments_dir>/pknicweb` (created as 0700, cookie file 0600). |

3. **Setup → Addon Modules** → activate **PKNIC Jobs**, and grant access to your admin role.
   The tables `mod_pknicweb_jobs`, `mod_pknicweb_nameservers` and `mod_pknicweb_settings` are created automatically on first use.

## 2. Fill in `config/pknic_map.php`

All PKNIC-specific URLs, field names and messages live in this one file. Follow **[docs/CAPTURE.md](docs/CAPTURE.md)**.
Until a key needed for an action is filled in, jobs stay *pending* and processing pauses with
`pknic_map.php incomplete: '<key>' …`. Fix it, then click **Resume processing**.

`CheckAvailability` is only offered when you fill `availability_page` (and the availability texts).
Otherwise WHMCS keeps using its normal WHOIS lookup for `.pk`.

## 3. TLD setup

**Setup → Products/Services → Domain Pricing**: add each TLD (`.pk`, `.com.pk`, `.net.pk`, `.org.pk`, `.biz.pk`, `.web.pk`, `.fam.pk`),
set **Auto Registration** to **Pknicweb**, and set prices for the registration periods PKNIC allows.
Disable EPP code / ID protection / DNS management for these TLDs (the module does not implement them).

Name rules are enforced in the cart (`ShoppingCartValidateDomain`) and again in `RegisterDomain`:
- `a–z`, `0–9` and hyphen only, no hyphen at the start or end, at most 63 characters.
- Names directly under `.pk` need at least 4 characters before `.pk`.
- Custom nameservers on the cart's domain configuration step need at least 2 valid hostnames, with no duplicates.

## 4. Cron

Pick one (or both: a database lock, `GET_LOCK`, stops two runs from processing at the same time):

- **WHMCS cron** – nothing to do. `hooks.php` runs the queue on `AfterCronJob` (up to 8 jobs, about 150 s per run).
- **Dedicated cron** (recommended; keeps the WHMCS cron fast):
  ```
  */5 * * * * php -q /path/to/whmcs/modules/registrars/pknicweb/cron/process.php
  ```
  Options: `--max-jobs=20 --max-seconds=270`. It prints a one-line JSON summary.

The domain sync (`Sync`) runs in WHMCS's normal *Domain Sync* cron task. It reads the expiry date and status from PKNIC.

### Job lifecycle
`pending → processing → done`, or, on error, back to `pending` with a backoff of 5 → 15 → 45 … minutes (capped at 6 h).
Once *Max retries* is reached the job becomes `failed`, and a ticket, a To-Do and an email go out with everything needed to do it by hand.

- **Registration:** returns *Pending Registration* to WHMCS right away. When PKNIC confirms the domain, the module sets
  the domain *Active* with the real expiry and next due date (`UpdateClientDomain`).
  Before submitting, a retry checks whether the domain is already in the account, so it is never registered twice.
- **Renewal:** the job records the current expiry before submitting and only succeeds when the expiry moves forward.
  If the result is unclear (e.g. success text shown but the expiry is unchanged), the job fails straight to a ticket instead of retrying.
  This prevents a double renewal charge.
- **Nameservers:** saved locally first, so the client area shows the new values straight away.
  - A newer change supersedes older pending ones.
  - A change made while the registration is still pending is merged into the registration.
  - The job is only marked done when the nameservers read back from PKNIC match.
- **Login failures:** two failed logins in a row (cron, sync or nameserver lookups) **pause** all processing and alert you.
  Resume from the PKNIC Jobs page.

## 5. Test with one real domain

1. Fill the login keys in `pknic_map.php`, then click **Addons → PKNIC Jobs → Test PKNIC login**.
2. Fill the `domain_info_page` keys. In WHMCS, open a .pk domain you already own that uses this registrar, and check that the
   nameservers load. On the domain page, run **Sync** and confirm the expiry date.
3. Fill the `ns_update_*` keys, change that domain's nameservers, then run the cron (`php …/cron/process.php`)
   and watch the job become `done`.
4. Only then fill `register_*` / `renew_*` and place one real test order. Watch the job and **System Logs → Module Log**.

## 6. Troubleshooting

- **Utilities → Logs → Module Log**: enable *Module Logging*. Every panel request appears under module `pknicweb`:
  method, URL, posted fields and the first ~3000 characters of the response.
  The password and session cookie values are replaced with `********`.
- **Utilities → Logs → Activity Log**: queue events (`PKNIC: …`).
- **Addons → PKNIC Jobs**: filter by status. Each job shows `last_error` and the payload.
  Buttons: *Retry*, *Mark done (manual)*, *Cancel*, *Test PKNIC login*, *Resume processing*.
- With **Debug logging** on, `<storage>/debug/*.html` holds full panel pages. Use them to fix selectors.

| Symptom | Likely cause |
|---|---|
| `paused: pknic_map.php incomplete: 'x'` | That key still contains `TODO`. |
| `PKNIC login failed: logged-in marker … not found` | Wrong `logged_in_marker` or login field names, or wrong credentials. Check the Module Log. |
| `Form 'form#x' not found on …` | Wrong `*_form_selector`, or the page URL is wrong (check the logged response). |
| `Nameserver verification failed: requested […], PKNIC shows []` | `ns_parse_selector` does not match. Test it against a saved page (see CAPTURE.md). |
| `Registration not confirmed …` | `register_success_text` / `domain_list_page` do not match, or PKNIC holds the order for approval. |

## 7. Tests

```bash
php tests/run.php
```
Runs `PknicClient` and `PknicActions` against `tests/fixtures/*.html` through a fake transport. It covers login with CSRF tokens,
re-login, form merging, selectors, verification, idempotent retries, log masking and validation. No network or WHMCS needed.
