# Informe — Sistema de Inventario Multicanal

**Materia:** Programación IV — TUP
**Integrante:** Fabián Blanco Wuest
**Fecha:** 07/11/2026

## 1. Qué se implementó

| Etapa | Implementación | Archivos principales |
|---|---|---|
| 1. Proyecto y modelo | Laravel 13 + SQLite, entidad `Producto` (`nombre`, `precio`, `stock`). Las reglas del inventario están centralizadas en `ProductoService`, que usan REST, GraphQL y la API interna. | `app/Models/Producto.php`, `app/Services/ProductoService.php`, `database/migrations/*_create_productos_table.php` |
| 2. REST | CRUD completo (`index`, `store`, `show`, `update`, `destroy`) + `PATCH /api/productos/{id}/stock` para sumar/restar unidades. | `app/Http/Controllers/Api/ProductoController.php`, `routes/api.php` |
| 3. GraphQL | Lighthouse 6. Queries `productos` y `producto(id)`. Mutaciones `crearProducto` y `ajustarStock`, que llaman a `ProductoService`, o sea que usan **las mismas validaciones y reglas que REST**. | `graphql/schema.graphql`, `app/GraphQL/Mutations/ProductoMutations.php` |
| 4. WebSockets | Laravel Reverb + evento `ProductoActualizado` (`ShouldBroadcast`, canal público `inventario`, nombre `producto.actualizado`). Se emite **después del commit** (`ShouldDispatchAfterCommit`). Página web con Laravel Echo. | `app/Events/ProductoActualizado.php`, `resources/views/inventario.blade.php`, `resources/js/inventario.js` |
| 5. gRPC | Microservicio en Node.js (`@grpc/grpc-js`) que implementa `ProductoService.ObtenerProducto` del contrato `producto.proto` y consulta a Laravel por una **API interna protegida** (`/api/interno/productos/{id}` + cabecera `X-Internal-Token`). Cliente que pide el producto 1. | `grpc/producto.proto`, `grpc/server.js`, `grpc/client.js`, `app/Http/Middleware/VerificarTokenInterno.php` |

### Códigos HTTP de la API REST

| Operación | Ruta | Éxito | Errores |
|---|---|---|---|
| Listar | `GET /api/productos` | 200 | — |
| Crear | `POST /api/productos` | 201 Created | 422 validación |
| Ver | `GET /api/productos/{id}` | 200 | 404 |
| Modificar (total/parcial) | `PUT`/`PATCH /api/productos/{id}` | 200 | 404, 422 |
| Eliminar | `DELETE /api/productos/{id}` | 204 No Content | 404 |
| Ajustar stock | `PATCH /api/productos/{id}/stock` `{"cantidad": -3}` | 200 | 404, 422 (stock insuficiente) |

Validaciones: `nombre` obligatorio, texto, máx. 100; `precio` numérico ≥ 0; `stock` entero ≥ 0. En `update` cada campo es opcional (`sometimes`), así se puede mandar sólo lo que cambia. Regla de negocio extra: el stock nunca puede quedar negativo (se controla dentro de una transacción con `lockForUpdate`).

Hay 15 tests automáticos (`php artisan test`) que cubren REST, GraphQL, validaciones, 404, la regla de stock y la protección de la API interna.

## 2. Comparación REST vs GraphQL (consigna etapa 3)

Bytes del cuerpo de la respuesta con los 5 productos de prueba:

| Consulta | Bytes |
|---|---|
| REST `GET /api/productos` | **733** |
| GraphQL `{ productos { nombre precio } }` | **238** |
| GraphQL `{ productos { id nombre precio stock } }` | **336** |

**Por qué son distintas:** REST devuelve siempre la representación completa que definió el servidor, incluidas columnas que el cliente quizás no usa (`created_at`, `updated_at`). En GraphQL el **cliente** elige los campos y el servidor devuelve sólo esos; al agregar `id` y `stock` la respuesta crece (238 → 336 bytes) pero sigue siendo más chica que REST. GraphQL sí agrega un envoltorio fijo (`{"data":{"productos":[...]}}`), por eso con un sólo registro y todos los campos la diferencia es menor.

## 3. Tabla comparativa

Mediciones propias con `grpc/comparar.js`: Windows 11, todo en la misma máquina, `php artisan serve` (servidor de desarrollo, un solo proceso), SQLite, producto con ID 1, 100 repeticiones por tecnología después de 10 de calentamiento. Para WebSocket, 20 repeticiones.

