<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventario en vivo</title>
    @vite(['resources/js/inventario.js'])
    <style>
        :root { --borde:#d4d4d8; --texto:#18181b; --suave:#71717a; --fondo:#fafafa; --acento:#2563eb; --ok:#16a34a; --mal:#dc2626; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, sans-serif; background:var(--fondo); color:var(--texto); }
        main { max-width: 900px; margin: 0 auto; padding: 24px 16px; }
        h1 { margin: 0 0 4px; font-size: 1.5rem; }
        .estado { font-size:.85rem; color:var(--suave); margin-bottom:20px; }
        .estado b.on { color:var(--ok); } .estado b.off { color:var(--mal); }
        table { width:100%; border-collapse: collapse; background:#fff; border:1px solid var(--borde); }
        th, td { padding:10px; border-bottom:1px solid var(--borde); text-align:left; }
        td.num { font-variant-numeric: tabular-nums; }
        button { border:1px solid var(--borde); background:#fff; border-radius:6px; padding:4px 10px; cursor:pointer; }
        button:hover { border-color: var(--acento); }
        tr.flash { animation: flash 1.2s ease-out; }
        @keyframes flash { from { background:#fef08a; } to { background:#fff; } }
        form { display:flex; gap:8px; flex-wrap:wrap; margin:20px 0; }
        input { padding:6px 8px; border:1px solid var(--borde); border-radius:6px; }
        #log { margin-top:20px; font-family: ui-monospace, monospace; font-size:.8rem; background:#18181b; color:#e4e4e7; padding:12px; border-radius:6px; max-height:220px; overflow:auto; white-space:pre-wrap; }
        .error { color: var(--mal); font-size:.85rem; min-height:1.2em; }
    </style>
</head>
<body>
<main>
    <h1>Inventario multicanal</h1>
    <div class="estado">WebSocket (Reverb): <b id="conexion" class="off">desconectado</b> · canal público <code>inventario</code></div>

    <table>
        <thead><tr><th>ID</th><th>Nombre</th><th>Precio</th><th>Stock</th><th>Ajustar</th></tr></thead>
        <tbody id="productos"></tbody>
    </table>
    <div class="error" id="error"></div>

    <form id="nuevo">
        <input name="nombre" placeholder="Nombre" required maxlength="100">
        <input name="precio" type="number" min="0" step="0.01" placeholder="Precio" required>
        <input name="stock" type="number" min="0" step="1" placeholder="Stock" required>
        <button type="submit">Crear producto</button>
    </form>

    <strong>Eventos recibidos</strong>
    <div id="log">Esperando eventos… Abrí esta página en otro navegador y modificá el stock.</div>
</main>
</body>
</html>
