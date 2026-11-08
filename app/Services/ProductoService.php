<?php

namespace App\Services;

use App\Events\ProductoActualizado;
use App\Models\Producto;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Capa de servicio: centraliza las reglas del inventario para que
 * REST, GraphQL y la API interna (usada por gRPC) se comporten igual.
 */
class ProductoService
{
    /**
     * Reglas de validación compartidas.
     * $parcial = true se usa en actualizaciones (PUT/PATCH): cada campo es opcional.
     */
    public static function reglas(bool $parcial = false): array
    {
        $requerido = $parcial ? 'sometimes' : 'required';

        return [
            'nombre' => [$requerido, 'string', 'max:100'],
            'precio' => [$requerido, 'numeric', 'min:0'],
            'stock' => [$requerido, 'integer', 'min:0'],
        ];
    }

    public function listar(): Collection
    {
        return Producto::orderBy('id')->get();
    }

    public function obtener(int $id): Producto
    {
        return Producto::findOrFail($id);
    }

    /**
     * @throws ValidationException
     */
    public function crear(array $datos): Producto
    {
        $validados = Validator::make($datos, self::reglas())->validate();

        $producto = DB::transaction(fn () => Producto::create($validados));

        ProductoActualizado::dispatch($producto, 'creado');

        return $producto;
    }

    /**
     * @throws ValidationException
     */
    public function actualizar(Producto $producto, array $datos): Producto
    {
        $validados = Validator::make($datos, self::reglas(parcial: true))->validate();

        DB::transaction(fn () => $producto->update($validados));

        ProductoActualizado::dispatch($producto->refresh(), 'actualizado');

        return $producto;
    }

    /**
     * Suma o resta unidades al stock. Regla de negocio: el stock nunca queda negativo.
     *
     * @throws ValidationException
     */
    public function ajustarStock(Producto $producto, int $cantidad): Producto
    {
        DB::transaction(function () use ($producto, $cantidad) {
            $actual = Producto::whereKey($producto->id)->lockForUpdate()->value('stock');

            if ($actual + $cantidad < 0) {
                throw ValidationException::withMessages([
                    'cantidad' => "Stock insuficiente: hay {$actual} unidades y se intentó descontar ".abs($cantidad).'.',
                ]);
            }

            Producto::whereKey($producto->id)->increment('stock', $cantidad);
        });

        ProductoActualizado::dispatch($producto->refresh(), 'stock');

        return $producto;
    }

    public function eliminar(Producto $producto): void
    {
        $producto->delete();

        // El evento guarda una copia de los datos, por eso funciona aunque la fila ya no exista.
        ProductoActualizado::dispatch($producto, 'eliminado');
    }
}
