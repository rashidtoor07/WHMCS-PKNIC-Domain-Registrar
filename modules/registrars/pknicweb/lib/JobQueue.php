<?php

declare(strict_types=1);

namespace PknicWeb;

use WHMCS\Database\Capsule;

final class JobQueue
{
    public const JOBS = 'mod_pknicweb_jobs';
    public const NAMESERVERS = 'mod_pknicweb_nameservers';
    public const SETTINGS = 'mod_pknicweb_settings';
    private const LOCK = 'pknicweb_queue';

    private static bool $schemaReady = false;

    public function __construct()
    {
        self::ensureSchema();
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) {
            return;
        }
        $schema = Capsule::schema();
        if (!$schema->hasTable(self::JOBS)) {
            $schema->create(self::JOBS, function ($t) {
                $t->increments('id');
                $t->unsignedInteger('domain_id')->default(0)->index();
                $t->string('domain', 255)->index();
                $t->string('action', 20);
                $t->text('payload')->nullable();
                $t->string('status', 20)->default('pending')->index();
                $t->unsignedInteger('attempts')->default(0);
                $t->text('last_error')->nullable();
                $t->text('result')->nullable();
                $t->dateTime('next_attempt_at')->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->dateTime('completed_at')->nullable();
            });
        }
        if (!$schema->hasTable(self::NAMESERVERS)) {
            $schema->create(self::NAMESERVERS, function ($t) {
                $t->unsignedInteger('domain_id')->primary();
                foreach (['ns1', 'ns2', 'ns3', 'ns4', 'ns5'] as $col) {
                    $t->string($col, 255)->default('');
                }
                $t->dateTime('synced_at')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
        if (!$schema->hasTable(self::SETTINGS)) {
            $schema->create(self::SETTINGS, function ($t) {
                $t->string('setting', 64)->primary();
                $t->text('value')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
        self::$schemaReady = true;
    }

    private static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ------------------------------------------------------------------ enqueue

    public function enqueue(int $domainId, string $domain, string $action, array $payload): int
    {
        $now = self::now();
        return (int) Capsule::table(self::JOBS)->insertGetId([
            'domain_id' => $domainId,
            'domain' => strtolower($domain),
            'action' => $action,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** First open (pending/processing) job of $action for the domain, if any. */
    public function findOpen(string $domain, string $action): ?object
    {
        return Capsule::table(self::JOBS)
            ->where('domain', strtolower($domain))
            ->where('action', $action)
            ->whereIn('status', ['pending', 'processing'])
            ->orderBy('id')
            ->first();
    }

    /**
     * Queue a nameserver change. A still-pending registration absorbs the new nameservers instead;
     * older pending nameserver jobs for the domain are superseded.
     */
    public function enqueueNameservers(int $domainId, string $domain, array $nameservers): int
    {
        $domain = strtolower($domain);
        $register = Capsule::table(self::JOBS)
            ->where('domain', $domain)->where('action', 'register')->where('status', 'pending')
            ->orderBy('id', 'desc')->first();
        $payload = $register !== null ? (json_decode((string) $register->payload, true) ?: []) : [];
        if ($register !== null && empty($payload['submitted_at'])) {
            foreach ([1, 2, 3, 4, 5] as $i) {
                $payload['ns' . $i] = $nameservers[$i - 1] ?? '';
            }
            $this->updatePayload((int) $register->id, $payload);
            return (int) $register->id;
        }

        $id = $this->enqueue($domainId, $domain, 'nameservers', ['domain' => $domain, 'nameservers' => array_values($nameservers)]);
        Capsule::table(self::JOBS)
            ->where('domain', $domain)->where('action', 'nameservers')->where('status', 'pending')
            ->where('id', '<', $id)
            ->update(['status' => 'cancelled', 'last_error' => "Superseded by job #$id", 'updated_at' => self::now(), 'completed_at' => self::now()]);
        return $id;
    }

    // ------------------------------------------------------------------ processing

    public function acquireLock(int $timeout = 0): bool
    {
        $row = Capsule::connection()->selectOne('SELECT GET_LOCK(?, ?) AS l', [self::LOCK, $timeout]);
        return (int) ($row->l ?? 0) === 1;
    }

    public function releaseLock(): void
    {
        Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS l', [self::LOCK]);
    }

    private function dueQuery()
    {
        $now = self::now();
        return Capsule::table(self::JOBS)
            ->where('status', 'pending')
            ->where(function ($q) use ($now) {
                $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now);
            });
    }

    public function hasDueJobs(): bool
    {
        return $this->dueQuery()->exists();
    }

    /** Atomically move the oldest due job to processing. */
    public function claimNext(): ?object
    {
        for ($i = 0; $i < 5; $i++) {
            $job = $this->dueQuery()->orderBy('id')->first();
            if ($job === null) {
                return null;
            }
            $claimed = Capsule::table(self::JOBS)
                ->where('id', $job->id)->where('status', 'pending')
                ->update(['status' => 'processing', 'updated_at' => self::now()]);
            if ($claimed === 1) {
                $job->status = 'processing';
                return $job;
            }
        }
        return null;
    }

    /** Jobs left in processing by a crashed run go back to pending (attempts unchanged). */
    public function recoverStale(int $minutes = 30): int
    {
        return Capsule::table(self::JOBS)
            ->where('status', 'processing')
            ->where('updated_at', '<', date('Y-m-d H:i:s', time() - $minutes * 60))
            ->update(['status' => 'pending', 'last_error' => 'Recovered after an interrupted run', 'updated_at' => self::now()]);
    }

    public function get(int $id): ?object
    {
        return Capsule::table(self::JOBS)->where('id', $id)->first();
    }

    public function updatePayload(int $id, array $payload): void
    {
        Capsule::table(self::JOBS)->where('id', $id)->update([
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => self::now(),
        ]);
    }

    public function markDone(int $id, ?string $result = null): void
    {
        $this->setStatus($id, 'done', ['result' => $result, 'last_error' => null, 'completed_at' => self::now()]);
    }

    public function markFailed(int $id, int $attempts, string $error): void
    {
        $this->setStatus($id, 'failed', ['attempts' => $attempts, 'last_error' => $error, 'completed_at' => self::now()]);
    }

    public function retryLater(int $id, int $attempts, string $error, int $delayMinutes): void
    {
        $this->setStatus($id, 'pending', [
            'attempts' => $attempts,
            'last_error' => $error,
            'next_attempt_at' => date('Y-m-d H:i:s', time() + $delayMinutes * 60),
        ]);
    }

    /** Back to pending without counting an attempt (login problems, waiting on another job). */
    public function release(int $id, string $reason, int $delayMinutes = 0): void
    {
        $this->setStatus($id, 'pending', [
            'last_error' => $reason,
            'next_attempt_at' => $delayMinutes > 0 ? date('Y-m-d H:i:s', time() + $delayMinutes * 60) : null,
        ]);
    }

    public function cancel(int $id, string $reason): void
    {
        $this->setStatus($id, 'cancelled', ['last_error' => $reason, 'completed_at' => self::now()]);
    }

    /** Admin "Retry": fresh attempt counter, due immediately. */
    public function retry(int $id): void
    {
        $this->setStatus($id, 'pending', ['attempts' => 0, 'next_attempt_at' => null, 'completed_at' => null]);
    }

    public function markManualDone(int $id, string $admin): void
    {
        $this->setStatus($id, 'done', ['result' => "Marked done manually by $admin", 'completed_at' => self::now()]);
    }

    public function cancelOpen(string $domain, string $action, string $reason): void
    {
        Capsule::table(self::JOBS)
            ->where('domain', strtolower($domain))->where('action', $action)->where('status', 'pending')
            ->update(['status' => 'cancelled', 'last_error' => $reason, 'updated_at' => self::now(), 'completed_at' => self::now()]);
    }

    public function newerNameserverJob(object $job): ?int
    {
        $id = Capsule::table(self::JOBS)
            ->where('domain', $job->domain)->where('action', 'nameservers')
            ->whereIn('status', ['pending', 'processing'])
            ->where('id', '>', $job->id)
            ->max('id');
        return $id !== null ? (int) $id : null;
    }

    public function hasOpenRegistration(string $domain): bool
    {
        return $this->findOpen($domain, 'register') !== null;
    }

    private function setStatus(int $id, string $status, array $extra = []): void
    {
        Capsule::table(self::JOBS)->where('id', $id)->update(array_merge($extra, [
            'status' => $status,
            'updated_at' => self::now(),
        ]));
    }

    /** @return object[] */
    public function listJobs(?string $status = null, int $limit = 200): array
    {
        $q = Capsule::table(self::JOBS)->orderBy('id', 'desc')->limit($limit);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }
        return $q->get()->all();
    }

    /** @return array<string, int> */
    public function countsByStatus(): array
    {
        $out = [];
        foreach (Capsule::table(self::JOBS)->select('status', Capsule::raw('COUNT(*) AS c'))->groupBy('status')->get() as $row) {
            $out[$row->status] = (int) $row->c;
        }
        return $out;
    }

    // ------------------------------------------------------------------ local nameservers

    /** @return string[]|null */
    public function getNameservers(int $domainId): ?array
    {
        $row = Capsule::table(self::NAMESERVERS)->where('domain_id', $domainId)->first();
        if ($row === null) {
            return null;
        }
        return [$row->ns1, $row->ns2, $row->ns3, $row->ns4, $row->ns5];
    }

    public function saveNameservers(int $domainId, array $nameservers, bool $synced): void
    {
        $values = ['updated_at' => self::now(), 'synced_at' => $synced ? self::now() : null];
        foreach ([1, 2, 3, 4, 5] as $i) {
            $values['ns' . $i] = (string) ($nameservers[$i - 1] ?? '');
        }
        Capsule::table(self::NAMESERVERS)->updateOrInsert(['domain_id' => $domainId], $values);
    }

    public function markNameserversSynced(int $domainId, array $confirmed): void
    {
        $this->saveNameservers($domainId, array_values($confirmed), true);
    }
}
