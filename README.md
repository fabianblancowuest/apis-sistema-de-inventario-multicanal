# Inventario multicanal (Laravel)

Trabajo práctico de Programación IV: una misma lógica de inventario expuesta por **REST**, **GraphQL**, **WebSockets (Reverb)** y **gRPC**. El análisis y la tabla comparativa están en [INFORME.md](INFORME.md).

## Requisitos

PHP 8.3+ (con pdo_sqlite), Composer, Node.js 22+.

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan reverb:install   # genera las claves REVERB_* (si no están)
php artisan migrate --seed   # crea la base SQLite con 5 productos de prueba
npm install && npm run build
```

En `.env` definir `INTERNAL_API_TOKEN` con un valor secreto (lo usa el microservicio gRPC).

Para gRPC:

```bash
cd grpc
npm install
cp .env.example .env         # copiar INTERNAL_API_TOKEN y REVERB_APP_KEY del .env de Laravel
```

## Ejecución (una terminal por proceso)

```bash
php artisan serve            # http://127.0.0.1:8000  (página en vivo + REST + GraphQL)
php artisan reverb:start     # WebSockets en el puerto 8080
php artisan queue:work       # envía los eventos (agregar --sleep=0 para menos demora)
cd grpc && npm run server    # gRPC en 127.0.0.1:50051
```

## Probar

- **Página en vivo:** abrir http://127.0.0.1:8000 en dos navegadores y tocar los botones de stock en uno.
- **REST:**
  ```bash
  curl -X POST http://127.0.0.1:8000/api/productos -H "Content-Type: application/json" -H "Accept: application/json" -d '{"nombre":"Mouse Gamer","precio":18500,"stock":20}'
  curl http://127.0.0.1:8000/api/productos/1
  curl -X PATCH http://127.0.0.1:8000/api/productos/1/stock -H "Content-Type: application/json" -H "Accept: application/json" -d '{"cantidad":-2}'
  ```
- **GraphQL** (`POST /graphql`):
  ```json
  { "query": "{ productos { id nombre precio stock } }" }
  { "query": "mutation { crearProducto(input: {nombre: \"Teclado\", precio: 54000, stock: 3}) { id nombre } }" }
  ```
- **gRPC:** `cd grpc && npm run client` (producto 1) o `npm run client -- 3`.
- **Comparativa:** `cd grpc && npm run comparar` (necesita los 4 procesos corriendo).
- **Tests:** `php artisan test`.

## Estructura

```
app/Services/ProductoService.php          reglas del inventario (validación, stock ≥ 0, eventos)
app/Http/Controllers/Api/ProductoController.php   REST
graphql/schema.graphql + app/GraphQL/Mutations/   GraphQL (Lighthouse)
app/Events/ProductoActualizado.php        evento broadcast (canal "inventario")
resources/views/inventario.blade.php + resources/js/inventario.js   cliente Echo
app/Http/Middleware/VerificarTokenInterno.php     protege /api/interno/*
grpc/                                     microservicio y cliente gRPC (Node.js)
```