| Prueba | REST | GraphQL | gRPC | WebSocket |
|---|---|---|---|---|
| **Obtener un producto** | `GET /api/productos/1`. Simple, se puede probar desde el navegador o Postman. | `POST /graphql` con `{ producto(id: 1) { ... } }`. Un único endpoint. | `ObtenerProducto({id: 1})` con un cliente generado desde el `.proto`. Hace falta un cliente gRPC (no se prueba desde el navegador). | No aplica: no es un mecanismo de consulta, sino de notificación. |
| **Seleccionar campos** | No (devuelve todos los campos). Se podría con parámetros tipo `?fields=` hechos a mano. | Sí, de forma nativa: `{ producto(id:1) { nombre } }`. | No: el mensaje `ProductoResponse` es fijo. Para otra forma hay que cambiar el contrato. | No: el servidor decide qué manda el evento (`broadcastWith`). |
| **Crear producto** | `POST /api/productos` → 201. | Mutación `crearProducto(input: {...})`. Mismas validaciones (vía `ProductoService`); errores en `extensions.validation`. | No implementado: el contrato sólo define `ObtenerProducto` (se podría agregar un `rpc CrearProducto`). | No crea: avisa a todos los clientes que se creó (`accion: "creado"`). |
| **Recibir cambios en vivo** | No; habría que hacer polling (preguntar cada X segundos). | No con queries/mutations (existen las *subscriptions*, no implementadas). | Posible con *server streaming*, no implementado. | **Sí.** Probado con dos navegadores: el stock cambia en el otro sin recargar. |
| **Tamaño de respuesta** (1 producto, campos id, nombre, precio, stock) | **143 bytes** (JSON, incluye timestamps) | **81 bytes** (46 bytes pidiendo sólo `nombre`) | **26 bytes** (protobuf binario) | **210 bytes** por mensaje (JSON del protocolo Pusher: evento + canal + datos) |
| **Tiempo de respuesta** (mediana / promedio / p95) | **33,7 / 39,0 / 77,1 ms** | **42,5 / 47,9 / 77,3 ms** | **32,2 / 36,0 / 61,8 ms** | Desde el `PATCH` hasta que llega el evento: **318 / 329 / 440 ms** con `queue:work --sleep=0`. Con `queue:work` por defecto: **≈ 3.200 ms** |

### Análisis de los resultados

- **Tamaño:** gRPC es el más compacto por usar Protocol Buffers (binario, sin nombres de campos, sólo números de campo). GraphQL es más chico que REST porque no manda los timestamps. WebSocket es el más grande por mensaje porque usa JSON y el protocolo Pusher agrega metadatos, pero manda un solo mensaje por cambio en lugar de que cada cliente pregunte.
- **Tiempo:** las tres tecnologías de consulta dan valores parecidos (30–50 ms) porque en local lo que domina es arrancar Laravel en cada petición con `php artisan serve`, no la red. GraphQL es un poco más lento porque tiene que parsear y validar la consulta contra el esquema. **gRPC tiene un detalle importante:** en mi arquitectura el microservicio le pide los datos a Laravel por HTTP (API interna), así que su tiempo *incluye una petición REST*; aun así quedó igual o mejor, porque el tramo cliente→microservicio (HTTP/2, conexión reutilizada, binario) es muy barato. En un sistema donde el servicio gRPC accede directamente a sus datos, la diferencia sería mayor.
- **WebSocket:** la mayor parte del tiempo es la **cola**. El evento implementa `ShouldBroadcast`, entonces se guarda como trabajo en la tabla `jobs` y `queue:work` lo procesa; con la configuración por defecto el worker espera 3 s cuando no hay trabajos, de ahí los ~3,2 s. Con `--sleep=0` baja a ~0,3 s. Si se quiere inmediato se puede usar `ShouldBroadcastNow` (sin cola), a costa de que la petición HTTP espere el envío.

## 4. Preguntas para el informe final

**1. ¿Por qué REST utiliza diferentes métodos HTTP mientras GraphQL suele utilizar un único endpoint?**

REST modela el sistema como **recursos** identificados por URL (`/api/productos/1`) y usa la semántica de HTTP para decir qué se hace con ellos: `GET` lee, `POST` crea, `PUT/PATCH` modifica, `DELETE` borra. Eso permite aprovechar lo que ya trae HTTP: códigos de estado (201, 404, 422), caché de `GET`, idempotencia de `PUT`/`DELETE`. GraphQL, en cambio, modela el sistema como **un esquema tipado** y la operación va escrita *dentro* del cuerpo de la petición (`query` para leer, `mutation` para escribir). Como el pedido completo está en el cuerpo, alcanza con un único `POST /graphql`; el servidor interpreta la consulta y arma la respuesta. La contra es que se pierde la caché HTTP estándar y los errores suelen venir con estado 200 dentro de `errors`.

