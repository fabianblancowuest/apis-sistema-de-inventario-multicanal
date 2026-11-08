# Sistema de Inventario Multicanal

Trabajo práctico de **Programación IV — TUP**.
**Integrante:** Fabián Blanco Wuest

**Objetivo:** desarrollar una aplicación Laravel que gestione productos y exponga sus datos con distintos mecanismos de comunicación (**REST**, **GraphQL**, **WebSockets** y **gRPC**) para analizar sus diferencias en funcionamiento, estructura y rendimiento.

> El análisis completo está en **[INFORME.md](INFORME.md)**.

## Resolución en breve

| Etapa | Qué se hizo |
|---|---|
| 1. Modelo | Laravel 13 + SQLite, entidad `Producto` (`nombre`, `precio`, `stock`). Todas las reglas del inventario están en [`ProductoService`](app/Services/ProductoService.php), que comparten REST, GraphQL y gRPC. |
| 2. REST | CRUD completo con códigos 200 / 201 / 204 / 404 / 422, y `PATCH /api/productos/{id}/stock` para sumar o restar unidades (el stock nunca queda negativo). |
| 3. GraphQL | Lighthouse: queries `productos` y `producto(id)`, mutaciones `crearProducto` y `ajustarStock` con **las mismas validaciones que REST**. |
| 4. WebSockets | Laravel Reverb + Echo: el evento `producto.actualizado` se emite por el canal `inventario` y la página se actualiza sola en todos los navegadores. |
| 5. gRPC | Microservicio y cliente en Node.js a partir de [`producto.proto`](grpc/producto.proto). Consulta a Laravel por una API interna protegida con token. |

Hay 15 tests automáticos (`php artisan test`) que cubren REST, GraphQL, validaciones y la API interna.

### Tabla comparativa

Mediciones propias (producto ID 1, 100 repeticiones, todo en local con `php artisan serve`). Tiempos expresados como mediana.

| Prueba | REST | GraphQL | gRPC | WebSocket |
|---|---|---|---|---|
| Obtener un producto | `GET /api/productos/1` | `POST /graphql` | `ObtenerProducto({id: 1})` | No aplica (notifica, no consulta) |
| Seleccionar campos | No | **Sí**, nativo | No (mensaje fijo del `.proto`) | No (lo decide el servidor) |
| Crear producto | `POST` → 201 | Mutación `crearProducto` | No implementado en el contrato | Avisa a todos que se creó |
| Recibir cambios en vivo | No (habría que hacer polling) | No (sin *subscriptions*) | Posible con *streaming*, no implementado | **Sí** |
| Tamaño de respuesta | 143 B | 81 B (46 B sólo `nombre`) | **26 B** (binario) | 210 B por mensaje |
| Tiempo de respuesta | 33,7 ms | 42,5 ms | 32,2 ms | ~318 ms del cambio al aviso* |

\* Con `queue:work --sleep=0`. Con la configuración por defecto de la cola son ≈ 3,2 s. La explicación está en el [informe](INFORME.md#análisis-de-los-resultados).

En el listado completo, REST devuelve **733 bytes** y GraphQL pidiendo sólo `nombre` y `precio` devuelve **238 bytes**, porque el cliente elige los campos.

### Preguntas del informe

1. **¿Por qué REST usa varios métodos HTTP y GraphQL un único endpoint?** REST trabaja con recursos y usa los verbos de HTTP para la acción; en GraphQL la operación va escrita dentro de la consulta.
2. **¿Qué ventaja tiene pedir sólo algunos campos?** Respuestas más chicas, sin datos de más ni peticiones de más, y cada pantalla pide exactamente lo que muestra.
3. **¿Por qué gRPC usa archivos `.proto`?** Son el contrato tipado entre cliente y servidor: permiten generar código en cualquier lenguaje y serializar en binario compacto.
4. **¿Qué aportan los WebSockets con varios usuarios?** El servidor avisa al instante, todos ven el stock real y se evita el polling.
5. **¿Qué usaría para ventas web + móvil?** REST como API principal, WebSockets con canales privados para el tiempo real, GraphQL si las pantallas piden datos muy distintos, y gRPC sólo entre servicios internos.
6. **¿Qué problemas trae usar todo junto?** Reglas duplicadas o inconsistentes, más procesos e infraestructura, seguridad en varios frentes y contratos que hay que versionar en paralelo.

Las respuestas completas están en la [sección 4 del informe](INFORME.md#4-preguntas-para-el-informe-final).

## Instalación

Requisitos: PHP 8.3+ (con pdo_sqlite), Composer y Node.js 22+.

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

## Cómo probar

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
app/Services/ProductoService.php                  reglas del inventario (validación, stock ≥ 0, eventos)
app/Http/Controllers/Api/ProductoController.php   REST
graphql/schema.graphql + app/GraphQL/Mutations/   GraphQL (Lighthouse)
app/Events/ProductoActualizado.php                evento broadcast (canal "inventario")
resources/views/inventario.blade.php
resources/js/inventario.js                        cliente Laravel Echo
app/Http/Middleware/VerificarTokenInterno.php     protege /api/interno/*
grpc/                                             microservicio y cliente gRPC (Node.js)
INFORME.md                                        análisis, tabla y respuestas completas
```
