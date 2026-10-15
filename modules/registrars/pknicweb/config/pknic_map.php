<?php

/**
 * Everything specific to the PKNIC web panel lives in this file.
 * Fill each "TODO" from Chrome DevTools - see docs/CAPTURE.md. Nothing here is a real PKNIC value.
 *
 * Paths are relative to the "PKNIC Panel Base URL" module setting (absolute URLs also work).
 * For PKNIC set that setting to: https://pk6.pknic.net.pk
 * Placeholders usable in page paths: {domain} (example.com.pk), {sld} (example), {tld} (com.pk),
 * {domain_b64} (base64 of the domain, as in displayDomain.PK?seq=...),
 * {id} (panel's internal domain id, looked up via domain_list_page + domain_id_regex).
 *
 * Selectors accept:
 *   CSS-like: 'table.info td:contains("Expiry") + td', '#nsForm', 'input[name="ns1"]'
 *   XPath:    '//th[contains(., "Expiry")]/following-sibling::td[1]'
 *   Regex:    'regex:#Expiry Date:\s*([0-9-]+)#'  (capture group 1)
 *
 * Text lists are matched case-insensitively against the visible page text.
 * A value that still contains "TODO" counts as not configured; the queue pauses with a clear message.
 */

return [
    // ---------------------------------------------------------------- login
    'login_page'           => '/pk5/login.PK',    // https://pk6.pknic.net.pk/pk5/login.PK
    'login_form_selector'  => 'form',             // TODO: e.g. 'form#loginForm' if the page has several forms
    'login_fields'         => [
        'username' => 'TODO_field',               // TODO: name="" of the username input
        'password' => 'TODO_field',               // TODO: name="" of the password input
    ],
    'login_extra_fields'   => [],                 // TODO (optional): e.g. ['btnLogin' => 'Login'] if the submit button's name/value is required
    'logged_in_check_page' => '/pk5/userDomains.PK', // "My Domains" - only reachable when logged in
    // Button on the My Domains page. Pages are checked for this only when it matters (login check page / after login).
    'logged_in_marker'     => 'Search in My Domains',
    'login_page_marker'    => '',                 // optional: text unique to the login page; default detects a password input
    'login_failed_text'    => ['TODO'],           // TODO: e.g. the "invalid username or password" message

    // ---------------------------------------------------------------- My Domains (userDomains.PK)
    // Columns: [checkbox] Domain | Registrant | Created | Expiry | Nameservers. The list cuts nameservers to
    // 20 characters, so they are read from the domain's own page (the link on its name) instead.
    'domain_list_page'            => '/pk5/userDomains.PK',
    // "Search in My Domains" box: needed once the list has more than one page. Fill from DevTools.
    'domain_search_field'         => 'TODO_field',  // TODO: name="" of the search text box
    'domain_search_form_selector' => 'form',        // TODO: confirm (the search form is the first form on the page?)
    'domain_search_static_fields' => [],
    'domain_id_regex'             => '',            // not needed: pages are reached via the domain's link ({link})

    // ---------------------------------------------------------------- reading a domain back
    // Domain record page, e.g. displayDomain.PK?seq=cG9zbHkucGs= (seq = base64 of "posly.pk").
    // Presence in the account is still decided by My Domains above (account-scoped).
    'domain_info_page'      => '/pk5/displayDomain.PK?seq={domain_b64}',
    'domain_not_found_text' => [],                  // TODO (optional): text this page shows for an unknown domain
    // "Expire Date:  Aug 15, 2028"
    'expiry_parse_selector' => '//*[normalize-space(.)="Expire Date:"]/following-sibling::*[1]',
    'date_format'           => 'M d, Y',
    'status_parse_selector' => '',                  // no status shown: active/expired is derived from the expiry date
    'status_active_text'    => ['Active'],
    'status_expired_text'   => ['Expired'],
    // Everything between the "Nameservers" label and the "Need A-Record CNAME TXT?" button. Hostnames are
    // extracted from it; the set id (e.g. 450779) and the Edit / Re-Assign buttons are ignored.
    'ns_info_page'          => '',                  // same page
    'ns_parse_selector'     => 'regex:#>\s*Nameservers\s*<(.*?)(?:Need A-Record|</table>)#is',

    // ---------------------------------------------------------------- register
    // "Domain Application for .pk" form (menu: Register Domain). Labels below are from that page;
    // the name="" of each input must be read in DevTools (right-click the box > Inspect).
    'register_page'          => '/pk5/register.PK', // "Domain Application for .pk"
    'register_form_selector' => 'form',           // TODO: e.g. 'form[name="..."]' if the page has more than one form
    // payload key => pknic field. Payload keys: domain, sld, tld, regperiod, ns1..ns5, nameservers (list),
    // firstname, lastname, fullname, companyname, email, address1, address2, city, state, postcode,
    // countrycode, countryname, fullphonenumber, admin* equivalents, additionalfields.<Field Name>.
    // Spec forms: 'field_name'
    //             ['field' => 'name', 'default' => 'x', 'transform' => fn ($v, $data) => ...]
    //             ['field' => 'name', 'select' => true, 'also' => ['other_key']]   pick a <select> option by value/text
    //             ['field' => 'name', 'select' => 'nameservers']                    pick the nameserver set listing exactly these hosts
    'register_fields'        => [
        'domain'          => 'TODO_field',        // "Domain Names" (textarea; one domain per job)
        'companyname'     => [                    // "Organization" (The Domain Owner) - falls back to the person's name
            'field' => 'TODO_field',
            'transform' => fn ($v, $d) => trim((string) $v) !== '' ? $v : ($d['fullname'] ?? ''),
        ],
        'fullname'        => 'TODO_field',        // "Contact Person"
        'role'            => ['field' => 'TODO_field', 'default' => 'Domain Owner'], // "Role Name"
        'address1'        => [                    // "Address"
            'field' => 'TODO_field',
            'transform' => fn ($v, $d) => trim($v . ' ' . ($d['address2'] ?? '')),
        ],
        'city'            => 'TODO_field',        // "City"
        'countryname'     => ['field' => 'TODO_field', 'select' => true, 'also' => ['countrycode']], // "Country" dropdown
        'state'           => 'TODO_field',        // "State/Province"
        'postcode'        => 'TODO_field',        // "Zip/Postal Code"
        'email'           => 'TODO_field',        // "Email Address"
        'fullphonenumber' => 'TODO_field',        // "Phone Number" (WHMCS format +92.3001234567)
        // "DNS Nameserver" dropdown of saved nameserver sets. If no set lists exactly the ordered
        // nameservers, the application is sent without one and a nameserver job follows.
        'nameservers'     => ['field' => 'TODO_field', 'select' => 'nameservers'],
        // "Group Tag" is optional; "Technical Contact" / "Billing Contact" keep their default selection.
    ],
    'register_static_fields'         => [
        // TODO: the "I agree to the terms..." checkbox and the submit button as they appear in Form Data, e.g.
        // 'agree_field_name' => 'on',
        // 'submit_button_name' => 'Submit Domain Application',
    ],
    // After "Submit Domain Application" PKNIC shows a confirmation page; registration happens on its Confirm button.
    'register_confirm_form_selector' => 'form',   // TODO: confirm, e.g. 'form[name="..."]' if that page has several forms
    'register_confirm_static_fields' => [
        // TODO: the Confirm button as it appears in Form Data, e.g. 'confirm_button_name' => 'Confirm',
    ],
    'register_success_text'          => ['TODO'], // TODO: message shown after clicking Confirm
    'register_error_text'            => [],       // TODO (optional): e.g. 'not available', 'already registered'
    'register_confirm_hours'         => 72,       // how long to wait for the domain to appear in My Domains before escalating

    // ---------------------------------------------------------------- renew
    // The PKNIC panel has no renew form. 'manual' = the job records the current expiry, alerts the admin
    // (To-Do, email, ticket if configured), then checks every renew_manual_check_minutes and completes by
    // itself once PKNIC shows a later expiry date. After renew_manual_days without change it escalates.
    'renew_page'                 => 'manual',
    'renew_manual_check_minutes' => 180,
    'renew_manual_days'          => 14,
    // Used only if renew_page is ever a real form:
    'renew_form_selector'         => 'form',
    'renew_fields'                => [],
    'renew_static_fields'         => [],
    'renew_confirm_form_selector' => '',
    'renew_confirm_static_fields' => [],
    'renew_success_text'          => [],
    'renew_error_text'            => [],
    'renew_verify_expiry'         => true,

    // ---------------------------------------------------------------- nameservers
    // On the domain record page the nameservers are a SET (e.g. 450779) with "Edit" and "Re-Assign".
    // NEVER map "Edit": it changes the set itself, i.e. every domain that shares it.
    // Use "Re-Assign", which points only this domain at another set (created first via ns_set_add_* if needed).
    'ns_update_page'                  => '/pk5/TODO?seq={domain_b64}', // TODO: URL behind the nameserver "Re-Assign" button
    'ns_update_form_selector'         => 'form',  // TODO
    'ns_fields'                       => [
        'nameservers' => ['field' => 'TODO_field', 'select' => 'nameservers'], // TODO: name="" of the set dropdown
    ],
    'ns_update_static_fields'         => [],      // TODO: the Re-Assign submit button from Form Data
    'ns_update_confirm_form_selector' => '',
    'ns_update_confirm_static_fields' => [],
    'ns_update_success_text'          => ['TODO'],
    'ns_update_error_text'            => [],

    // ---------------------------------------------------------------- nameserver sets (optional)
    // PKNIC picks nameservers from saved sets (the "Add" link next to the DNS Nameserver dropdown).
    // Fill these to let the module create a missing set automatically; otherwise it opens a ticket.
    'ns_set_add_page'          => '',             // TODO (optional): URL behind the "Add" link
    'ns_set_add_form_selector' => 'form',
    'ns_set_add_fields'        => [               // keys: set_name, ns1..ns5, nameservers, domain
        // 'set_name' => 'TODO_field',
        // 'ns1' => 'TODO_field',
        // 'ns2' => 'TODO_field',
    ],
    'ns_set_add_static_fields' => [],
    'ns_set_add_error_text'    => [],

    // ---------------------------------------------------------------- availability (optional)
    // Leave availability_page empty and the module does not offer CheckAvailability.
    'availability_page'           => '',          // optional, e.g. '/TODO/whois?domain={domain}'
    'availability_requires_login' => true,
    'availability_form_selector'  => '',          // optional: submit a search form instead of a plain GET
    'availability_fields'         => [],          // e.g. ['domain' => 'TODO_field']
    'availability_available_text' => [],         // e.g. 'is available'
    'availability_taken_text'     => [],          // e.g. 'is not available', 'already registered'

    // ---------------------------------------------------------------- shared texts
    'success_text'           => ['TODO'],         // fallback when an action has no own *_success_text
    'error_text'             => ['TODO'],         // known error messages, checked after every action
    'error_message_selector' => '',
    // Text PKNIC shows when the account balance is too low (on the confirmation page or after Confirm).
    // Jobs then wait and retry hourly without using up retries; the admin gets one alert every 6 hours.
    'insufficient_balance_text' => ['TODO'],      // TODO: e.g. 'insufficient balance'               // optional: element holding the error message, e.g. '.alert-danger'

    'allowed_tlds' => ['pk', 'com.pk', 'net.pk', 'org.pk', 'biz.pk', 'web.pk', 'fam.pk'],
];
