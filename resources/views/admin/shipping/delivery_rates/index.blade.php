<x-app-layout :title="__('أسعار التوصيل للمدن')">
    <x-admin.header
        :title="__('أسعار التوصيل للمدن')"
        :description="__('رسوم التوصيل والإرجاع لكل مدينة (نمط شركة التوصيل) — تُزامَن المدن من المزوّد وتُدار الأسعار هنا.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الشحن والتوصيل') => null, __('أسعار التوصيل') => null]">
        @can('settings.geography.manage')
            <form method="POST" action="{{ route('admin.shipping.delivery_rates.sync') }}"
                  onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='{{ __('جارٍ المزامنة...') }}'">
                @csrf
                <button type="submit" class="btn-primary btn-sm">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                    {{ __('مزامنة المدن من Opost') }}
                </button>
            </form>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    @unless (config('services.opost.token'))
        <div class="mb-5">
            <x-admin.alert tone="amber" :title="__('لم يُضبط توكن Opost')">
                {{ __('لتمكين المزامنة الآلية، ضع OPOST_API_TOKEN و OPOST_BUSINESS_ID في ملف .env على الخادم. يمكنك حتى ذلك إدارة الأسعار يدويًا أدناه.') }}
            </x-admin.alert>
        </div>
    @endunless

    @if ($lastSync)
        <p class="mb-4 text-xs text-gray-400">{{ __('آخر مزامنة') }}: {{ \Illuminate\Support\Carbon::parse($lastSync)->diffForHumans() }}</p>
    @endif

    {{-- إضافة سعر مدينة يدويًا --}}
    @can('settings.geography.manage')
        @if ($unpricedCities->isNotEmpty())
            <div class="admin-card admin-card-pad mb-6">
                <h3 class="font-semibold text-gray-800 mb-4">{{ __('إضافة سعر مدينة') }}</h3>
                <form method="POST" action="{{ route('admin.shipping.delivery_rates.store') }}" class="grid gap-4 md:grid-cols-4 items-end">
                    @csrf
                    <x-admin.field :label="__('المدينة')" name="city_id" required>
                        <select name="city_id" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500">
                            @foreach ($unpricedCities as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('رسوم التوصيل')" name="delivery_fee" required>
                        <input type="number" step="0.01" min="0" name="delivery_fee" value="0" required class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <x-admin.field :label="__('سعر البيع للزبون')" name="customer_fee">
                        <input type="number" step="0.01" min="0" name="customer_fee" placeholder="{{ __('اتركه فارغًا = كالتكلفة') }}" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <x-admin.field :label="__('رسوم الإرجاع')" name="return_fee">
                        <input type="number" step="0.01" min="0" name="return_fee" value="0" class="w-full rounded-lg border-gray-300 focus:border-emerald-500 focus:ring-emerald-500" />
                    </x-admin.field>
                    <button type="submit" class="btn-primary">{{ __('إضافة') }}</button>
                </form>
            </div>
        @endif
    @endcan

    {{-- جدول الأسعار --}}
    <form method="POST" action="{{ route('admin.shipping.delivery_rates.update') }}">
        @csrf
        @method('PUT')
        <x-admin.table :title="__('أسعار المدن') . ' (' . $rates->count() . ')'">
            <thead>
                <tr>
                    <th>{{ __('المدينة') }}</th>
                    <th>{{ __('كود المزوّد') }}</th>
                    <th>{{ __('تكلفة الشركة') }}</th>
                    <th>{{ __('سعر البيع للزبون') }}</th>
                    <th>{{ __('الهامش') }}</th>
                    <th>{{ __('رسوم الإرجاع') }}</th>
                    <th>{{ __('العملة') }}</th>
                    <th>{{ __('مفعّل') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rates as $rate)
                    <tr>
                        <td class="font-medium text-gray-800">{{ $rate->city?->name ?? $rate->name }}</td>
                        <td class="text-gray-400 text-xs font-mono">{{ $rate->external_code ?? '—' }}</td>
                        <td>
                            @can('settings.geography.manage')
                                <input type="number" step="0.01" min="0" name="rates[{{ $rate->id }}][delivery_fee]" value="{{ rtrim(rtrim(number_format($rate->delivery_fee, 2, '.', ''), '0'), '.') }}" class="w-28 rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            @else
                                {{ number_format($rate->delivery_fee, 2) }}
                            @endcan
                        </td>
                        {{-- سعر البيع: الفراغ يعني «بلا هامش» فتبقى المدينة على
                             تكلفتها. ولذلك لا `value="0"` هنا — الصفر يعني
                             توصيلًا مجّانيًّا وهو قرارٌ آخر تمامًا. --}}
                        <td>
                            @can('settings.geography.manage')
                                <input type="number" step="0.01" min="0" name="rates[{{ $rate->id }}][customer_fee]"
                                       value="{{ $rate->customer_fee === null ? '' : rtrim(rtrim(number_format($rate->customer_fee, 2, '.', ''), '0'), '.') }}"
                                       placeholder="{{ __('كالتكلفة') }}"
                                       class="w-28 rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            @else
                                {{ $rate->customer_fee === null ? __('كالتكلفة') : number_format($rate->customer_fee, 2) }}
                            @endcan
                        </td>
                        <td class="tabular-nums {{ $rate->margin() > 0 ? 'text-emerald-700 font-semibold' : ($rate->margin() < 0 ? 'text-rose-600 font-semibold' : 'text-gray-400') }}">
                            {{ $rate->margin() == 0.0 ? '—' : number_format($rate->margin(), 2) }}
                        </td>
                        <td>
                            @can('settings.geography.manage')
                                <input type="number" step="0.01" min="0" name="rates[{{ $rate->id }}][return_fee]" value="{{ rtrim(rtrim(number_format($rate->return_fee, 2, '.', ''), '0'), '.') }}" class="w-28 rounded-md border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
                            @else
                                {{ number_format($rate->return_fee, 2) }}
                            @endcan
                        </td>
                        <td class="text-gray-500">{{ $rate->currency }}</td>
                        <td>
                            @can('settings.geography.manage')
                                <input type="checkbox" name="rates[{{ $rate->id }}][is_active]" value="1" @checked($rate->is_active) class="rounded border-gray-300 text-emerald-600 focus:ring-emerald-500" />
                            @else
                                <x-admin.badge :tone="$rate->is_active ? 'green' : 'gray'" :label="$rate->is_active ? __('نعم') : __('لا')" />
                            @endcan
                        </td>
                        <td class="text-end">
                            @can('settings.geography.manage')
                                <x-admin.confirm
                                    :action="route('admin.shipping.delivery_rates.destroy', $rate)"
                                    :title="__('حذف سعر المدينة')"
                                    :message="__('سيُحذف سعر :city.', ['city' => $rate->name])" />
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="!p-0">
                        <x-admin.empty-state
                            :title="__('لا توجد أسعار مدن بعد')"
                            :description="config('services.opost.token') ? __('اضغط «مزامنة المدن من Opost» لجلب المدن، ثم أدخل الأسعار.') : __('أضف أسعار المدن يدويًا من الأعلى، أو اضبط توكن Opost للمزامنة الآلية.')"
                            :icon="'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25'" />
                    </td></tr>
                @endforelse
            </tbody>
        </x-admin.table>

        @can('settings.geography.manage')
            @if ($rates->isNotEmpty())
                <div class="mt-4 flex justify-end">
                    <button type="submit" class="btn-primary">{{ __('حفظ الأسعار') }}</button>
                </div>
            @endif
        @endcan
    </form>
</x-app-layout>
