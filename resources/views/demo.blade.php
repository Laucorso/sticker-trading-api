<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demo · Intercambio de cromos</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#1d2330; --muted:#6b7280; --accent:#1f6feb; --ok:#1a7f37; --err:#cf222e; }
        * { box-sizing: border-box; }
        body { margin:0; font: 15px/1.45 system-ui, -apple-system, Segoe UI, sans-serif; background:var(--bg); color:var(--ink); }
        header { padding:16px 24px; background:var(--ink); color:#fff; display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
        header h1 { font-size:18px; margin:0; }
        header a { color:#c9d1d9; }
        header .me { margin-left:auto; font-weight:600; }
        #status { font-size:13px; padding:2px 8px; border-radius:999px; background:#444; }
        #status.on { background:var(--ok); }
        main { display:grid; grid-template-columns: 1fr 1.4fr; gap:16px; padding:16px 24px; }
        section { background:var(--card); border-radius:10px; padding:16px; box-shadow:0 1px 2px rgba(0,0,0,.06); }
        h2 { font-size:15px; margin:0 0 12px; }
        table { width:100%; border-collapse:collapse; }
        td, th { text-align:left; padding:6px 4px; border-bottom:1px solid #eee; font-size:14px; }
        th { color:var(--muted); font-weight:500; }
        button { border:0; border-radius:6px; padding:6px 10px; background:var(--accent); color:#fff; cursor:pointer; font:inherit; font-size:13px; }
        button.secondary { background:#e5e7eb; color:var(--ink); }
        select { padding:5px; font:inherit; }
        form { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:16px; }
        .muted { color:var(--muted); font-size:13px; }
        #toasts { position:fixed; right:16px; bottom:16px; display:flex; flex-direction:column; gap:8px; z-index:10; }
        .toast { background:var(--ink); color:#fff; padding:12px 16px; border-radius:8px; max-width:360px; box-shadow:0 6px 20px rgba(0,0,0,.25); animation: in .2s ease-out; }
        .toast.ok { background:var(--ok); } .toast.err { background:var(--err); }
        @keyframes in { from { transform:translateY(10px); opacity:0; } }
        @media (max-width: 800px) { main { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<header>
    <h1>Intercambio de cromos</h1>
    <span class="muted" style="color:#c9d1d9">Abre otra pestaña con otro usuario:
        @foreach ([1, 2, 3, 4] as $u)
            <a href="/demo?user={{ $u }}" target="_blank">?user={{ $u }}</a>
        @endforeach
    </span>
    <span class="me" id="me">Usuario {{ (int) request('user', 1) }}</span>
    <span id="status">websocket desconectado</span>
</header>

<main>
    <section>
        <h2>Mi colección</h2>
        <table>
            <thead><tr><th>Cromo</th><th>Copias</th><th>Reservadas</th><th>Intercambiables</th></tr></thead>
            <tbody id="collection"></tbody>
        </table>
    </section>

    <section>
        <h2>Publicar oferta</h2>
        <form id="publish">
            Doy <select id="offered"></select>
            a cambio de <select id="requested"></select>
            <button>Publicar</button>
        </form>

        <h2>Ofertas abiertas <button class="secondary" id="refresh" type="button">Actualizar</button></h2>
        <table>
            <thead><tr><th>#</th><th>Autor</th><th>Da</th><th>Pide</th><th>Caduca</th><th></th></tr></thead>
            <tbody id="offers"></tbody>
        </table>
        <p class="muted">El listado se refresca cada 5 s. El aviso de aceptación llega por websocket al instante.</p>
    </section>
</main>
<div id="toasts"></div>

<script src="https://cdn.jsdelivr.net/npm/pusher-js@8.4.0/dist/web/pusher.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/laravel-echo@2.2.0/dist/echo.iife.js"></script>
<script>
    const USER = {{ (int) request('user', 1) }};
    const headers = { 'X-User-Id': String(USER), 'Accept': 'application/json', 'Content-Type': 'application/json' };
    const api = async (method, path, body) => {
        const res = await fetch('/api' + path, { method, headers, body: body ? JSON.stringify(body) : undefined });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(json.message || res.statusText);
        return json;
    };
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const toast = (text, kind = '') => {
        const el = document.createElement('div');
        el.className = 'toast ' + kind; el.textContent = text;
        $('toasts').appendChild(el); setTimeout(() => el.remove(), 6000);
    };

    let stickers = [];

    async function loadCollection() {
        const { data } = await api('GET', `/users/${USER}/collection`);
        $('collection').innerHTML = data.map(s =>
            `<tr><td>${esc(s.name)}</td><td>${s.quantity}</td><td>${s.reserved}</td><td>${s.tradeable}</td></tr>`).join('')
            || '<tr><td colspan="4" class="muted">Sin cromos</td></tr>';
        $('offered').innerHTML = data.filter(s => s.tradeable > 0)
            .map(s => `<option value="${s.sticker_id}">${esc(s.name)} (${s.tradeable})</option>`).join('')
            || '<option value="">No tienes repetidos libres</option>';
    }

    async function loadOffers() {
        const { data } = await api('GET', '/offers?per_page=50');
        $('offers').innerHTML = data.map(o => `<tr>
            <td>${o.id}</td><td>${esc(o.author.name)}</td><td>${esc(o.offered_sticker.name)}</td><td>${esc(o.requested_sticker.name)}</td>
            <td class="muted">${new Date(o.expires_at).toLocaleString()}</td>
            <td>${o.author.id === USER
                ? `<button class="secondary" onclick="act('withdraw', ${o.id})">Retirar</button>`
                : `<button onclick="act('accept', ${o.id})">Aceptar</button>`}</td></tr>`).join('')
            || '<tr><td colspan="6" class="muted">No hay ofertas abiertas</td></tr>';
    }

    const refresh = () => Promise.all([loadCollection(), loadOffers()]).catch(e => toast(e.message, 'err'));

    async function act(action, id) {
        try {
            await api('POST', `/offers/${id}/${action}`);
            toast(action === 'accept' ? `Has aceptado la oferta #${id}` : `Oferta #${id} retirada`, 'ok');
        } catch (e) { toast(e.message, 'err'); }
        refresh();
    }

    $('publish').addEventListener('submit', async (e) => {
        e.preventDefault();
        try {
            const { data } = await api('POST', '/offers', {
                offered_sticker_id: Number($('offered').value),
                requested_sticker_id: Number($('requested').value),
            });
            toast(`Oferta #${data.id} publicada`, 'ok');
        } catch (err) { toast(err.message, 'err'); }
        refresh();
    });
    $('refresh').addEventListener('click', refresh);

    (async () => {
        stickers = (await api('GET', '/stickers')).data;
        $('requested').innerHTML = stickers.map(s => `<option value="${s.id}">${esc(s.name)}</option>`).join('');
        refresh();
        setInterval(loadOffers, 5000);
    })();

    // Tiempo real: canal privado del usuario, autorizado con la misma cabecera X-User-Id.
    const EchoClass = window.Echo.default ?? window.Echo; // el build IIFE expone el módulo
    const echo = new EchoClass({
        broadcaster: 'reverb',
        key: @json(config('services.reverb_client.key')),
        wsHost: @json(config('services.reverb_client.host')),
        wsPort: @json(config('services.reverb_client.port')),
        forceTLS: false,
        enabledTransports: ['ws'],
        authEndpoint: '/api/broadcasting/auth',
        auth: { headers },
    });
    echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        $('status').textContent = 'websocket ' + (current === 'connected' ? 'conectado' : current);
        $('status').className = current === 'connected' ? 'on' : '';
    });
    echo.private(`users.${USER}`)
        .listen('.offer.accepted', (e) => {
            const name = stickers.find(s => s.id === e.received_sticker_id)?.name ?? e.received_sticker_id;
            toast(`🎉 El usuario ${e.accepted_by} ha aceptado tu oferta #${e.offer_id}. Recibes ${name}.`, 'ok');
            refresh();
        })
        .listen('.offer.expired', (e) => {
            toast(`Tu oferta #${e.offer_id} ha caducado; el cromo vuelve a estar libre.`);
            refresh();
        });
</script>
</body>
</html>
