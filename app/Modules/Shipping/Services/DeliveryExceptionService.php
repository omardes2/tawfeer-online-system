<?php

namespace App\Modules\Shipping\Services;

use App\Models\User;
use App\Modules\Shipping\Models\DeliveryException;
use App\Modules\Shipping\Models\DeliveryExceptionCategory;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك استثناءات التوصيل (Phase 4.3 / ADR-039): فتح/إسناد/ملاحظة/تصعيد/حلّ/إعادة فتح.
 * SLA وقواعد التصعيد من فئة قابلة للضبط. كل المنطق هنا (لا تكرار).
 */
class DeliveryExceptionService
{
    private const OPEN_STATES = ['open', 'in_progress', 'escalated', 'reopened'];

    /** فتح استثناء لشحنة بفئة مُصنّفة — يحسب مهلة SLA من الفئة. */
    public function open(Shipment $shipment, DeliveryExceptionCategory $category, array $opts = []): DeliveryException
    {
        return DB::transaction(function () use ($shipment, $category, $opts) {
            $exception = DeliveryException::create([
                'shipment_id' => $shipment->id,
                'category_id' => $category->id,
                'status' => 'open',
                'assigned_to' => $opts['assigned_to'] ?? null,
                'sla_due_at' => $category->sla_hours ? now()->addHours($category->sla_hours) : null,
                'opened_by' => $opts['actor']?->id ?? auth()->id(),
            ]);
            if (! empty($opts['note'])) {
                $this->addNote($exception, $opts['note'], $opts['actor'] ?? null);
            }

            return $exception;
        });
    }

    /** إسناد الاستثناء لموظف مسؤول. */
    public function assign(DeliveryException $exception, User $assignee, ?User $actor = null): DeliveryException
    {
        $exception->update(['assigned_to' => $assignee->id, 'status' => $exception->status === 'open' ? 'in_progress' : $exception->status]);
        $this->addNote($exception, __('أُسنِد إلى :name', ['name' => $assignee->name]), $actor, 'status_change');

        return $exception;
    }

    /** ملاحظة داخلية (append-only). */
    public function addNote(DeliveryException $exception, string $note, ?User $actor = null, string $kind = 'note'): void
    {
        $exception->notes()->create([
            'note' => $note,
            'kind' => $kind,
            'author_id' => $actor?->id ?? auth()->id(),
            'created_at' => now(),
        ]);
    }

    /** تصعيد يدوي. */
    public function escalate(DeliveryException $exception, ?User $actor = null, ?string $note = null): DeliveryException
    {
        if ($exception->status === 'resolved') {
            throw ValidationException::withMessages(['status' => __('لا يمكن تصعيد استثناء محلول.')]);
        }
        $exception->update(['status' => 'escalated', 'escalated_at' => now()]);
        $this->addNote($exception, $note ?? __('تم التصعيد'), $actor, 'escalation');

        return $exception;
    }

    /** حلّ الاستثناء. */
    public function resolve(DeliveryException $exception, string $resolution, ?User $actor = null): DeliveryException
    {
        if ($exception->status === 'resolved') {
            throw ValidationException::withMessages(['status' => __('الاستثناء محلول مسبقًا.')]);
        }
        $exception->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution' => $resolution]);
        $this->addNote($exception, __('حُلّ: :r', ['r' => $resolution]), $actor, 'status_change');

        return $exception;
    }

    /** إعادة فتح استثناء محلول. */
    public function reopen(DeliveryException $exception, ?User $actor = null, ?string $note = null): DeliveryException
    {
        if ($exception->status !== 'resolved') {
            throw ValidationException::withMessages(['status' => __('يمكن إعادة فتح المحلول فقط.')]);
        }
        $exception->update([
            'status' => 'reopened',
            'resolved_at' => null,
            'reopened_count' => $exception->reopened_count + 1,
        ]);
        $this->addNote($exception, $note ?? __('أُعيد الفتح'), $actor, 'status_change');

        return $exception;
    }

    /**
     * تصعيد مجدول للاستثناءات المتجاوزة مهلة التصعيد (يُستدعى من مهمّة). idempotent.
     *
     * @return int عدد المُصعَّدة
     */
    public function escalateOverdue(): int
    {
        $count = 0;
        DeliveryException::with('category')
            ->whereIn('status', ['open', 'in_progress', 'reopened'])
            ->whereNull('escalated_at')
            ->chunkById(200, function ($exceptions) use (&$count) {
                foreach ($exceptions as $exception) {
                    $hours = $exception->category?->escalate_after_hours;
                    if ($hours === null) {
                        continue;
                    }
                    if ($exception->created_at->copy()->addHours($hours)->isPast()) {
                        $this->escalate($exception, null, __('تصعيد تلقائي بعد :h ساعة', ['h' => $hours]));
                        $count++;
                    }
                }
            });

        return $count;
    }

    /** استثناءات مفتوحة متجاوزة SLA (للتقارير). */
    public function breachedCount(): int
    {
        return DeliveryException::breached()->count();
    }

    public function isOpen(DeliveryException $exception): bool
    {
        return in_array($exception->status, self::OPEN_STATES, true);
    }
}
