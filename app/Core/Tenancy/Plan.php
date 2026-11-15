<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Plan extends Model
{
    public const ALL_MODULES = '*';

    protected $connection = 'control';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return list<string> */
    public function moduleKeys(): array
    {
        return DB::connection('control')->table('plan_modules')
            ->where('plan_id', $this->id)
            ->pluck('module_key')
            ->all();
    }

    /** @param list<string> $keys */
    public function setModuleKeys(array $keys): void
    {
        DB::connection('control')->transaction(function () use ($keys): void {
            DB::connection('control')->table('plan_modules')->where('plan_id', $this->id)->delete();
            foreach (array_unique($keys) as $key) {
                DB::connection('control')->table('plan_modules')->insert(['plan_id' => $this->id, 'module_key' => $key]);
            }
        });
    }
}
