@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'text-danger small ps-3 mb-0']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
