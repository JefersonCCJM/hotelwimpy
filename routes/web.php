<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\ElectronicInvoiceController;
use App\Http\Controllers\CompanyTaxSettingController;
use App\Http\Controllers\SaleController;
use App\Http\Middleware\VerifyCsrfToken;

/* |-------------------------------------------------------------------------- | Web Routes |-------------------------------------------------------------------------- | | Here is where you can register web routes for your application. These | routes are loaded by the RouteServiceProvider and all of them will | be assigned to the "web" middleware group. Make something great! | */

// Rutas de autenticación
Route::get('/', function () {
    return redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class , 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class , 'login'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class , 'index'])->name('dashboard');

    // Dashboard Recepcionistas
    Route::middleware('permission:manage_shift_handovers|view_shift_handovers')->group(function () {
            // Español (actual)
            Route::get('/dashboard/recepcion/dia', [\App\Http\Controllers\ReceptionistDashboardController::class , 'day'])->name('dashboard.receptionist.day');
            Route::get('/dashboard/recepcion/noche', [\App\Http\Controllers\ReceptionistDashboardController::class , 'night'])->name('dashboard.receptionist.night');

            // Inglés (checklist)
            Route::get('/dashboard/receptionist-day', [\App\Http\Controllers\ReceptionistDashboardController::class , 'day'])->name('dashboard.receptionist.day.en');
            Route::get('/dashboard/receptionist-night', [\App\Http\Controllers\ReceptionistDashboardController::class , 'night'])->name('dashboard.receptionist.night.en');
        }
        );

        // Gestión de Turnos (Shifts)
        // - Ver (listado / detalle / pantalla de recibir)
        Route::middleware('permission:view_shift_handovers|manage_shift_handovers')->group(function () {
            Route::get('/shift-handovers', [\App\Http\Controllers\ReceptionistDashboardController::class , 'index'])->name('shift-handovers.index');
            Route::get('/shift-handovers/receive', [\App\Http\Controllers\ReceptionistDashboardController::class , 'receiveShift'])->name('shift-handovers.receive');
            Route::get('/shift-handovers/{id}/pdf', [\App\Http\Controllers\ReceptionistDashboardController::class , 'downloadHandoverPdf'])->whereNumber('id')->name('shift-handovers.pdf');
            Route::get('/shift-handovers/{id}', [\App\Http\Controllers\ReceptionistDashboardController::class , 'show'])->whereNumber('id')->name('shift-handovers.show');
        }
        );

        // - Acciones (iniciar / entregar / crear acta / recibir)
        Route::middleware('permission:manage_shift_handovers')->group(function () {
            Route::post('/shifts/start', [\App\Http\Controllers\ReceptionistDashboardController::class , 'startShift'])->name('shift.start');
            Route::post('/shifts/end', [\App\Http\Controllers\ReceptionistDashboardController::class , 'endShift'])->name('shift.end');

            Route::get('/shift-handovers/deliver', [\App\Http\Controllers\ReceptionistDashboardController::class , 'deliverShift'])->name('shift-handovers.deliver');
            Route::get('/shift-handovers/create', [\App\Http\Controllers\ReceptionistDashboardController::class , 'createHandover'])->name('shift-handovers.create');
            Route::post('/shift-handovers', [\App\Http\Controllers\ReceptionistDashboardController::class , 'storeHandover'])->name('shift-handovers.store');
            Route::post('/shift-handovers/receive', [\App\Http\Controllers\ReceptionistDashboardController::class , 'storeReception'])->name('shift-handovers.store-reception');

            // Solo administradores: forzar cierre de turnos operativos atascados
            Route::post('/shifts/force-close', [\App\Http\Controllers\ReceptionistDashboardController::class , 'forceCloseOperational'])->middleware('role:Administrador')->name('shift.force-close');
            Route::post('/shifts/operations/toggle', [\App\Http\Controllers\ReceptionistDashboardController::class , 'toggleShiftOperations'])->middleware('role:Administrador')->name('shift.operations.toggle');
        }
        );

        // Salidas de Efectivo de Turno (Shift Cash Outs)
        // - Ver
        Route::middleware('permission:view_shift_cash_outs|create_shift_cash_outs')->group(function () {
            Route::get('/shift-cash-outs', [\App\Http\Controllers\ReceptionistDashboardController::class , 'cashOutsIndex'])->name('shift-cash-outs.index');
        }
        );

        // - Acciones
        Route::middleware('permission:create_shift_cash_outs')->group(function () {
            Route::get('/shift-cash-outs/create', [\App\Http\Controllers\ReceptionistDashboardController::class , 'createCashOut'])->name('shift-cash-outs.create');
            Route::post('/shift-cash-outs', [\App\Http\Controllers\ReceptionistDashboardController::class , 'storeCashOut'])->name('shift-cash-outs.store');
            Route::delete('/shift-cash-outs/{id}', [\App\Http\Controllers\ReceptionistDashboardController::class , 'destroyCashOut'])->name('shift-cash-outs.destroy');
        }
        );

        // Salidas de Productos (No Ventas)
        Route::middleware('permission:create_shift_cash_outs')->group(function () {
            Route::get('/shift-product-outs', [\App\Http\Controllers\ShiftProductOutController::class , 'index'])->name('shift-product-outs.index');
            Route::get('/shift-product-outs/create', [\App\Http\Controllers\ShiftProductOutController::class , 'create'])->name('shift-product-outs.create');
            Route::post('/shift-product-outs', [\App\Http\Controllers\ShiftProductOutController::class , 'store'])->name('shift-product-outs.store');
            Route::delete('/shift-product-outs/{id}', [\App\Http\Controllers\ShiftProductOutController::class , 'destroy'])->name('shift-product-outs.destroy');
        }
        );

        // Logout
        Route::post('/logout', [AuthController::class , 'logout'])->name('logout');

        // Habitaciones
        Route::resource('rooms', \App\Http\Controllers\RoomController::class);
        Route::get('/api/rooms/{room}/detail', [\App\Http\Controllers\RoomController::class , 'getDetail'])->name('api.rooms.detail');
        Route::post('/rooms/{room}/add-sale', [\App\Http\Controllers\RoomController::class , 'addSale'])->name('rooms.add-sale');
        Route::post('/sales/{sale}/pay', [\App\Http\Controllers\RoomController::class , 'paySale'])->name('sales.pay');
        Route::post('/reservations/{reservation}/pay-night', [\App\Http\Controllers\RoomController::class , 'payNight'])->name('reservations.pay-night');
        Route::put('/reservations/{reservation}/update-deposit', [\App\Http\Controllers\RoomController::class , 'updateDeposit'])->name('reservations.update-deposit');
        Route::patch('/rooms/{room}/status', [\App\Http\Controllers\RoomController::class , 'updateStatus'])->name('rooms.update-status');
        Route::post('/rooms/{room}/release', [\App\Http\Controllers\RoomController::class , 'release'])->name('rooms.release');
        Route::post('/rooms/{room}/continue', [\App\Http\Controllers\RoomController::class , 'continueStay'])->name('rooms.continue');
        Route::post('/rooms/{room}/rates', [\App\Http\Controllers\RoomController::class , 'storeRate'])->name('rooms.rates.store');
        Route::delete('/rooms/{room}/rates/{rate}', [\App\Http\Controllers\RoomController::class , 'destroyRate'])->name('rooms.rates.destroy');

        // Productos (Inventario) - Resource route with rate limiting
        Route::get('/api/products/search', [\App\Http\Controllers\ProductController::class , 'search'])->name('api.products.search');
        Route::get('/products/history', \App\Livewire\InventoryHistory::class)->name('products.history');
        Route::resource('products', ProductController::class)->middleware('throttle:60,1');

        // Ventas - Rutas específicas primero para evitar conflictos con parámetros
        // Arquitectura híbrida: Livewire maneja UI, Controlador maneja lógica de negocio
        Route::middleware('permission:create_sales')->group(function () {
            Route::get('/sales/create', [SaleController::class , 'create'])->name('sales.create');
            Route::post('/sales', [SaleController::class , 'store'])->name('sales.store');
        }
        );

        Route::middleware('permission:view_sales')->group(function () {
            Route::get('/sales', [SaleController::class , 'index'])->name('sales.index');
            Route::get('/sales/by-room', [SaleController::class , 'byRoom'])->name('sales.byRoom');
            Route::get('/sales/reports', [SaleController::class , 'dailyReport'])->name('sales.reports');
        }
        );

        Route::middleware('permission:edit_sales')->group(function () {
            Route::get('/sales/{sale}/edit', [SaleController::class , 'edit'])->name('sales.edit');
            Route::put('/sales/{sale}', [SaleController::class , 'update'])->name('sales.update');
        }
        );

        Route::middleware('permission:view_sales')->group(function () {
            Route::get('/sales/{sale}', [SaleController::class , 'show'])->name('sales.show');
        }
        );

        Route::middleware('permission:delete_sales')->group(function () {
            Route::delete('/sales/{sale}', [SaleController::class , 'destroy'])->name('sales.destroy');
        }
        );

        // Clientes - con middleware de permisos
        Route::middleware('permission:view_customers')->group(function () {
            Route::get('/customers', [CustomerController::class , 'index'])->name('customers.index');
            Route::get('/api/customers/search', [CustomerController::class , 'search'])->name('api.customers.search');
            Route::get('/api/customers/check-identification', [CustomerController::class , 'checkIdentification'])->name('api.customers.check-identification');
        }
        );

        Route::middleware('permission:create_customers')->group(function () {
            Route::get('/customers/create', [CustomerController::class , 'create'])->name('customers.create');
            Route::post('/customers', [CustomerController::class , 'store'])->name('customers.store');
        }
        );

        Route::middleware('permission:edit_customers')->group(function () {
            Route::get('/customers/{customer}/edit', [CustomerController::class , 'edit'])->name('customers.edit');
            Route::put('/customers/{customer}', [CustomerController::class , 'update'])->name('customers.update');
            Route::patch('/customers/{customer}/toggle-status', [CustomerController::class , 'toggleStatus'])->name('customers.toggle-status');
        }
        );

        // Show debe ir al final para evitar conflictos con create y edit
        Route::middleware('permission:view_customers')->group(function () {
            Route::get('/customers/{customer}', [CustomerController::class , 'show'])->name('customers.show');
        }
        );

        // Reservas
        Route::middleware('permission:view_reservations')->group(function () {
            Route::get('/reservations', [ReservationController::class , 'index'])->name('reservations.index');
            Route::get('/reservations/{reservation}/download', [ReservationController::class , 'download'])->name('reservations.download');
            Route::get('/reservations/{reservation}/guest-document', [ReservationController::class , 'viewGuestsDocument'])->name('reservations.guest-document.view');
            Route::get('/reservations/{reservation}/guest-document/download', [ReservationController::class , 'downloadGuestsDocument'])->name('reservations.guest-document.download');
            Route::get('/reservations/export/monthly', [ReservationController::class , 'exportMonthlyReport'])->name('reservations.export.monthly');
            Route::get('/api/check-room-availability', [ReservationController::class , 'checkAvailability'])->name('api.check-availability');
            Route::get('/reservations/{reservation}/release-data', [ReservationController::class , 'getReleaseData'])->name('reservations.release-data');
        }
        );

        Route::middleware('permission:create_reservations')->group(function () {
            Route::get('/reservations/create', [ReservationController::class , 'create'])->name('reservations.create');
            Route::post('/reservations', [ReservationController::class , 'store'])->name('reservations.store');
        }
        );

        Route::middleware('permission:edit_reservations')->group(function () {
            Route::get('/reservations/{reservation}/edit', [ReservationController::class , 'edit'])->name('reservations.edit');
            Route::put('/reservations/{reservation}', [ReservationController::class , 'update'])->name('reservations.update');
            Route::post('/reservations/{reservation}/check-in', [ReservationController::class , 'checkIn'])->name('reservations.check-in');
            Route::post('/reservations/{reservation}/payments', [ReservationController::class , 'registerPayment'])->name('reservations.register-payment');
            Route::post('/reservations/{reservation}/payments/{payment}/cancel', [ReservationController::class , 'cancelPayment'])->name('reservations.cancel-payment')->withTrashed();
        }
        );

        Route::middleware('permission:delete_reservations')->group(function () {
            Route::delete('/reservations/{reservation}', [ReservationController::class , 'destroy'])->name('reservations.destroy');
        }
        );

        // Categorías - con middleware de permisos para administradores
        Route::middleware('permission:view_categories')->group(function () {
            Route::get('/categories', [CategoryController::class , 'index'])->name('categories.index');
        }
        );

        Route::middleware('permission:create_categories')->group(function () {
            Route::get('/categories/create', [CategoryController::class , 'create'])->name('categories.create');
            Route::post('/categories', [CategoryController::class , 'store'])->name('categories.store');
        }
        );

        Route::middleware('permission:edit_categories')->group(function () {
            Route::get('/categories/{category}/edit', [CategoryController::class , 'edit'])->name('categories.edit');
            Route::put('/categories/{category}', [CategoryController::class , 'update'])->name('categories.update');
        }
        );

        Route::middleware('permission:delete_categories')->group(function () {
            Route::delete('/categories/{category}', [CategoryController::class , 'destroy'])->name('categories.destroy');
        }
        );

        // Show debe ir al final para evitar conflictos con create y edit
        Route::middleware('permission:view_categories')->group(function () {
            Route::get('/categories/{category}', [CategoryController::class , 'show'])->name('categories.show');
            // API route to get products by subcategory - using where to allow numeric IDs
            Route::get('/categories/{category}/subcategories/{subcategory}/products', [CategoryController::class , 'getProductsBySubcategory'])
                ->where('subcategory', '[0-9]+')
                ->name('categories.subcategories.products');
        }
        );

        // Admin routes (if needed for compatibility)
        Route::prefix('admin')->middleware('permission:view_categories')->group(function () {
            Route::get('/categories/{category}', [CategoryController::class , 'show'])->name('admin.categories.show');
            Route::get('/categories/{category}/subcategories/{subcategory}/products', [CategoryController::class , 'getProductsBySubcategory'])
                ->where('subcategory', '[0-9]+')
                ->name('admin.categories.subcategories.products');
        }
        );

        // Reportes
        Route::middleware('permission:view_reports')->group(function () {
            Route::get('reports', [ReportController::class , 'index'])->name('reports.index');
            Route::get('reports/filter-data', [ReportController::class , 'getFilterData'])->name('reports.filter-data');
            Route::post('reports/pdf', [ReportController::class , 'generatePDF'])->name('reports.pdf');
            Route::get('reports/single-pdf/{type}/{id}', [ReportController::class , 'generateSinglePDF'])->name('reports.single.pdf');
        }
        );

        // Salidas de Dinero
        Route::get('/cash-outflows', \App\Livewire\CashOutflowManager::class)->name('cash-outflows.index');

        // Servicios
        Route::middleware('permission:view_services')->group(function () {
            Route::get('/services', [\App\Http\Controllers\ServiceController::class , 'index'])->name('services.index');
            Route::get('/services/{service}', [\App\Http\Controllers\ServiceController::class , 'show'])->name('services.show');
        }
        );

        Route::middleware('permission:create_services')->group(function () {
            Route::get('/services/create', [\App\Http\Controllers\ServiceController::class , 'create'])->name('services.create');
            Route::post('/services', [\App\Http\Controllers\ServiceController::class , 'store'])->name('services.store');
        }
        );

        Route::middleware('permission:edit_services')->group(function () {
            Route::get('/services/{service}/edit', [\App\Http\Controllers\ServiceController::class , 'edit'])->name('services.edit');
            Route::put('/services/{service}', [\App\Http\Controllers\ServiceController::class , 'update'])->name('services.update');
        }
        );

        Route::middleware('permission:delete_services')->group(function () {
            Route::delete('/services/{service}', [\App\Http\Controllers\ServiceController::class , 'destroy'])->name('services.destroy');
        }
        );

        // Facturas electrónicas
        Route::middleware('permission:generate_invoices')->group(function () {
            Route::get('/electronic-invoices', function () {
                    return view('electronic-invoices.index-livewire');
                }
                )->name('electronic-invoices.index');

                Route::get('/electronic-invoices/create', [\App\Http\Controllers\ElectronicInvoiceController::class , 'create'])
                    ->name('electronic-invoices.create');
                Route::post('/electronic-invoices', [\App\Http\Controllers\ElectronicInvoiceController::class , 'store'])
                    ->name('electronic-invoices.store');
                Route::get('/electronic-invoices/{electronicInvoice}', [\App\Http\Controllers\ElectronicInvoiceController::class , 'show'])
                    ->name('electronic-invoices.show');
                Route::get('/electronic-invoices/{electronicInvoice}/credit-notes/create', [\App\Http\Controllers\ElectronicCreditNoteController::class, 'create'])
                    ->name('electronic-credit-notes.create');
                Route::post('/electronic-invoices/{electronicInvoice}/credit-notes', [\App\Http\Controllers\ElectronicCreditNoteController::class, 'store'])
                    ->name('electronic-credit-notes.store');
                Route::get('/electronic-credit-notes/{electronicCreditNote}', [\App\Http\Controllers\ElectronicCreditNoteController::class, 'show'])
                    ->name('electronic-credit-notes.show');
                Route::post('/electronic-credit-notes/{electronicCreditNote}/verify', [\App\Http\Controllers\ElectronicCreditNoteController::class, 'verify'])
                    ->name('electronic-credit-notes.verify');
                Route::post('/electronic-credit-notes/{electronicCreditNote}/cleanup', [\App\Http\Controllers\ElectronicCreditNoteController::class, 'cleanup'])
                    ->name('electronic-credit-notes.cleanup');
                Route::get('/electronic-credit-notes/{electronicCreditNote}/download-pdf', [\App\Http\Controllers\ElectronicCreditNoteController::class, 'downloadPdf'])
                    ->name('electronic-credit-notes.download-pdf');
                Route::post('/electronic-invoices/{electronicInvoice}/refresh-status', [\App\Http\Controllers\ElectronicInvoiceController::class , 'refreshStatus'])
                    ->name('electronic-invoices.refresh-status');
                Route::get('/electronic-invoices/{electronicInvoice}/download-pdf', [\App\Http\Controllers\ElectronicInvoiceController::class , 'downloadPdf'])
                    ->name('electronic-invoices.download-pdf');
                Route::delete('/electronic-invoices/{electronicInvoice}', [\App\Http\Controllers\ElectronicInvoiceController::class , 'destroy'])
                    ->name('electronic-invoices.destroy');
            }
            );

            // Configuración Fiscal de la Empresa
            Route::middleware('permission:manage_roles')->group(function () {
            Route::get('/company-tax-settings/edit', [CompanyTaxSettingController::class , 'edit'])->name('company-tax-settings.edit');
            Route::put('/company-tax-settings', [CompanyTaxSettingController::class , 'update'])->name('company-tax-settings.update');
        }
        );

        // Gestión de Roles y Usuarios (Solo Administrador)
        Route::middleware(['role:Administrador'])->group(function () {
            Route::get('/roles', [App\Http\Controllers\RoleController::class , 'index'])->name('roles.index');
            Route::post('/usuarios/{user}/rol', [App\Http\Controllers\UserRoleController::class , 'update'])->name('usuarios.rol.update');

            // Hardening de Seguridad Avanzado
            Route::get('/admin/security/permissions', [App\Http\Controllers\SecurityController::class , 'permissionsMatrix'])->name('admin.security.permissions');
            Route::post('/admin/security/permissions', [App\Http\Controllers\SecurityController::class , 'updatePermissions'])->name('admin.security.permissions.update');
            Route::get('/admin/security/audit', \App\Livewire\AuditLogManager::class)->name('admin.security.audit');

            // Impersonación
            Route::post('/admin/security/impersonate/stop', [App\Http\Controllers\SecurityController::class , 'stopImpersonation'])
                ->name('admin.security.impersonate.stop')
                ->withoutMiddleware(['role:Administrador']); // Permitir que el usuario impersonado pueda volver
    
            Route::post('/admin/security/impersonate/{user}', [App\Http\Controllers\SecurityController::class , 'startImpersonation'])
                ->name('admin.security.impersonate.start')
                ->where('user', '[0-9]+');

            // Verificación de PIN
            Route::post('/api/admin/security/verify-pin', [App\Http\Controllers\SecurityController::class , 'verifyPin'])->name('api.admin.security.verify-pin');
        }
        );

        // Tax profile API endpoints (used from Blade views with JavaScript)
        Route::middleware('permission:edit_customers')->group(function () {
            Route::get('/api/customers/{customer}/tax-profile', [CustomerController::class , 'getTaxProfile'])->name('api.customers.tax-profile.get');
            Route::post('/api/customers/{customer}/tax-profile', [CustomerController::class , 'saveTaxProfile'])->name('api.customers.tax-profile.save');
        }
        );    });

