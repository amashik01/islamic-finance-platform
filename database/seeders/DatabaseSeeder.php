<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        // Demo records are clearly flagged (is_demo) and never seeded in production.
        if (! app()->isProduction()) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
