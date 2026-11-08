<?php

namespace App\Models;

use Database\Factories\ProductoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    /** @use HasFactory<ProductoFactory> */
    use HasFactory;

    protected $fillable = [
        'nombre', 'precio', 'stock',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'float',
            'stock' => 'integer',
        ];
    }
}