/* |-------------------------------------------------------------------------- | PUBLIC CLEANING PANEL - NO AUTHENTICATION REQUIRED |-------------------------------------------------------------------------- | | Livewire component for cleaning staff to view and manage room status. | Designed for tablets/screens in cleaning area. | Uses Livewire for real-time updates and state management. | */
Route::prefix('panel-aseo')->middleware('throttle:cleaning-panel')->group(function () {
    Route::get('/rooms/status', \App\Livewire\CleaningPanel::class)
        ->name('public.rooms.status');
});

/* |-------------------------------------------------------------------------- | TEMPORARY DEPLOYMENT ROUTES - REMOVE AFTER DEPLOYMENT |-------------------------------------------------------------------------- | | ⚠️ WARNING: These routes are for deployment purposes only. | Remove them immediately after completing the deployment. | | Usage: | - /__deploy__?token=YOUR_TOKEN - Deployment dashboard | - /__infra__/migrate?token=YOUR_TOKEN - Run migrations | - /__infra__/seed?token=YOUR_TOKEN - Run seeders | - /__infra__/status?token=YOUR_TOKEN - Check status | | IMPORTANT: Change DEPLOYMENT_TOKEN in DeploymentController.php | before using these routes in production. | | NOTE: These routes use withoutMiddleware(VerifyCsrfToken::class) to | avoid CSRF token issues. They are protected by deployment token instead. | */
Route::prefix('__deploy__')->withoutMiddleware([VerifyCsrfToken::class])->group(function () {
    Route::get('/', [DeploymentController::class , 'index'])->name('deployment.index');
});

