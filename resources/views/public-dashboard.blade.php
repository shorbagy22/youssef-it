<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>AI Dashboard</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        .department-card .card-body {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 0.5rem;
        }

        .header-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-brand__logo {
            height: 64px;
            width: auto;
            display: block;
        }

        .header-brand__title {
            font-family: 'figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: #1f2937;
            letter-spacing: -0.01em;
            line-height: 1;
        }
    </style>
</head>
<body class="antialiased">
    <div class="min-vh-100 bg-light">
        <!-- Top Bar -->
        <nav class="navbar navbar-light bg-white border-bottom sticky-top">
            <div class="container d-flex justify-content-between align-items-center">
                <div class="header-brand">
                    <img src="{{ asset('images/electrolux.png') }}" alt="Electrolux" class="header-brand__logo">
                    <span class="header-brand__title">AI Dashboard</span>
                </div>

                @auth
                    <a class="btn btn-dark btn-sm" href="{{ route('admin.sources.index') }}">Admin Panel</a>
                @else
                    <a class="btn btn-outline-dark btn-sm" href="{{ route('login') }}">Admin Login</a>
                @endauth
            </div>
        </nav>

        <!-- Page Content -->
        <main class="py-4">
            <div class="container">
                <div class="bg-white shadow-sm rounded p-4 mb-4">
                    <h1 class="fs-3 fw-semibold mb-1">{{ __('Welcome to the Factory AI Dashboard') }}</h1>
                    <p class="text-secondary mb-0">{{ __('Pick a department below to start chatting with its assistant.') }}</p>
                </div>

                <h2 class="fs-6 text-secondary text-uppercase fw-semibold mb-3">{{ __('Departments') }}</h2>
                <div class="row g-3">
                    @forelse ($departments as $department)
                        <div class="col-sm-6 col-lg-3">
                            <a href="{{ route('public.chat', $department->slug) }}" class="text-decoration-none">
                                <div class="card h-100 shadow-sm department-card">
                                    <div class="card-body">
                                        <h3 class="fs-6 text-secondary text-uppercase fw-semibold mb-2">{{ $department->name }}</h3>
                                        <span class="badge text-bg-primary">{{ __('Open Chat') }}</span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    @empty
                        <p class="text-secondary mb-0">{{ __('No departments configured yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </main>
    </div>
</body>
</html>
