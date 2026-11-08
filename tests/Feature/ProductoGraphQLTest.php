<?php

namespace Tests\Feature;

use App\Events\ProductoActualizado;
use App\Models\Producto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProductoGraphQLTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([ProductoActualizado::class]);
    }

    private function graphql(string $query, array $variables = [])
    {
        return $this->postJson('/graphql', ['query' => $query, 'variables' => $variables]);
    }

    public function test_query_devuelve_solo_campos_pedidos(): void
    {
        Producto::factory()->create(['nombre' => 'Mouse Gamer', 'precio' => 18500]);

        $respuesta = $this->graphql('{ productos { nombre precio } }')->assertOk();

        $this->assertEquals(['nombre' => 'Mouse Gamer', 'precio' => 18500], $respuesta->json('data.productos.0'));
    }

    public function test_query_producto_por_id(): void
    {
        $producto = Producto::factory()->create();

        $this->graphql('query ($id: ID!) { producto(id: $id) { id nombre stock } }', ['id' => $producto->id])
            ->assertJsonPath('data.producto.id', (string) $producto->id)
            ->assertJsonPath('data.producto.stock', $producto->stock);
    }

    public function test_mutacion_crea_producto(): void
    {
        $this->graphql('mutation { crearProducto(input: {nombre: "Teclado", precio: 54000, stock: 3}) { id nombre stock } }')
            ->assertJsonPath('data.crearProducto.nombre', 'Teclado');

        $this->assertDatabaseHas('productos', ['nombre' => 'Teclado', 'stock' => 3]);
        Event::assertDispatched(ProductoActualizado::class);
    }

    public function test_mutacion_usa_las_mismas_validaciones_que_rest(): void
    {
        $respuesta = $this->graphql('mutation { crearProducto(input: {nombre: "X", precio: -10, stock: -1}) { id } }');

        $errores = $respuesta->json('errors.0.extensions.validation');
        $this->assertArrayHasKey('precio', $errores);
        $this->assertArrayHasKey('stock', $errores);
        $this->assertDatabaseCount('productos', 0);
    }

    public function test_mutacion_ajustar_stock(): void
    {
        $producto = Producto::factory()->create(['stock' => 4]);

        $this->graphql("mutation { ajustarStock(id: {$producto->id}, cantidad: -4) { stock } }")
            ->assertJsonPath('data.ajustarStock.stock', 0);

        $respuesta = $this->graphql("mutation { ajustarStock(id: {$producto->id}, cantidad: -1) { stock } }");
        $this->assertArrayHasKey('cantidad', $respuesta->json('errors.0.extensions.validation'));
    }
}