Route::prefix('__infra__')->withoutMiddleware([VerifyCsrfToken::class])->group(function () {
    Route::post('/migrate', [DeploymentController::class , 'migrate'])->name('deployment.migrate');
    Route::get('/migrate', [DeploymentController::class , 'migrate'])->name('deployment.migrate.get');
    Route::post('/seed', [DeploymentController::class , 'seed'])->name('deployment.seed');
    Route::get('/seed', [DeploymentController::class , 'seed'])->name('deployment.seed.get');
    Route::post('/sync-municipalities', [DeploymentController::class , 'syncMunicipalities'])->name('deployment.sync-municipalities');
    Route::get('/sync-municipalities', [DeploymentController::class , 'syncMunicipalities'])->name('deployment.sync-municipalities.get');
    Route::post('/sync-numbering-ranges', [DeploymentController::class , 'syncNumberingRanges'])->name('deployment.sync-numbering-ranges');
    Route::get('/sync-numbering-ranges', [DeploymentController::class , 'syncNumberingRanges'])->name('deployment.sync-numbering-ranges.get');
    Route::post('/sync-measurement-units', [DeploymentController::class , 'syncMeasurementUnits'])->name('deployment.sync-measurement-units');
    Route::get('/sync-measurement-units', [DeploymentController::class , 'syncMeasurementUnits'])->name('deployment.sync-measurement-units.get');
    Route::get('/status', [DeploymentController::class , 'status'])->name('deployment.status');
});
