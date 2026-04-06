@props(['categories'])

<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 shadow-sm">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Buscar</label>
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400 text-sm"></i>
                </div>
                <input type="text" autocomplete="off" wire:model.live.debounce.300ms="search" 
                       class="block w-full pl-10 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all"
                       placeholder="Nombre o SKU...">
            </div>
        </div>
        
        <div>
            <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Categoría</label>
            <div class="relative">
                <select wire:model.live="category_id"
                        class="block w-full pl-3 pr-10 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none">
                    <option value="">Todas las categorías</option>
                    @php
                        $aseoKeywords = ['aseo', 'limpieza', 'amenities', 'insumo', 'papel', 'jabon', 'cloro', 'mantenimiento'];
                        $aseoCats = $categories->filter(function($cat) use ($aseoKeywords) {
                            $name = strtolower($cat->name);
                            foreach ($aseoKeywords as $kw) if (str_contains($name, $kw)) return true;
                            return false;
                        });
                        $ventaCats = $categories->diff($aseoCats);
                    @endphp

                    @if($ventaCats->isNotEmpty())
                        <optgroup label="PRODUCTOS DE VENTA">
                            @foreach($ventaCats as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif

                    @if($aseoCats->isNotEmpty())
                        <optgroup label="INSUMOS DE ASEO">
                            @foreach($aseoCats as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
            </div>
        </div>
        
        <div>
            <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wider mb-2">Estado</label>
            <div class="relative">
                <select wire:model.live="status"
                        class="block w-full pl-3 pr-10 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 appearance-none">
                    <option value="">Todos los estados</option>
                    <option value="active">Activo</option>
                    <option value="inactive">Inactivo</option>
                    <option value="discontinued">Descontinuado</option>
                </select>
                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                    <i class="fas fa-chevron-down text-gray-400 text-xs"></i>
                </div>
            </div>
        </div>
        
        <div class="flex items-end">
            <button wire:click="$set('search', ''); $set('category_id', ''); $set('status', '');"
                    class="w-full inline-flex items-center justify-center px-4 py-2.5 rounded-xl border border-gray-200 bg-white text-gray-500 text-sm font-semibold hover:bg-gray-50 transition-all">
                <i class="fas fa-times mr-2"></i> Limpiar
            </button>
        </div>
    </div>
</div>

