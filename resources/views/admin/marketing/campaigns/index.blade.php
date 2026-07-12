<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('حملات التسويق') }}</h2></x-slot>
    <div class="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
        <x-admin.flash />
        <div class="flex justify-end">
            @can('marketing.campaigns.manage')
                <a href="{{ route('admin.marketing.campaigns.create') }}" class="px-4 py-2 bg-fuchsia-600 text-white text-sm rounded-md hover:bg-fuchsia-700">{{ __('حملة جديدة') }}</a>
            @endcan
        </div>
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <table class="w-full text-sm">
                <thead class="text-gray-500 border-b">
                    <tr>
                        <th class="py-2 text-start">{{ __('الاسم') }}</th>
                        <th class="py-2 text-start">{{ __('الحالة') }}</th>
                        <th class="py-2 text-start">{{ __('القناة') }}</th>
                        <th class="py-2 text-start">{{ __('المحفّز') }}</th>
                        <th class="py-2 text-start">{{ __('الرسائل') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($campaigns as $campaign)
                        <tr class="border-b last:border-0">
                            <td class="py-2"><a href="{{ route('admin.marketing.campaigns.show', $campaign) }}" class="text-fuchsia-600 hover:underline">{{ $campaign->name }}</a></td>
                            <td class="py-2">{{ __('marketing.status.'.$campaign->status) }}</td>
                            <td class="py-2">{{ __('marketing.channel.'.$campaign->channel) }}</td>
                            <td class="py-2">{{ $campaign->trigger_type }}{{ $campaign->trigger_event ? ' · '.$campaign->trigger_event : '' }}</td>
                            <td class="py-2">{{ $campaign->messages_count }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-gray-400">{{ __('لا توجد حملات.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="mt-4">{{ $campaigns->links() }}</div>
        </div>
    </div>
</x-app-layout>
