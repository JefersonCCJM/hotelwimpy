<div x-show="$wire.isOpen"
     x-transition
     class="fixed inset-0 z-[60] overflow-y-auto"
     x-cloak>
    <div class="flex min-h-screen items-center justify-center p-4">
        <div @click="$wire.close()" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>

        <div class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <div class="flex items-center space-x-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-red-50 text-red-600">
                        <i class="fas fa-trash"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900">Eliminar Cliente</h3>
                </div>

                <button @click="$wire.close()" class="text-gray-400 hover:text-gray-900">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="p-6">
                <div class="text-center">
                    <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                        <i class="fas fa-exclamation-triangle text-xl text-red-600"></i>
                    </div>

                    <h3 class="mb-2 text-lg font-medium text-gray-900">Estas seguro?</h3>
                    <p class="mb-6 text-sm text-gray-500">
                        Estas a punto de eliminar al cliente <strong>{{ $customerName }}</strong>. Esta accion no se puede deshacer.
                    </p>

                    <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-left">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle mr-3 mt-0.5 text-amber-600"></i>
                            <div class="text-sm text-amber-800">
                                <p class="mb-1 font-semibold">Importante:</p>
                                <p class="text-xs">
                                    Si el cliente tiene reservas asociadas no podra eliminarse. En ese caso, si solo necesitas usarlo para facturacion electronica, editalo y activa esa opcion sobre el mismo registro.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button type="button"
                                wire:click="close"
                                class="flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                            Cancelar
                        </button>

                        <button type="button"
                                wire:click="delete"
                                wire:loading.attr="disabled"
                                wire:target="delete"
                                class="flex-1 rounded-lg border border-transparent bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                            <span wire:loading.remove wire:target="delete">Eliminar</span>
                            <span wire:loading wire:target="delete">
                                <i class="fas fa-spinner fa-spin mr-1"></i>
                                Eliminando...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
