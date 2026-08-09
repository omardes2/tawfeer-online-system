<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ $user->exists ? __('تعديل مستخدم') : __('مستخدم جديد') }}</h2></x-slot>
    <div class="py-8 max-w-2xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6">
            <x-admin.flash />
            <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="space-y-4">
                @csrf @if ($user->exists) @method('PUT') @endif

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-admin.field :label="__('الاسم')" name="name"><input type="text" name="name" value="{{ old('name', $user->name) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('البريد الإلكتروني')" name="email"><input type="email" name="email" value="{{ old('email', $user->email) }}" required class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الهاتف')" name="phone"><input type="text" name="phone" value="{{ old('phone', $user->phone) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الدور')" name="role">
                        <select name="role" required class="w-full rounded-md border-gray-300">
                            @php $current = old('role', $user->roles->first()?->name); @endphp
                            @foreach ($roles as $r)<option value="{{ $r->name }}" @selected($current === $r->name)><x-admin.role-label :name="$r->name" /></option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('القسم')" name="department"><input type="text" name="department" value="{{ old('department', $user->department) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('المسمّى الوظيفي')" name="job_title"><input type="text" name="job_title" value="{{ old('job_title', $user->job_title) }}" class="w-full rounded-md border-gray-300" /></x-admin.field>
                    <x-admin.field :label="__('الفرع')" name="branch_id">
                        <select name="branch_id" class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('— بلا —') }}</option>
                            @foreach ($branches as $b)<option value="{{ $b->id }}" @selected((int) old('branch_id', $user->branch_id) === $b->id)>{{ $b->name }}</option>@endforeach
                        </select>
                    </x-admin.field>
                    <x-admin.field :label="__('حساب البزنس في شركة التوصيل')" name="delivery_business_id">
                        <select name="delivery_business_id" class="w-full rounded-md border-gray-300">
                            <option value="">{{ __('— بلا —') }}</option>
                            @foreach ($deliveryBusinesses as $biz)
                                <option value="{{ $biz->id }}" @selected((int) old('delivery_business_id', $user->delivery_business_id) === $biz->id)>{{ $biz->name }}@unless($biz->is_active) ({{ __('غير نشط') }})@endunless</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-gray-400">{{ __('تُدخَل طرود طلبات هذا المستخدم تحت هذا الحساب في شركة التوصيل. زامِن القائمة من زر «مزامنة حسابات التوصيل» في صفحة المستخدمين.') }}</p>
                    </x-admin.field>
                    <label class="flex items-center gap-2 text-sm pb-2 pt-6">
                        <input type="hidden" name="is_active" value="0" />
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->exists ? $user->is_active : true)) class="rounded border-gray-300 text-emerald-600" />
                        {{ __('نشط') }}
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t pt-4">
                    <x-admin.field :label="$user->exists ? __('كلمة مرور جديدة (اختياري)') : __('كلمة المرور')" name="password"><input type="password" name="password" @required(! $user->exists) class="w-full rounded-md border-gray-300" autocomplete="new-password" /></x-admin.field>
                    <x-admin.field :label="__('تأكيد كلمة المرور')" name="password_confirmation"><input type="password" name="password_confirmation" class="w-full rounded-md border-gray-300" autocomplete="new-password" /></x-admin.field>
                </div>

                <div class="flex gap-2 pt-2">
                    <button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('حفظ') }}</button>
                    <a href="{{ route('admin.users.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('إلغاء') }}</a>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
