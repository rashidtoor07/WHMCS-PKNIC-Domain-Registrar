<?php

declare(strict_types=1);

namespace PknicWeb;

use WHMCS\Database\Capsule;

/** Ticket, To-Do and email escalation. Each channel is best-effort and never throws. */
final class Notifier
{
    private const CONTACT_KEYS = [
        'firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city', 'state', 'postcode',
        'countrycode', 'fullphonenumber', 'adminfirstname', 'adminlastname', 'admincompanyname', 'adminemail',
        'adminaddress1', 'adminaddress2', 'admincity', 'adminstate', 'adminpostcode', 'admincountry', 'adminfullphonenumber',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    public function escalate(object $job, string $error): void
    {
        $payload = json_decode((string) $job->payload, true) ?: [];
        $subject = sprintf('PKNIC %s failed: %s (job #%d)', $job->action, $job->domain, $job->id);
        $body = $this->describe($job, $payload, $error);

        $this->openTicket($subject, $body);
        $this->addTodo($subject, $body);
        $this->alertAdmin($subject, $body);
    }

    public function alertAdmin(string $subject, string $message): void
    {
        try {
            if (function_exists('localAPI')) {
                localAPI('SendAdminEmail', [
                    'customsubject' => $subject,
                    'custommessage' => nl2br(htmlspecialchars($message, ENT_QUOTES)),
                    'type' => 'system',
                ]);
            }
        } catch (\Throwable) {
        }

        if ($this->config->alertEmail === '' || !filter_var($this->config->alertEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        try {
            $from = $this->systemEmail();
            $headers = "Content-Type: text/plain; charset=UTF-8\r\n" . ($from !== '' ? "From: $from\r\n" : '');
            @mail($this->config->alertEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $message, $headers);
        } catch (\Throwable) {
        }
    }

    private function openTicket(string $subject, string $body): void
    {
        if ($this->config->ticketDeptId <= 0 || !function_exists('localAPI')) {
            return;
        }
        try {
            $result = localAPI('OpenTicket', [
                'deptid' => $this->config->ticketDeptId,
                'subject' => $subject,
                'message' => $body,
                'name' => 'PKNIC Automation',
                'email' => $this->config->alertEmail !== '' ? $this->config->alertEmail : $this->systemEmail(),
                'priority' => 'High',
                'markdown' => true,
                'noemail' => true,
            ]);
            if (($result['result'] ?? '') !== 'success' && function_exists('logActivity')) {
                logActivity('PKNIC: could not open failure ticket: ' . ($result['message'] ?? 'unknown error'));
            }
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('PKNIC: could not open failure ticket: ' . $e->getMessage());
            }
        }
    }

    private function addTodo(string $title, string $body): void
    {
        try {
            Capsule::table('tbltodolist')->insert([
                'date' => date('Y-m-d'),
                'title' => mb_substr($title, 0, 250),
                'description' => $body,
                'admin' => 0,
                'status' => 'Pending',
                'duedate' => date('Y-m-d'),
            ]);
        } catch (\Throwable) {
        }
    }

    private function systemEmail(): string
    {
        try {
            if (class_exists('\\WHMCS\\Config\\Setting')) {
                return (string) (\WHMCS\Config\Setting::getValue('SystemEmailsFromEmail') ?: \WHMCS\Config\Setting::getValue('Email'));
            }
        } catch (\Throwable) {
        }
        return '';
    }

    public function describe(object $job, array $payload, string $error): string
    {
        $years = (int) ($payload['regperiod'] ?? 1);
        $ns = [];
        foreach ([1, 2, 3, 4, 5] as $i) {
            if (!empty($payload['ns' . $i])) {
                $ns[] = $payload['ns' . $i];
            }
        }
        $ns = $ns ?: (array) ($payload['nameservers'] ?? []);

        $lines = [
            "The PKNIC automation could not complete this job after {$job->attempts} attempt(s).",
            '',
            "Job:       #{$job->id}",
            "Action:    {$job->action}",
            "Domain:    {$job->domain}",
            "WHMCS domain ID: {$job->domain_id}",
            "Client ID: " . ($payload['userid'] ?? 'n/a'),
            "Last error: $error",
            '',
            '## What to do manually in the PKNIC panel',
        ];

        switch ($job->action) {
            case 'register':
                $lines[] = "1. Register {$job->domain} for $years year(s).";
                $lines[] = '2. Nameservers: ' . ($ns ? implode(', ', $ns) : '(none given)');
                $lines[] = '3. Registrant / admin details:';
                foreach (self::CONTACT_KEYS as $key) {
                    if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                        $lines[] = "   - $key: {$payload[$key]}";
                    }
                }
                foreach ((array) ($payload['additionalfields'] ?? []) as $k => $v) {
                    if (is_scalar($v) && $v !== '') {
                        $lines[] = "   - $k: $v";
                    }
                }
                $lines[] = '4. In WHMCS set the domain to Active with the PKNIC expiry date, then click "Mark done" in Addons > PKNIC Jobs.';
                break;
            case 'renew':
                $lines[] = "1. FIRST check the current expiry of {$job->domain} in the panel"
                    . (!empty($payload['expiry_before']) ? " (it was {$payload['expiry_before']} before the automated attempt)" : '')
                    . ' - the renewal may already have gone through.';
                $lines[] = "2. If not renewed, renew it for $years year(s).";
                $lines[] = '3. Update the expiry / next due date in WHMCS and click "Mark done" in Addons > PKNIC Jobs.';
                break;
            case 'nameservers':
                $lines[] = "1. Set the nameservers of {$job->domain} to: " . implode(', ', $ns);
                $lines[] = '2. Click "Mark done" in Addons > PKNIC Jobs.';
                break;
        }

        return implode("\n", $lines);
    }
}
