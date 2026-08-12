<x-app-layout>
    <x-slot name="header">
        <h2 class="fs-4 fw-semibold text-dark">{{ __('Add Department') }}</h2>
    </x-slot>

    <div class="py-4">
        <div class="container">
            <div class="bg-white shadow-sm rounded p-4" style="max-width: 32rem;">
                <form method="POST" action="{{ route('admin.departments.store') }}">
                    @csrf

                    <div class="mb-3">
                        <x-input-label for="name" :value="__('Name')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 w-100" :value="old('name')" required autofocus />
                        <x-input-error :messages="$errors->get('name')" class="mt-1" />
                    </div>

                    <div class="mb-3">
                        <x-input-label for="slug" :value="__('Slug')" />
                        <x-text-input id="slug" name="slug" type="text" class="mt-1 w-100" :value="old('slug')" placeholder="e.g. logistics" required />
                        <p class="text-secondary small mt-1 mb-0">{{ __('Used in the chat URL: /chat/your-slug. Letters, numbers, dashes and underscores only.') }}</p>
                        <x-input-error :messages="$errors->get('slug')" class="mt-1" />
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-4">
                        <x-primary-button>{{ __('Save') }}</x-primary-button>
                        <a href="{{ route('admin.departments.index') }}" class="btn btn-link">{{ __('Cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
