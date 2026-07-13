{{--
    Compact action menu (kebab). Put <a>/<button> items in the default slot.
    Props: align(end|start), label(optional aria).
--}}
@props(['align' => 'end', 'label' => 'إجراءات'])

<div class="relative inline-block text-right" x-data="{ open: false }">
    <button type="button" @click="open = !open" aria-label="{{ $label }}"
            class="grid place-items-center w-8 h-8 rounded-md text-gray-500 hover:bg-gray-100 hover:text-gray-700">
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4zm0 6a2 2 0 110-4 2 2 0 010 4z"/></svg>
    </button>
    <div x-show="open" x-cloak @click.outside="open = false" x-transition
         class="absolute {{ $align === 'end' ? 'end-0' : 'start-0' }} mt-1 w-44 rounded-lg bg-white shadow-lg ring-1 ring-black/5 p-1 z-40
                [&>a]:flex [&>a]:items-center [&>a]:gap-2 [&>a]:rounded-md [&>a]:px-3 [&>a]:py-2 [&>a]:text-sm [&>a]:text-gray-700 [&>a:hover]:bg-gray-50
                [&_button]:w-full [&_button]:flex [&_button]:items-center [&_button]:gap-2 [&_button]:rounded-md [&_button]:px-3 [&_button]:py-2 [&_button]:text-sm [&_button]:text-right">
        {{ $slot }}
    </div>
</div>
