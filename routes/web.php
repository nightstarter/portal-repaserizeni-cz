<?php

use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BuybackController;
use App\Http\Controllers\BuybackInvoiceController;
use App\Http\Controllers\CoreReturnActionController;
use App\Http\Controllers\CustomRebuildActionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiscussionController;
use App\Http\Controllers\DiscussionQueueController;
use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MessageAttachmentDownloadController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\QrItemController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\ShipmentActionController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\ShipmentItemController;
use App\Http\Controllers\StockRebuildActionController;
use App\Http\Controllers\SupplierCreditNoteController;
use App\Http\Controllers\SupplierInvoiceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarrantyController;
use App\Http\Controllers\WarrantyQueueController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware(['auth', 'active', 'password.changed'])->group(function (): void {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/returns', ReturnController::class)->name('returns.index');
    Route::get('/finance', FinanceController::class)->name('finance.index');
    Route::get('/discussions', DiscussionQueueController::class)->name('discussions.index');
    Route::get('/warranties', WarrantyQueueController::class)->name('warranties.index');
    Route::post('/buybacks', [BuybackController::class, 'store'])->name('buybacks.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/orders/{order}/discussion', [DiscussionController::class, 'index'])->name('orders.discussion.index');
    Route::post('/orders/{order}/discussion', [DiscussionController::class, 'store'])->name('orders.discussion.store');
    Route::post('/orders/{order}/discussion/read', [DiscussionController::class, 'markRead'])->name('orders.discussion.read');
    Route::get('/message-attachments/{attachment}/download', MessageAttachmentDownloadController::class)->name('message-attachments.download');
    Route::get('/q/{randomToken}', QrItemController::class)->name('items.qr');
    Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
    Route::get('/shipments/create', [ShipmentController::class, 'create'])->name('shipments.create');
    Route::post('/shipments', [ShipmentController::class, 'store'])->name('shipments.store');
    Route::get('/shipments/{shipment}', [ShipmentController::class, 'show'])->name('shipments.show');
    Route::post('/shipments/{shipment}/items', [ShipmentItemController::class, 'store'])->name('shipments.items.store');
    Route::delete('/shipments/{shipment}/items/{item}', [ShipmentItemController::class, 'destroy'])->name('shipments.items.destroy');
    Route::post('/shipments/{shipment}/actions/{action}', ShipmentActionController::class)->name('shipments.actions');
    Route::post('/orders/{order}/custom-rebuild/actions/{action}', CustomRebuildActionController::class)
        ->name('orders.custom-rebuild.actions');
    Route::post('/orders/{order}/stock-rebuild/{threadType}/actions/{action}', StockRebuildActionController::class)
        ->name('orders.stock-rebuild.actions');
    Route::post('/orders/{order}/core-return/actions/{action}', CoreReturnActionController::class)->name('orders.core-return.actions');
    Route::post('/orders/{order}/items/{item}/warranty', [WarrantyController::class, 'store'])->name('orders.warranty.store');
    Route::post('/orders/{order}/warranty/{thread}/actions/{action}', [WarrantyController::class, 'action'])->name('orders.warranty.actions');
    Route::post('/orders/{order}/buyback/{thread}/actions/{action}', [BuybackController::class, 'action'])->name('orders.buyback.actions');
    Route::post('/orders/{order}/buyback/{thread}/invoice', [BuybackInvoiceController::class, 'store'])->name('orders.buyback.invoice.store');
    Route::post('/orders/{order}/buyback/{thread}/invoice/{document}/replace', [BuybackInvoiceController::class, 'replace'])->name('orders.buyback.invoice.replace');
    Route::post('/orders/{order}/threads/{thread}/supplier-invoice', [SupplierInvoiceController::class, 'store'])->name('documents.invoice.store');
    Route::post('/orders/{order}/threads/{thread}/supplier-invoice/{document}/replace', [SupplierInvoiceController::class, 'replace'])->name('documents.invoice.replace');
    Route::post('/orders/{order}/threads/{thread}/supplier-credit-note', [SupplierCreditNoteController::class, 'store'])->name('documents.credit.store');
    Route::post('/orders/{order}/threads/{thread}/supplier-credit-note/{document}/replace', [SupplierCreditNoteController::class, 'replace'])->name('documents.credit.replace');
    Route::get('/documents/{document}/download', DocumentDownloadController::class)->name('documents.download');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::put('/locale', [LocaleController::class, 'update'])->name('locale.update');
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password', [PasswordController::class, 'update'])->name('password.update');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::get('/admin/notifications', [AdminNotificationController::class, 'index'])->name('admin.notifications.index');
    Route::post('/admin/notifications/{event}/retry', [AdminNotificationController::class, 'retry'])->name('admin.notifications.retry');
});
