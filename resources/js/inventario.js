import './echo';

const tbody = document.getElementById('productos');
const log = document.getElementById('log');
const errorBox = document.getElementById('error');
const conexion = document.getElementById('conexion');
const productos = new Map();

const api = async (url, opciones = {}) => {
    const respuesta = await fetch(url, {
        ...opciones,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    });
    const cuerpo = respuesta.status === 204 ? null : await respuesta.json();
    if (!respuesta.ok) throw new Error(cuerpo?.message ?? `HTTP ${respuesta.status}`);
    return cuerpo;
};

const formatoPrecio = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });

function pintarFila(p, resaltar = false) {
    let fila = document.getElementById(`p-${p.id}`);
    if (!fila) {
        fila = document.createElement('tr');
        fila.id = `p-${p.id}`;
        tbody.appendChild(fila);
    }
    fila.innerHTML = `
        <td>${p.id}</td>
        <td></td>
        <td class="num">${formatoPrecio.format(p.precio)}</td>
        <td class="num"><b>${p.stock}</b></td>
        <td>
            <button data-id="${p.id}" data-cant="-1">−1</button>
            <button data-id="${p.id}" data-cant="1">+1</button>
            <button data-id="${p.id}" data-cant="10">+10</button>
        </td>`;
    fila.children[1].textContent = p.nombre; // textContent evita inyectar HTML
    if (resaltar) {
        fila.classList.remove('flash');
        void fila.offsetWidth;
        fila.classList.add('flash');
    }
}

async function cargar() {
    const lista = await api('/api/productos');
    tbody.innerHTML = '';
    lista.forEach((p) => { productos.set(p.id, p); pintarFila(p); });
}

// Botones de stock -> REST PATCH /api/productos/{id}/stock
tbody.addEventListener('click', async (e) => {
    const boton = e.target.closest('button[data-id]');
    if (!boton) return;
    errorBox.textContent = '';
    try {
        await api(`/api/productos/${boton.dataset.id}/stock`, {
            method: 'PATCH',
            body: JSON.stringify({ cantidad: Number(boton.dataset.cant) }),
        });
        // No actualizamos la fila acá: la actualización llega por WebSocket, igual que en los demás navegadores.
    } catch (err) {
        errorBox.textContent = err.message;
    }
});

// Alta -> REST POST /api/productos
document.getElementById('nuevo').addEventListener('submit', async (e) => {
    e.preventDefault();
    errorBox.textContent = '';
    const datos = Object.fromEntries(new FormData(e.target));
    try {
        await api('/api/productos', {
            method: 'POST',
            body: JSON.stringify({ nombre: datos.nombre, precio: Number(datos.precio), stock: Number(datos.stock) }),
        });
        e.target.reset();
    } catch (err) {
        errorBox.textContent = err.message;
    }
});

// Suscripción al canal público
window.Echo.channel('inventario').listen('.producto.actualizado', (evento) => {
    console.log('Producto actualizado:', evento);
    const { accion, producto } = evento;

    if (accion === 'eliminado') {
        productos.delete(producto.id);
        document.getElementById(`p-${producto.id}`)?.remove();
    } else {
        productos.set(producto.id, producto);
        pintarFila(producto, true);
    }

    const linea = `[${new Date().toLocaleTimeString()}] ${accion}: #${producto.id} ${producto.nombre} → stock ${producto.stock}`;
    log.textContent = log.textContent.startsWith('Esperando') ? linea : `${linea}\n${log.textContent}`;
});

const pusher = window.Echo.connector.pusher;
pusher.connection.bind('state_change', ({ current }) => {
    conexion.textContent = current === 'connected' ? 'conectado' : current;
    conexion.className = current === 'connected' ? 'on' : 'off';
});

cargar().catch((err) => { errorBox.textContent = `No se pudo cargar el inventario: ${err.message}`; });
