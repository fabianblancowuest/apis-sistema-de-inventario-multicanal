<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Services\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * API REST de productos.
 *
 * Códigos HTTP:
 *  200 OK          -> index, show, update, ajustarStock
 *  201 Created     -> store
 *  204 No Content  -> destroy
 *  404 Not Found   -> el producto no existe (route model binding)
 *  422 Unprocessable Content -> error de validación / regla de negocio
 */
class ProductoController extends Controller
{
    public function __construct(private ProductoService $servicio) {}

    /** GET /api/productos */
    public function index(): JsonResponse
    {
        return response()->json($this->servicio->listar());
    }

    /** POST /api/productos */
    public function store(Request $request): JsonResponse
    {
        $producto = $this->servicio->crear($request->all());

        return response()->json($producto, 201);
    }

    /** GET /api/productos/{producto} */
    public function show(Producto $producto): JsonResponse
    {
        return response()->json($producto);
    }

    /** PUT/PATCH /api/productos/{producto} */
    public function update(Request $request, Producto $producto): JsonResponse
    {
        $producto = $this->servicio->actualizar($producto, $request->all());

        return response()->json($producto);
    }

    /** DELETE /api/productos/{producto} */
    public function destroy(Producto $producto): Response
    {
        $this->servicio->eliminar($producto);

        return response()->noContent();
    }

    /** PATCH /api/productos/{producto}/stock  { "cantidad": -3 } */
    public function ajustarStock(Request $request, Producto $producto): JsonResponse
    {
        $datos = $request->validate([
            'cantidad' => ['required', 'integer', 'not_in:0'],
        ]);

        $producto = $this->servicio->ajustarStock($producto, $datos['cantidad']);

        return response()->json($producto);
    }
}
