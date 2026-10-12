<?php
declare(strict_types=1);

namespace DRELinkedData\Dump;

/**
 * The dump's home under Omeka's files/ directory.
 *
 * files/ is the one tree that is both persistent and writable in the immutable
 * Docker layout (DreVisualizations, which writes into its own asset/ directory,
 * needs a dedicated volume for exactly that reason). Layout:
 *
 *   files/dre-linked-data/dump.nt.gz        the published dump
 *   files/dre-linked-data/state.json        what the last successful run produced
 *   files/dre-linked-data/.dump-*.part      a dump being written
 *   files/dre-linked-data/.lock             held while a run is in progress
 *
 * A run writes to a part file and renames it into place, then writes the state
 * the same way, so a reader sees either the previous dump or the new one —
 * never half of one. The dot-files are refused by the web server's hidden-file
 * rule, so an in-progress dump is never served.
 */
final class DumpStore
{
    public const DIRECTORY = 'dre-linked-data';
    private const DUMP = 'dump.nt.gz';
    private const STATE = 'state.json';
    private const LOCK = '.lock';

    private readonly string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public static function inFilesPath(string $filesPath): self
    {
        return new self(rtrim($filesPath, '/\\') . '/' . self::DIRECTORY);
    }

    public function dumpPath(): string
    {
        return $this->directory . '/' . self::DUMP;
    }

    public function hasDump(): bool
    {
        return is_file($this->dumpPath());
    }

    /**
     * The state of the published dump, or null when there is none (never
     * generated, or the state file is missing or unreadable).
     *
     * @return array<string,mixed>|null
     */
    public function state(): ?array
    {
        $path = $this->directory . '/' . self::STATE;
        if (!$this->hasDump() || !is_file($path)) {
            return null;
        }
        $json = @file_get_contents($path);
        $state = is_string($json) ? json_decode($json, true) : null;
        return is_array($state) ? $state : null;
    }

    /**
     * Take the run lock without waiting.
     *
     * @return resource|null the lock handle, or null when another run holds it
     */
    public function lock()
    {
        $this->ensureDirectory();
        $handle = @fopen($this->directory . '/' . self::LOCK, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the dump lock file in ' . $this->directory);
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /** @param resource $handle */
    public function unlock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function partPath(): string
    {
        $this->ensureDirectory();
        return $this->directory . '/.dump-' . bin2hex(random_bytes(6)) . '.part';
    }

    /**
     * Move a finished part file into place and record its state.
     *
     * @param array<string,mixed> $state
     */
    public function publish(string $partPath, array $state): void
    {
        if (!@rename($partPath, $this->dumpPath())) {
            throw new \RuntimeException('Unable to move the new dump into place at ' . $this->dumpPath());
        }
        $statePart = $this->directory . '/.state-' . bin2hex(random_bytes(6)) . '.part';
        $json = json_encode(
            $state,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
        if (@file_put_contents($statePart, $json . "\n", LOCK_EX) === false
            || !@rename($statePart, $this->directory . '/' . self::STATE)
        ) {
            @unlink($statePart);
            throw new \RuntimeException('Unable to write the dump state in ' . $this->directory);
        }
    }

    public function discard(string $partPath): void
    {
        if (is_file($partPath)) {
            @unlink($partPath);
        }
    }

    /** Remove everything the module wrote (uninstall). */
    public function clear(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $entry) {
            $path = $this->directory . '/' . $entry;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->directory);
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create the dump directory ' . $this->directory);
        }
    }
}
