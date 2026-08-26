{{-- حقول ملفّ الموظف — مشتركة بين الإنشاء والتعديل. --}}
@php $e = $employee ?? null; @endphp

<div class="admin-card admin-card-pad space-y-5">
    <div class="grid gap-4 md:grid-cols-2">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('المستخدم') }} <span class="text-rose-500">*</span></label>
            @if ($e)
                {{-- المستخدم لا يُغيَّر بعد الإنشاء: الملفّ يحمل مسيّرات وقيودًا
                     باسمه، ونقلُه إلى شخصٍ آخر ينقل تاريخه المالي معه. --}}
                <input type="hidden" name="user_id" value="{{ $e->user_id }}">
                <p class="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-700">
                    {{ $e->user?->name }} <span class="text-gray-400">{{ $e->user?->email }}</span>
                </p>
            @else
                <select name="user_id" required
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                    <option value="">{{ __('اختر مستخدمًا') }}</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}" @selected(old('user_id') == $u->id)>
                            {{ $u->name }} @if ($u->job_title) — {{ $u->job_title }} @endif
                        </option>
                    @endforeach
                </select>
                @if ($users->isEmpty())
                    <p class="mt-1 text-xs text-amber-600">{{ __('كل المستخدمين لهم ملفّات بالفعل.') }}</p>
                @endif
            @endif
            <x-input-error :messages="$errors->get('user_id')" class="mt-1" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('الفرع') }}</label>
            <select name="branch_id" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">{{ __('بلا فرع') }}</option>
                @foreach ($branches as $b)
                    <option value="{{ $b->id }}" @selected(old('branch_id', $e?->branch_id) == $b->id)>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('تاريخ التعيين') }} <span class="text-rose-500">*</span></label>
            <input type="date" name="hire_date" required
                   value="{{ old('hire_date', $e?->hire_date?->toDateString()) }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            <p class="mt-1 text-xs text-gray-400">{{ __('عليه يُحتسب مخصّص نهاية الخدمة ومستحقّ الإجازة.') }}</p>
            <x-input-error :messages="$errors->get('hire_date')" class="mt-1" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('تاريخ انتهاء الخدمة') }}</label>
            <input type="date" name="end_date" value="{{ old('end_date', $e?->end_date?->toDateString()) }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            <p class="mt-1 text-xs text-gray-400">{{ __('بتعبئته يُغلق الملفّ ويخرج من المسيّرات القادمة.') }}</p>
            <x-input-error :messages="$errors->get('end_date')" class="mt-1" />
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('نوع التعاقد') }}</label>
            <select name="employment_type" class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                @foreach (['full_time' => __('دوام كامل'), 'part_time' => __('دوام جزئي'), 'contract' => __('عقد')] as $k => $label)
                    <option value="{{ $k }}" @selected(old('employment_type', $e?->employment_type ?? 'full_time') === $k)>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('الإجازة السنوية (أيام)') }}</label>
            <input type="number" step="0.5" min="0" name="annual_leave_days"
                   value="{{ old('annual_leave_days', $e?->annual_leave_days ?? 14) }}"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
            <p class="mt-1 text-xs text-gray-400">{{ __('تُحتسب بالتناسب في سنة التعيين.') }}</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('رقم الهوية') }}</label>
            <input type="text" name="national_id" value="{{ old('national_id', $e?->national_id) }}" maxlength="30"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('الحساب البنكي') }}</label>
            <input type="text" name="bank_account" value="{{ old('bank_account', $e?->bank_account) }}" maxlength="60"
                   class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
        </div>
    </div>

    <input type="hidden" name="status" value="{{ old('status', $e?->status ?? 'active') }}">

    <div>
        <label class="block text-sm font-medium text-gray-700 mb-1">{{ __('ملاحظات') }}</label>
        <textarea name="notes" rows="3" maxlength="2000"
                  class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ old('notes', $e?->notes) }}</textarea>
    </div>

    <div class="flex items-center gap-3">
        <button type="submit" class="btn-primary btn-sm">{{ $e ? __('حفظ التعديل') : __('إنشاء الملفّ') }}</button>
        <a href="{{ $e ? route('admin.hr.employees.show', $e) : route('admin.hr.employees.index') }}"
           class="btn-secondary btn-sm">{{ __('إلغاء') }}</a>
    </div>
</div>
