import { fileURLToPath } from 'node:url';
import grpc from '@grpc/grpc-js';
import protoLoader from '@grpc/proto-loader';

export const PROTO_PATH = fileURLToPath(new URL('./producto.proto', import.meta.url));

export const config = {
    grpcAddr: process.env.GRPC_ADDR ?? '127.0.0.1:50051',
    laravelUrl: (process.env.LARAVEL_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, ''),
    token: process.env.INTERNAL_API_TOKEN ?? '',
};

const definicion = protoLoader.loadSync(PROTO_PATH, {
    keepCase: true,
    longs: Number,
    defaults: true,
});

export const inventario = grpc.loadPackageDefinition(definicion).inventario;
export { grpc };
