@props(['name' => '', 'color' => '#64748b', 'size' => null])
<span {{ $attributes->merge(['class' => 'avatar'.($size ? ' avatar-'.$size : ''), 'style' => '--c:'.e($color).';--ink:'.\App\Support\Planning::contrast($color), 'aria-hidden' => 'true']) }}>{{ \App\Support\Planning::initials($name) }}</span>
