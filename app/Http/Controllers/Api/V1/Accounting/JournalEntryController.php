<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReverseJournalEntryRequest;
use App\Http\Requests\Accounting\StoreJournalEntryRequest;
use App\Http\Resources\Accounting\JournalEntryResource;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class JournalEntryController extends Controller
{
    public function __construct(private readonly AccountingService $accounting) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', JournalEntry::class);

        $query = JournalEntry::query()->with('reversal:id,reverses_entry_id,status');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return JournalEntryResource::collection($query->latest('id')->paginate($this->perPage()));
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        $this->authorize('view', $journalEntry);

        return new JournalEntryResource($journalEntry->load('lines.account'));
    }

    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $this->authorize('create', JournalEntry::class);

        $entry = $this->accounting->createEntry(
            ['entry_date' => $request->validated('entry_date'), 'description' => $request->validated('description')],
            $this->lines($request),
        );

        if ($request->boolean('post')) {
            $this->authorize('post', $entry);
            $this->accounting->post($entry);
        }

        return (new JournalEntryResource($entry->load('lines.account')))->response()->setStatusCode(201);
    }

    public function post(JournalEntry $journalEntry): JournalEntryResource
    {
        $this->authorize('post', $journalEntry);

        return new JournalEntryResource($this->accounting->post($journalEntry)->load('lines.account'));
    }

    public function reverse(ReverseJournalEntryRequest $request, JournalEntry $journalEntry): JsonResponse
    {
        $this->authorize('reverse', $journalEntry);

        $reversal = $this->accounting->reverse($journalEntry, [
            'date' => $request->validated('date'),
            'description' => $request->validated('description'),
        ]);

        return (new JournalEntryResource($reversal->load('lines.account')))->response()->setStatusCode(201);
    }

    private function lines(StoreJournalEntryRequest $request): array
    {
        return collect($request->validated('lines'))->map(fn ($l) => [
            'account_code' => $l['account_code'],
            'debit' => $l['debit'] ?? 0,
            'credit' => $l['credit'] ?? 0,
            'description' => $l['description'] ?? null,
        ])->all();
    }
}
