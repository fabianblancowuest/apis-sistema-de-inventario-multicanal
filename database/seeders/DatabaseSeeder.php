<?php

namespace Database\Seeders;

use App\Models\Producto;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Datos ficticios para la práctica.
     */
    public function run(): void
    {
        $productos = [
            ['nombre' => 'Mouse Gamer', 'precio' => 18500, 'stock' => 20],
            ['nombre' => 'Teclado Mecánico', 'precio' => 54000, 'stock' => 12],
            ['nombre' => 'Monitor 24"', 'precio' => 210000, 'stock' => 5],
            ['nombre' => 'Auriculares USB', 'precio' => 32000, 'stock' => 18],
            ['nombre' => 'Webcam HD', 'precio' => 41000, 'stock' => 9],
        ];

        foreach ($productos as $producto) {
            Producto::create($producto);
        }
    }
}
