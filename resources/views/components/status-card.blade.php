@props(['label', 'status'])

<div class="col-sm-6 col-lg-3">
    <div class="card h-100 shadow-sm">
        <div class="card-body">
            <h3 class="fs-6 text-secondary text-uppercase fw-semibold mb-2">{{ $label }}</h3>
            <span class="badge text-bg-{{ $status->badgeVariant() }}">{{ $status->label() }}</span>
        </div>
    </div>
</div>
