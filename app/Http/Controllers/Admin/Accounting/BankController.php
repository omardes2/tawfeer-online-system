<?php

namespace App\Http\Controllers\Admin\Accounting;

/**
 * إدارة الحسابات البنكية (Phase 7.1). صلاحيات accounting.banks.*.
 * الإيداع/السحب بين البنك والصندوق يتمّان عبر التحويلات (TransferController).
 */
class BankController extends TreasuryControllerBase
{
    protected function type(): string
    {
        return 'bank';
    }

    protected function perm(): string
    {
        return 'banks';
    }

    protected function views(): string
    {
        return 'banks';
    }

    protected function routeName(): string
    {
        return 'banks';
    }
}
