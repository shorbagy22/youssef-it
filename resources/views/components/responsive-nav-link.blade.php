@props(['active'])

@php
$classes = ($active ?? false)
            ? 'd-block w-100 px-3 py-2 border-start border-4 border-dark text-dark fw-semibold text-decoration-none bg-light'
            : 'd-block w-100 px-3 py-2 border-start border-4 border-transparent text-secondary text-decoration-none';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