**2. ¿Qué ventajas tiene solicitar solamente determinados campos en GraphQL?**

- Respuestas más chicas: en mis pruebas, 238 bytes contra 733 de REST para el listado. Importa sobre todo en celulares y redes lentas.
- Se evita el *over-fetching* (recibir datos que no se usan) y el *under-fetching* (tener que hacer varias peticiones para juntar datos relacionados).
- Cada pantalla pide exactamente lo que muestra sin que el backend tenga que crear un endpoint nuevo para cada vista.
- El esquema documenta qué campos existen y sus tipos, y permite saber qué campos usan realmente los clientes.

**3. ¿Por qué gRPC utiliza archivos .proto?**

El `.proto` es el **contrato** entre cliente y servidor: define los servicios, los métodos (`rpc ObtenerProducto`) y la estructura exacta de los mensajes con tipos y números de campo. A partir de ese archivo se generan (o cargan, como hice con `proto-loader`) clientes y servidores en cualquier lenguaje, de modo que un servidor en Node y un cliente en Python o Go hablan exactamente el mismo idioma. Además, los números de campo (`id = 1`, `nombre = 2`…) son los que viajan en el binario de Protocol Buffers en lugar de los nombres, por eso el mensaje pesa 26 bytes, y permiten agregar campos nuevos sin romper clientes viejos.

**4. ¿Qué ventaja ofrecen los WebSockets cuando varios usuarios modifican un inventario?**

Con WebSockets la conexión queda abierta y **el servidor avisa** apenas hay un cambio, en lugar de que cada cliente tenga que preguntar cada tantos segundos (polling). Ventajas:
- Todos ven el stock real casi al instante, así se reducen las ventas de productos que ya no hay o las decisiones con datos viejos.
- Menos carga: un solo mensaje por cambio, en lugar de N clientes preguntando todo el tiempo aunque no haya cambios.
- Mejor experiencia: la pantalla se actualiza sola, sin recargar (lo comprobé con dos navegadores).

Igualmente, el WebSocket **sólo notifica**; la consistencia la garantiza el servidor (en mi implementación, la transacción con bloqueo que impide stock negativo cuando dos usuarios descuentan a la vez).

**5. ¿Qué tecnologías utilizarían para un sistema de gestión de ventas con aplicación web y móvil? Justificar.**

- **REST como API principal** para las operaciones de ventas, clientes y productos: es simple, la entienden todos los frameworks web y móviles, se prueba fácil con Postman, aprovecha la caché HTTP y los códigos de estado, y en Laravel sale casi de forma nativa (con Sanctum para la autenticación).
- **WebSockets (Reverb) con canales privados** para lo que tiene que estar en tiempo real: stock, nuevos pedidos, estado de la caja. Evita el polling desde web y móvil.
- **GraphQL como opción** si las pantallas de la app móvil y la web necesitan datos muy distintos o hay muchas relaciones (venta → cliente → ítems → productos); ahorra datos móviles y peticiones. Si el sistema es chico, agrega complejidad que no hace falta.
- **gRPC sólo entre servicios internos** (por ejemplo facturación o un servicio de stock separado), donde importa el rendimiento y los contratos estrictos. No lo usaría hacia el navegador porque necesita gRPC-Web y un proxy.

**6. ¿Qué inconvenientes podrían aparecer al implementar todas estas tecnologías en un mismo proyecto?**

- **Duplicación de lógica y reglas inconsistentes:** si cada interfaz valida a su manera, un producto puede ser válido por GraphQL e inválido por REST. Lo resolví con `ProductoService`, pero hay que mantener esa disciplina.
- **Más infraestructura y procesos:** en mi práctica hacen falta 4 procesos (`serve`, `reverb:start`, `queue:work`, servidor gRPC) y dos lenguajes (PHP y Node). Más cosas para desplegar, monitorear y que se pueden caer.
- **Seguridad en varios frentes:** autenticación y autorización distintas en REST, GraphQL (consultas muy profundas o costosas), canales WebSocket (privados/presencia) y API interna de gRPC (token compartido, TLS).
- **Versionado de contratos:** cambiar un campo implica actualizar el `.proto`, el esquema GraphQL, las respuestas REST y el payload de los eventos.
- **Problemas de rendimiento propios de cada uno:** N+1 en GraphQL, retrasos de la cola en los eventos (medí 3 s por la configuración del worker), conexiones abiertas en WebSocket.
- **Curva de aprendizaje y pruebas más complejas** para el equipo, y más dependencias externas que actualizar.
