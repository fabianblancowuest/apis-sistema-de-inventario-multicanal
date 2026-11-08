<?php

namespace Database\Factories;

use App\Models\Producto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Producto>
 */
class ProductoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nombre' => ucfirst(fake()->words(2, true)),
            'precio' => fake()->randomFloat(2, 500, 250000),
            'stock' => fake()->numberBetween(0, 100),
        ];
    }
}
