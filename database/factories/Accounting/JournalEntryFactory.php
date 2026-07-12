<?php

namespace Database\Factories\Accounting;

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'number' => 'JE-'.$this->faker->unique()->numberBetween(100000, 999999),
            'fiscal_year_id' => FiscalYear::factory(),
            'entry_date' => now()->toDateString(),
            'source' => 'manual',
            'status' => 'draft',
        ];
    }
}
