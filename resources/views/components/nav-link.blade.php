@props(['active'])

@php
$classes = ($active ?? false)
            ? 'nav-link active fw-semibold'
            : 'nav-link text-secondary';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
