<?php
declare(strict_types=1);

/*
 * Unit-test bootstrap: no Omeka, no database. The pure classes (RDF model and
 * serialisers, projection, dump, dataset description) only touch three
 * framework types, stubbed here; everything that needs real Omeka is covered
 * by tests/integration/omeka-smoke.php.
 */

namespace Doctrine\DBAL {
    if (!class_exists(Connection::class)) {
        class Connection
        {
            /** @return array<int,array<string,mixed>> */
            public function fetchAllAssociative(string $query, array $params = []): array
            {
                throw new \LogicException('Unit tests use FakeSource; the connection is never queried.');
            }

            public function quote($value): string
            {
                return "'" . str_replace("'", "''", (string) $value) . "'";
            }
        }
    }
}

namespace Laminas\Log {
    if (!interface_exists(LoggerInterface::class)) {
        interface LoggerInterface
        {
            public function emerg($message, $extra = []);
            public function alert($message, $extra = []);
            public function crit($message, $extra = []);
            public function err($message, $extra = []);
            public function warn($message, $extra = []);
            public function notice($message, $extra = []);
            public function info($message, $extra = []);
            public function debug($message, $extra = []);
        }
    }
}

namespace Omeka\Settings {
    if (!class_exists(Settings::class)) {
        class Settings
        {
            /** @param array<string,mixed> $values */
            public function __construct(private array $values = [])
            {
            }

            public function get($id, $default = null)
            {
                return $this->values[$id] ?? $default;
            }

            public function set($id, $value): void
            {
                $this->values[$id] = $value;
            }

            public function delete($id): void
            {
                unset($this->values[$id]);
            }
        }
    }
}

namespace {
    use Doctrine\DBAL\Connection;
    use DRELinkedData\Projection\OmekaSource;

