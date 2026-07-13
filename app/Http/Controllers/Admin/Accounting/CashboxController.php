<?php

namespace App\Http\Controllers\Admin\Accounting;

/**
 * إدارة الخزائن النقدية (Phase 7.1). صلاحيات accounting.cashboxes.*.
 */
class CashboxController extends TreasuryControllerBase
{
    protected function type(): string
    {
        return 'cash';
    }

    protected function perm(): string
    {
        return 'cashboxes';
    }

    protected function views(): string
    {
        return 'cashboxes';
    }

    protected function routeName(): string
    {
        return 'cashboxes';
    }
}
