<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Modules\ModuleRegistry;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/dms-registry-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*/module.json') as $file) {
            unlink($file);
            rmdir(dirname($file));
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function module(string $key, array $requires = [], array $overrides = []): void
    {
        mkdir("{$this->dir}/{$key}");
        file_put_contents("{$this->dir}/{$key}/module.json", json_encode($overrides + [
            'key' => $key,
            'name' => ucfirst($key),
            'version' => '1.0.0',
            'requires' => $requires,
            'table_prefix' => $key.'_',
            'entry' => 'Some\\Entry',
        ]));
    }

    private function registry(string $core = '1.0.0'): ModuleRegistry
    {
        return new ModuleRegistry($this->dir, $core);
    }

    public function test_modules_load_after_the_modules_they_require(): void
    {
        $this->module('reports', ['laboratory']);
        $this->module('laboratory', ['frontdesk']);
        $this->module('frontdesk');
        $this->module('finance', ['frontdesk']);
        $this->module('portal', ['reports', 'finance']);

        $registry = $this->registry();
        $order = array_keys($registry->all());

        $this->assertSame([], $registry->errors());
        $this->assertSame(['frontdesk', 'finance', 'laboratory', 'reports', 'portal'], $order);
        $this->assertEqualsCanonicalizing(['finance', 'laboratory'], $registry->dependentsOf('frontdesk'));
        $this->assertSame([], $registry->dependentsOf('portal'));
    }

    public function test_an_empty_folder_is_valid(): void
    {
        $this->assertSame([], $this->registry()->all());
        $this->assertSame([], $this->registry()->errors());
    }

    public function test_a_dependency_cycle_is_reported_and_excluded(): void
    {
        $this->module('aaa', ['ccc']);
        $this->module('bbb', ['aaa']);
        $this->module('ccc', ['bbb']);
        $this->module('solo');

        $registry = $this->registry();

        $this->assertSame(['solo'], array_keys($registry->all()));
        $this->assertCount(1, $registry->errors());
        $this->assertStringContainsString('cycle', $registry->errors()[0]);
    }

    public function test_a_missing_requirement_removes_the_module_and_its_dependents(): void
    {
        $this->module('base');
        $this->module('needs_ghost', ['ghost']);
        $this->module('needs_needs_ghost', ['needs_ghost']);

        $registry = $this->registry();

        $this->assertSame(['base'], array_keys($registry->all()));
        $this->assertCount(2, $registry->errors());
    }

    public function test_duplicate_table_prefixes_are_refused(): void
    {
        $this->module('one', [], ['table_prefix' => 'same_']);
        $this->module('two', [], ['table_prefix' => 'same_']);

        $registry = $this->registry();

        $this->assertSame(['one'], array_keys($registry->all()));
        $this->assertStringContainsString('same_', $registry->errors()[0]);
    }

    public function test_a_module_needing_a_newer_core_is_refused(): void
    {
        $this->module('future', [], ['core' => '>=2.0.0']);
        $this->module('current', [], ['core' => '>=1.0.0']);

        $this->assertSame(['current'], array_keys($this->registry('1.4.0')->all()));
        $this->assertSame(['current', 'future'], array_keys($this->registry('2.0.0')->all()));
    }

    public function test_invalid_manifests_are_reported_not_loaded(): void
    {
        $this->module('good');
        $this->module('badversion', [], ['version' => 'one']);
        $this->module('badprefix', [], ['table_prefix' => 'NoUnderscore']);
        $this->module('selfish', ['selfish']);
        $this->module('reserved', [], ['key' => 'core']);
        $this->module('badjson');
        file_put_contents("{$this->dir}/badjson/module.json", '{not json');

        $registry = $this->registry();

        $this->assertSame(['good'], array_keys($registry->all()));
        $this->assertCount(5, $registry->errors());
    }
}
