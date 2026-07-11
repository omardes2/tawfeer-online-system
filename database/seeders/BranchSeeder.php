<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\Branch;
use Illuminate\Database\Seeder;

// الفرع الافتراضي (المبدأ 1). نبدأ بفرع واحد، والبنية جاهزة للتوسّع.
class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::query()->firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'الفرع الرئيسي',
                'address' => null,
                'phone' => null,
                'is_default' => true,
                'is_active' => true,
            ],
        );
    }
}
