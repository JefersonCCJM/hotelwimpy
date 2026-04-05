<div id="customers-table">
    <!-- Filtros -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="space-y-4">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-gray-700">Filtros de Búsqueda</h3>
                @if ($search || $status)
                    <button wire:click="resetFilters"
                        class="text-xs text-emerald-600 hover:text-emerald-700 font-medium flex items-center">
                        <i class="fas fa-times mr-1"></i>
                        Limpiar filtros
                    </button>
                @endif
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 sm:gap-5">
                <div>
                    <label for="search" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">
                        Buscar Cliente
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400 text-sm"></i>
                        </div>
                        <input type="text" autocomplete="off" wire:model.live.debounce.300ms="search" id="search"
                            class="block w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent transition-all"
                            placeholder="Nombre, email, telefono o documento...">
                    </div>
                    @if ($search)
                        <p class="mt-1.5 text-xs text-gray-500 flex items-center">
                            <i class="fas fa-info-circle mr-1.5"></i>
                            Buscando: "{{ $search }}"
                        </p>
                    @endif
                </div>

                <div>
                    <label for="status"
                        class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">
                        Estado del Cliente
                    </label>
                    <div class="relative">
                        <select wire:model.live="status" id="status"
                            class="block w-full pl-3 sm:pl-4 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent appearance-none bg-white">
                            <option value="">Todos los estados</option>
                            <option value="active">Activo</option>
                            <option value="inactive">Inactivo</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                            <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                        </div>
                    </div>
                </div>

                <div>
                    <label for="perPage"
                        class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">
                        Resultados por página
                    </label>
                    <select wire:model.live="perPage" id="perPage"
                        class="block w-full pl-3 pr-10 py-2.5 border border-gray-300 rounded-xl text-sm text-gray-900 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:border-transparent appearance-none bg-white">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                </div>
            </div>

            @if ($search || $status)
                <div class="pt-3 border-t border-gray-100">
                    <div class="flex items-center text-xs text-gray-600">
                        <i class="fas fa-info-circle mr-2 text-emerald-600"></i>
                        <span>
                            Mostrando
                            <span class="font-semibold text-gray-900">{{ $customers->total() }}</span>
                            {{ $customers->total() === 1 ? 'cliente' : 'clientes' }}
                            @if ($search)
                                que coinciden con "{{ $search }}"
                            @endif
                            @if ($status)
                                con estado {{ $status === 'active' ? 'activo' : 'inactivo' }}
                            @endif
                        </span>
                    </div>
                </div>
            @endif
        </div>
    </div>

    <!-- Tabla de clientes - Desktop -->
    <div class="hidden lg:block bg-white rounded-xl border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            CLIENTE
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            CONTACTO
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            UBICACIÓN
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            INFORMACIÓN DIAN
                        </th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            ESTADO
                        </th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            ACCIONES
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse($customers as $customer)
                        <tr class="hover:bg-gray-50 transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div
                                        class="h-10 w-10 rounded-full bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center text-white text-sm font-semibold shadow-sm flex-shrink-0">
                                        {{ strtoupper(substr($customer->name, 0, 1)) }}
                                    </div>
                                    <div class="ml-3">
                                        <div class="text-sm font-semibold text-gray-900">{{ $customer->name }}</div>
                                        <div class="text-xs text-gray-500 mt-0.5">ID: {{ $customer->id }}</div>
                                        @if ($customer->requires_electronic_invoice && $customer->taxProfile)
                                            <div class="text-xs text-blue-600 mt-0.5">
                                                <i class="fas fa-file-invoice mr-1"></i>
                                                {{ $customer->taxProfile->identification ?? 'S/N' }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td class="px-6 py-4">
                                <div class="text-sm space-y-2">
                                    <!-- Contacto básico del cliente -->
                                    <div class="space-y-1">
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Contacto
                                            Básico</p>
                                        @if ($customer->email)
                                            <div class="flex items-center text-gray-700">
                                                <i class="fas fa-envelope text-gray-400 mr-2 text-xs"></i>
                                                <span class="truncate max-w-xs">{{ $customer->email }}</span>
                                            </div>
                                        @endif
                                        @if ($customer->phone)
                                            <div class="flex items-center text-gray-700">
                                                <i class="fas fa-phone text-gray-400 mr-2 text-xs"></i>
                                                <span>{{ $customer->phone }}</span>
                                            </div>
                                        @endif
                                        @if (!$customer->email && !$customer->phone)
                                            <span class="text-xs text-gray-400">Sin contacto básico</span>
                                        @endif
                                    </div>

                                    <!-- Contacto para facturación electrónica -->
                                    @if ($customer->requires_electronic_invoice && $customer->taxProfile)
                                        <div class="space-y-1 pt-2 border-t border-gray-100">
                                            <p class="text-xs font-semibold text-blue-500 uppercase tracking-wider">
                                                Facturación Electrónica</p>
                                            @if ($customer->taxProfile->email)
                                                <div class="flex items-center text-blue-700">
                                                    <i class="fas fa-envelope text-blue-400 mr-2 text-xs"></i>
                                                    <span
                                                        class="truncate max-w-xs">{{ $customer->taxProfile->email }}</span>
                                                </div>
                                            @endif
                                            @if ($customer->taxProfile->phone)
                                                <div class="flex items-center text-blue-700">
                                                    <i class="fas fa-phone text-blue-400 mr-2 text-xs"></i>
                                                    <span>{{ $customer->taxProfile->phone }}</span>
                                                </div>
                                            @endif
                                            @if ($customer->taxProfile->address)
                                                <div class="flex items-center text-blue-700">
                                                    <i class="fas fa-map-marker-alt text-blue-400 mr-2 text-xs"></i>
                                                    <span
                                                        class="truncate max-w-xs">{{ $customer->taxProfile->address }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </td>

                            <td class="px-6 py-4">
                                <div class="text-sm space-y-2">
                                    <!-- Ubicación básica del cliente -->
                                    <div class="space-y-1">
                                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                                            Ubicación Básica</p>
                                        @if ($customer->address)
                                            <div class="flex items-center text-gray-700">
                                                <i class="fas fa-map-marker-alt text-gray-400 mr-1.5 text-xs"></i>
                                                <span class="truncate max-w-xs">{{ $customer->address }}</span>
                                            </div>
                                        @endif
                                        @if ($customer->city || $customer->state)
                                            <div class="text-xs text-gray-500">
                                                {{ $customer->city }}{{ $customer->city && $customer->state ? ', ' : '' }}{{ $customer->state }}
                                            </div>
                                        @endif
                                        @if (!$customer->address && !$customer->city && !$customer->state)
                                            <span class="text-xs text-gray-400">Sin ubicación básica</span>
                                        @endif
                                    </div>

                                    <!-- Ubicación para facturación electrónica -->
                                    @if ($customer->requires_electronic_invoice && $customer->taxProfile && $customer->taxProfile->municipality)
                                        <div class="space-y-1 pt-2 border-t border-gray-100">
                                            <p class="text-xs font-semibold text-blue-500 uppercase tracking-wider">
                                                Ubicación Factura</p>
                                            <div class="text-xs text-blue-700">
                                                {{ $customer->taxProfile->municipality->name ?? 'S/N' }}
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex flex-col space-y-2">
                                    <!-- Información de facturación electrónica -->
                                    @if ($customer->requires_electronic_invoice && $customer->taxProfile)
                                        <div class="space-y-1.5">
                                            <p class="text-xs font-semibold text-blue-500 uppercase tracking-wider">DIAN
                                            </p>
                                            <div class="flex items-center space-x-2">
                                                <div class="p-1.5 rounded-lg bg-blue-50 text-blue-600"
                                                    title="Facturación Electrónica">
                                                    <i class="fas fa-file-invoice text-xs"></i>
                                                </div>
                                                <div class="text-xs text-blue-700">
                                                    {{ $customer->taxProfile->identificationDocument?->code ?? 'S/N' }}:
                                                    {{ $customer->taxProfile->identification ?? 'S/N' }}
                                                    @if ($customer->taxProfile->dv)
                                                        -{{ $customer->taxProfile->dv }}
                                                    @endif
                                                </div>
                                            </div>
                                            @if ($customer->taxProfile->legalOrganization)
                                                <div class="text-xs text-blue-600">
                                                    {{ $customer->taxProfile->legalOrganization->name ?? 'S/N' }}
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <div class="text-xs text-gray-400">
                                            <i class="fas fa-times-circle mr-1"></i>
                                            Sin facturación electrónica
                                        </div>
                                    @endif

                                    <!-- Registro y actividad -->
                                    <div class="pt-2 border-t border-gray-100">
                                        <div class="text-xs text-gray-500">
                                            {{ $customer->created_at->format('d/m/Y') }}
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap">
                                @if ($customer->is_active)
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">
                                        <i class="fas fa-check-circle mr-1.5"></i>
                                        Activo
                                    </span>
                                @else
                                    <span
                                        class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                        <i class="fas fa-times-circle mr-1.5"></i>
                                        Inactivo
                                    </span>
                                @endif
                            </td>

                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <div class="flex items-center justify-end space-x-2 sm:space-x-3">
                                    <button
                                        wire:click="viewCustomer({{ $customer->id }})"
                                        class="p-2 sm:p-1.5 text-blue-600 hover:text-blue-700 hover:bg-blue-50 rounded-lg transition-colors min-w-[44px] min-h-[44px] sm:min-w-0 sm:min-h-0 flex items-center justify-center"
                                        title="Ver detalles">
                                        <i class="fas fa-eye text-sm sm:text-base"></i>
                                    </button>

                                    <button
                                        wire:click="$dispatch('open-edit-customer-modal', { customerId: {{ $customer->id }} })"
                                        class="p-2 sm:p-1.5 text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 rounded-lg transition-colors min-w-[44px] min-h-[44px] sm:min-w-0 sm:min-h-0 flex items-center justify-center"
                                        title="Editar">
                                        <i class="fas fa-edit text-sm sm:text-base"></i>
                                    </button>

                                    <button
                                        wire:click="$dispatch('open-delete-customer-modal', { customerId: {{ $customer->id }}, customerName: '{{ $customer->name }}' })"
                                        class="p-2 sm:p-1.5 text-red-600 hover:text-red-700 hover:bg-red-50 rounded-lg transition-colors min-w-[44px] min-h-[44px] sm:min-w-0 sm:min-h-0 flex items-center justify-center"
                                        title="Eliminar">
                                        <i class="fas fa-trash text-sm sm:text-base"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center">
                                    @if ($search || $status)
                                        <div class="p-4 rounded-full bg-amber-50 text-amber-600 mb-4">
                                            <i class="fas fa-search text-3xl"></i>
                                        </div>
                                        <p class="text-lg font-semibold text-gray-900 mb-2">No se encontraron
                                            resultados</p>
                                        <p class="text-sm text-gray-500 mb-4">
                                            No hay clientes que coincidan con los filtros aplicados.
                                        </p>
                                        <button wire:click="resetFilters"
                                            class="inline-flex items-center justify-center px-4 sm:px-5 py-3 sm:py-2.5 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm sm:text-base font-semibold hover:bg-emerald-700 transition-all min-h-[44px]">
                                            <i class="fas fa-times mr-2"></i>
                                            Limpiar filtros
                                        </button>
                                    @else
                                        <div class="p-4 rounded-full bg-gray-50 text-gray-400 mb-4">
                                            <i class="fas fa-users text-3xl"></i>
                                        </div>
                                        <p class="text-lg font-semibold text-gray-900 mb-2">No hay clientes registrados
                                        </p>
                                        <p class="text-sm text-gray-500 mb-4">
                                            Comienza agregando tu primer cliente al sistema.
                                        </p>
                                        <button wire:click="$dispatch('open-create-customer-modal')"
                                            class="inline-flex items-center justify-center px-4 sm:px-5 py-3 sm:py-2.5 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm sm:text-base font-semibold hover:bg-emerald-700 transition-all min-h-[44px]">
                                            <i class="fas fa-plus mr-2"></i>
                                            <span class="hidden sm:inline">Crear Primer Cliente</span>
                                            <span class="sm:hidden">Crear Cliente</span>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Paginación Desktop -->
        @if ($customers->hasPages())
            <div class="border-t border-gray-100 bg-gradient-to-r from-white via-white to-emerald-50/70 px-4 py-5 sm:px-6">
                {{ $customers->links('livewire.customers.partials.pagination', ['scrollTo' => '#customers-table']) }}
            </div>
        @endif
    </div>

    <!-- Cards de clientes - Mobile/Tablet -->
    <div class="lg:hidden space-y-4">
        @forelse($customers as $customer)
            <div class="bg-white rounded-xl border border-gray-100 p-4 hover:shadow-md transition-shadow duration-200">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex items-center space-x-3 flex-1 min-w-0">
                        <div
                            class="h-12 w-12 rounded-full bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center text-white text-base font-semibold shadow-sm flex-shrink-0">
                            {{ strtoupper(substr($customer->name, 0, 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $customer->name }}</h3>
                            <p class="text-xs text-gray-500 mt-0.5">ID: {{ $customer->id }}</p>
                        </div>
                    </div>

                    <div class="flex items-center space-x-2 ml-2">
                        @if ($customer->is_active)
                            <span
                                class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700">
                                <i class="fas fa-check-circle mr-1"></i>
                                Activo
                            </span>
                        @else
                            <span
                                class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-700">
                                <i class="fas fa-times-circle mr-1"></i>
                                Inactivo
                            </span>
                        @endif
                    </div>
                </div>

                <div class="space-y-3 mb-4">
                    <!-- Información básica del cliente -->
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Información
                            Básica</p>
                        <div class="space-y-1.5">
                            @if ($customer->email)
                                <div class="flex items-center text-sm text-gray-700">
                                    <i class="fas fa-envelope text-gray-400 mr-2 text-xs w-4"></i>
                                    <span class="truncate">{{ $customer->email }}</span>
                                </div>
                            @endif
                            @if ($customer->phone)
                                <div class="flex items-center text-sm text-gray-700">
                                    <i class="fas fa-phone text-gray-400 mr-2 text-xs w-4"></i>
                                    <span>{{ $customer->phone }}</span>
                                </div>
                            @endif
                            @if ($customer->address || $customer->city || $customer->state)
                                <div class="flex items-start text-sm text-gray-700">
                                    <i class="fas fa-map-marker-alt text-gray-400 mr-2 text-xs mt-0.5 w-4"></i>
                                    <span class="flex-1">
                                        {{ $customer->address ?? '' }}{{ $customer->address && ($customer->city || $customer->state) ? ', ' : '' }}
                                        {{ $customer->city ?? '' }}{{ $customer->city && $customer->state ? ', ' : '' }}{{ $customer->state ?? '' }}
                                    </span>
                                </div>
                            @endif
                            @if (!$customer->email && !$customer->phone && !$customer->address && !$customer->city && !$customer->state)
                                <span class="text-xs text-gray-400">Sin información básica</span>
                            @endif
                        </div>
                    </div>

                    <!-- Información de facturación electrónica -->
                    @if ($customer->requires_electronic_invoice && $customer->taxProfile)
                        <div>
                            <p class="text-xs font-semibold text-blue-500 uppercase tracking-wider mb-1.5">Facturación
                                Electrónica</p>
                            <div class="space-y-1.5">
                                <div class="flex items-center text-sm text-blue-700">
                                    <i class="fas fa-file-invoice text-blue-400 mr-2 text-xs w-4"></i>
                                    <span>
                                        {{ $customer->taxProfile->identificationDocument?->code ?? 'S/N' }}:
                                        {{ $customer->taxProfile->identification ?? 'S/N' }}
                                        @if ($customer->taxProfile->dv)
                                            -{{ $customer->taxProfile->dv }}
                                        @endif
                                    </span>
                                </div>
                                @if ($customer->taxProfile->email && $customer->taxProfile->email !== $customer->email)
                                    <div class="flex items-center text-sm text-blue-700">
                                        <i class="fas fa-envelope text-blue-400 mr-2 text-xs w-4"></i>
                                        <span class="truncate">{{ $customer->taxProfile->email }}</span>
                                    </div>
                                @endif
                                @if ($customer->taxProfile->phone && $customer->taxProfile->phone !== $customer->phone)
                                    <div class="flex items-center text-sm text-blue-700">
                                        <i class="fas fa-phone text-blue-400 mr-2 text-xs w-4"></i>
                                        <span>{{ $customer->taxProfile->phone }}</span>
                                    </div>
                                @endif
                                @if ($customer->taxProfile->address && $customer->taxProfile->address !== $customer->address)
                                    <div class="flex items-start text-sm text-blue-700">
                                        <i class="fas fa-map-marker-alt text-blue-400 mr-2 text-xs mt-0.5 w-4"></i>
                                        <span class="flex-1 truncate">{{ $customer->taxProfile->address }}</span>
                                    </div>
                                @endif
                                @if ($customer->taxProfile->municipality)
                                    <div class="flex items-center text-sm text-blue-700">
                                        <i class="fas fa-city text-blue-400 mr-2 text-xs w-4"></i>
                                        <span>{{ $customer->taxProfile->municipality->name }}</span>
                                    </div>
                                @endif
                                @if ($customer->taxProfile->legalOrganization)
                                    <div class="flex items-center text-sm text-blue-600">
                                        <i class="fas fa-building text-blue-400 mr-2 text-xs w-4"></i>
                                        <span
                                            class="truncate">{{ $customer->taxProfile->legalOrganization->name }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif

                    <!-- Registro y Actividad -->
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Registro y
                            Actividad</p>
                        <div class="space-y-2">
                            <div class="flex items-center space-x-2">
                                <div class="flex items-center text-xs text-gray-600">
                                    <i class="fas fa-calendar text-gray-400 mr-2 text-xs w-4"></i>
                                    <span>Registrado: {{ $customer->created_at->format('d/m/Y') }}</span>
                                </div>
                                @if ($customer->requires_electronic_invoice && $customer->taxProfile)
                                    <div class="flex items-center text-xs text-blue-600">
                                        <i class="fas fa-file-invoice text-blue-400 mr-2 text-xs w-4"></i>
                                        <span>Facturación Electrónica</span>
                                    </div>
                                @endif
                            </div>
                            <div class="flex items-center p-2 bg-gray-50 rounded-lg">
                                <div class="p-1.5 rounded-lg bg-white border border-gray-200 text-gray-400 mr-2">
                                    <i class="fas fa-history text-xs"></i>
                                </div>
                                <span class="text-xs text-gray-500 italic">Próximamente: Reservas</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Acciones -->
                <div class="flex items-center justify-end space-x-2 sm:space-x-3 pt-3 border-t border-gray-100">
                    <button wire:click="$dispatch('open-edit-customer-modal', { customerId: {{ $customer->id }} })"
                        class="p-3 sm:p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors min-w-[44px] min-h-[44px] sm:min-w-0 sm:min-h-0 flex items-center justify-center"
                        title="Ver">
                        <i class="fas fa-eye text-base sm:text-sm"></i>
                    </button>

                    <button wire:click="$dispatch('open-edit-customer-modal', { customerId: {{ $customer->id }} })"
                        class="p-3 sm:p-2 text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors min-w-[44px] min-h-[44px] sm:min-w-0 sm:min-h-0 flex items-center justify-center"
                        title="Editar">
                        <i class="fas fa-edit text-base sm:text-sm"></i>
                    </button>

                    <button
                        wire:click="$dispatch('open-delete-customer-modal', { customerId: {{ $customer->id }}, customerName: '{{ $customer->name }}' })"
                        class="p-3 sm:p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors min-w-[44px] min-h-[44px] sm:min-w-0 sm:min-h-0 flex items-center justify-center"
                        title="Eliminar">
                        <i class="fas fa-trash text-base sm:text-sm"></i>
                    </button>
                </div>
            </div>
        @empty
            <div class="bg-white rounded-xl border border-gray-100 p-12 text-center">
                @if ($search || $status)
                    <div class="p-4 rounded-full bg-amber-50 text-amber-600 mb-4 inline-block">
                        <i class="fas fa-search text-3xl"></i>
                    </div>
                    <p class="text-lg font-semibold text-gray-900 mb-2">No se encontraron resultados</p>
                    <p class="text-sm text-gray-500 mb-4">
                        No hay clientes que coincidan con los filtros aplicados.
                    </p>
                    <button wire:click="resetFilters"
                        class="inline-flex items-center px-4 py-2 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-all">
                        <i class="fas fa-times mr-2"></i>
                        Limpiar filtros
                    </button>
                @else
                    <div class="p-4 rounded-full bg-gray-50 text-gray-400 mb-4 inline-block">
                        <i class="fas fa-users text-3xl"></i>
                    </div>
                    <p class="text-lg font-semibold text-gray-900 mb-2">No hay clientes registrados</p>
                    <p class="text-sm text-gray-500 mb-4">
                        Comienza agregando tu primer cliente al sistema.
                    </p>
                    <button wire:click="$dispatch('open-create-customer-modal')"
                        class="inline-flex items-center px-4 py-2 rounded-xl border-2 border-emerald-600 bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-all">
                        <i class="fas fa-plus mr-2"></i>
                        Crear Primer Cliente
                    </button>
                @endif
            </div>
        @endforelse

        <!-- Paginación Mobile -->
        @if ($customers->hasPages())
            <div
                class="rounded-xl border border-gray-100 bg-gradient-to-br from-white via-white to-emerald-50/80 p-4 sm:p-5">
                {{ $customers->links('livewire.customers.partials.pagination', ['scrollTo' => '#customers-table']) }}
            </div>
        @endif
    </div>
</div>
