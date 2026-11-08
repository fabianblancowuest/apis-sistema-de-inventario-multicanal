<?php

namespace App\Events;

use App\Models\Producto;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se emite por el canal público "inventario" cada vez que un producto
 * se crea, se modifica, cambia su stock o se elimina.
 *
 * ShouldBroadcast => se envía a través de la cola (php artisan queue:work).
 * ShouldDispatchAfterCommit => sólo se publica si la transacción se confirmó.
 */
class ProductoActualizado implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    /** Datos planos (no el modelo) para que el evento sobreviva a un borrado. */
    public array $producto;

    public function __construct(Producto $producto, public string $accion = 'actualizado')
    {
        $this->producto = [
            'id' => $producto->id,
            'nombre' => $producto->nombre,
            'precio' => $producto->precio,
            'stock' => $producto->stock,
        ];
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('inventario'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'producto.actualizado';
    }

    public function broadcastWith(): array
    {
        return [
            'accion' => $this->accion,
            'producto' => $this->producto,
            'momento' => now()->toIso8601String(),
        ];
    }
}
