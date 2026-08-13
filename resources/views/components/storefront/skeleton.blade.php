@props(['type' => 'card', 'count' => 4])

{{-- هياكل تحميل بأبعاد المحتوى الحقيقي، فلا تقفز الصفحة عند وصوله. --}}
@if ($type === 'card')
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
        @for ($i = 0; $i < $count; $i++)
            <div class="sf-card overflow-hidden">
                <span class="sf-skeleton block aspect-square rounded-none"></span>
                <div class="p-3 space-y-2">
                    <span class="sf-skeleton h-3 w-3/4"></span>
                    <span class="sf-skeleton h-3 w-1/2"></span>
                    <span class="sf-skeleton h-8 w-full mt-3"></span>
                </div>
            </div>
        @endfor
    </div>
@else
    <div class="space-y-3">
        @for ($i = 0; $i < $count; $i++)
            <span class="sf-skeleton h-16 w-full"></span>
        @endfor
    </div>
@endif
