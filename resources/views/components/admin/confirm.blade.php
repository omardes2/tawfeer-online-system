{{--
    Confirmation dialog for destructive actions. Renders a trigger button that
    opens an accessible modal; confirming submits a form to `action` with the
    given `method` (CSRF included). Preserves standard Laravel form posting.

    Props: action(url), method(DELETE|POST...), title, message, confirm(label),
           tone(red|amber|green). Slot = trigger content (defaults to a label).
--}}
@props([
    'action',
    'method' => 'DELETE',
    'title' => 'تأكيد الإجراء',
    'message' => 'هل أنت متأكد؟ لا يمكن التراجع عن هذا الإجراء.',
    'confirm' => 'تأكيد',
    'tone' => 'red',
    'trigger' => 'حذف',
])

@php $btn = ['red' => 'btn-danger', 'amber' => 'btn bg-amber-500 text-white hover:bg-amber-600', 'green' => 'btn-primary'][$tone] ?? 'btn-danger'; @endphp

<div x-data="{ open: false }" class="inline-block">
    <button type="button" @click="open = true" class="text-rose-600 hover:text-rose-700 text-sm font-medium">{{ $trigger }}</button>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-slate-900/50" @click="open = false"></div>
            <div class="relative admin-card w-full max-w-md p-6" @keydown.escape.window="open = false" x-transition>
                <div class="flex items-start gap-3">
                    <span class="grid place-items-center w-11 h-11 rounded-full bg-rose-50 text-rose-500 shrink-0">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold text-gray-900">{{ $title }}</h3>
                        <p class="mt-1 text-sm text-gray-500">{{ $message }}</p>
                    </div>
                </div>
                <div class="mt-6 flex items-center justify-end gap-2">
                    <button type="button" @click="open = false" class="btn-secondary">{{ __('إلغاء') }}</button>
                    <form method="POST" action="{{ $action }}">
                        @csrf
                        @if (!in_array(strtoupper($method), ['POST', 'GET']))
                            @method($method)
                        @endif
                        <button type="submit" class="{{ $btn }}">{{ $confirm }}</button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
