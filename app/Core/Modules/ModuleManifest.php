<?php

declare(strict_types=1);

namespace App\Core\Modules;

use JsonException;

/**
 * The contents of one module.json. See docs/module-standard.md, section 3.
 */
final readonly class ModuleManifest
{
    /**
     * @param  list<string>  $requires
     * @param  list<string>  $suggests
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public string $version,
        public string $core,
        public array $requires,
        public array $suggests,
        public string $tablePrefix,
        public bool $defaultEnabled,
        public string $entry,
        public string $path,
    ) {}

    public static function fromFile(string $file): self
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidManifest("{$file}: not valid JSON ({$e->getMessage()}).");
        }

        if (! is_array($data)) {
            throw new InvalidManifest("{$file}: the manifest must be a JSON object.");
        }

        $fail = fn (string $problem) => throw new InvalidManifest("{$file}: {$problem}");

        $string = function (string $field, ?string $pattern = null, ?string $default = null) use ($data, $fail): string {
            $value = $data[$field] ?? $default;

            if (! is_string($value) || $value === '') {
                $fail("\"{$field}\" is required and must be text.");
            }

            if ($pattern !== null && ! preg_match($pattern, $value)) {
                $fail("\"{$field}\" has an invalid value [{$value}].");
            }

            return $value;
        };

        $list = function (string $field) use ($data, $fail): array {
            $value = $data[$field] ?? [];

            if (! is_array($value) || array_filter($value, fn ($v) => ! is_string($v) || $v === '') !== []) {
                $fail("\"{$field}\" must be a list of module keys.");
            }

            return array_values(array_unique($value));
        };

        $key = $string('key', '/^[a-z][a-z0-9_]{1,39}$/');

        if ($key === 'core') {
            $fail('"core" is reserved and cannot be used as a module key.');
        }

        $requires = $list('requires');

        if (in_array($key, $requires, true)) {
            $fail('a module cannot require itself.');
        }

        if (isset($data['default_enabled']) && ! is_bool($data['default_enabled'])) {
            $fail('"default_enabled" must be true or false.');
        }

        return new self(
            key: $key,
            name: $string('name'),
            description: $string('description', default: '-'),
            version: $string('version', '/^\d+\.\d+\.\d+$/'),
            core: $string('core', '/^>=\d+\.\d+\.\d+$/', '>=1.0.0'),
            requires: $requires,
            suggests: $list('suggests'),
            tablePrefix: $string('table_prefix', '/^[a-z][a-z0-9]{1,10}_$/'),
            defaultEnabled: $data['default_enabled'] ?? false,
            entry: $string('entry'),
            path: dirname($file),
        );
    }

    public function migrationsPath(): string
    {
        return $this->path.'/database/migrations';
    }

    /** Whether the running core version meets this module's "core" constraint. */
    public function supportsCore(string $coreVersion): bool
    {
        return version_compare($coreVersion, substr($this->core, 2), '>=');
    }
}
