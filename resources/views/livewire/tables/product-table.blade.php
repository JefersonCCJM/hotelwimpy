<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title">
                {{ __('Productos') }}
            </h3>
        </div>

        <div class="card-actions btn-group">
            <div class="dropdown">
                <a href="#" class="btn-action dropdown-toggle" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <x-icon.vertical-dots/>
                </a>
                <div class="dropdown-menu dropdown-menu-end">
                    <a href="{{ route('products.create') }}" class="dropdown-item">
                        <x-icon.plus/>
                        {{ __('Crear Producto') }}
                    </a>
                    <a href="{{ route('products.import.view') }}" class="dropdown-item">
                        <x-icon.plus/>
                        {{ __('Importar Productos') }}
                    </a>
                    <a href="{{ route('products.export.store') }}" class="dropdown-item">
                        <x-icon.plus/>
                        {{ __('Exportar Productos') }}
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body border-bottom py-3">
        <div class="d-flex">
            <div class="text-secondary">
                Mostrar
                <div class="mx-2 d-inline-block">
                    <select wire:model.live="perPage" class="form-select form-select-sm" aria-label="resultados por página">
                        <option value="5">5</option>
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                    </select>
                </div>
                entradas
            </div>
            <div class="ms-auto text-secondary">
                Buscar:
                <div class="ms-2 d-inline-block">
                    <input type="text" autocomplete="off" wire:model.live="search" class="form-control form-control-sm" aria-label="Buscar producto">
                </div>
            </div>
        </div>
    </div>

    <x-spinner.loading-spinner/>

    <div class="table-responsive">
        <table wire:loading.remove class="table table-bordered card-table table-vcenter text-nowrap datatable">
            <thead class="thead-light">
                <tr>
                    <th class="align-middle text-center w-1">
                        {{ __('No.') }}
                    </th>
                    <th scope="col" class="align-middle text-center">
                        <a wire:click.prevent="sortBy('name')" href="#" role="button">
                            {{ __('Nombre') }}
                            @include('inclues._sort-icon', ['field' => 'name'])
                        </a>
                    </th>
                    <th scope="col" class="align-middle text-center">
                        <a wire:click.prevent="sortBy('code')" href="#" role="button">
                            {{ __('Código') }}
                            @include('inclues._sort-icon', ['field' => 'code'])
                        </a>
                    </th>
                    <th scope="col" class="align-middle text-center">
                        <a wire:click.prevent="sortBy('category_id')" href="#" role="button">
                            {{ __('Categoría') }}
                            @include('inclues._sort-icon', ['field' => 'category_id'])
                        </a>
                    </th>
                    <th scope="col" class="align-middle text-center">
                        <a wire:click.prevent="sortBy('quantity')" href="#" role="button">
                            {{ __('Cantidad') }}
                            @include('inclues._sort-icon', ['field' => 'quantity'])
                        </a>
                    </th>

                    <th scope="col" class="align-middle text-center">
                        <a wire:click.prevent="sortBy('quantity_alert')" href="#" role="button">
                            {{ __('Alerta de Cantidad') }}
                            @include('inclues._sort-icon', ['field' => 'quantity_alert'])
                        </a>
                    </th>

                    <th scope="col" class="align-middle text-center">
                        {{ __('Acción') }}
                    </th>
                </tr>
            </thead>
            <tbody>
            @forelse ($products as $product)
                <tr>
                    <td class="align-middle text-center">
                        {{ ($products->currentPage() - 1) * $products->perPage() + $loop->iteration }}
                    </td>
                    <td class="align-middle">
                        {{ $product->name }}
                    </td>
                    <td class="align-middle text-center">
                        {{ $product->code }}
                    </td>
                    <td class="align-middle text-center">
                        {{ $product->category->name }}
                    </td>
                    <td class="align-middle text-center">
                        {{ $product->quantity }}
                    </td>
                    <td class="align-middle text-center"
                        x-data="{ bgColor: 'transparent' }"
                        x-effect="bgColor = getBgColor({{ $product->quantity }}, {{ $product->quantity_alert }})"
                        :style="'background: ' + bgColor"
                    >
                        {{ $product->quantity_alert }}
                    </td>

                    <script>
                        function getBgColor(quantity, quantity_alert) {
                            if (quantity_alert >= quantity) {
                                return '#f8d7da'; // Rojo
                            } else if (quantity_alert === quantity - 1 || quantity_alert === quantity - 2) {
                                return '#fff70063'; // Amarillo
                            } 
                            return 'transparent';
                        }
                    </script>

                    <td class="align-middle text-center" style="width: 10%">
                        <x-button.show class="btn-icon" route="{{ route('products.show', $product) }}"/>
                        <x-button.edit class="btn-icon" route="{{ route('products.edit', $product) }}"/>
                        <x-button.delete class="btn-icon" route="{{ route('products.destroy', $product) }}"/>
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="align-middle text-center" colspan="7">
                        No se encontraron resultados
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card-footer d-flex align-items-center">
        <p class="m-0 text-secondary">
            Mostrando <span>{{ $products->firstItem() }}</span>
            a <span>{{ $products->lastItem() }}</span> de <span>{{ $products->total() }}</span> entradas
        </p>

        <ul class="pagination m-0 ms-auto">
            {{ $products->links() }}
        </ul>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
