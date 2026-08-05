<button {{ $attributes->merge(['type' => 'button', 'class' => 'btn btn-outline-secondary btn-sm text-uppercase fw-semibold']) }}>
    {{ $slot }}
</button>
