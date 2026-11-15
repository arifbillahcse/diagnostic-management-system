<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected $connection = 'clinic';

    public function up(): void
    {
        throw new RuntimeException('This fixture migration always fails.');
    }
};
