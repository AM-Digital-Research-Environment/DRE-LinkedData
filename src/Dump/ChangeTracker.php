<?php
declare(strict_types=1);

namespace DRELinkedData\Dump;

use DRELinkedData\Settings\DatasetSettings;
use Omeka\Settings\Settings;

/**
 * Notices when the dump falls behind the data.
 *
 * Resource documents are live; the dump is a snapshot. Every create, update
 * or delete of an item, item set or media is counted, and the count is written
 * to one setting (`dre_ld_changes`: since, count, last) once per request or
 * job, at shutdown, however many resources the request touched. The admin
 * status panel and the page block then say "out of date (N changes since …)"
 * until the next dump.
 *
 * Changes are compared with the dump's start time, not its end: a change made
 * while a dump is running may or may not be in it, so it still counts. The
 * count is approximate under concurrent writers (two requests may read the
 * same count); the date and the "out of date" verdict are not.
 */
final class ChangeTracker
{
    private int $pending = 0;
    private bool $flushRegistered = false;

    /** @param (\Closure():string)|null $clock the current UTC time as ISO 8601 */
    public function __construct(
        private readonly Settings $settings,
        private readonly DumpStore $store,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** Count $count changes; they are written once, when the process ends. */
    public function record(int $count = 1): void
    {
        if ($count <= 0) {
            return;
        }
        $this->pending += $count;
        if (!$this->flushRegistered) {
            $this->flushRegistered = true;
            register_shutdown_function(function (): void {
                $this->flush();
            });
        }
    }

    /** Write the changes counted so far. Never throws: tracking must not break a save. */
    public function flush(): void
    {
        if ($this->pending === 0) {
            return;
        }
        $count = $this->pending;
        $this->pending = 0;
        try {
            $now = $this->now();
            $record = $this->read();
            $started = self::startedAt($this->store->state());
            // Everything recorded so far is in the current dump: start afresh.
            if ($record === null || ($started !== null && strcmp($record['last'], $started) < 0)) {
                $record = ['since' => $now, 'count' => 0, 'last' => $now];
            }
            $record['count'] += $count;
            $record['last'] = $now;
            $this->settings->set(DatasetSettings::CHANGES, $record);
        } catch (\Throwable) {
            // The worst case is a dump that looks up to date for a while.
        }
    }

    /**
     * Changes the published dump does not contain, or null when it is up to
     * date (or when nothing has been recorded).
     *
     * @param array<string,mixed>|null $state the dump state
     * @return array{since:string,count:int}|null
     */
    public function pending(?array $state): ?array
    {
        $record = $this->read();
        if ($record === null) {
            return null;
        }
        $started = self::startedAt($state);
        if ($started !== null && strcmp($record['last'], $started) < 0) {
            return null;
        }
        return ['since' => $record['since'], 'count' => $record['count']];
    }

    /** After a dump that started at $startedAt: forget the changes it contains. */
    public function settle(string $startedAt): void
    {
        try {
            $record = $this->read();
            if ($record !== null && strcmp($record['last'], $startedAt) < 0) {
                $this->settings->delete(DatasetSettings::CHANGES);
            }
        } catch (\Throwable) {
            // A stale record is reconciled against the dump's state anyway.
        }
    }

    /** @return array{since:string,count:int,last:string}|null */
    private function read(): ?array
    {
        $record = $this->settings->get(DatasetSettings::CHANGES);
        if (!is_array($record) || !is_string($record['since'] ?? null) || !is_string($record['last'] ?? null)) {
            return null;
        }
        return ['since' => $record['since'], 'count' => (int) ($record['count'] ?? 0), 'last' => $record['last']];
    }

    /** @param array<string,mixed>|null $state */
    private static function startedAt(?array $state): ?string
    {
        foreach (['started_at', 'generated_at'] as $key) {
            if (is_string($state[$key] ?? null) && $state[$key] !== '') {
                return $state[$key];
            }
        }
        return null;
    }

    private function now(): string
    {
        return $this->clock !== null ? ($this->clock)() : gmdate('Y-m-d\TH:i:s\Z');
    }
}
