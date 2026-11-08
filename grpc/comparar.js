// Benchmark comparativo: REST vs GraphQL vs gRPC (+ latencia de WebSocket).
// Requiere corriendo: php artisan serve, php artisan reverb:start, php artisan queue:work y npm run server.
//   npm run comparar            -> 100 repeticiones por tecnología, producto 1
//   npm run comparar -- 300 2   -> 300 repeticiones, producto 2
import protobuf from 'protobufjs';
import { PROTO_PATH, config, grpc, inventario } from './comun.js';

const N = Number(process.argv[2] ?? 100);
const ID = Number(process.argv[3] ?? 1);
const CALENTAMIENTO = 10;

const estadisticas = (tiempos) => {
    const ordenados = [...tiempos].sort((a, b) => a - b);
    const prom = tiempos.reduce((a, b) => a + b, 0) / tiempos.length;
    const p = (q) => ordenados[Math.min(ordenados.length - 1, Math.floor(q * ordenados.length))];
    return { promedio: prom.toFixed(2), mediana: p(0.5).toFixed(2), p95: p(0.95).toFixed(2) };
};

async function medir(nombre, fn) {
    for (let i = 0; i < CALENTAMIENTO; i++) await fn();
    const tiempos = [];
    let bytes = 0;
    for (let i = 0; i < N; i++) {
        const t0 = performance.now();
        bytes = await fn();
        tiempos.push(performance.now() - t0);
    }
    return { tecnologia: nombre, bytes_respuesta: bytes, ...estadisticas(tiempos) };
}

const json = (url, opciones = {}) =>
    fetch(url, { ...opciones, headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
        .then(async (r) => {
            const texto = await r.text();
            if (!r.ok) throw new Error(`${url} -> HTTP ${r.status}: ${texto}`);
            return Buffer.byteLength(texto);
        });

const graphql = (query) => json(`${config.laravelUrl}/graphql`, { method: 'POST', body: JSON.stringify({ query }) });

// gRPC: el tamaño es el del mensaje protobuf serializado (sin cabeceras HTTP/2).
const raiz = await protobuf.load(PROTO_PATH);
const ProductoResponse = raiz.lookupType('inventario.ProductoResponse');
const cliente = new inventario.ProductoService(config.grpcAddr, grpc.credentials.createInsecure());
const grpcObtener = () => new Promise((resolve, reject) => {
    cliente.ObtenerProducto({ id: ID }, (err, res) => {
        if (err) return reject(new Error(`gRPC: ${err.details}`));
        resolve(ProductoResponse.encode(ProductoResponse.fromObject(res)).finish().length);
    });
});

const resultados = [];
resultados.push(await medir('REST  GET /api/productos/{id}', () => json(`${config.laravelUrl}/api/productos/${ID}`)));
resultados.push(await medir('GraphQL (id nombre precio stock)', () => graphql(`{ producto(id: ${ID}) { id nombre precio stock } }`)));
resultados.push(await medir('GraphQL (sólo nombre)', () => graphql(`{ producto(id: ${ID}) { nombre } }`)));
resultados.push(await medir('gRPC  ObtenerProducto', grpcObtener));
cliente.close();

// Comparación de listados (consigna de la etapa 3)
const listado = {
    rest_todos_los_campos: await json(`${config.laravelUrl}/api/productos`),
    graphql_nombre_precio: await graphql('{ productos { nombre precio } }'),
    graphql_id_nombre_precio_stock: await graphql('{ productos { id nombre precio stock } }'),
};

console.log(`\nObtener el producto ${ID} — ${N} repeticiones (tras ${CALENTAMIENTO} de calentamiento), tiempos en ms:`);
console.table(resultados);
console.log('Listado completo — bytes del cuerpo de la respuesta:');
console.table(listado);

// ---- WebSocket: tiempo desde el PATCH hasta que llega el evento por Reverb ----
async function medirWebSocket() {
    const clave = process.env.REVERB_APP_KEY;
    if (!clave) return console.log('\n(WebSocket omitido: definí REVERB_APP_KEY en grpc/.env)');

    const host = process.env.REVERB_HOST ?? '127.0.0.1';
    const puerto = process.env.REVERB_PORT ?? 8080;
    const ws = new WebSocket(`ws://${host}:${puerto}/app/${clave}?protocol=7&client=js&version=8.4.0&flash=false`);
    let esperando = null;

    ws.addEventListener('message', ({ data }) => {
        const msj = JSON.parse(data);
        if (msj.event === 'pusher:connection_established') {
            ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel: 'inventario' } }));
        } else if (msj.event === 'pusher_internal:subscription_succeeded') {
            ws.dispatchEvent(new Event('suscripto'));
        } else if (msj.event === 'producto.actualizado' && esperando) {
            esperando(Buffer.byteLength(data));
        }
    });
    await new Promise((resolve, reject) => {
        ws.addEventListener('suscripto', resolve, { once: true });
        ws.addEventListener('error', () => reject(new Error('No se pudo conectar a Reverb')), { once: true });
    });

    const tiempos = [];
    let bytes = 0;
    const repeticiones = 20;
    for (let i = 0; i < repeticiones; i++) {
        const cantidad = i % 2 === 0 ? 1 : -1; // deja el stock como estaba
        const llegada = new Promise((resolve) => { esperando = resolve; });
        const t0 = performance.now();
        await json(`${config.laravelUrl}/api/productos/${ID}/stock`, { method: 'PATCH', body: JSON.stringify({ cantidad }) });
        bytes = await Promise.race([llegada, new Promise((_, rej) => setTimeout(() => rej(new Error('El evento no llegó en 10 s (¿está corriendo queue:work?)')), 10000))]);
        tiempos.push(performance.now() - t0);
    }
    ws.close();

    console.log(`\nWebSocket (Reverb): PATCH de stock → evento recibido, ${repeticiones} repeticiones, ms:`);
    console.table([{ tecnologia: 'WebSocket producto.actualizado', bytes_mensaje: bytes, ...estadisticas(tiempos) }]);
}

await medirWebSocket().catch((e) => console.log(`\nWebSocket: ${e.message}`));
