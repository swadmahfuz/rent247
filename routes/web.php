<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingPeriodController;
use App\Http\Controllers\ChargeTypeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoricalImportController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LeaseController;
use App\Http\Controllers\MeterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TenantPortalController;
use App\Http\Controllers\UnitController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/portal/{token}', [TenantPortalController::class, 'show'])->name('portal.show');
Route::get('/portal/{token}/invoices/{invoice}/pdf', [TenantPortalController::class, 'pdf'])->name('portal.invoice.pdf');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::post('/properties', [PropertyController::class, 'store'])->name('properties.store');
    Route::put('/properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
    Route::post('/properties/{property}/switch', [PropertyController::class, 'switch'])->name('properties.switch');
    Route::post('/properties/{property}/signature', [PropertyController::class, 'uploadSignature'])->name('properties.signature');
    Route::delete('/properties/{property}/signature', [PropertyController::class, 'clearSignature'])->name('properties.signature.destroy');

    Route::resource('units', UnitController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('tenants', TenantController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/tenants/{tenant}/portal/enable', [TenantController::class, 'enablePortal'])->name('tenants.portal.enable');
    Route::post('/tenants/{tenant}/portal/rotate', [TenantController::class, 'rotatePortal'])->name('tenants.portal.rotate');
    Route::post('/tenants/{tenant}/portal/disable', [TenantController::class, 'disablePortal'])->name('tenants.portal.disable');
    Route::resource('leases', LeaseController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('meters', MeterController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('charges', ChargeTypeController::class)->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['charges' => 'charge']);

    Route::get('/billing', [BillingPeriodController::class, 'index'])->name('billing.index');
    Route::post('/billing', [BillingPeriodController::class, 'store'])->name('billing.store');
    Route::get('/billing/{billing}', [BillingPeriodController::class, 'show'])->name('billing.show');
    Route::put('/billing/{billing}/inputs', [BillingPeriodController::class, 'updateInputs'])->name('billing.inputs');
    Route::post('/billing/{billing}/copy-prior', [BillingPeriodController::class, 'copyPrior'])->name('billing.copy-prior');
    Route::post('/billing/{billing}/generate', [BillingPeriodController::class, 'generate'])->name('billing.generate');
    Route::post('/billing/{billing}/finalize', [BillingPeriodController::class, 'finalize'])->name('billing.finalize');
    Route::get('/billing/{billing}/summary-pdf', [AnalyticsController::class, 'summaryPdf'])->name('billing.summary-pdf');
    Route::get('/billing/{billing}/invoices-zip', [BillingPeriodController::class, 'invoicesZip'])->name('billing.invoices-zip');
    Route::post('/billing/{billing}/documents', [BillingPeriodController::class, 'storeDocument'])->name('billing.documents.store');
    Route::post('/billing/{billing}/unit-electricity-documents', [BillingPeriodController::class, 'storeUnitElectricityDocuments'])->name('billing.documents.unit-electricity.store');
    Route::delete('/billing/{billing}/documents/{document}', [BillingPeriodController::class, 'destroyDocument'])->name('billing.documents.destroy');

    Route::get('/import', [HistoricalImportController::class, 'index'])->name('import.index');
    Route::get('/import/template', [HistoricalImportController::class, 'template'])->name('import.template');
    Route::post('/import', [HistoricalImportController::class, 'store'])->name('import.store');

    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::put('/invoices/{invoice}/lines', [InvoiceController::class, 'updateLines'])->name('invoices.lines');
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::post('/invoices/{invoice}/email', [InvoiceController::class, 'email'])->name('invoices.email');

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('/payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    Route::get('/analytics', AnalyticsController::class)->name('analytics');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
