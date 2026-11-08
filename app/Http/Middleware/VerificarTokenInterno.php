<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege la API interna que consume el microservicio gRPC.
 * El microservicio debe enviar la cabecera  X-Internal-Token: <INTERNAL_API_TOKEN>.
 */
class VerificarTokenInterno
{
    public function handle(Request $request, Closure $next): Response
    {
        $esperado = (string) config('services.interno.token');
        $recibido = (string) $request->header('X-Internal-Token');

        if ($esperado === '' || ! hash_equals($esperado, $recibido)) {
            return response()->json(['message' => 'Token interno inválido.'], 401);
        }

        return $next($request);
    }
}
