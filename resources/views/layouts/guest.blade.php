<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased">
        <div class="min-vh-100 d-flex flex-column justify-content-center align-items-center bg-light py-4">
            <div class="mb-3">
                <a href="/">
                    <x-application-logo style="width: 4rem; height: 4rem; fill: #6c757d;" />
                </a>
            </div>

            <div class="w-100 px-4 py-4 bg-white shadow-sm rounded" style="max-width: 28rem;">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
