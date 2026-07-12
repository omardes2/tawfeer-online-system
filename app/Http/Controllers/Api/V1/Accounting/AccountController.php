<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Resources\Accounting\AccountResource;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('accounting.accounts.view'), 403);

        $query = Account::query()->with('parent');
        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        return AccountResource::collection($query->orderBy('code')->get());
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        abort_unless($request->user()?->can('accounting.accounts.manage'), 403);

        $data = $request->safe()->except('parent_code');
        if ($request->filled('parent_code')) {
            $data['parent_id'] = Account::where('code', $request->validated('parent_code'))->value('id');
        }

        $account = Account::create($data);

        return (new AccountResource($account->load('parent')))->response()->setStatusCode(201);
    }

    public function balance(Request $request, Account $account): JsonResponse
    {
        abort_unless($request->user()?->can('accounting.reports.view'), 403);

        return response()->json([
            'account_code' => $account->code,
            'account_name' => $account->name,
            'balance' => $this->accounting->accountBalance($account),
        ]);
    }
}
