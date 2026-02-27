<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Hotel Wimpy</title>
    <link rel="icon" type="image/png" href="{{ asset('assets/img/backgrounds/logo-Photoroom.png') }}">
    
    {{-- SEO Meta Tags --}}
    <meta name="title" content="Iniciar Sesión - Hotel Wimpy">
    <meta name="description" content="Accede al sistema de gestión hotelera de Hotel Wimpy. Administra reservaciones, habitaciones, inventario y facturación electrónica.">
    <meta name="keywords" content="hotel, gestión hotelera, reservaciones, sistema hotelero, Hotel Wimpy">
    <meta name="author" content="Hotel Wimpy">
    <meta name="robots" content="index, follow">
    
    <!-- TailwindCSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .login-bg {
            background-image: url('{{ asset('assets/img/backgrounds/login-bg.jpeg') }}');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }
        .login-overlay {
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(8px);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-up {
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
    </style>
    
</head>
<body class="min-h-screen flex items-center justify-center login-bg relative overflow-hidden">
    <div class="absolute inset-0 login-overlay"></div>

    <div class="max-w-lg w-full px-4 z-10 animate-slide-up">
        <div class="relative">
            <!-- Badge -->
            <div class="absolute -top-6 left-1/2 -translate-x-1/2 z-20">
                <div class="bg-slate-900/90 text-white px-5 py-2.5 rounded-2xl flex items-center shadow-2xl text-xs font-bold whitespace-nowrap border border-white/10 backdrop-blur-md tracking-wider">
                    <i class="fas fa-hotel mr-2 text-sm text-slate-300"></i>
                    HOTEL WIMPY Deploy
                </div>
            </div>

            <!-- Login Card -->
            <div class="glass-card pt-14 pb-10 px-8 sm:px-12 rounded-[2.5rem] shadow-2xl overflow-hidden">
                @if(session('error'))
                    <div class="mb-6 p-4 bg-red-50 border border-red-100 rounded-2xl animate-in fade-in slide-in-from-top-2">
                        <div class="flex items-center text-red-700 text-sm font-bold">
                            <i class="fas fa-exclamation-circle mr-2 text-red-500"></i>
                            {{ session('error') }}
                        </div>
                    </div>
                @endif

                @if(session('success'))
                    <div class="mb-6 p-4 bg-emerald-50 border border-emerald-100 rounded-2xl animate-in fade-in slide-in-from-top-2">
                        <div class="flex items-center text-emerald-700 text-sm font-bold">
                            <i class="fas fa-check-circle mr-2 text-emerald-500"></i>
                            {{ session('success') }}
                        </div>
                    </div>
                @endif

                <!-- Logo -->
                <div class="text-center mb-8">
                    <div class="inline-flex items-center justify-center mb-4">
                        <img src="{{ asset('assets/img/backgrounds/logo-Photoroom.png') }}" alt="Hotel Wimpy" class="h-20 w-auto object-contain">
                    </div>
                    <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">Iniciar Sesión</h1>
                </div>

                <form class="space-y-6" method="POST" action="{{ route('login') }}">
                    @csrf
                    
                    <!-- Email/Username Field -->
                    <div>
                        <label for="login" class="flex items-center text-sm font-semibold text-slate-700 mb-2 ml-1">
                            <i class="fas fa-envelope-open mr-2 text-slate-400"></i>
                            Email o Usuario
                        </label>
                        <div class="relative group">
                            <input id="login" name="login" type="text" required autocomplete="username"
                                   class="block w-full pl-4 pr-12 py-4 bg-slate-50/50 border border-slate-200 rounded-2xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-slate-900/5 focus:border-slate-900 transition-all duration-200 text-base"
                                   placeholder="admin@hotelwimpy.com o admin"
                                   value="{{ old('login') }}">
                            <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none">
                                <i class="fas fa-check text-slate-400 opacity-0 group-focus-within:opacity-100 transition-opacity"></i>
                            </div>
                        </div>
                        @error('login')
                            <p class="mt-2 text-xs text-red-500 flex items-center font-medium">
                                <i class="fas fa-circle-exclamation mr-1.5"></i>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                    
                    <!-- Password Field -->
                    <div>
                        <div class="flex items-center justify-between mb-2 ml-1">
                            <label for="password" class="flex items-center text-sm font-semibold text-slate-700">
                                <i class="fas fa-shield-alt mr-2 text-slate-400"></i>
                                Contraseña
                            </label>
                        </div>
                        <div class="relative group">
                            <input id="password" name="password" type="password" required autocomplete="current-password"
                                   class="block w-full pl-4 pr-12 py-4 bg-slate-50/50 border border-slate-200 rounded-2xl text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-slate-900/5 focus:border-slate-900 transition-all duration-200 text-base"
                                   placeholder="••••••••">
                        </div>
                        @error('password')
                            <p class="mt-2 text-xs text-red-500 flex items-center font-medium">
                                <i class="fas fa-circle-exclamation mr-1.5"></i>
                                {{ $message }}
                            </p>
                        @enderror
                    </div>
                    
                    <!-- Submit Button -->
                    <div class="pt-2">
                        <button type="submit" 
                                class="w-full flex justify-center items-center py-4 px-4 border border-transparent text-base font-bold rounded-2xl text-white bg-slate-900 hover:bg-slate-800 focus:outline-none focus:ring-4 focus:ring-slate-900/10 shadow-xl shadow-slate-900/20 transition-all duration-200 active:scale-[0.98]">
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Entrar al Sistema
                        </button>
                    </div>
                </form>

            </div>
        </div>
        
        <!-- Footer -->
        <div class="mt-8 text-center">
            <p class="text-white/60 text-xs font-semibold tracking-widest uppercase">
                &copy; {{ date('Y') }} HOTEL WIMPY &bull; GESTIÓN INTEGRAL
            </p>
        </div>
    </div>
</body>
</html>
