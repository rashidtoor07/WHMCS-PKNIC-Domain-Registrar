# Capturing PKNIC panel details for `pknic_map.php`

The module does not know any real PKNIC URL or field name. Fill them in once, from your own browser,
for each action. Every value still containing `TODO` is treated as missing: the queue pauses and says which key is missing.

## 1. Record one action in Chrome DevTools

1. Open the PKNIC panel in Chrome, **log out** first (so you can capture the login too).
2. Press `F12` → **Network** tab → tick **Preserve log** and **Disable cache**. Filter: **Doc** (plus **Fetch/XHR** if a form submits via JavaScript).
3. Perform the action once by hand: log in, open a domain, change nameservers, renew, register…
4. Click the request that submitted the form (method **POST**, usually the one just before the redirect).
   - **Headers** tab → *Request URL* = where the form posts. You normally don't need this: the module reads the `action` from the form itself.
   - **Payload** tab → *Form Data* = every field name the browser sent. These are the names you map.
5. Right-click the request → **Copy → Copy as cURL (bash)** and paste it into a text file. It shows every field and value in one place.
   **It contains your session cookie and password: don't share it, delete it when done.**
6. Click the GET request of the page that *contained* the form (e.g. the "Modify nameservers" page). Its URL is what goes into `*_page`.
   Right-click the form in the page → **Inspect** to find a selector (`id="..."` → `form#thatId`).

## 2. What to copy where

| What you see | Map key | Example (not real) |
|---|---|---|
| URL of the login page | `login_page` | `/index.php?page=login` |
| `<form id="frmLogin">` | `login_form_selector` | `form#frmLogin` |
| Form Data `txtUser=…&txtPass=…` | `login_fields` | `['username' => 'txtUser', 'password' => 'txtPass']` |
| Submit button sent in Form Data (`btnLogin=Login`) | `login_extra_fields` | `['btnLogin' => 'Login']` |
| A page only shown when logged in | `logged_in_check_page` | `/index.php?page=dashboard` |
| Text only shown when logged in | `logged_in_marker` | `Logout` |
| Wrong-password message | `login_failed_text` | `['Invalid login']` |
| Domain details page URL, domain swapped for `{domain}` | `domain_info_page` | `/domain.php?name={domain}` |
| Element containing the nameservers | `ns_parse_selector` | `td:contains("Name Server") + td` |
| Element containing the expiry date | `expiry_parse_selector` | `//th[.="Expiry"]/following-sibling::td[1]` |
| How that date is written | `date_format` | `d-m-Y` for `15-10-2027` |
| Nameserver form page / fields | `ns_update_page`, `ns_update_form_selector`, `ns_fields` | `['ns1' => 'txtNS1', 'ns2' => 'txtNS2', …]` |
| Message after a successful change | `ns_update_success_text` | `['updated successfully']` |
| Error messages you have seen | `error_text` / `*_error_text` | `['Insufficient balance']` |

Hidden inputs (CSRF tokens, `__VIEWSTATE`, ids…) do **not** need mapping. The module loads the page, copies
every hidden input and every pre-filled value, then overlays only the fields you map.

### Pages that use an internal id instead of the domain name
If URLs look like `domain.php?id=4711`, use `{id}` in the page paths and set:
```php
'domain_list_page' => '/domains.php?search={domain}',
'domain_id_regex'  => '#domain\.php\?id=(\d+)"[^>]*>\s*{domain}#i', // group 1 = id
```

### Two-step forms
If a submit shows a "Please confirm" page with another button, set `register_confirm_form_selector`
(or `renew_…` / `ns_update_…`). Add the button name/value to `*_confirm_static_fields` if it appears in Form Data.

### Value transforms
If the panel wants `1 Year` instead of `1`:
```php
'regperiod' => ['field' => 'ddlYears', 'transform' => fn ($v) => $v . ' Year'],
```
Additional domain fields from WHMCS are available as `additionalfields.<Field Name>`.

## 3. Selectors

- **CSS-like:** `tag`, `#id`, `.class`, `[name="x"]`, `[name^=ns]`, `:contains("text")`, `:first-child`,
  `:nth-child(2)`, combinators ` ` `>` `+` `~`, and `,` for alternatives.
  For label/value tables, `td:contains("Expiry") + td` is usually enough.
- **XPath:** anything starting with `/`, `./`, `(` or `xpath:`.
  In DevTools → Elements, right-click a node → **Copy → Copy XPath**, then make it less brittle (ids rather than positions).
- **Regex:** `regex:#Expiry Date:\s*([0-9-]+)#`. Capture group 1 is used.

Nameserver selectors may match one element per nameserver, or one element with all of them (`<br>`, commas, spaces).
Hostnames are extracted from the text either way.

## 4. Check your work offline

1. Turn on **Debug logging** in the module settings, run one action, then look in `<storage dir>/debug/`.
   It holds the full HTML of recent panel responses, with the password and cookies masked.
2. Copy a page into `tests/fixtures/`, point a copy of `tests/fixtures/test_map.php` at it, and run `php tests/run.php`.
   You can also test a selector quickly:
   ```bash
   php -r 'require "modules/registrars/pknicweb/lib/bootstrap.php"; print_r(PknicWeb\Selector::texts(file_get_contents($argv[1]), $argv[2]));' page.html 'td:contains("Expiry") + td'
   ```
