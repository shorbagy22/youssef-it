<nav x-data="{ open: false }" class="navbar navbar-expand-sm navbar-light bg-white border-bottom">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center w-100">
            <div class="d-flex align-items-center">
                <!-- Logo -->
                <a class="navbar-brand d-flex align-items-center me-4" href="{{ route('dashboard') }}">
                    <x-application-logo style="height: 2.25rem; width: auto; fill: #212529;" />
                </a>

                <!-- Navigation Links (desktop only) -->
                <ul class="navbar-nav d-none d-sm-flex flex-row">
                    <li class="nav-item">
                        <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                            {{ __('Dashboard') }}
                        </x-nav-link>
                    </li>
                    <li class="nav-item">
                        <x-nav-link :href="route('chat')" :active="request()->routeIs('chat')">
                            {{ __('Chat') }}
                        </x-nav-link>
                    </li>
                    <li class="nav-item">
                        <x-nav-link :href="route('documents')" :active="request()->routeIs('documents')">
                            {{ __('Documents') }}
                        </x-nav-link>
                    </li>
                    <li class="nav-item">
                        <x-nav-link :href="route('settings')" :active="request()->routeIs('settings')">
                            {{ __('Settings') }}
                        </x-nav-link>
                    </li>
                </ul>
            </div>

            <!-- Settings Dropdown (desktop only) -->
            <div class="d-none d-sm-flex align-items-center">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="btn btn-outline-secondary btn-sm d-flex align-items-center">
                            {{ Auth::user()->name }}
                            <svg class="ms-1" width="14" height="14" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Profile') }}
                        </x-dropdown-link>

                        <!-- Authentication -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Log Out') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger (mobile only) -->
            <button class="navbar-toggler d-sm-none" type="button" @click="open = ! open">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>
    </div>

    <!-- Responsive Navigation Menu (mobile only) -->
    <div class="w-100 d-sm-none" x-show="open" x-cloak>
        <div class="border-top py-2">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('chat')" :active="request()->routeIs('chat')">
                {{ __('Chat') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('documents')" :active="request()->routeIs('documents')">
                {{ __('Documents') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('settings')" :active="request()->routeIs('settings')">
                {{ __('Settings') }}
            </x-responsive-nav-link>
        </div>

        <div class="border-top py-2">
            <div class="px-3">
                <div class="fw-medium">{{ Auth::user()->name }}</div>
                <div class="text-muted small">{{ Auth::user()->email }}</div>
            </div>

            <div class="mt-2">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
