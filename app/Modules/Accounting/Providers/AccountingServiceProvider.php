<?php

namespace App\Modules\Accounting\Providers;

use App\Modules\Accounting\Events\FinancialEventOccurred;
use App\Modules\Accounting\Listeners\PostFinancialEventToLedger;
use App\Modules\Accounting\Models\ExpenseCategory;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Policies\ExpenseCategoryPolicy;
use App\Modules\Accounting\Policies\JournalEntryPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * يسجّل سياسة القيود ويربط مستمع المحاسبة بالحدث المالي (عزل عبر الأحداث — المتطلّب 6).
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(JournalEntry::class, JournalEntryPolicy::class);
        Gate::policy(ExpenseCategory::class, ExpenseCategoryPolicy::class);

        Event::listen(FinancialEventOccurred::class, PostFinancialEventToLedger::class);
    }
}
