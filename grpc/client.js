// Cliente gRPC: solicita el producto con ID 1 (o el que se pase por argumento).
//   npm run client        -> producto 1
//   npm run client -- 3   -> producto 3
import { config, grpc, inventario } from './comun.js';

const id = Number(process.argv[2] ?? 1);
const cliente = new inventario.ProductoService(config.grpcAddr, grpc.credentials.createInsecure());

const inicio = performance.now();
cliente.ObtenerProducto({ id }, (error, producto) => {
    const ms = (performance.now() - inicio).toFixed(1);

    if (error) {
        console.error(`Error gRPC ${grpc.status[error.code]} (${error.code}): ${error.details}`);
        process.exit(1);
    }

    console.log('Respuesta gRPC:', producto);
    console.log(`Tiempo: ${ms} ms (incluye abrir la conexión HTTP/2)`);
    cliente.close();
});
