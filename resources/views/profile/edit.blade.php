<x-app-layout>
    <x-slot name="header">
        <h2 class="fs-4 fw-semibold text-dark">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="container" style="max-width: 42rem;">
            <div class="p-4 mb-3 bg-white shadow-sm rounded">
                @include('profile.partials.update-profile-information-form')
            </div>

            <div class="p-4 mb-3 bg-white shadow-sm rounded">
                @include('profile.partials.update-password-form')
            </div>

            <div class="p-4 mb-3 bg-white shadow-sm rounded">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-app-layout>
