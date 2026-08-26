<x-app-layout :title="__('تعديل ملفّ الموظف')">
    <x-admin.header
        :title="__('تعديل ملفّ :name', ['name' => $employee->user?->name])"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الرواتب والموظفون') => route('admin.hr.employees.index'), __('تعديل') => null]" />

    <x-admin.flash />

    <form method="POST" action="{{ route('admin.hr.employees.update', $employee) }}">
        @csrf
        @method('PUT')
        @include('admin.hr.employees._form', ['employee' => $employee])
    </form>
</x-app-layout>
