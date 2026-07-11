<?php

namespace App\Support\Concerns;

use App\Modules\Foundation\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * يجعل النموذج قابلًا للتدقيق آليًا (المبدأ 8 في ARCHITECTURE.md).
 *
 * يسجّل عمليات الإنشاء/التعديل/الحذف في audit_logs دون أن يعتمد على
 * تذكّر المطوّر يدويًا. يمكن للنموذج استثناء حقول عبر $auditExclude.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->recordAudit('created', [], $model->auditableAttributes()));

        static::updated(function ($model) {
            $changes = $model->getChanges();
            unset($changes['updated_at']);

            if (empty($changes)) {
                return;
            }

            $old = array_intersect_key($model->getOriginal(), $changes);

            $model->recordAudit(
                'updated',
                $model->filterAuditable($old),
                $model->filterAuditable($changes)
            );
        });

        static::deleted(fn ($model) => $model->recordAudit('deleted', $model->auditableAttributes(), []));
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable');
    }

    /**
     * الحقول المستثناة من التدقيق (كلمات المرور، التوكنات...).
     */
    protected function auditExclude(): array
    {
        return array_merge(['password', 'remember_token'], property_exists($this, 'auditExclude') ? $this->auditExclude : []);
    }

    protected function filterAuditable(array $attributes): array
    {
        return array_diff_key($attributes, array_flip($this->auditExclude()));
    }

    protected function auditableAttributes(): array
    {
        return $this->filterAuditable($this->getAttributes());
    }

    protected function recordAudit(string $action, array $old, array $new): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }
}
