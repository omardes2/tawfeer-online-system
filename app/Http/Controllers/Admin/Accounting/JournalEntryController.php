<?php

namespace App\Http\Controllers\Admin\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReverseJournalEntryRequest;
use App\Http\Requests\Accounting\StoreJournalEntryRequest;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class JournalEntryController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function index(): View
    {
        $this->authorize('viewAny', JournalEntry::class);

        return view('admin.accounting.journal.index', [
            'entries' => JournalEntry::latest('id')->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', JournalEntry::class);

        return view('admin.accounting.journal.form', [
            'accounts' => Account::postable()->where('is_active', true)->orderBy('code')->get(),
        ]);
    }

    public function store(StoreJournalEntryRequest $request): RedirectResponse
    {
        $this->authorize('create', JournalEntry::class);

        $lines = collect($request->validated('lines'))->map(fn ($l) => [
            'account_code' => $l['account_code'],
            'debit' => $l['debit'] ?? 0,
            'credit' => $l['credit'] ?? 0,
            'description' => $l['description'] ?? null,
        ])->all();

        try {
            $entry = $this->accounting->createEntry([
                'entry_date' => $request->validated('entry_date'),
                'description' => $request->validated('description'),
            ], $lines);

            if ($request->boolean('post') && $request->user()->can('accounting.journal.post')) {
                $this->accounting->post($entry);
            }
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.accounting.journal.show', $entry)->with('success', __('أُنشئ القيد.'));
    }

    public function show(JournalEntry $journalEntry): View
    {
        $this->authorize('view', $journalEntry);

        return view('admin.accounting.journal.show', [
            'entry' => $journalEntry->load('lines.account'),
        ]);
    }

    public function post(JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('post', $journalEntry);

        return $this->guard(fn () => $this->accounting->post($journalEntry), __('رُحّل القيد.'));
    }

    public function reverse(ReverseJournalEntryRequest $request, JournalEntry $journalEntry): RedirectResponse
    {
        $this->authorize('reverse', $journalEntry);

        return $this->guard(fn () => $this->accounting->reverse($journalEntry, [
            'description' => $request->validated('description'),
        ]), __('أُنشئ القيد العاكس.'));
    }

    private function guard(callable $fn, string $success): RedirectResponse
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
