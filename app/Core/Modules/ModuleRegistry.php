<?php

declare(strict_types=1);

namespace App\Core\Modules;

/**
 * Discovers the modules present in the code base, validates them, and puts
 * them in dependency order. It knows nothing about any clinic.
 */
final class ModuleRegistry
{
    /** @var array<string, ModuleManifest>|null usable modules, in load order */
    private ?array $modules = null;

    /** @var list<string> */
    private array $errors = [];

    /** @var array<string, Module> */
    private array $instances = [];

    public function __construct(
        private readonly string $path,
        private readonly string $coreVersion,
    ) {}

    /** @return array<string, ModuleManifest> usable modules, each after the modules it requires */
    public function all(): array
    {
        $this->load();

        return $this->modules;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function get(string $key): ?ModuleManifest
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<string> every problem found; empty when all manifests are sound */
    public function errors(): array
    {
        $this->load();

        return $this->errors;
    }

    /** @return list<string> keys of modules that directly require the given module */
    public function dependentsOf(string $key): array
    {
        return array_keys(array_filter(
            $this->all(),
            fn (ModuleManifest $m): bool => in_array($key, $m->requires, true),
        ));
    }

    /** The module's entry object. */
    public function module(string $key): Module
    {
        $manifest = $this->get($key) ?? throw ModuleException::unknown($key);

        return $this->instances[$key] ??= new ($manifest->entry)($manifest);
    }

    /** @return list<string> problems with entry classes, which need the classes to be loadable */
    public function entryErrors(): array
    {
        $errors = [];

        foreach ($this->all() as $manifest) {
            if (! class_exists($manifest->entry)) {
                $errors[] = "Module [{$manifest->key}]: entry class {$manifest->entry} was not found.";
            } elseif (! is_subclass_of($manifest->entry, Module::class)) {
                $errors[] = "Module [{$manifest->key}]: entry class {$manifest->entry} must extend ".Module::class.'.';
            }
        }

        return $errors;
    }

    private function load(): void
    {
        if ($this->modules !== null) {
            return;
        }

        $this->errors = [];
        $found = [];
        $prefixes = [];

        $files = glob(rtrim($this->path, '/').'/*/module.json') ?: [];
        sort($files);

        foreach ($files as $file) {
            try {
                $manifest = ModuleManifest::fromFile($file);
            } catch (InvalidManifest $e) {
                $this->errors[] = $e->getMessage();

                continue;
            }

            if (isset($found[$manifest->key])) {
                $this->errors[] = "Module key [{$manifest->key}] is used by more than one module.";

                continue;
            }

            if (isset($prefixes[$manifest->tablePrefix])) {
                $this->errors[] = "Table prefix [{$manifest->tablePrefix}] is used by both [{$prefixes[$manifest->tablePrefix]}] and [{$manifest->key}].";

                continue;
            }

            if (! $manifest->supportsCore($this->coreVersion)) {
                $this->errors[] = "Module [{$manifest->key}] needs core {$manifest->core}, but core is {$this->coreVersion}.";

                continue;
            }

            $found[$manifest->key] = $manifest;
            $prefixes[$manifest->tablePrefix] = $manifest->key;
        }

        $this->modules = $this->sort($this->dropUnsatisfied($found));
    }

    /**
     * Remove modules whose requirements are missing. Repeats until stable,
     * because removing one module can leave another without its requirement.
     *
     * @param  array<string, ModuleManifest>  $modules
     * @return array<string, ModuleManifest>
     */
    private function dropUnsatisfied(array $modules): array
    {
        do {
            $removed = false;

            foreach ($modules as $key => $manifest) {
                $missing = array_diff($manifest->requires, array_keys($modules));

                if ($missing !== []) {
                    $this->errors[] = "Module [{$key}] requires [".implode(', ', $missing).'], which is not available.';
                    unset($modules[$key]);
                    $removed = true;
                }
            }
        } while ($removed);

        return $modules;
    }

    /**
     * Topological sort (Kahn's algorithm): a module is placed only after
     * everything it requires. Modules left over are part of a cycle.
     *
     * @param  array<string, ModuleManifest>  $modules
     * @return array<string, ModuleManifest>
     */
    private function sort(array $modules): array
    {
        ksort($modules);

        $waitingOn = array_map(fn (ModuleManifest $m): array => array_flip($m->requires), $modules);
        $sorted = [];

        while ($waitingOn !== []) {
            $ready = array_keys(array_filter($waitingOn, fn (array $deps): bool => $deps === []));

            if ($ready === []) {
                $this->errors[] = 'Modules ['.implode(', ', array_keys($waitingOn)).'] require each other in a cycle.';

                break;
            }

            foreach ($ready as $key) {
                $sorted[$key] = $modules[$key];
                unset($waitingOn[$key]);
            }

            foreach ($waitingOn as $key => $deps) {
                $waitingOn[$key] = array_diff_key($deps, array_flip($ready));
            }
        }

        return $sorted;
    }
}
