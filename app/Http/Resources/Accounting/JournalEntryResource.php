<?php

namespace App\Http\Resources\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status,
            'source' => $this->source,
            'entry_date' => $this->entry_date?->toDateString(),
            'description' => $this->description,
            'is_reversed' => $this->isReversed(),
            'reverses_entry_id' => $this->reverses_entry_id,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'line_no' => $l->line_no,
                'account_code' => $l->account?->code,
                'account_name' => $l->account?->name,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'description' => $l->description,
            ])),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
