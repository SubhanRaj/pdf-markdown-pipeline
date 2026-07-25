@props(['items' => []])

@if(count($items))
<nav class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs text-slate-400 dark:text-slate-500 mb-4">
    @foreach($items as $item)
        @if(!$loop->first)
            <i class="ti ti-chevron-right text-[10px] flex-shrink-0"></i>
        @endif

        @if(!empty($item['url']))
            <a href="{{ $item['url'] }}" class="hover:text-slate-600 dark:hover:text-slate-300 transition-colors {{ $loop->last ? 'inline-block align-bottom max-w-[70vw] sm:max-w-xs truncate' : '' }}">
                {{ $item['name'] }}
            </a>
        @else
            <span class="text-slate-600 dark:text-slate-300 font-medium {{ $loop->last ? 'inline-block align-bottom max-w-[70vw] sm:max-w-xs truncate' : '' }}">{{ $item['name'] }}</span>
        @endif
    @endforeach
</nav>
@endif
