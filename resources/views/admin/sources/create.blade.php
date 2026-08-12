<x-app-layout>
    <x-slot name="header">
        <h2 class="fs-4 fw-semibold text-dark">{{ __('Add Source') }}</h2>
    </x-slot>

    <div class="py-4">
        <div class="container">
            <div class="bg-white shadow-sm rounded p-4" style="max-width: 32rem;">
                <form method="POST" action="{{ route('admin.sources.store') }}" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 w-100" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div class="mb-3">
                        <x-input-label for="department_id" :value="__('Department')" />
                        <select id="department_id" name="department_id" class="form-select mt-1" required>
                            <option value="" disabled selected>{{ __('Select a department') }}</option>
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}" @selected((int) old('department_id') === $department->id)>
                                    {{ $department->name }}
                                </option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('department_id')" class="mt-1" />
                    </div>

                    <div class="mb-3">
                        <x-input-label for="type" :value="__('Source Type')" />
                        <select id="type" name="type" class="form-select mt-1" required onchange="toggleSourceType(this.value)">
                            <option value="" disabled selected>{{ __('Select a type') }}</option>
                            <option value="file" @selected(old('type') === 'file')>{{ __('File') }}</option>
                            <option value="url" @selected(old('type') === 'url')>{{ __('URL') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('type')" class="mt-1" />
                    </div>

                    <div class="mb-3" id="file-field" style="display: none;">
                        <x-input-label for="file" :value="__('Excel File')" />
                        <input id="file" name="file" type="file" class="form-control mt-1" accept=".xlsx,.xls">
                        <x-input-error :messages="$errors->get('file')" class="mt-1" />
                    </div>

                    <div class="mb-3" id="url-field" style="display: none;">
                        <x-input-label for="url" :value="__('URL')" />
                        <x-text-input id="url" name="url" type="text" class="mt-1 w-100" :value="old('url')" placeholder="https://..." />
                        <x-input-error :messages="$errors->get('url')" class="mt-1" />
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('admin.sources.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function toggleSourceType(type) {
            document.getElementById('file-field').style.display = type === 'file' ? 'block' : 'none';
            document.getElementById('url-field').style.display = type === 'url' ? 'block' : 'none';
        }

        document.addEventListener('DOMContentLoaded', function () {
            toggleSourceType(document.getElementById('type').value);
        });
    </script>
</x-app-layout>
