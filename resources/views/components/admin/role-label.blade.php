@props(['name'])
{{-- اسم الدور للعرض: من ملف اللغة للأدوار المزروعة، وإلا اسم مقروء للأدوار المخصّصة. --}}
@php $key = 'roles.'.$name; $label = __($key); @endphp
{{ $label === $key ? \Illuminate\Support\Str::headline($name) : $label }}
