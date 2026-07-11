@props(['label', 'name'])
<div>
    <label class="block text-sm font-medium text-gray-700 mb-1">{{ $label }}</label>
    {{ $slot }}
    @error($name)
        <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
    @enderror
</div>
