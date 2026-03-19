@props(['products'])

<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50/50 font-bold uppercase text-[10px] text-gray-500 tracking-widest">
                <tr>
                    <th class="px-6 py-4 text-left">Producto</th>
                    <th class="px-6 py-4 text-left">Categoría</th>
                    <th class="px-6 py-4 text-left">Stock</th>
                    <th class="px-6 py-4 text-left">Precio</th>
                    <th class="px-6 py-4 text-left">Estado</th>
                    <th class="px-6 py-4 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-50">
                @forelse($products as $product)
                    <x-product-list.product-row :product="$product" wire:key="product-row-{{ $product->id }}" />
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-boxes text-4xl mb-4 block text-gray-300"></i>
                            No se encontraron productos
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($products->hasPages())
        <div class="px-6 py-4 border-t border-gray-50">
            {{ $products->links('livewire::tailwind') }}
        </div>
    @endif
</div>

