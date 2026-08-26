<x-app-layout :title="__('موظف جديد')">
    <x-admin.header
        :title="__('موظف جديد')"
        :description="__('ملفّ التوظيف. الراتب يُسجَّل بعده بتاريخ سريانه.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الرواتب والموظفون') => route('admin.hr.employees.index'), __('موظف جديد') => null]" />

    <x-admin.flash />

    <form method="POST" action="{{ route('admin.hr.employees.store') }}">
        @csrf
        @include('admin.hr.employees._form', ['employee' => null])
    </form>
</x-app-layout>
