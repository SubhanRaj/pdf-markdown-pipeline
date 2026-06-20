@props(['items' => []])

@if(count($items))
<nav class="flex items-center gap-1.5 text-xs text-slate-400 dark:text-slate-500 mb-4">
    @foreach($items as $item)
        @if(!$loop->first)
            <i class="ti ti-chevron-right text-[10px]"></i>
        @endif

        @if(!empty($item['url']))
            <a href="{{ $item['url'] }}" class="hover:text-slate-600 dark:hover:text-slate-300 transition-colors">
                {{ $item['name'] }}
            </a>
        @else
            <span class="text-slate-600 dark:text-slate-300 font-medium">{{ $item['name'] }}</span>
        @endif
    @endforeach
</nav>
@endif
