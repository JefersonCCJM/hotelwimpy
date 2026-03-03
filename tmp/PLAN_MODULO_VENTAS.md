# Plan: Módulo de Ventas - Hotel Wimpy

## Estado Actual
- Existe `reservation_sales` para ventas relacionadas con reservas
- No existe un módulo de ventas independiente
- No hay reportes de ventas por día
- No hay diferenciación por recepcionista/turno

## Estado Final
Módulo completo de ventas que permita:
- Crear ventas normales o asociadas a habitación
- Múltiples productos por venta
- Método de pago (efectivo, transferencia)
- Estado de deuda (pagado/pendiente)
- Asignación de recepcionista y turno (noche/día)
- Reportes de ventas por día
- Descuento automático de stock del inventario

## Archivos a Crear/Modificar

### Migraciones
1. `database/migrations/YYYY_MM_DD_HHMMSS_create_sales_table.php`
   - Campos: id, user_id (recepcionista), room_id (nullable), shift (noche/día), payment_method, debt_status, sale_date, total, notes, timestamps

2. `database/migrations/YYYY_MM_DD_HHMMSS_create_sale_items_table.php`
   - Campos: id, sale_id, product_id, quantity, unit_price, total, timestamps

### Modelos
1. `app/Models/Sale.php`
   - Relaciones: user (recepcionista), room (opcional), items (productos)
   - Scopes: porFecha, porRecepcionista, porTurno, conDeuda

2. `app/Models/SaleItem.php`
   - Relaciones: sale, product

### Controladores
1. `app/Http/Controllers/SaleController.php`
   - Métodos: index, create, store, show, edit, update, destroy
   - Reportes: dailySales, byReceptionist, byShift

### Requests (Validación)
1. `app/Http/Requests/StoreSaleRequest.php`
   - Validar: room_id (nullable), shift, payment_method, items (array de productos)

2. `app/Http/Requests/UpdateSaleRequest.php`
   - Validar: payment_method, debt_status

### Vistas
1. `resources/views/sales/index.blade.php` - Listado de ventas con filtros
2. `resources/views/sales/create.blade.php` - Formulario de creación
3. `resources/views/sales/edit.blade.php` - Editar venta (cambiar estado deuda)
4. `resources/views/sales/show.blade.php` - Detalle de venta
5. `resources/views/sales/reports.blade.php` - Reportes por día

### Rutas
- Agregar rutas en `routes/web.php` para el módulo de ventas

### Seeders (Opcional)
- Crear datos de prueba si es necesario

## Lista de Tareas

1. ✅ Crear migración `create_sales_table`
2. ✅ Crear migración `create_sale_items_table`
3. ✅ Crear modelo `Sale` con relaciones y scopes
4. ✅ Crear modelo `SaleItem` con relaciones
5. ✅ Crear `StoreSaleRequest` con validaciones
6. ✅ Crear `UpdateSaleRequest` con validaciones
7. ✅ Crear `SaleController` con métodos CRUD y reportes
8. ✅ Crear vista `sales/index.blade.php` con filtros
9. ✅ Crear vista `sales/create.blade.php` con selección de productos múltiples
10. ✅ Crear vista `sales/edit.blade.php` para cambiar estado deuda
11. ✅ Crear vista `sales/show.blade.php` para detalle
12. ✅ Crear vista `sales/reports.blade.php` para reportes diarios
13. ✅ Agregar rutas en `web.php`
14. ✅ Agregar permisos en `RoleSeeder`
15. ✅ Agregar menú en sidebar (`layouts/app.blade.php`)

## Consideraciones Técnicas

- El stock se descuenta automáticamente al crear la venta
- Las ventas asociadas a habitación deben validar que la habitación tenga reserva activa
- El turno se determina automáticamente por la hora (antes de 14:00 = día, después = noche)
- El recepcionista se toma del usuario autenticado
- El estado de deuda se calcula: si payment_method = 'pendiente' o is_paid = false

