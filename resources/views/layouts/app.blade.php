<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="google" content="notranslate">
    <title>@yield('title', 'Hotel San Pedro') - Sistema de Gestion</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('assets/img/backgrounds/logo-Photoroom.png') }}">

    @include('partials.seo', [
        'title' => View::yieldContent('title', 'Dashboard'),
        'description' =>
            'Sistema de gestion hotelera de Hotel San Pedro. Administra reservaciones, habitaciones, inventario y facturacion electronica de manera eficiente.',
    ])

    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Alpine.js Cloak -->
    <style>
        [x-cloak] {
            display: none !important;
        }
    </style>

    @stack('styles')
    @livewireStyles
</head>

<body class="bg-gray-100 notranslate overflow-x-hidden" x-data="layoutState()" x-cloak translate="no">
    <div class="flex min-h-screen w-full overflow-x-hidden">
        <!-- Impersonation Banner -->
        @if (session()->has('impersonated_by'))
            <div
                class="fixed top-0 left-0 right-0 z-[100] bg-amber-600 text-white px-4 py-2 flex items-center justify-between shadow-lg animate-pulse">
                <div class="flex items-center space-x-3">
                    <i class="fas fa-user-secret text-xl"></i>
                    <span class="text-sm font-bold">ESTAS IMPERSONANDO A: {{ strtoupper(Auth::user()->name) }}</span>
                </div>
                <form action="{{ route('admin.security.impersonate.stop') }}" method="POST">
                    @csrf
                    <button type="submit"
                        class="bg-white text-amber-600 px-4 py-1 rounded-lg text-xs font-bold hover:bg-amber-50 transition-all uppercase">
                        Volver a mi sesion
                    </button>
                </form>
            </div>
        @endif

        <!-- Overlay para movil -->
        <div x-show="sidebarOpen" x-cloak @click="if (window.innerWidth < 1024) sidebarOpen = false"
            x-transition:enter="transition-opacity ease-linear duration-300" x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity ease-linear duration-300"
            x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="fixed inset-0 bg-gray-600 bg-opacity-75 z-40 lg:hidden" style="display: none;"></div>

        <!-- Sidebar -->
        <aside
            class="shrink-0 fixed inset-y-0 left-0 z-50 bg-gray-800 text-white transform transition-all duration-300 ease-in-out lg:static lg:inset-0 flex flex-col overflow-hidden"
            :class="sidebarOpen ? 'translate-x-0 w-[260px]' : '-translate-x-full w-[260px] lg:translate-x-0 lg:w-0'">
            <div class="flex items-center justify-between p-4 lg:justify-center">
                <div class="flex flex-col items-center">
                    <img src="{{ asset('assets/img/backgrounds/logo-Photoroom.png') }}" alt="Hotel San Pedro"
                        class="h-12 w-auto object-contain mb-2">
                    <h1 class="text-xl lg:text-2xl font-bold text-center">Hotel San Pedro</h1>
                    <p class="text-gray-400 text-xs lg:text-sm text-center">Sistema de Gestion</p>
                </div>
                <button @click="if (window.innerWidth < 1024) sidebarOpen = false" class="lg:hidden text-gray-400 hover:text-white">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <nav class="mt-4 lg:mt-8 flex-1 overflow-y-auto">
                <div class="px-4 mb-4">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Menu Principal</p>
                </div>

                <a href="{{ route('dashboard') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                    class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('dashboard*') ? 'bg-gray-700 text-white' : '' }}">
                    <i class="fas fa-tachometer-alt w-5"></i>
                    <span class="ml-3">Dashboard</span>
                </a>

                <a href="{{ route('rooms.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                    class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('rooms.*') ? 'bg-gray-700 text-white' : '' }}">
                    <i class="fas fa-door-open w-5"></i>
                    <span class="ml-3">Habitaciones</span>
                </a>

                @can('view_products')
                    <a href="{{ route('products.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                        class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('products.index') ? 'bg-gray-700 text-white' : '' }}">
                        <i class="fas fa-boxes w-5 text-center"></i>
                        <span class="ml-3">Inventario</span>
                    </a>
                    @if (Auth::user()->hasRole('Administrador'))
                        <div class="pl-4 space-y-1">
                            <a href="{{ route('products.history') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                                class="flex items-center px-4 py-2 text-sm text-gray-400 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('products.history') ? 'text-white font-bold' : '' }}">
                                <i class="fas fa-history w-5 text-center"></i>
                                <span class="ml-3">Historial</span>
                            </a>
                        </div>
                    @endif
                @endcan

                @can('view_sales')
                    <a href="{{ route('sales.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                        class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('sales.*') ? 'bg-gray-700 text-white' : '' }}">
                        <i class="fas fa-shopping-cart w-5"></i>
                        <span class="ml-3">Ventas</span>
                    </a>
                @endcan

                @can('manage_cash_outflows')
                    <a href="{{ route('cash-outflows.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                        class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('cash-outflows.*') ? 'bg-gray-700 text-white' : '' }}">
                        <i class="fas fa-money-bill-wave w-5"></i>
                        <span class="ml-3">Gastos (Caja)</span>
                    </a>
                @endcan

                @can('view_reservations')
                    <a href="{{ route('reservations.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                        class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('reservations.*') ? 'bg-gray-700 text-white' : '' }}">
                        <i class="fas fa-calendar-check w-5"></i>
                        <span class="ml-3">Reservaciones</span>
                    </a>
                @endcan

                <a href="{{ route('customers.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                    class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('customers.*') ? 'bg-gray-700 text-white' : '' }}">
                    <i class="fas fa-users w-5"></i>
                    <span class="ml-3">Clientes</span>
                </a>

                @can('generate_invoices')
                    <a href="{{ route('electronic-invoices.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                        class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('electronic-invoices.*') ? 'bg-gray-700 text-white' : '' }}">
                        <i class="fas fa-file-invoice-dollar w-5"></i>
                        <span class="ml-3">Facturas Electronicas</span>
                    </a>
                @endcan


                {{-- Seccion de Reportes (Para un futuro los resportes avanzados, por ahora solo el resumen de ventas en el dashboard) --}}
                {{-- @can('view_reports')
                    @if (Auth::user()->hasRole('Administrador'))
                        <a href="{{ route('reports.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                            class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('reports.*') ? 'bg-gray-700 text-white' : '' }}">
                            <i class="fas fa-chart-bar w-5"></i>
                            <span class="ml-3">Reportes</span>
                        </a>
                    @endif
                @endcan --}}

                @php
                    $authUser = auth()->user();
                    $shiftOperationsEnabled = app(\App\Services\ShiftControlService::class)->isOperationalEnabled();
                    $showMyShiftLink = $shiftOperationsEnabled &&
                        ($authUser->hasRole('Recepcionista Día') || $authUser->hasRole('Recepcionista Noche'));
                    $showShiftHistoryLink = $authUser->hasRole('Administrador');
                @endphp

                @if (($authUser->can('view_shift_handovers') ||
                        $authUser->can('manage_shift_handovers') ||
                        $authUser->can('view_shift_cash_outs') ||
                        $authUser->can('create_shift_cash_outs')) &&
                        ($showMyShiftLink || $showShiftHistoryLink))
                    <div class="px-4 mt-4 mb-2">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Gestion de Turnos</p>
                    </div>

                    {{-- Mi Turno: visible para recepcionistas --}}
                    @if ($showMyShiftLink && $authUser->hasRole('Recepcionista Día'))
                        <a href="{{ route('dashboard.receptionist.day') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                            class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('dashboard.receptionist.day*') ? 'bg-gray-700 text-white' : '' }}">
                            <i class="fas fa-cash-register w-5"></i>
                            <span class="ml-3">Mi Turno</span>
                        </a>
                    @elseif ($showMyShiftLink && $authUser->hasRole('Recepcionista Noche'))
                        <a href="{{ route('dashboard.receptionist.night') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                            class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('dashboard.receptionist.night*') ? 'bg-gray-700 text-white' : '' }}">
                            <i class="fas fa-cash-register w-5"></i>
                            <span class="ml-3">Mi Turno</span>
                        </a>
                    @endif

                    {{-- Historial: solo Administrador --}}
                    @if ($showShiftHistoryLink)
                        <a href="{{ route('shift-handovers.index') }}" @click="if (window.innerWidth < 1024) sidebarOpen = false"
                            class="flex items-center px-4 py-3 text-gray-300 hover:bg-gray-700 hover:text-white transition-colors {{ request()->routeIs('shift-handovers.*') ? 'bg-gray-700 text-white' : '' }}">
                            <i class="fas fa-exchange-alt w-5"></i>
                            <span class="ml-3">Historial Turnos</span>
                        </a>
                    @endif
                @endif

            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 min-w-0 overflow-x-hidden min-h-[calc(100vh-64px)]">
            <!-- Header -->
            <header class="bg-white border-b border-gray-100 sticky top-0 z-30">
                <div class="flex items-center justify-between px-4 sm:px-6 py-3 lg:py-4">
                    <div class="flex items-center space-x-3 lg:space-x-0">
                        <button @click="sidebarOpen = !sidebarOpen"
                            class="text-gray-600 hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500 p-2 rounded-lg">
                            <i class="fas text-xl" :class="sidebarOpen ? 'fa-times' : 'fa-bars'"></i>
                        </button>
                        <div>
                            <h1 class="text-xl sm:text-2xl font-bold text-gray-900">@yield('header', 'Dashboard')</h1>
                            @hasSection('subheader')
                                <p class="text-xs sm:text-sm text-gray-500 mt-1 hidden sm:block">@yield('subheader')</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center space-x-2 sm:space-x-4">
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open"
                                class="flex items-center space-x-2 sm:space-x-3 px-2 sm:px-3 py-2 rounded-xl border border-gray-200 hover:border-gray-300 hover:bg-gray-50 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <div
                                    class="flex items-center justify-center w-8 h-8 sm:w-9 sm:h-9 rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-white text-xs sm:text-sm font-semibold shadow-sm">
                                    {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                                </div>
                                <div class="hidden sm:flex flex-col items-start">
                                    <span class="text-sm font-medium text-gray-900">{{ Auth::user()->name }}</span>
                                    <span
                                        class="text-xs text-gray-500">{{ Auth::user()->roles->first()->name ?? 'Usuario' }}</span>
                                </div>
                                <i class="fas fa-chevron-down text-gray-400 text-xs transition-transform duration-200 hidden sm:block"
                                    :class="{ 'rotate-180': open }"></i>
                            </button>

                            <div x-show="open" @click.away="open = false"
                                x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="transform opacity-0 scale-95"
                                x-transition:enter-end="transform opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="transform opacity-100 scale-100"
                                x-transition:leave-end="transform opacity-0 scale-95"
                                class="absolute right-0 mt-2 w-56 sm:w-64 bg-white rounded-xl shadow-xl py-2 z-50 border border-gray-100">
                                <div class="px-4 py-4 border-b border-gray-100">
                                    <p class="text-sm font-semibold text-gray-900">{{ Auth::user()->name }}</p>
                                    <p class="text-xs text-gray-500 mt-1 truncate">{{ Auth::user()->email }}</p>
                                </div>
                                <div class="px-4 py-3 border-b border-gray-100">
                                    <div class="flex items-center justify-between">
                                        <span
                                            class="text-xs font-medium text-gray-500 uppercase tracking-wider">Rol</span>
                                        <span
                                            class="px-2.5 py-1 rounded-full bg-gray-100 text-gray-700 text-xs font-semibold">
                                            {{ Auth::user()->roles->first()->name ?? 'Sin rol' }}
                                        </span>
                                    </div>
                                </div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit"
                                        class="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-red-50 hover:text-red-600 transition-colors duration-200 flex items-center space-x-2 group">
                                        <i
                                            class="fas fa-sign-out-alt text-gray-400 group-hover:text-red-600 transition-colors"></i>
                                        <span>Cerrar sesion</span>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <div class="p-4 sm:p-6 min-w-0">
                <!-- Session Messages -->
                <div class="max-w-7xl mx-auto min-w-0">
                    @if (session('success'))
                        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                            x-transition:enter="transition ease-out duration-300"
                            x-transition:enter-start="opacity-0 transform -translate-y-2"
                            x-transition:enter-end="opacity-100 transform translate-y-0"
                            x-transition:leave="transition ease-in duration-200"
                            x-transition:leave-start="opacity-100 transform translate-y-0"
                            x-transition:leave-end="opacity-0 transform -translate-y-2"
                            class="mb-6 flex items-center p-4 text-emerald-800 rounded-2xl bg-emerald-50 border border-emerald-100 shadow-sm">
                            <div
                                class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 mr-4">
                                <i class="fas fa-check-circle text-lg"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold">{{ session('success') }}</p>
                            </div>
                            <button @click="show = false"
                                class="ml-auto text-emerald-400 hover:text-emerald-600 transition-colors">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    @endif

                    @if (session('error'))
                        <div x-data="{ show: true }" x-show="show"
                            class="mb-6 flex items-center p-4 text-red-800 rounded-2xl bg-red-50 border border-red-100 shadow-sm">
                            <div
                                class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-xl bg-red-100 text-red-600 mr-4">
                                <i class="fas fa-exclamation-circle text-lg"></i>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold">{{ session('error') }}</p>
                            </div>
                            <button @click="show = false"
                                class="ml-auto text-red-400 hover:text-red-600 transition-colors">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div x-data="{ show: true }" x-show="show"
                            class="mb-6 p-4 text-red-800 rounded-2xl bg-red-50 border border-red-100 shadow-sm">
                            <div class="flex items-center mb-3">
                                <div
                                    class="flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-xl bg-red-100 text-red-600 mr-4">
                                    <i class="fas fa-exclamation-triangle text-lg"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-bold">Por favor corrige los siguientes errores:</p>
                                </div>
                                <button @click="show = false"
                                    class="ml-auto text-red-400 hover:text-red-600 transition-colors">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <ul class="ml-14 list-disc list-inside text-sm space-y-1">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                @yield('content')
            </div>
        </main>
    </div>

    @stack('scripts')
    @livewireScripts

    {{-- Global Notification Components --}}
    <x-notifications.toast />
    <x-notifications.confirm-modal />
    <x-notifications.select-modal />
    <x-notifications.input-modal />
    <x-notifications.payment-modal />

    <!-- Modal de Verificacion de PIN -->
    <div x-data="pinVerification()" x-show="isOpen" x-cloak @open-pin-modal.window="openModal($event.detail)"
        class="fixed inset-0 z-[110] overflow-y-auto" aria-labelledby="modal-title" role="dialog"
        aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="isOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <div x-show="isOpen" x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div
                            class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-lock text-red-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-bold text-gray-900" id="modal-title" x-text="title">
                                Accion Protegida</h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-500" x-text="description">Esta accion requiere
                                    verificacion de PIN administrativo.</p>
                            </div>
                            <div class="mt-4">
                                <input type="password" x-model="pin" maxlength="4"
                                    placeholder="Ingrese PIN de 4 digitos"
                                    class="block w-full px-4 py-3 text-center text-2xl tracking-widest border-2 border-gray-200 rounded-xl focus:border-red-500 focus:ring-0 transition-all">
                                <p x-show="error" x-text="error" class="mt-2 text-xs text-red-600 font-bold"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="button" @click="verify()" :disabled="loading"
                        class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50">
                        <span x-show="!loading">Confirmar Accion</span>
                        <span x-show="loading"><i class="fas fa-spinner fa-spin mr-2"></i>Verificando...</span>
                    </button>
                    <button type="button" @click="closeModal()"
                        class="mt-3 w-full inline-flex justify-center rounded-xl border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function layoutState() {
            return {
                sidebarOpen: false,

                init() {
                    const storageKey = 'layout.sidebar.open';
                    const isDesktop = window.matchMedia('(min-width: 1024px)').matches;
                    const savedState = localStorage.getItem(storageKey);

                    if (isDesktop) {
                        this.sidebarOpen = savedState === null ? true : savedState === '1';
                    } else {
                        this.sidebarOpen = false;
                    }

                    this.$watch('sidebarOpen', (value) => {
                        localStorage.setItem(storageKey, value ? '1' : '0');
                    });

                    window.addEventListener('resize', () => {
                        if (!window.matchMedia('(min-width: 1024px)').matches) {
                            this.sidebarOpen = false;
                        }
                    });
                }
            };
        }
    </script>
    <script>
        function pinVerification() {
            return {
                isOpen: false,
                loading: false,
                pin: '',
                error: '',
                title: '',
                description: '',
                onSuccess: null,

                openModal(data) {
                    this.isOpen = true;
                    this.title = data.title || 'Accion Protegida';
                    this.description = data.description || 'Esta accion requiere verificacion de PIN administrativo.';
                    this.onSuccess = data.onSuccess;
                    this.pin = '';
                    this.error = '';
                },

                closeModal() {
                    this.isOpen = false;
                    this.pin = '';
                    this.error = '';
                },

                async verify() {
                    if (this.pin.length !== 4) {
                        this.error = 'El PIN debe ser de 4 digitos.';
                        return;
                    }

                    this.loading = true;
                    this.error = '';

                    try {
                        const response = await fetch('{{ route('api.admin.security.verify-pin') }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify({
                                pin: this.pin
                            })
                        });

                        const data = await response.json();

                        if (response.ok && data.success) {
                            this.closeModal();
                            if (typeof this.onSuccess === 'function') {
                                this.onSuccess();
                            } else if (typeof window[this.onSuccess] === 'function') {
                                window[this.onSuccess]();
                            }
                        } else {
                            this.error = data.message || 'PIN incorrecto.';
                        }
                    } catch (e) {
                        this.error = 'Error de comunicacion con el servidor.';
                    } finally {
                        this.loading = false;
                    }
                }
            }
        }
    </script>
    <script>
        function formatNumberInput(input) {
            // Remove non-numeric characters except for the decimal point
            let value = input.value.replace(/\D/g, "");

            // Format with dots as thousand separators
            if (value !== "") {
                input.value = new Intl.NumberFormat('de-DE').format(value);
            } else {
                input.value = "";
            }

            // Update a hidden input or the raw value if needed by the framework
            // This is a helper for vanilla HTML inputs
        }

        // Helper for Livewire inputs to keep raw value in sync
        function maskCurrency(event) {
            let value = event.target.value.replace(/\D/g, "");
            if (value !== "") {
                event.target.value = new Intl.NumberFormat('de-DE').format(value);
            }
        }
    </script>
</body>

</html>

