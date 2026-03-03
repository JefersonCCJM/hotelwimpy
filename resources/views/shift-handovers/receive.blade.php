@extends('layouts.app')

@section('title', 'Recibir Caja de Turno')
@section('header', 'Recepción de Caja')

@section('content')
    <div class="space-y-6">
        @if (!$pendingReception)
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <div class="p-6 text-center py-8">
                        <div class="mb-4 text-gray-300">
                            <i class="fas fa-check-circle text-6xl"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 mb-2">No hay turnos pendientes</h3>
                        <p class="text-gray-500 mb-6">No se encontraron turnos entregados pendientes de recibir por tu parte.
                        </p>
                        <a href="{{ route('dashboard') }}"
                            class="bg-gray-800 text-white px-6 py-2 rounded-lg text-sm font-bold">
                            Volver al Dashboard
                        </a>
                    </div>
                </div>
            </div>
        @else
            {{-- Encabezado del turno a recibir --}}
            <div class="bg-amber-50 border-2 border-amber-200 rounded-xl p-6 shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div class="flex items-center gap-4">
                        <div class="p-3 bg-amber-100 text-amber-600 rounded-xl">
                            <i class="fas fa-hand-holding-usd text-xl"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-amber-900 text-lg">Turno Pendiente de Recibir</h3>
                            <p class="text-sm text-amber-700">
                                Entregado por <span
                                    class="font-bold">{{ $pendingReception->receptionist_display_name }}</span>
                                — Turno <span class="uppercase font-bold">{{ $pendingReception->shift_type->value }}</span>
                                — {{ $pendingReception->shift_date->format('d/m/Y') }}
                            </p>
                            @if ($pendingReception->ended_at)
                                <p class="text-xs text-amber-600 mt-1">
                                    Entregado {{ $pendingReception->ended_at->diffForHumans() }}
                                    ({{ $pendingReception->ended_at->format('H:i') }})
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Resumen financiero del turno anterior --}}
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-center">
                    <p class="text-[10px] font-black text-gray-500 uppercase tracking-wider mb-1">Base Inicial</p>
                    <p class="text-xl font-bold text-gray-900">
                        ${{ number_format($pendingReception->base_inicial, 0, ',', '.') }}</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-center">
                    <p class="text-[10px] font-black text-emerald-600 uppercase tracking-wider mb-1">Ventas Efectivo</p>
                    <p class="text-xl font-bold text-emerald-600">
                        ${{ number_format($pendingReception->total_entradas_efectivo, 0, ',', '.') }}</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-center">
                    <p class="text-[10px] font-black text-blue-600 uppercase tracking-wider mb-1">Ventas Transferencia</p>
                    <p class="text-xl font-bold text-blue-600">
                        ${{ number_format($pendingReception->total_entradas_transferencia, 0, ',', '.') }}</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-center">
                    <p class="text-[10px] font-black text-red-600 uppercase tracking-wider mb-1">Total Salidas</p>
                    <p class="text-xl font-bold text-red-600">
                        ${{ number_format($pendingReception->total_salidas, 0, ',', '.') }}</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm text-center">
                    <p class="text-[10px] font-black text-indigo-600 uppercase tracking-wider mb-1">Base Esperada</p>
                    <p class="text-2xl font-black text-indigo-600">
                        ${{ number_format($pendingReception->base_esperada, 0, ',', '.') }}</p>
                </div>
            </div>

            {{-- Contadores rápidos --}}
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex items-center gap-3">
                    <div class="p-2 bg-emerald-50 text-emerald-600 rounded-lg">
                        <i class="fas fa-receipt text-sm"></i>
                    </div>
                    <div>
                        <p class="text-lg font-black text-gray-900">{{ $pendingReception->sales->count() }}</p>
                        <p class="text-[10px] text-gray-500 uppercase font-bold">Ventas</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex items-center gap-3">
                    <div class="p-2 bg-red-50 text-red-600 rounded-lg">
                        <i class="fas fa-file-invoice-dollar text-sm"></i>
                    </div>
                    <div>
                        <p class="text-lg font-black text-gray-900">{{ $pendingReception->cashOutflows->count() }}</p>
                        <p class="text-[10px] text-gray-500 uppercase font-bold">Gastos</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex items-center gap-3">
                    <div class="p-2 bg-blue-50 text-blue-600 rounded-lg">
                        <i class="fas fa-box text-sm"></i>
                    </div>
                    <div>
                        <p class="text-lg font-black text-gray-900">{{ $pendingReception->productOuts->count() }}</p>
                        <p class="text-[10px] text-gray-500 uppercase font-bold">Salidas Producto</p>
                    </div>
                </div>
            </div>

            {{-- Observaciones del turno anterior --}}
            @if ($pendingReception->observaciones)
                <div class="bg-white rounded-xl border border-gray-100 p-6 shadow-sm">
                    <h4 class="text-xs font-black text-gray-500 uppercase tracking-wider mb-2">
                        <i class="fas fa-comment-alt mr-1"></i> Observaciones de
                        {{ $pendingReception->receptionist_display_name }}
                    </h4>
                    <p class="text-sm text-gray-700">{{ $pendingReception->observaciones }}</p>
                </div>
            @endif

            @if ($pendingReception->base_final)
                @php
                    $diff = $pendingReception->base_final - $pendingReception->base_esperada;
                @endphp
                @if (abs($diff) > 0)
                    <div
                        class="rounded-xl border p-4 flex items-start gap-3 {{ $diff < 0 ? 'bg-red-50 border-red-200 text-red-800' : 'bg-amber-50 border-amber-200 text-amber-800' }}">
                        <i class="fas fa-exclamation-triangle mt-0.5"></i>
                        <div>
                            <p class="text-sm font-bold">
                                {{ $diff < 0 ? 'Faltante' : 'Sobrante' }} reportado:
                                ${{ number_format(abs($diff), 0, ',', '.') }}
                            </p>
                            <p class="text-xs opacity-80">
                                Base reportada: ${{ number_format($pendingReception->base_final, 0, ',', '.') }}
                                — Base esperada: ${{ number_format($pendingReception->base_esperada, 0, ',', '.') }}
                            </p>
                        </div>
                    </div>
                @endif
            @endif

            {{-- Formulario de recepción --}}
            <div class="max-w-2xl mx-auto">
                <div class="bg-white rounded-xl border-2 border-blue-200 shadow-sm overflow-hidden">
                    <div class="bg-blue-50 px-6 py-4 border-b border-blue-100">
                        <h3 class="font-bold text-blue-900 text-lg">
                            <i class="fas fa-clipboard-check mr-2"></i> Confirmar Recepción
                        </h3>
                        <p class="text-xs text-blue-700 mt-1">Cuenta el dinero físico en caja y confirma la recepción del
                            turno.</p>
                    </div>
                    <div class="p-6">
                        <form action="{{ route('shift-handovers.store-reception') }}" method="POST" class="space-y-4">
                            @csrf
                            <input type="hidden" name="handover_id" value="{{ $pendingReception->id }}">

                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">Monto Físico Recibido ($)</label>
                                <input type="text" name="base_recibida" oninput="formatNumberInput(this)"
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-blue-500 focus:ring-0 transition-all text-2xl font-black text-center"
                                    placeholder="0" required autofocus>
                                <p class="mt-1 text-xs text-gray-500">Cuenta el dinero físico que hay en caja actualmente.
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">Observaciones de Recepción</label>
                                <textarea name="observaciones" rows="3"
                                    class="w-full px-4 py-2 border-2 border-gray-200 rounded-xl focus:border-blue-500 focus:ring-0 transition-all"
                                    placeholder="Escribe aquí cualquier novedad encontrada en la caja..."></textarea>
                            </div>

                            <div class="pt-4 flex gap-3">
                                <a href="{{ route('dashboard') }}"
                                    class="flex-1 text-center px-4 py-3 bg-gray-100 text-gray-700 rounded-xl text-sm font-bold hover:bg-gray-200 transition-colors">
                                    Cancelar
                                </a>
                                <button type="submit"
                                    class="flex-1 bg-blue-600 text-white py-3 rounded-xl text-sm font-black hover:bg-blue-700 transition-all shadow-md">
                                    <i class="fas fa-check-double mr-2"></i> CONFIRMAR RECEPCIÓN E INICIAR MI TURNO
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection
