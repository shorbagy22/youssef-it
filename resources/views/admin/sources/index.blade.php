<x-app-layout>
    <x-slot name="header">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="fs-4 fw-semibold text-dark">{{ __('Sources') }}</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.departments.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('Manage Departments') }}</a>
                <a href="{{ route('admin.sources.create') }}" class="btn btn-dark btn-sm">{{ __('Add Source') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="container">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            <div class="bg-white shadow-sm rounded p-4">
                @if ($sources->isEmpty())
                    <p class="text-secondary mb-0">{{ __('No sources configured yet.') }}</p>
                @else
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Department') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Created At') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sources as $source)
                                <tr>
                                    <td>{{ $source->name }}</td>
                                    <td>{{ $source->department->name }}</td>
                                    <td class="text-capitalize">{{ $source->type }}</td>
                                    <td>{{ $source->created_at?->format('Y-m-d H:i') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
