@props(['href', 'icon', 'active' => false, 'badge' => null, 'badgeTitle' => null, 'badgeClass' => ''])
<a href="{{ $href }}" {{ $attributes->merge(['class' => 'nav-item'.($active ? ' is-active' : ''), 'title' => trim(strip_tags($slot))]) }} @if ($active) aria-current="page" @endif>
    <x-icon :name="$icon" />
    <span class="nav-label">{{ $slot }}</span>
    @if ($badge)
        <span class="nav-badge {{ $badgeClass }}" @if ($badgeTitle) title="{{ $badgeTitle }}" @endif>{{ $badge }}</span>
    @endif
</a>
