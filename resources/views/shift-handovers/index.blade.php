@extends('layouts.app')

@section('title', 'Historial de Turnos')
@section('header', 'Historial de Turnos')

@section('content')
@php
    $isAdmin = auth()->user()?->hasRole('Administrador') ?? false;
@endphp

<div class="space-y-6">
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="p-3 rounded-xl {{ $isAdmin ? 'bg-indigo-50 text-indigo-600' : 'bg-emerald-50 text-emerald-600' }}">
                    <i class="fas {{ $isAdmin ? 'fa-shield-alt' : 'fa-history' }} text-xl"></i>
                </div>
                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-gray-900 tracking-tight">Historial de Turnos</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        @if($isAdmin)
                            Vista global de entregas y recepciones de todos los turnos.
                        @else
                            Consulta de turnos relacionados contigo.
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if($isAdmin)
                    <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-black uppercase tracking-wider border border-indigo-100">
                        <i class="fas fa-user-shield"></i> Panel Administrador
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- Turno activo destacado --}}
    @php
        $activeTurno = $handovers->firstWhere(fn($h) => $h->status->value === 'activo');
    @endphp

    @if($activeTurno)
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 shadow-sm">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-gray-100 pb-4">
            <div class="flex items-center gap-4">
                <div class="relative">
                    <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl">
                        <i class="fas fa-broadcast-tower text-xl"></i>
                    </div>
                    <span class="absolute -top-1 -right-1 flex h-3 w-3">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-3 w-3 bg-emerald-500"></span>
                    </span>
                </div>
                <div>
                    <h3 class="font-black text-gray-900 text-lg tracking-tight">Turno EN VIVO</h3>
                    <p class="text-sm text-gray-600">
                        <span class="font-bold">{{ $activeTurno->receptionist_display_name }}</span>
                        - Turno {{ strtoupper($activeTurno->shift_type->value) }}
                        - Desde {{ $activeTurno->started_at->format('H:i') }}
                        ({{ $activeTurno->started_at->diffForHumans() }})
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="text-[10px] text-gray-500 uppercase font-black tracking-wider">Base Esperada</p>
                    <p class="text-2xl font-black text-emerald-600">${{ number_format($activeTurno->base_esperada, 0, ',', '.') }}</p>
                </div>
                <a href="{{ route('shift-handovers.show', $activeTurno->id) }}"
                   class="inline-flex items-center justify-center bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-3 rounded-xl text-sm font-black transition-colors shadow-sm">
                    <i class="fas fa-eye mr-2"></i> Ver en Vivo
                </a>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4">
            <div class="bg-gray-50 rounded-xl p-3 text-center border border-gray-100">
                <p class="text-[10px] text-gray-500 uppercase font-black tracking-wider">Base Inicial</p>
                <p class="text-lg font-bold text-gray-900">${{ number_format($activeTurno->base_inicial, 0, ',', '.') }}</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-3 text-center border border-gray-100">
                <p class="text-[10px] text-emerald-600 uppercase font-black tracking-wider">Efectivo</p>
                <p class="text-lg font-bold text-emerald-600">${{ number_format($activeTurno->total_entradas_efectivo, 0, ',', '.') }}</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-3 text-center border border-gray-100">
                <p class="text-[10px] text-blue-600 uppercase font-black tracking-wider">Transferencia</p>
                <p class="text-lg font-bold text-blue-600">${{ number_format($activeTurno->total_entradas_transferencia, 0, ',', '.') }}</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-3 text-center border border-gray-100">
                <p class="text-[10px] text-red-600 uppercase font-black tracking-wider">Salidas</p>
                <p class="text-lg font-bold text-red-600">${{ number_format($activeTurno->total_salidas, 0, ',', '.') }}</p>
            </div>
            <div class="bg-gray-50 rounded-xl p-3 text-center border border-gray-100">
                <p class="text-[10px] text-gray-500 uppercase font-black tracking-wider">Ventas</p>
                <p class="text-lg font-bold text-gray-900">{{ $activeTurno->sales->count() }}</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Tabla de historial --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="p-4 sm:p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Fecha</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Turno</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Recepcionista</th>
                            <th class="px-4 py-3 text-left text-[10px] font-black text-gray-500 uppercase tracking-wider">Recibido por</th>
                            <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider">Ventas Efect.</th>
                            <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider">Ventas Transf.</th>
                            <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider">Salidas</th>
                            <th class="px-4 py-3 text-right text-[10px] font-black text-gray-500 uppercase tracking-wider">Base Final</th>
                            <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider">Estado</th>
                            <th class="px-4 py-3 text-center text-[10px] font-black text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-100">
                        @foreach($handovers as $handover)
                        <tr class="hover:bg-gray-50 transition-colors {{ $handover->status->value === 'activo' ? 'bg-emerald-50/50' : '' }}">
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 font-medium">
                                {{ $handover->shift_date->format('d/m/Y') }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500 uppercase font-bold">
                                {{ $handover->shift_type->value }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700 font-medium">
                                {{ $handover->receptionist_display_name }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                                {{ $handover->recibidoPor->name ?? '—' }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-bold text-emerald-600">
                                ${{ number_format($handover->total_entradas_efectivo, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-bold text-blue-600">
                                ${{ number_format($handover->total_entradas_transferencia, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-bold text-red-600">
                                ${{ number_format($handover->total_salidas, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900">
                                ${{ number_format($handover->base_final, 0, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-center">
                                @php
                                    $statusClasses = [
                                        'activo' => 'bg-emerald-100 text-emerald-700',
                                        'entregado' => 'bg-amber-100 text-amber-700',
                                        'recibido' => 'bg-blue-100 text-blue-700',
                                        'cerrado' => 'bg-gray-100 text-gray-700',
                                    ];
                                    $class = $statusClasses[$handover->status->value] ?? 'bg-gray-100 text-gray-700';
                                @endphp
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase {{ $class }}">
                                    @if($handover->status->value === 'activo')
                                        <span class="relative flex h-2 w-2">
                                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                            <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                                        </span>
                                    @endif
                                    {{ $handover->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-4 whitespace-nowrap text-center text-sm font-medium space-x-1">
                                <a href="{{ route('shift-handovers.show', $handover->id) }}"
                                   class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg transition-colors text-xs font-bold {{ $handover->status->value === 'activo' ? 'text-emerald-700 hover:text-emerald-900 bg-emerald-50 border border-emerald-100' : 'text-blue-700 hover:text-blue-900 bg-blue-50 border border-blue-100' }}">
                                    <i class="fas fa-eye mr-1"></i>
                                    {{ $handover->status->value === 'activo' ? 'Ver en Vivo' : 'Ver Detalle' }}
                                </a>
                                <a href="{{ route('shift-handovers.pdf', $handover->id) }}"
                                   class="inline-flex items-center justify-center px-3 py-1.5 rounded-lg transition-colors text-xs font-bold text-red-700 hover:text-red-900 bg-red-50 border border-red-100">
                                    <i class="fas fa-file-pdf mr-1"></i>
                                    PDF
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                {{ $handovers->links() }}
            </div>
        </div>
    </div>
</div>
@endsection
