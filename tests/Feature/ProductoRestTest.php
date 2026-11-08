<?php

namespace Tests\Feature;

use App\Events\ProductoActualizado;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProductoRestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ProductoActualizado::class]);
    }

    public function test_index_lista_productos(): void
    {
        Producto::factory()->count(3)->create();

        $this->getJson('/api/productos')->assertOk()->assertJsonCount(3);
    }

    public function test_store_crea_producto_y_emite_evento(): void
    {
        $this->postJson('/api/productos', ['nombre' => 'Mouse Gamer', 'precio' => 18500, 'stock' => 20])
            ->assertCreated()
            ->assertJsonPath('nombre', 'Mouse Gamer');

        $this->assertDatabaseHas('productos', ['nombre' => 'Mouse Gamer']);
        Event::assertDispatched(ProductoActualizado::class, fn ($e) => $e->accion === 'creado');
    }

    public function test_store_valida_datos(): void
    {
        $this->postJson('/api/productos', ['nombre' => '', 'precio' => -1, 'stock' => 'diez'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre', 'precio', 'stock']);
    }

    public function test_show_devuelve_producto_o_404(): void
    {
        $producto = Producto::factory()->create();

        $this->getJson("/api/productos/{$producto->id}")->assertOk()->assertJsonPath('id', $producto->id);
        $this->getJson('/api/productos/9999')->assertNotFound();
    }

    public function test_update_parcial(): void
    {
        $producto = Producto::factory()->create(['stock' => 10]);

        $this->patchJson("/api/productos/{$producto->id}", ['stock' => 7])
            ->assertOk()
            ->assertJsonPath('stock', 7)
            ->assertJsonPath('nombre', $producto->nombre);

        $this->patchJson("/api/productos/{$producto->id}", ['stock' => -5])->assertUnprocessable();
        $this->putJson('/api/productos/9999', ['stock' => 1])->assertNotFound();
    }

    public function test_destroy_devuelve_204(): void
    {
        $producto = Producto::factory()->create();

        $this->deleteJson("/api/productos/{$producto->id}")->assertNoContent();
        $this->assertDatabaseMissing('productos', ['id' => $producto->id]);
        $this->deleteJson("/api/productos/{$producto->id}")->assertNotFound();
    }

    public function test_ajustar_stock_no_permite_negativos(): void
    {
        $producto = Producto::factory()->create(['stock' => 5]);

        $this->patchJson("/api/productos/{$producto->id}/stock", ['cantidad' => -3])
            ->assertOk()->assertJsonPath('stock', 2);

        $this->patchJson("/api/productos/{$producto->id}/stock", ['cantidad' => -3])
            ->assertUnprocessable()->assertJsonValidationErrors('cantidad');

        Event::assertDispatched(ProductoActualizado::class, fn ($e) => $e->accion === 'stock' && $e->producto['stock'] === 2);
    }

    public function test_api_interna_requiere_token(): void
    {
        config(['services.interno.token' => 'secreto']);
        $producto = Producto::factory()->create();

        $this->getJson("/api/interno/productos/{$producto->id}")->assertUnauthorized();
        $this->getJson("/api/interno/productos/{$producto->id}", ['X-Internal-Token' => 'secreto'])
            ->assertOk()->assertJsonPath('id', $producto->id);
    }
}
