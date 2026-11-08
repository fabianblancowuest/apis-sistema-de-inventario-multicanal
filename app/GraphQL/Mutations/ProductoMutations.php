<?php

namespace App\GraphQL\Mutations;

use App\Models\Producto;
use App\Services\ProductoService;

/**
 * Resolvers de las mutaciones GraphQL. No validan nada por su cuenta:
 * delegan en ProductoService, igual que el controlador REST.
 * Si la validación falla, Lighthouse devuelve el error en extensions.validation.
 */
class ProductoMutations
{
    public function __construct(private ProductoService $servicio) {}

    public function crear(mixed $root, array $args): Producto
    {
        return $this->servicio->crear($args['input']);
    }

    public function ajustarStock(mixed $root, array $args): Producto
    {
        $producto = $this->servicio->obtener((int) $args['id']);

        return $this->servicio->ajustarStock($producto, (int) $args['cantidad']);
    }
}
