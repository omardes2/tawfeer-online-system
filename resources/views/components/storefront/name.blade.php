@props(['product'])
{{-- الاسم المحلّي للمنتج: الإنجليزي عند اللغة الإنجليزية إن توفّر، وإلا العربي. --}}
{{ app()->getLocale() === 'en' && filled($product->name_en) ? $product->name_en : $product->name }}
