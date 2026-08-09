<x-app-layout>
    <x-slot name="header">
        <h2 class="fs-4 fw-semibold text-dark">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-4">
        <div class="container">
            <div class="bg-white shadow-sm rounded p-4 mb-4">
                <h1 class="fs-3 fw-semibold mb-1">{{ __('Welcome, :name!', ['name' => auth()->user()->name]) }}</h1>
                <p class="text-secondary mb-1">{{ auth()->user()->email }}</p>
                <p class="text-secondary small mb-0">{{ __('CompanyAIChatbot') }} v{{ $appVersion }}</p>
            </div>

            <h2 class="fs-6 text-secondary text-uppercase fw-semibold mb-3">{{ __('System Status') }}</h2>
            <div class="row g-3">
                <x-status-card label="AI Service" :status="$systemStatus->aiService" />
                <x-status-card label="Database" :status="$systemStatus->database" />
                <x-status-card label="Authentication" :status="$systemStatus->authentication" />
            </div>
        </div>
    </div>
</x-app-layout>
