<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('قوالب رسائل التسويق') }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <h3 class="font-semibold text-gray-800 mb-4">{{ __('قالب جديد') }}</h3>
            <form method="POST" action="{{ route('admin.marketing.templates.store') }}" class="space-y-4">
                @csrf
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الاسم')" name="name"><input type="text" name="name" value="{{ old('name') }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('القناة')" name="channel">
                        <select name="channel" class="w-full rounded-md border-gray-300">
                            @foreach (['whatsapp','email','sms','internal'] as $c)<option value="{{ $c }}">{{ __('marketing.channel.'.$c) }}</option>@endforeach
                        </select>
                    </x-admin.field>
                </div>
                <x-admin.field :label="__('الموضوع (للبريد)')" name="subject"><input type="text" name="subject" value="{{ old('subject') }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                <x-admin.field :label="__('النص (عربي)')" name="body_ar"><textarea name="body_ar" rows="3" required class="w-full rounded-md border-gray-300">{{ old('body_ar') }}</textarea></x-admin.field>
                <x-admin.field :label="__('النص (إنجليزي)')" name="body_en"><textarea name="body_en" rows="3" class="w-full rounded-md border-gray-300">{{ old('body_en') }}</textarea></x-admin.field>
                @foreach ($errors->all() as $e)<p class="text-xs text-rose-600">{{ $e }}</p>@endforeach
                <button class="px-4 py-2 bg-fuchsia-600 text-white text-sm rounded-md hover:bg-fuchsia-700">{{ __('حفظ') }}</button>
            </form>
        </div>

        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <table class="w-full text-sm">
                <thead class="text-gray-500 border-b"><tr><th class="py-2 text-start">{{ __('الاسم') }}</th><th class="py-2 text-start">{{ __('القناة') }}</th><th class="py-2 text-start">{{ __('النص') }}</th><th></th></tr></thead>
                <tbody>
                    @forelse ($templates as $t)
                        <tr class="border-b last:border-0">
                            <td class="py-2">{{ $t->name }}</td>
                            <td class="py-2">{{ __('marketing.channel.'.$t->channel) }}</td>
                            <td class="py-2 text-gray-500">{{ Str::limit($t->body_ar, 60) }}</td>
                            <td class="py-2 text-end">
                                <form method="POST" action="{{ route('admin.marketing.templates.destroy', $t) }}" onsubmit="return confirm('{{ __('حذف القالب؟') }}')">@csrf @method('DELETE')<button class="text-rose-600 text-xs hover:underline">{{ __('حذف') }}</button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-4 text-center text-gray-400">{{ __('لا قوالب.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $templates->links() }}</div>
        </div>
    </div>
</x-app-layout>
