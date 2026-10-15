<?php

/**
 * Everything specific to the PKNIC web panel lives in this file.
 * Fill each "TODO" from Chrome DevTools - see docs/CAPTURE.md. Nothing here is a real PKNIC value.
 *
 * Paths are relative to the "PKNIC Panel Base URL" module setting (absolute URLs also work).
 * Placeholders usable in page paths: {domain} (example.com.pk), {sld} (example), {tld} (com.pk),
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
    'login_page'           => '/TODO',            // TODO: fill from DevTools - URL of the login form page
    'login_form_selector'  => 'form',             // TODO: e.g. 'form#loginForm' if the page has several forms
    'login_fields'         => [
        'username' => 'TODO_field',               // TODO: name="" of the username input
        'password' => 'TODO_field',               // TODO: name="" of the password input
    ],
    'login_extra_fields'   => [],                 // TODO (optional): e.g. ['btnLogin' => 'Login'] if the submit button's name/value is required
    'logged_in_check_page' => '/TODO',            // TODO: a page only reachable when logged in (dashboard / domain list)
    'logged_in_marker'     => 'Logout',           // TODO: confirm - text only visible when logged in
    'login_page_marker'    => '',                 // optional: text unique to the login page; default detects a password input
    'login_failed_text'    => ['TODO'],           // TODO: e.g. the "invalid username or password" message

    // ---------------------------------------------------------------- domain lookup inside the account
    // Used to confirm a registration and, if pages use an internal id, to find {id}.
    'domain_list_page'     => '',                 // TODO (optional): e.g. '/TODO/domains?search={domain}'
    'domain_id_regex'      => '',                 // TODO (only if a page path uses {id}): e.g. '#domain\.php\?id=(\d+)"[^>]*>\s*{domain}#i'

    // ---------------------------------------------------------------- domain detail page (read back)
    'domain_info_page'      => '/TODO?domain={domain}', // TODO: fill from DevTools
    'domain_not_found_text' => ['TODO'],          // TODO: message shown when the domain is not in the account
    'ns_parse_selector'     => 'TODO',            // TODO: element(s) holding the nameservers
    'expiry_parse_selector' => 'TODO',            // TODO: element holding the expiry date
    'date_format'           => 'TODO',            // TODO: PHP date format of that text, e.g. 'd-m-Y' or 'Y-m-d' or 'd M Y'
    'status_parse_selector' => '',                // optional: element holding the domain status
    'status_active_text'    => ['Active'],        // TODO: confirm
    'status_expired_text'   => ['Expired'],       // TODO: confirm

    // ---------------------------------------------------------------- register
    'register_page'          => '/TODO',          // TODO: fill from DevTools
    'register_form_selector' => 'form',           // TODO
    // whmcs/payload key => pknic field name. Payload keys: domain, sld, tld, regperiod, ns1..ns5,
    // firstname, lastname, fullname, companyname, email, address1, address2, city, state, postcode,
    // countrycode, countryname, fullphonenumber, admin* equivalents, additionalfields.<Field Name>.
    // A value may also be ['field' => 'name', 'default' => 'x', 'transform' => fn ($v, $data) => ...].
    'register_fields'        => [
        'domain'    => 'TODO_field',
        'regperiod' => 'TODO_field',
        'ns1'       => 'TODO_field',
        'ns2'       => 'TODO_field',
        // 'fullname'        => 'TODO_field',
        // 'email'           => 'TODO_field',
        // 'address1'        => 'TODO_field',
        // 'city'            => 'TODO_field',
        // 'fullphonenumber' => 'TODO_field',
    ],
    'register_static_fields'         => [],       // fixed extra fields, e.g. ['agree' => '1', 'submit' => 'Register']
    'register_confirm_form_selector' => '',       // optional: a second "confirm" form shown after the first submit
    'register_confirm_static_fields' => [],
    'register_success_text'          => ['TODO'], // TODO
    'register_error_text'            => [],       // TODO (optional): e.g. 'not available', 'already registered'

    // ---------------------------------------------------------------- renew
    'renew_page'                  => '/TODO?domain={domain}', // TODO: fill from DevTools
    'renew_form_selector'         => 'form',      // TODO
    'renew_fields'                => [
        'regperiod' => 'TODO_field',              // TODO: years field (remove the line if there is none)
    ],
    'renew_static_fields'         => [],
    'renew_confirm_form_selector' => '',
    'renew_confirm_static_fields' => [],
    'renew_success_text'          => ['TODO'],    // TODO
    'renew_error_text'            => [],
    'renew_verify_expiry'         => true,        // require the expiry date to move forward before marking done

    // ---------------------------------------------------------------- nameservers
    'ns_update_page'                  => '/TODO?domain={domain}', // TODO: fill from DevTools
    'ns_update_form_selector'         => 'form',  // TODO
    'ns_fields'                       => [
        'ns1' => 'TODO',
        'ns2' => 'TODO',
        'ns3' => 'TODO',
        'ns4' => 'TODO',
        // 'ns5' => 'TODO',
    ],
    'ns_update_static_fields'         => [],
    'ns_update_confirm_form_selector' => '',
    'ns_update_confirm_static_fields' => [],
    'ns_update_success_text'          => ['TODO'],
    'ns_update_error_text'            => [],

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
    'error_message_selector' => '',               // optional: element holding the error message, e.g. '.alert-danger'

    'allowed_tlds' => ['pk', 'com.pk', 'net.pk', 'org.pk', 'biz.pk', 'web.pk', 'fam.pk'],
];
