{{--
    Form field wrapper — label (above), slot for the input, hint, and validation
    error. Backward compatible: existing usages pass :label and name only.
    Props: label, name, required(bool), hint(string|null).
--}}
@props(['label', 'name', 'required' => false, 'hint' => null])

<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">
        {{ $label }}
        @if ($required)<span class="text-rose-500">*</span>@endif
    </label>
    {{ $slot }}
    @if ($hint)
        <p class="mt-1 text-xs text-gray-400">{{ $hint }}</p>
    @endif
    @error($name)
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