    spl_autoload_register(static function (string $class): void {
        $prefix = 'DRELinkedData\\';
        if (str_starts_with($class, $prefix)) {
            $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });

    final class MemoryLogger implements \Laminas\Log\LoggerInterface
    {
        /** @var array<string,array<int,string>> */
        public array $records = [];

        private function record(string $level, $message): self
        {
            $this->records[$level][] = (string) $message;
            return $this;
        }

        public function emerg($message, $extra = []): self { return $this->record('emerg', $message); }
        public function alert($message, $extra = []): self { return $this->record('alert', $message); }
        public function crit($message, $extra = []): self { return $this->record('crit', $message); }
        public function err($message, $extra = []): self { return $this->record('err', $message); }
        public function warn($message, $extra = []): self { return $this->record('warn', $message); }
        public function notice($message, $extra = []): self { return $this->record('notice', $message); }
        public function info($message, $extra = []): self { return $this->record('info', $message); }
        public function debug($message, $extra = []): self { return $this->record('debug', $message); }
    }

    /**
     * An in-memory OmekaSource. Rows are what the real queries would return:
     * visibility filtering happens in SQL, so the fixtures hold only visible
     * resources and public values (with target_visible precomputed), and the
     * smoke test checks the SQL itself against a real database.
     */
    final class FakeSource extends OmekaSource
    {
        /** @var array<int,array<string,mixed>> */
        public array $resourceRows = [];
        /** @var array<int,array<string,mixed>> */
        public array $valueRows = [];
        /** @var array<int,array{item_id:int,item_set_id:int}> */
        public array $memberships = [];
        /** @var array<int,array{item_id:int,id:int}> */
        public array $itemMediaRows = [];
        /** @var array<int,array<string,mixed>> */
        public array $mediaRows = [];
        /** @var array<int,array{iri:string,term:string}> */
        public array $propertyMap = [];
        /** @var array<int,string> */
        public array $classMap = [];
        /** @var array<int,array{prefix:string,namespace_uri:string}> */
        public array $vocabularyRows = [];

        public function __construct()
        {
            parent::__construct(new Connection());
        }

        public function resources(array $ids): array
        {
            $rows = array_values(array_filter($this->resourceRows, static fn (array $row): bool => in_array((int) $row['id'], $ids, true)));
            usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            return $rows;
        }

        public function idsAfter(int $afterId, int $limit): array
        {
            $ids = array_map(static fn (array $row): int => (int) $row['id'], $this->resourceRows);
            sort($ids);
            return array_slice(array_values(array_filter($ids, static fn (int $id): bool => $id > $afterId)), 0, $limit);
        }

        public function values(array $ids): array
        {
            return array_values(array_filter($this->valueRows, static fn (array $row): bool => in_array((int) $row['resource_id'], $ids, true)));
        }

        public function itemSetMemberships(array $itemIds): array
        {
            return array_values(array_filter($this->memberships, static fn (array $row): bool => in_array((int) $row['item_id'], $itemIds, true)));
        }

        public function itemMedia(array $itemIds): array
        {
            return array_values(array_filter($this->itemMediaRows, static fn (array $row): bool => in_array((int) $row['item_id'], $itemIds, true)));
        }

        public function media(array $mediaIds): array
        {
            return array_values(array_filter($this->mediaRows, static fn (array $row): bool => in_array((int) $row['id'], $mediaIds, true)));
        }

        /** @var array<int,int[]> every properties() request, in order */
        public array $propertyRequests = [];
        /** @var array<int,int[]> every classes() request, in order */
        public array $classRequests = [];

        public function properties(array $ids): array
        {
            $this->propertyRequests[] = $ids;
            return array_intersect_key($this->propertyMap, array_flip($ids));
        }

        public function classes(array $ids): array
        {
            $this->classRequests[] = $ids;
            return array_intersect_key($this->classMap, array_flip($ids));
        }

        public function vocabularies(): array
        {
            return $this->vocabularyRows;
        }

        public function itemSetsWithMembersOfClass(array $itemSetIds, array $classIris): array
        {
            $classIds = array_keys(array_filter($this->classMap, static fn (string $iri): bool => in_array($iri, $classIris, true)));
            $classOf = [];
            foreach ($this->resourceRows as $row) {
                $classOf[(int) $row['id']] = $row['resource_class_id'];
            }
            $sets = [];
            foreach ($this->memberships as $membership) {
                $setId = (int) $membership['item_set_id'];
                if (in_array($setId, $itemSetIds, true) && in_array($classOf[(int) $membership['item_id']] ?? null, $classIds, true)) {
                    $sets[$setId] = true;
                }
            }
            ksort($sets);
            return array_keys($sets);
        }

        /** @var array<int,array{0:int,1:string[]}> every termValues() request */
        public array $termRequests = [];

        public function termValues(int $id, array $terms): array
        {
            $this->termRequests[] = [$id, $terms];
            $rows = [];
            foreach ($this->valueRows as $row) {
                $term = $this->propertyMap[(int) $row['property_id']]['term'] ?? null;
                if ((int) $row['resource_id'] === $id && $row['value_resource_id'] === null && in_array($term, $terms, true)) {
                    $rows[] = ['term' => $term, 'value' => $row['value'], 'uri' => $row['uri']];
                }
            }
            return $rows;
        }

        public function isVisible(int $id): bool
        {
            return $this->resources([$id]) !== [];
        }
    }

    /** @var array<string,Closure():void> $tests */
    $tests = [];

    function test(string $name, Closure $callback): void
    {
        global $tests;
        $tests[$name] = $callback;
    }

    function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $detail = sprintf('Expected %s, got %s', var_export($expected, true), var_export($actual, true));
            throw new RuntimeException($message !== '' ? $message . ': ' . $detail : $detail);
        }
    }

    function assertContainsText(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException(($message !== '' ? $message . ': ' : '') . "missing\n    {$needle}\nin\n{$haystack}");
        }
    }

    function assertNotContainsText(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            throw new RuntimeException(($message !== '' ? $message . ': ' : '') . "unexpected\n    {$needle}\nin\n{$haystack}");
        }
    }

    /** The module's projection config, as Omeka would merge it. */
    function moduleConfig(): array
    {
        return (include dirname(__DIR__) . '/config/module.config.php')['dre_linked_data'];
    }

    function tempDirectory(string $label): string
    {
        $dir = sys_get_temp_dir() . '/dre-ld-' . $label . '-' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        return $dir;
    }

    function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $path = $dir . '/' . $entry;
                is_dir($path) ? removeDirectory($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }

    require __DIR__ . '/fixtures.php';
}
