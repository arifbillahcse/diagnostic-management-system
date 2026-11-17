<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Plan extends Model
{
    public const ALL_MODULES = '*';

    protected $connection = 'control';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price_monthly' => MoneyCast::class,
            'price_yearly' => MoneyCast::class,
            'max_branches' => 'integer',
        ];
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

    public function includesModule(string $key): bool
    {
        $keys = $this->moduleKeys();

        return in_array(self::ALL_MODULES, $keys, true) || in_array($key, $keys, true);
    }

    public function branchLimitLabel(): string
    {
        return $this->max_branches === null ? 'Any number of branches' : ($this->max_branches === 1 ? '1 branch' : "{$this->max_branches} branches");
    }
}
