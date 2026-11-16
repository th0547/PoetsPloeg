@props(['name'])
<svg {{ $attributes->merge(['class' => 'icon', 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '2', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true']) }}><use href="#i-{{ $name }}"/></svg>
