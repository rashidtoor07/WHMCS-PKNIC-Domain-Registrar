<?php

declare(strict_types=1);

namespace PknicWeb;

final class JobProcessor
{
    private const PAID_ACTIONS = ['register', 'renew'];
    private const BALANCE_RETRY_MINUTES = 60;
    private const BALANCE_ALERT_HOURS = 6;

    /** @var \Closure(): PknicActions */
    private \Closure $actionsFactory;

    private bool $balanceLow = false;

    public function __construct(
        private readonly Config $config,
        private readonly JobQueue $queue,
        private readonly Settings $settings,
        private readonly Notifier $notifier,
        \Closure $actionsFactory
    ) {
        $this->actionsFactory = $actionsFactory;
    }

    /** @return array{processed: int, status: string} */
    public function run(int $maxJobs = 10, int $maxSeconds = 150): array
    {
        $started = time();
        if ($this->settings->isPaused()) {
            return ['processed' => 0, 'status' => 'paused: ' . $this->settings->pauseReason()];
        }
        if (!$this->queue->acquireLock()) {
            return ['processed' => 0, 'status' => 'another run holds the lock'];
        }

        $processed = 0;
        try {
            $this->queue->recoverStale();
            if (!$this->queue->hasDueJobs()) {
                return ['processed' => 0, 'status' => 'no due jobs'];
            }

            try {
                $actions = ($this->actionsFactory)();
                $actions->client()->ensureLogin();
                $this->settings->recordLoginSuccess();
            } catch (MapException $e) {
                $this->pause('pknic_map.php / module settings incomplete: ' . $e->getMessage());
                return ['processed' => 0, 'status' => 'paused: ' . $e->getMessage()];
            } catch (LoginException $e) {
                $this->settings->recordLoginFailure($e->getMessage(), $this->notifier);
                return ['processed' => 0, 'status' => 'login failed: ' . $e->getMessage()];
            } catch (\Throwable $e) {
                $this->debug('PKNIC: queue run aborted before login: ' . $e->getMessage());
                return ['processed' => 0, 'status' => 'error: ' . $e->getMessage()];
            }

            while ($processed < $maxJobs && time() - $started < $maxSeconds) {
                $job = $this->queue->claimNext();
                if ($job === null) {
                    break;
                }
                $processed++;
                if (!$this->processJob($job, $actions)) {
                    break;
                }
                if ($processed < $maxJobs && $this->queue->hasDueJobs()) {
                    sleep(random_int(3, 5));
                }
            }
        } finally {
            $this->queue->releaseLock();
        }

        return ['processed' => $processed, 'status' => 'ok'];
    }

    /** @return bool false when the whole run should stop (login/map problems). */
    private function processJob(object $job, PknicActions $actions): bool
    {
        $id = (int) $job->id;
        $payload = json_decode((string) $job->payload, true) ?: [];
        $this->debug("PKNIC: processing job #$id {$job->action} {$job->domain} (attempt " . ((int) $job->attempts + 1) . ')');

        if ($this->balanceLow && in_array($job->action, self::PAID_ACTIONS, true)) {
            $this->queue->release($id, 'Waiting for PKNIC account balance to be topped up', self::BALANCE_RETRY_MINUTES);
            return true;
        }

        try {
            switch ($job->action) {
                case 'register':
                    $result = $actions->registerDomain($payload, fn (array $p) => $this->queue->updatePayload($id, $p));
                    $result['message'] .= $this->updateWhmcsDomain($job, $result['expiry'], true);
                    $wanted = Validator::normalizeNameservers(array_map(
                        static fn ($i) => (string) ($payload['ns' . $i] ?? ''),
                        [1, 2, 3, 4, 5]
                    ));
                    if ($result['ns_pending'] && $wanted !== []) {
                        // Registered without a nameserver set: apply the nameservers in a follow-up job.
                        $this->queue->markDone($id, $result['message']);
                        $nsJob = $this->queue->enqueueNameservers((int) $job->domain_id, $job->domain, $wanted);
                        $result['message'] .= "; nameservers queued as job #$nsJob";
                    } elseif ($result['nameservers']) {
                        $this->queue->markNameserversSynced((int) $job->domain_id, $result['nameservers']);
                    }
                    break;

                case 'renew':
                    $result = $actions->renewDomain($payload, fn (array $p) => $this->queue->updatePayload($id, $p));
                    if ($result['expiry'] !== null) {
                        $result['message'] .= $this->updateWhmcsDomain($job, $result['expiry'], false);
                    }
                    break;

                case 'nameservers':
                    $newer = $this->queue->newerNameserverJob($job);
                    if ($newer !== null) {
                        $this->queue->cancel($id, "Superseded by job #$newer");
                        return true;
                    }
                    if ($this->queue->hasOpenRegistration($job->domain)) {
                        $this->queue->release($id, 'Waiting for the registration job to finish', 5);
                        return true;
                    }
                    $result = $actions->updateNameservers($job->domain, (array) ($payload['nameservers'] ?? []));
                    $this->queue->markNameserversSynced((int) $job->domain_id, $result['nameservers']);
                    break;

                default:
                    throw new NonRetryableException("Unknown job action '{$job->action}'");
            }

            $this->queue->markDone($id, $result['message']);
            $this->activity("PKNIC: job #$id {$job->action} {$job->domain} done - {$result['message']}", $payload);
            return true;
        } catch (AwaitingConfirmationException $e) {
            $this->queue->release($id, $e->getMessage(), 30);
            $this->debug("PKNIC: job #$id waiting: " . $e->getMessage());
            return true;
        } catch (InsufficientBalanceException $e) {
            // Nothing was registered/renewed; wait for a top-up instead of spending retries.
            $this->balanceLow = true;
            $this->queue->release($id, $e->getMessage() . ' - will retry after a top-up', self::BALANCE_RETRY_MINUTES);
            $this->alertLowBalance($e->getMessage());
            return true;
        } catch (LoginException $e) {
            $this->queue->release($id, 'Login problem: ' . $e->getMessage());
            $this->settings->recordLoginFailure($e->getMessage(), $this->notifier);
            return false;
        } catch (MapException $e) {
            $this->queue->release($id, $e->getMessage());
            $this->pause('pknic_map.php incomplete: ' . $e->getMessage());
            return false;
        } catch (NonRetryableException $e) {
            $this->fail($job, (int) $job->attempts + 1, $e->getMessage());
        } catch (\Throwable $e) {
            $attempts = (int) $job->attempts + 1;
            if ($attempts >= $this->config->maxRetries) {
                $this->fail($job, $attempts, $e->getMessage());
            } else {
                $delay = self::backoffMinutes($attempts);
                $this->queue->retryLater($id, $attempts, $e->getMessage(), $delay);
                $this->activity("PKNIC: job #$id {$job->action} {$job->domain} failed (attempt $attempts), retry in {$delay} min: {$e->getMessage()}", $payload);
            }
        }
        return true;
    }

