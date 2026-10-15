<?php

/**
 * Admin page for the PKNIC queue: list/filter jobs, retry, mark done, cancel,
 * test the PKNIC login and resume paused processing.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once dirname(__DIR__, 2) . '/registrars/pknicweb/lib/bootstrap.php';

use PknicWeb\JobQueue;
use PknicWeb\Runner;
use PknicWeb\Settings;
use WHMCS\Database\Capsule;

function pknicweb_admin_config()
{
    return [
        'name' => 'PKNIC Jobs',
        'description' => 'Queue monitor for the pknicweb registrar module (PKNIC web panel automation).',
        'version' => '1.0.0',
        'author' => 'pknicweb',
        'fields' => [],
    ];
}

function pknicweb_admin_activate()
{
    try {
        JobQueue::ensureSchema();
        return ['status' => 'success', 'description' => 'PKNIC Jobs activated.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Could not create tables: ' . $e->getMessage()];
    }
}

function pknicweb_admin_deactivate()
{
    // Tables are kept: they hold the queue and local nameserver state used by the registrar module.
    return ['status' => 'success', 'description' => 'PKNIC Jobs deactivated (queue tables kept).'];
}

function pknicweb_admin_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function pknicweb_admin_adminName(): string
{
    $id = (int) ($_SESSION['adminid'] ?? 0);
    try {
        $name = Capsule::table('tbladmins')->where('id', $id)->value('username');
    } catch (\Throwable) {
        $name = null;
    }
    return $name ? (string) $name : "admin #$id";
}

function pknicweb_admin_button(string $link, string $action, string $label, string $class, int $jobId = 0, string $confirm = ''): string
{
    $onsubmit = $confirm !== '' ? ' onsubmit="return confirm(\'' . pknicweb_admin_h(addslashes($confirm)) . '\');"' : '';
    return '<form method="post" action="' . pknicweb_admin_h($link) . '" style="display:inline"' . $onsubmit . '>'
        . generate_token('form')
        . '<input type="hidden" name="pk_action" value="' . pknicweb_admin_h($action) . '">'
        . ($jobId ? '<input type="hidden" name="job_id" value="' . $jobId . '">' : '')
        . '<button type="submit" class="btn btn-xs ' . $class . '">' . pknicweb_admin_h($label) . '</button></form> ';
}

function pknicweb_admin_handlePost(JobQueue $queue, Settings $settings): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['pk_action'])) {
        return [];
    }
    check_token('WHMCS.admin.default');

    $action = (string) $_POST['pk_action'];
    $jobId = (int) ($_POST['job_id'] ?? 0);
    $job = $jobId ? $queue->get($jobId) : null;
    $admin = pknicweb_admin_adminName();

    if (in_array($action, ['retry', 'done', 'cancel'], true) && $job === null) {
        return ['danger', "Job #$jobId not found."];
    }
    if (in_array($action, ['retry', 'cancel'], true) && $job->status === 'processing') {
        return ['warning', "Job #$jobId is being processed right now; try again in a minute."];
    }

    switch ($action) {
        case 'retry':
            $queue->retry($jobId);
            logActivity("PKNIC: job #$jobId requeued by $admin");
            return ['success', "Job #$jobId queued again; it runs on the next cron."];
        case 'done':
            $queue->markManualDone($jobId, $admin);
            if ($job->action === 'nameservers') {
                $payload = json_decode((string) $job->payload, true) ?: [];
                $queue->markNameserversSynced((int) $job->domain_id, (array) ($payload['nameservers'] ?? []));
            }
            logActivity("PKNIC: job #$jobId marked done manually by $admin");
            return ['success', "Job #$jobId marked done. Remember to update the domain in WHMCS if needed."];
        case 'cancel':
            $queue->cancel($jobId, "Cancelled by $admin");
            logActivity("PKNIC: job #$jobId cancelled by $admin");
            return ['success', "Job #$jobId cancelled."];
        case 'test_login':
            $r = Runner::testLogin();
            return [$r['ok'] ? 'success' : 'danger', 'Test PKNIC login: ' . $r['message']];
        case 'resume':
            $settings->resume();
            logActivity("PKNIC: processing resumed by $admin");
            return ['success', 'Processing resumed. Pending jobs run on the next cron.'];
    }
    return ['danger', 'Unknown action.'];
}

function pknicweb_admin_output($vars)
{
    $link = (string) $vars['modulelink'];

    try {
        $queue = new JobQueue();
        $settings = new Settings();
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">Database error: ' . pknicweb_admin_h($e->getMessage()) . '</div>';
        return;
    }

    $flash = pknicweb_admin_handlePost($queue, $settings);
    if ($flash) {
        echo '<div class="alert alert-' . $flash[0] . '">' . pknicweb_admin_h($flash[1]) . '</div>';
    }

    if ($settings->isPaused()) {
        echo '<div class="alert alert-warning"><strong>Processing is paused.</strong> '
            . pknicweb_admin_h($settings->pauseReason())
            . ' <small>(since ' . pknicweb_admin_h($settings->get('paused_at', '')) . ')</small><br><br>'
            . pknicweb_admin_button($link, 'resume', 'Resume processing', 'btn-warning')
            . '</div>';
    }

    echo '<p>' . pknicweb_admin_button($link, 'test_login', 'Test PKNIC login', 'btn-default')
        . ' <small class="text-muted">Login failures in a row: '
        . pknicweb_admin_h($settings->get('login_failures', '0')) . '</small></p>';

    $status = (string) ($_GET['status'] ?? '');
    $statuses = ['' => 'All', 'pending' => 'Pending', 'processing' => 'Processing', 'failed' => 'Failed', 'done' => 'Done', 'cancelled' => 'Cancelled'];
    if (!array_key_exists($status, $statuses)) {
        $status = '';
    }
    $counts = $queue->countsByStatus();
    echo '<ul class="nav nav-tabs" style="margin-bottom:10px">';
    foreach ($statuses as $key => $label) {
        $n = $key === '' ? array_sum($counts) : ($counts[$key] ?? 0);
        echo '<li' . ($key === $status ? ' class="active"' : '') . '><a href="' . pknicweb_admin_h($link . ($key !== '' ? '&status=' . $key : '')) . '">'
            . pknicweb_admin_h($label) . ' <span class="badge">' . $n . '</span></a></li>';
    }
    echo '</ul>';

    $jobs = $queue->listJobs($status !== '' ? $status : null);
    if (!$jobs) {
        echo '<p>No jobs.</p>';
        return;
    }

    echo '<div class="table-responsive"><table class="table table-striped table-condensed"><thead><tr>'
        . '<th>#</th><th>Domain</th><th>Action</th><th>Status</th><th>Attempts</th><th>Next attempt</th>'
        . '<th>Last error / result</th><th>Created</th><th>Updated</th><th>Actions</th></tr></thead><tbody>';

    $labels = ['pending' => 'info', 'processing' => 'primary', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'default'];
    foreach ($jobs as $job) {
        $id = (int) $job->id;
        $payload = json_encode(json_decode((string) $job->payload, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $domainLink = (int) $job->domain_id > 0
            ? '<a href="clientsdomains.php?id=' . (int) $job->domain_id . '">' . pknicweb_admin_h($job->domain) . '</a>'
            : pknicweb_admin_h($job->domain);

        $buttons = '';
        if (in_array($job->status, ['failed', 'cancelled', 'pending'], true)) {
            $buttons .= pknicweb_admin_button($link, 'retry', 'Retry', 'btn-primary', $id);
        }
        if ($job->status !== 'done') {
            $buttons .= pknicweb_admin_button($link, 'done', 'Mark done (manual)', 'btn-success', $id, "Mark job #$id as done? Only do this after completing it in the PKNIC panel.");
        }
        if (in_array($job->status, ['pending', 'failed'], true)) {
            $buttons .= pknicweb_admin_button($link, 'cancel', 'Cancel', 'btn-danger', $id, "Cancel job #$id?");
        }

        $message = $job->status === 'done' ? (string) $job->result : (string) $job->last_error;
        echo '<tr>'
            . '<td>' . $id . '</td>'
            . '<td>' . $domainLink . '</td>'
            . '<td>' . pknicweb_admin_h($job->action) . '</td>'
            . '<td><span class="label label-' . ($labels[$job->status] ?? 'default') . '">' . pknicweb_admin_h($job->status) . '</span></td>'
            . '<td>' . (int) $job->attempts . '</td>'
            . '<td>' . pknicweb_admin_h($job->status === 'pending' ? ($job->next_attempt_at ?: 'next cron') : '') . '</td>'
            . '<td style="max-width:420px;white-space:pre-wrap;word-break:break-word">' . pknicweb_admin_h($message)
            . '<details><summary><small>payload</small></summary><pre style="max-height:300px;overflow:auto">' . pknicweb_admin_h($payload) . '</pre></details></td>'
            . '<td>' . pknicweb_admin_h($job->created_at) . '</td>'
            . '<td>' . pknicweb_admin_h($job->updated_at) . '</td>'
            . '<td style="white-space:nowrap">' . $buttons . '</td>'
            . '</tr>';
    }
    echo '</tbody></table></div>';
}
