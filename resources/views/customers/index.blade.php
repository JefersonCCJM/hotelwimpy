@extends('layouts.app')

@section('title', 'Clientes')
@section('header', 'Gestión de Clientes')

@section('content')
    <div class="space-y-4 sm:space-y-6">
        <!-- Header -->
        <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div class="flex items-center space-x-3 sm:space-x-4">
                    <div class="p-2.5 sm:p-3 rounded-xl bg-emerald-50 text-emerald-600">
                        <i class="fas fa-users text-lg sm:text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Gestión de Clientes</h1>
                        <div class="flex items-center space-x-2 mt-1">
                            <span class="text-xs sm:text-sm text-gray-500">
                                <i class="fas fa-database mr-1"></i> Base de datos de clientes
                            </span>
                        </div>
                    </div>
                </div>

                <button onclick="Livewire.dispatch('open-create-customer-modal')"
                    class="inline-flex items-center justify-center px-4 sm:px-5 py-2.5 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 hover:border-emerald-700 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-emerald-500 shadow-sm hover:shadow-md">
                    <i class="fas fa-plus mr-2"></i>
                    <span>Nuevo Cliente</span>
                </button>
            </div>
        </div>

        <!-- Componentes Livewire -->
        <livewire:customers.customers-table />
        <livewire:customers.create-customer-modal />
        <livewire:customers.view-customer-modal />
        <livewire:customers.edit-customer-modal />
        <livewire:customers.delete-customer-modal />
    </div>
@endsection

@push('scripts')
    <script>
        // Sistema de notificaciones
        window.addEventListener('notify', function(event) {
            const notification = event.detail;

            // Crear elemento de notificación
            const notificationEl = document.createElement('div');
            notificationEl.className =
                `fixed top-4 right-4 z-50 max-w-sm w-full p-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;

            // Definir colores según el tipo
            const colors = {
                success: 'bg-green-500 text-white',
                error: 'bg-red-500 text-white',
                warning: 'bg-yellow-500 text-white',
                info: 'bg-blue-500 text-white'
            };

            notificationEl.classList.add(...colors[notification.type].split(' '));

            // Estructura de la notificación
            notificationEl.innerHTML = `
        <div class="flex items-center">
            <div class="flex-shrink-0">
                ${notification.type === 'success' ? '<i class="fas fa-check-circle"></i>' : ''}
                ${notification.type === 'error' ? '<i class="fas fa-exclamation-circle"></i>' : ''}
                ${notification.type === 'warning' ? '<i class="fas fa-exclamation-triangle"></i>' : ''}
                ${notification.type === 'info' ? '<i class="fas fa-info-circle"></i>' : ''}
            </div>
            <div class="ml-3">
                <p class="text-sm font-medium">${notification.message}</p>
            </div>
            <div class="ml-auto pl-3">
                <button onclick="this.parentElement.parentElement.parentElement.remove()" class="inline-flex text-white hover:opacity-75">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    `;

            // Agregar al DOM
            document.body.appendChild(notificationEl);

            // Animar entrada
            setTimeout(() => {
                notificationEl.classList.remove('translate-x-full');
                notificationEl.classList.add('translate-x-0');
            }, 100);

            // Auto eliminar después de 5 segundos
            setTimeout(() => {
                notificationEl.classList.add('translate-x-full');
                setTimeout(() => {
                    if (notificationEl.parentElement) {
                        notificationEl.remove();
                    }
                }, 300);
            }, 5000);
        });

        // Manejar tecla ESC para cerrar modales
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                // Cerrar modales usando Livewire
                if (typeof Livewire !== 'undefined') {
                    Livewire.dispatch('close-create-customer-modal');
                    Livewire.dispatch('close-view-customer-modal');
                    Livewire.dispatch('close-edit-customer-modal');
                    Livewire.dispatch('close-delete-customer-modal');
                }
            }
        });

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Customers index loaded with Livewire components');
        });
    </script>
@endpush
