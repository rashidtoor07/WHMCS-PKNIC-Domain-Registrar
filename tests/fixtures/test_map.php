<?php

// Map matching the fake panel in tests/fixtures/*.html. Not real PKNIC values.
return [
    'login_page' => '/login.php',
    'login_form_selector' => 'form#loginForm',
    'login_fields' => ['username' => 'user_name', 'password' => 'user_pass'],
    'login_extra_fields' => ['btnLogin' => 'Sign in'],
    'logged_in_check_page' => '/dashboard.php',
    'logged_in_marker' => 'Logout',
    'login_failed_text' => ['Invalid username or password'],

    'domain_list_page' => '/domains.php',
    'domain_id_regex' => '#domain\.php\?id=(\d+)"[^>]*>\s*{domain}\s*<#i',

    'domain_info_page' => '/domain.php?id={id}',
    'domain_not_found_text' => ['No such domain'],
    'ns_parse_selector' => 'table.details td:contains("Name Servers") + td',
    'expiry_parse_selector' => '//table[@class="details"]//td[contains(., "Expiry Date")]/following-sibling::td[1]',
    'date_format' => 'd-m-Y',
    'status_parse_selector' => 'table.details td:contains("Status") + td',
    'status_active_text' => ['Active'],
    'status_expired_text' => ['Expired'],

    'register_page' => '/register.php',
    'register_form_selector' => 'form#regForm',
    'register_fields' => [
        'domain' => 'domain_name',
        'regperiod' => ['field' => 'years', 'transform' => fn ($v) => $v . 'Y'],
        'fullname' => 'reg_name',
        'ns1' => 'ns1',
        'ns2' => 'ns2',
        'additionalfields.CNIC' => 'cnic',
    ],
    'register_static_fields' => ['agree' => '1'],
    'register_confirm_form_selector' => 'form#confirmForm',
    'register_success_text' => ['has been registered'],

    'renew_page' => '/renew.php?id={id}',
    'renew_form_selector' => 'form',
    'renew_fields' => ['regperiod' => 'years'],
    'renew_success_text' => ['renewed successfully'],

    'ns_update_page' => '/ns.php?id={id}',
    'ns_update_form_selector' => '#nsForm',
    'ns_fields' => ['ns1' => 'ns1', 'ns2' => 'ns2', 'ns3' => 'ns3', 'ns4' => 'ns4'],
    'ns_update_success_text' => ['Nameservers updated'],

    'availability_page' => '/whois.php?q={domain}',
    'availability_available_text' => ['is available'],
    'availability_taken_text' => ['is not available'],

    'success_text' => ['Success'],
    'error_text' => ['Domain is locked', 'Insufficient balance'],
    'error_message_selector' => '.alert-danger',
];
