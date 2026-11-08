<?php

use App\Http\Controllers\Api\ProductoController;
use App\Http\Middleware\VerificarTokenInterno;
use App\Services\ProductoService;
use Illuminate\Support\Facades\Route;

// ---- REST público ----
Route::apiResource('productos', ProductoController::class);
Route::patch('productos/{producto}/stock', [ProductoController::class, 'ajustarStock'])
    ->name('productos.stock');

// ---- API interna (sólo para el microservicio gRPC) ----
Route::prefix('interno')->middleware(VerificarTokenInterno::class)->group(function () {
    Route::get('productos/{id}', fn (int $id, ProductoService $servicio) => $servicio->obtener($id))
        ->whereNumber('id')
        ->name('interno.productos.show');
});
