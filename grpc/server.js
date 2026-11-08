// Servidor gRPC (microservicio). No accede a la base de datos:
// consulta a Laravel por la API interna protegida con X-Internal-Token,
// así las reglas del inventario siguen viviendo en ProductoService.
import { config, grpc, inventario } from './comun.js';

if (!config.token) {
    console.error('Falta INTERNAL_API_TOKEN (copiá el valor del .env de Laravel en grpc/.env).');
    process.exit(1);
}

async function ObtenerProducto(call, callback) {
    const { id } = call.request;

    try {
        const respuesta = await fetch(`${config.laravelUrl}/api/interno/productos/${id}`, {
            headers: { Accept: 'application/json', 'X-Internal-Token': config.token },
        });

        if (respuesta.status === 404) {
            return callback({ code: grpc.status.NOT_FOUND, details: `No existe el producto ${id}` });
        }
        if (respuesta.status === 401) {
            return callback({ code: grpc.status.UNAUTHENTICATED, details: 'Token interno rechazado por Laravel' });
        }
        if (!respuesta.ok) {
            return callback({ code: grpc.status.INTERNAL, details: `Laravel respondió HTTP ${respuesta.status}` });
        }

        const p = await respuesta.json();
        callback(null, { id: p.id, nombre: p.nombre, precio: Number(p.precio), stock: p.stock });
    } catch (error) {
        callback({ code: grpc.status.UNAVAILABLE, details: `No se pudo contactar a Laravel: ${error.message}` });
    }
}

const server = new grpc.Server();
server.addService(inventario.ProductoService.service, { ObtenerProducto });

server.bindAsync(config.grpcAddr, grpc.ServerCredentials.createInsecure(), (error, puerto) => {
    if (error) {
        console.error(error);
        process.exit(1);
    }
    console.log(`Servidor gRPC escuchando en ${config.grpcAddr} (puerto ${puerto}) → Laravel ${config.laravelUrl}`);
});
