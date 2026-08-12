<x-app-layout>
    <x-slot name="header">
        <div class="d-flex justify-content-between align-items-center">
            <h2 class="fs-4 fw-semibold text-dark">{{ __('Departments') }}</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.sources.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('Sources') }}</a>
                <a href="{{ route('admin.departments.create') }}" class="btn btn-dark btn-sm">{{ __('Add Department') }}</a>
            </div>
        </div>
    </x-slot>

    <div class="py-4">
        <div class="container">
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif

            <div class="bg-white shadow-sm rounded p-4">
                @if ($departments->isEmpty())
                    <p class="text-secondary mb-0">{{ __('No departments configured yet.') }}</p>
                @else
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Slug') }}</th>
                                <th>{{ __('Sources') }}</th>
                                <th>{{ __('Created At') }}</th>
                                <th class="text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($departments as $department)
                                <tr>
                                    <td>{{ $department->name }}</td>
                                    <td><code>{{ $department->slug }}</code></td>
                                    <td>{{ $department->sources_count }}</td>
                                    <td>{{ $department->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('admin.departments.destroy', $department) }}" onsubmit="return confirm('Delete this department?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('Delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