    /** 5, 15, 45 ... minutes, capped at 6 hours. */
    public static function backoffMinutes(int $attempts): int
    {
        return (int) min(360, 5 * (3 ** max(0, $attempts - 1)));
    }

    private function fail(object $job, int $attempts, string $error): void
    {
        $this->queue->markFailed((int) $job->id, $attempts, $error);
        $job->attempts = $attempts;
        if ($job->action === 'register') {
            $this->queue->cancelOpen($job->domain, 'nameservers', "Registration job #{$job->id} failed");
        }
        $this->activity("PKNIC: job #{$job->id} {$job->action} {$job->domain} FAILED: $error", []);
        $this->notifier->escalate($job, $error);
    }

    private function pause(string $reason): void
    {
        if ($this->settings->pause($reason)) {
            $this->notifier->alertAdmin('PKNIC automation paused', $reason
                . "\n\nFix the problem, then click \"Resume processing\" in Addons > PKNIC Jobs.");
        }
    }

    private function alertLowBalance(string $message): void
    {
        $last = (int) strtotime((string) $this->settings->get('balance_alert_at', ''));
        if ($last > time() - self::BALANCE_ALERT_HOURS * 3600) {
            return;
        }
        $this->settings->set('balance_alert_at', date('Y-m-d H:i:s'));
        $this->notifier->alertAdmin(
            'PKNIC account balance too low',
            $message . "\n\nRegistrations and renewals are waiting and will be retried every "
            . self::BALANCE_RETRY_MINUTES . ' minutes without using up their retries. Top up the PKNIC account balance.'
        );
    }

    private function updateWhmcsDomain(object $job, ?string $expiry, bool $activate): string
    {
        if (!function_exists('localAPI') || (int) $job->domain_id <= 0) {
            return '';
        }
        $update = ['domainid' => (int) $job->domain_id];
        if ($activate) {
            $update['status'] = 'Active';
        }
        if ($expiry !== null) {
            $update['expirydate'] = $expiry;
            $update['nextduedate'] = $expiry;
        }
        if (count($update) === 1) {
            return '';
        }
        $r = localAPI('UpdateClientDomain', $update);
        return ($r['result'] ?? '') === 'success'
            ? '; WHMCS domain updated'
            : '; WARNING: UpdateClientDomain failed: ' . ($r['message'] ?? 'unknown error');
    }

    private function activity(string $message, array $payload): void
    {
        if (function_exists('logActivity')) {
            logActivity($message, (int) ($payload['userid'] ?? 0));
        }
    }

    private function debug(string $message): void
    {
        if ($this->config->debug && function_exists('logActivity')) {
            logActivity($message);
        }
    }
}
