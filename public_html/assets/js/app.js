/*
 * app.js — Comportamiento de la interfaz de Moscode Panel (vanilla, sin librerías).
 * Secciones: overlays (drawer, sheet, modal) · tema · toasts · confirmaciones ·
 *            formularios (carga, condicionales) · listas · gráficos · PWA
 */
(function () {
    'use strict';

    var raiz = document.documentElement;
    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
    // Versión central de los assets (viene en <meta name="app-version">)
    var meta = $('meta[name="app-version"]');
    var VERSION = meta ? meta.getAttribute('content') : '';
    // Carpeta del sitio (viene en <meta name="base-url">; vacía si está en la raíz del dominio)
    var metaBase = $('meta[name="base-url"]');
    var BASE = metaBase ? metaBase.getAttribute('content') : '';
    function rutaBase(ruta) { return BASE + ruta; }
    var SPRITE = rutaBase('/assets/img/iconos.svg') + (VERSION ? '?v=' + encodeURIComponent(VERSION) : '');
    var reducirMovimiento = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Overlays: drawer (sidebar en celular), sheet (acciones rápidas), modal ---------- */
    var overlays = {
        drawer: { el: function () { return $('#sidebar'); }, clase: 'ov-drawer' },
        sheet:  { el: function () { return $('#sheet-acciones'); }, clase: 'ov-sheet', esSheet: true },
        pago:   { el: function () { return $('#sheet-pago'); }, clase: 'ov-sheet', esSheet: true },
        modal:  { el: function () { return $('#modal-fondo'); }, clase: 'ov-modal' }
    };
    var abiertos = [];       // pila de nombres abiertos
    var focoPrevio = {};

    function enfocables(cont) {
        return $$('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])', cont)
            .filter(function (e) { return e.offsetParent !== null || e === document.activeElement; });
    }
    function actualizarScroll() {
        raiz.classList.toggle('sin-scroll', abiertos.length > 0);
    }
    function abrir(nombre, disparador) {
        var def = overlays[nombre];
        var el = def && def.el();
        if (!el || abiertos.indexOf(nombre) !== -1) { return; }
        if (nombre !== 'modal') {            // drawer y sheet no conviven
            ['drawer', 'sheet', 'pago'].forEach(function (otro) { if (otro !== nombre) { cerrar(otro); } });
        }
        focoPrevio[nombre] = disparador || document.activeElement;
        abiertos.push(nombre);
        raiz.classList.add(def.clase);
        if (def.esSheet) { el.classList.add('abierta'); }
        if (disparador && disparador.setAttribute) { disparador.setAttribute('aria-expanded', 'true'); }
        actualizarScroll();
        window.setTimeout(function () {
            var f = nombre === 'modal' ? $('[data-modal-ok]', el) : enfocables(el)[0];
            if (f) { f.focus({ preventScroll: true }); }
        }, 60);
    }
    function cerrar(nombre) {
        var i = abiertos.indexOf(nombre);
        if (i === -1) { return; }
        var def = overlays[nombre];
        abiertos.splice(i, 1);
        raiz.classList.remove(def.clase);
        var el = def.el();
        if (def.esSheet && el) { el.classList.remove('abierta'); }
        $$('[data-abrir="' + nombre + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        actualizarScroll();
        var f = focoPrevio[nombre];
        if (f && f.focus) { f.focus({ preventScroll: true }); }
    }

    document.addEventListener('click', function (ev) {
        var t = ev.target.closest ? ev.target : ev.target.parentElement;
        if (!t) { return; }
        var ab = t.closest('[data-abrir]');
        if (ab) {
            ev.preventDefault();
            var nom = ab.getAttribute('data-abrir');
            if (nom === 'pago') { prepararPago(ab); }
            abrir(nom, ab);
            return;
        }
        var ce = t.closest('[data-cerrar]');
        if (ce) { ev.preventDefault(); cerrar(ce.getAttribute('data-cerrar')); return; }
        if (t.closest('.scrim')) { cerrar('drawer'); cerrar('sheet'); cerrar('pago'); return; }
        // Al tocar un link dentro del drawer, se cierra (la navegación sigue)
        if (t.closest('#sidebar a')) { cerrar('drawer'); }
    });
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && abiertos.length) {
            ev.preventDefault();
            var ultimo = abiertos[abiertos.length - 1];
            if (ultimo === 'modal') { resolverConfirm(false); } else { cerrar(ultimo); }
            return;
        }
        if (ev.key === 'Tab' && abiertos.length) {      // el foco no se escapa del overlay abierto
            var cont = overlays[abiertos[abiertos.length - 1]].el();
            var lista = cont ? enfocables(cont) : [];
            if (!lista.length) { return; }
            var primero = lista[0], ultimoEl = lista[lista.length - 1];
            if (ev.shiftKey && document.activeElement === primero) { ev.preventDefault(); ultimoEl.focus(); }
            else if (!ev.shiftKey && document.activeElement === ultimoEl) { ev.preventDefault(); primero.focus(); }
        }
    });
    // Deslizar para cerrar: drawer hacia la izquierda, sheet hacia abajo
    (function () {
        var x0 = 0, y0 = 0, objetivo = null;
        document.addEventListener('touchstart', function (ev) {
            var t = ev.touches[0];
            objetivo = ev.target.closest ? ev.target.closest('#sidebar, #sheet-acciones, #sheet-pago') : null;
            x0 = t.clientX; y0 = t.clientY;
        }, { passive: true });
        document.addEventListener('touchend', function (ev) {
            if (!objetivo) { return; }
            var t = ev.changedTouches[0], dx = t.clientX - x0, dy = t.clientY - y0;
            if (objetivo.id === 'sidebar' && dx < -60 && Math.abs(dy) < 50) { cerrar('drawer'); }
            if (objetivo.id === 'sheet-acciones' && dy > 80 && Math.abs(dx) < 60 && objetivo.scrollTop <= 0) { cerrar('sheet'); }
            if (objetivo.id === 'sheet-pago' && dy > 80 && Math.abs(dx) < 60 && objetivo.scrollTop <= 0) { cerrar('pago'); }
            objetivo = null;
        }, { passive: true });
    })();
    // Si se agranda la ventana con el drawer abierto, se cierra
    window.matchMedia('(min-width: 1024px)').addEventListener('change', function (e) { if (e.matches) { cerrar('drawer'); } });

    /* ---------- Tema y sidebar colapsable ---------- */
    document.addEventListener('click', function (ev) {
        if (!ev.target.closest) { return; }
        if (ev.target.closest('[data-tema-toggle]') && window.moscodeTema) {
            window.moscodeTema.alternar();
            // La preferencia también se guarda en el usuario (sigue en otros dispositivos)
            var tk = document.querySelector('meta[name="csrf-token"]');
            if (tk && window.fetch) {
                var datos = new URLSearchParams({ tema: window.moscodeTema.actual(), csrf: tk.getAttribute('content') });
                window.fetch(rutaBase('/acciones/tema_guardar'), { method: 'POST', body: datos, credentials: 'same-origin' }).catch(function () {});
            }
        }
        if (ev.target.closest('[data-sidebar-colapsar]') && window.moscodeTema) {
            var min = window.moscodeTema.colapsarSidebar();
            $$('[data-sidebar-colapsar]').forEach(function (b) { b.setAttribute('aria-pressed', min ? 'true' : 'false'); });
        }
    });

    /* ---------- Aviso de renovación anual (servicio o dominio): el link de WhatsApp ya está en el href ----------
       (lo abre el clic normal); acá solo se registra que se mandó, por fetch (un <form> que termine en wa.me
       chocaría con la CSP). data-aviso-renovacion="servicio:3" o "dominio:5". */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('[data-aviso-renovacion]');
        if (!btn) { return; }
        var tk = document.querySelector('meta[name="csrf-token"]');
        if (!tk || !window.fetch) { return; }
        var clave = btn.getAttribute('data-aviso-renovacion');
        var partes = clave.split(':');
        var datos = new URLSearchParams({ tipo: partes[0], id: partes[1], csrf: tk.getAttribute('content') });
        window.fetch(rutaBase('/acciones/aviso_renovacion_enviar'), { method: 'POST', body: datos, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data || !data.ok) { return; }
                var estado = $('[data-aviso-estado="' + clave + '"]');
                if (estado) { estado.textContent = 'aviso enviado el ' + data.fecha; }
            })
            .catch(function () {});
    });

    /* ---------- Toasts (los mensajes flash del servidor llegan en #flash-data) ---------- */
    var iconoToast = { ok: 'circle-check', error: 'circle-alert', aviso: 'triangle-alert' };
    function toast(tipo, mensaje) {
        var cont = $('#toasts');
        if (!cont) { return; }
        tipo = iconoToast[tipo] ? tipo : 'ok';
        var el = document.createElement('div');
        el.className = 'toast ' + tipo;
        el.setAttribute('role', tipo === 'error' ? 'alert' : 'status');
        el.innerHTML = '<svg class="ico" aria-hidden="true"><use href="' + SPRITE + '#' + iconoToast[tipo] + '"></use></svg>'
            + '<div class="toast-txt"></div>'
            + '<button type="button" class="toast-x" aria-label="Cerrar"><svg class="ico chico" aria-hidden="true"><use href="' + SPRITE + '#x"></use></svg></button>';
        el.querySelector('.toast-txt').textContent = mensaje;     // textContent: nunca interpreta HTML
        cont.appendChild(el);
        var quitar = function () {
            if (!el.parentNode) { return; }
            el.classList.add('saliendo');
            window.setTimeout(function () { if (el.parentNode) { el.parentNode.removeChild(el); } }, reducirMovimiento ? 0 : 220);
        };
        el.querySelector('.toast-x').addEventListener('click', quitar);
        var espera = tipo === 'error' ? 10000 : (mensaje.length > 120 ? 9000 : 5000);
        var timer = window.setTimeout(quitar, espera);
        el.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
    }
    window.moscodeToast = toast;
    // Aviso persistente con botón (ej. nueva versión de la app)
    function toastAccion(mensaje, textoBoton, alHacerClic) {
        var cont = $('#toasts');
        if (!cont) { return; }
        var el = document.createElement('div');
        el.className = 'toast aviso';
        el.setAttribute('role', 'status');
        el.innerHTML = '<svg class="ico" aria-hidden="true"><use href="' + SPRITE + '#refresh-cw"></use></svg><div class="toast-txt"></div><button type="button" class="btn chico"></button>';
        el.querySelector('.toast-txt').textContent = mensaje;
        var b = el.querySelector('button');
        b.textContent = textoBoton;
        b.addEventListener('click', alHacerClic);
        cont.appendChild(el);
    }
    $$('#flash-data [data-tipo]').forEach(function (n) { toast(n.getAttribute('data-tipo'), n.textContent); });

    /* ---------- Confirmaciones con modal propio (reemplaza confirm()) ---------- */
    var pendiente = null;
    function mostrarConfirm(form, submitter) {
        var fondo = $('#modal-fondo');
        if (!fondo) { if (window.confirm(form.getAttribute('data-confirmar'))) { enviar(form, submitter); } return; }
        pendiente = { form: form, submitter: submitter };
        $('[data-modal-titulo]', fondo).textContent = form.getAttribute('data-confirmar-titulo') || '¿Confirmás?';
        $('[data-modal-texto]', fondo).textContent = form.getAttribute('data-confirmar');
        var ok = $('[data-modal-ok]', fondo);
        var peligro = (submitter && submitter.classList.contains('peligro')) || form.hasAttribute('data-confirmar-peligro');
        ok.classList.toggle('peligro', !!peligro);
        ok.textContent = form.getAttribute('data-confirmar-boton') || (peligro ? 'Sí, continuar' : 'Confirmar');
        abrir('modal', submitter || document.activeElement);
    }
    function resolverConfirm(acepto) {
        var p = pendiente;
        pendiente = null;
        cerrar('modal');
        if (acepto && p) { enviar(p.form, p.submitter); }
    }
    function enviar(form, submitter) {
        form.__confirmado = true;
        if (form.requestSubmit) {
            try { form.requestSubmit(submitter && submitter.form === form ? submitter : undefined); return; } catch (e) { /* cae al submit común */ }
        }
        form.submit();
    }
    document.addEventListener('click', function (ev) {
        if (!ev.target.closest) { return; }
        if (ev.target.closest('[data-modal-ok]')) { resolverConfirm(true); }
        if (ev.target.closest('[data-modal-cancelar]') || ev.target.id === 'modal-fondo') { resolverConfirm(false); }
    });

    /* ---------- Formularios: validación con el error debajo de cada campo (data-validar) ---------- */
    function mensajeError(c) {
        var v = c.validity;
        if (v.valueMissing) { return c.type === 'radio' || c.tagName === 'SELECT' ? 'Elegí una opción.' : 'Completá este campo.'; }
        if (v.typeMismatch) { return c.type === 'email' ? 'Ingresá un email válido (ej. nombre@dominio.com).' : 'El formato no es válido.'; }
        if (v.rangeUnderflow) { return 'El valor mínimo es ' + c.min + '.'; }
        if (v.rangeOverflow) { return 'El valor máximo es ' + c.max + '.'; }
        if (v.stepMismatch) { return 'Usá hasta 2 decimales.'; }
        if (v.tooLong) { return 'Es demasiado largo (máx. ' + c.maxLength + ' caracteres).'; }
        return c.validationMessage || 'Revisá este campo.';
    }
    function limpiarError(c) {
        var l = c.closest('label');
        if (l) {
            l.classList.remove('con-error');
            var e = l.querySelector('.campo-error');
            if (e) { e.remove(); }
        }
    }
    function mostrarErrores(form) {
        var primero = null;
        $$('input, select, textarea', form).forEach(function (c) {
            limpiarError(c);
            if (c.willValidate && !c.validity.valid) {
                var l = c.closest('label');
                if (l) {
                    var s = document.createElement('span');
                    s.className = 'campo-error';
                    s.setAttribute('role', 'alert');
                    s.textContent = mensajeError(c);
                    l.classList.add('con-error');
                    l.appendChild(s);
                }
                if (!primero) { primero = c; }
            }
        });
        if (primero) { primero.focus({ preventScroll: true }); primero.scrollIntoView({ block: 'center', behavior: reducirMovimiento ? 'auto' : 'smooth' }); }
    }
    document.addEventListener('input', function (ev) { if (ev.target.closest && ev.target.closest('form[data-validar]')) { limpiarError(ev.target); } });
    document.addEventListener('change', function (ev) { if (ev.target.closest && ev.target.closest('form[data-validar]')) { limpiarError(ev.target); } });

    /* ---------- Formularios: confirmación + estado de carga (evita dobles envíos) ---------- */
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form || form.tagName !== 'FORM') { return; }
        if (form.hasAttribute('data-validar') && !form.checkValidity()) {
            ev.preventDefault();
            mostrarErrores(form);
            return;
        }
        if (form.hasAttribute('data-confirmar') && !form.__confirmado) {
            ev.preventDefault();
            mostrarConfirm(form, ev.submitter);
            return;
        }
        if ((form.method || 'get').toLowerCase() === 'get') { return; }
        if (form.__enviando) { ev.preventDefault(); return; }
        form.__enviando = true;
        window.setTimeout(function () {       // después del envío, para no perder el valor del botón
            $$('button[type="submit"], input[type="submit"]', form).forEach(function (b) { b.classList.add('cargando'); b.disabled = true; });
        }, 0);
    });
    window.addEventListener('pageshow', function () {   // al volver con el botón "atrás"
        $$('form').forEach(function (f) { f.__enviando = false; f.__confirmado = false; });
        $$('button.cargando').forEach(function (b) { b.classList.remove('cargando'); b.disabled = false; });
    });

    /* ---------- Campos condicionales con transición: data-mostrar-si="campo=valor" ---------- */
    var condicionales = [];
    $$('[data-mostrar-si]').forEach(function (el) {
        var envoltorio = document.createElement('div');
        var interior = document.createElement('div');
        envoltorio.className = 'condicional';
        interior.className = 'condicional-in';
        el.parentNode.insertBefore(envoltorio, el);
        interior.appendChild(el);
        envoltorio.appendChild(interior);
        el.hidden = false;
        condicionales.push({ el: el, w: envoltorio, in: interior });
    });
    function actualizarCondicionales(inicial) {
        condicionales.forEach(function (c) {
            var partes = c.el.getAttribute('data-mostrar-si').split('=');
            var campo = $('[name="' + partes[0] + '"][type="radio"]:checked') || $('[name="' + partes[0] + '"]');
            if (!campo) { return; }
            var valor = campo.type === 'checkbox' ? (campo.checked ? '1' : '0') : campo.value;
            var abierto = valor === partes[1];
            if (inicial) { c.w.style.transition = 'none'; }
            c.w.classList.toggle('abierto', abierto);
            c.in.inert = !abierto;
            c.in.setAttribute('aria-hidden', abierto ? 'false' : 'true');
            if (inicial) { window.requestAnimationFrame(function () { c.w.style.transition = ''; }); }
        });
    }
    document.addEventListener('change', function () { actualizarCondicionales(false); });
    actualizarCondicionales(true);

    /* ---------- Sheet "Registrar pago": monto sugerido según la deuda del cliente ---------- */
    function dineroInput(n) { return (Math.round(n * 100) / 100).toFixed(2); }
    function actualizarPago(reiniciar) {
        var sel = $('[data-pago-cliente]');
        if (!sel) { return; }
        var opt = sel.options[sel.selectedIndex];
        var monto = $('[data-pago-monto]');
        var debe = $('[data-pago-debe]');
        var ars = opt ? parseFloat(opt.getAttribute('data-ars')) || 0 : 0;
        var usd = opt ? parseFloat(opt.getAttribute('data-usd')) || 0 : 0;
        var radios = $$('[data-pago-moneda]');
        if (reiniciar) {            // al elegir cliente, la moneda sugerida es donde tiene deuda
            var sugMoneda = (ars > 0.004 || usd <= 0.004) ? 'ARS' : 'USD';
            radios.forEach(function (r) { r.checked = r.value === sugMoneda; });
            monto.removeAttribute('data-tocado');
        }
        var marcado = radios.filter(function (r) { return r.checked; })[0];
        var moneda = marcado ? marcado.value : 'ARS';
        if (debe) { debe.textContent = opt && opt.value ? opt.getAttribute('data-debe') : ''; }
        if (!monto.hasAttribute('data-tocado')) {
            var sugerido = moneda === 'USD' ? usd : ars;
            monto.value = sugerido > 0.004 ? dineroInput(sugerido) : '';
        }
        var av = $('[data-pago-avanzado]');
        if (av && opt && opt.value) { av.href = rutaBase('/pagos/nuevo') + '?cliente_id=' + encodeURIComponent(opt.value); }
    }
    function prepararPago(disparador) {
        var sel = $('[data-pago-cliente]');
        if (!sel) { return; }
        var id = disparador && disparador.getAttribute('data-cliente');
        sel.value = id || '';
        actualizarPago(true);
        window.setTimeout(function () { var f = id ? $('[data-pago-monto]') : sel; if (f) { f.focus({ preventScroll: true }); } }, 380);
    }
    document.addEventListener('change', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-pago-cliente]')) { actualizarPago(true); }
        if (ev.target.matches && ev.target.matches('[data-pago-moneda]')) { actualizarPago(false); }
    });
    document.addEventListener('input', function (ev) {
        if (ev.target.matches && ev.target.matches('[data-pago-monto]')) { ev.target.setAttribute('data-tocado', '1'); }
    });

    /* ---------- Pestañas: <div data-tabs="id"> con .tab[data-tab] y [data-panel] ---------- */
    $$('[data-tabs]').forEach(function (cont) {
        var clave = 'tab-' + cont.getAttribute('data-tabs');
        var tabs = $$('.tab', cont), paneles = $$('[data-panel]', cont);
        cont.classList.add('tabs-js');
        function mostrar(nombre, guardar) {
            if (!paneles.some(function (p) { return p.getAttribute('data-panel') === nombre; })) { nombre = tabs[0].getAttribute('data-tab'); }
            tabs.forEach(function (t) {
                var on = t.getAttribute('data-tab') === nombre;
                t.classList.toggle('activo', on);
                t.setAttribute('aria-selected', on ? 'true' : 'false');
                t.tabIndex = on ? 0 : -1;
            });
            paneles.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== nombre; });
            if (guardar) { try { window.sessionStorage.setItem(clave, nombre); } catch (e) { /* sin storage */ } }
        }
        var inicial = (window.location.hash || '').replace('#', '').replace('tab-', '');
        if (!inicial) { try { inicial = window.sessionStorage.getItem(clave) || ''; } catch (e) { inicial = ''; } }
        mostrar(inicial || tabs[0].getAttribute('data-tab'), false);
        tabs.forEach(function (t, i) {
            t.addEventListener('click', function () { mostrar(t.getAttribute('data-tab'), true); });
            t.addEventListener('keydown', function (ev) {
                var d = ev.key === 'ArrowRight' ? 1 : ev.key === 'ArrowLeft' ? -1 : 0;
                if (!d) { return; }
                var sig = tabs[(i + d + tabs.length) % tabs.length];
                mostrar(sig.getAttribute('data-tab'), true);
                sig.focus();
            });
        });
        $$('[data-tab-ir]').forEach(function (a) {
            a.addEventListener('click', function (ev) {
                ev.preventDefault();
                mostrar(a.getAttribute('data-tab-ir'), true);
                cont.scrollIntoView({ behavior: reducirMovimiento ? 'auto' : 'smooth', block: 'start' });
            });
        });
    });

    /* ---------- Listas y utilidades ---------- */
    // Filas/cards clickeables: <tr data-href="...">
    document.addEventListener('click', function (ev) {
        var fila = ev.target.closest && ev.target.closest('[data-href]');
        if (!fila || ev.target.closest('a, button, input, select, textarea, label, form')) { return; }
        window.location.href = fila.getAttribute('data-href');
    });
    // Buscador que filtra al dejar de escribir: <form data-autoenviar>
    $$('form[data-autoenviar]').forEach(function (form) {
        var campo = $('input[type="search"]', form);
        if (!campo) { return; }
        var timer = null, valorInicial = campo.value;
        try {
            if (window.sessionStorage.getItem('foco-busqueda') === '1') {
                window.sessionStorage.removeItem('foco-busqueda');
                campo.focus(); campo.setSelectionRange(campo.value.length, campo.value.length);
            }
        } catch (e) { /* sin storage */ }
        campo.addEventListener('input', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                if (campo.value === valorInicial) { return; }
                try { window.sessionStorage.setItem('foco-busqueda', '1'); } catch (e) { /* sin storage */ }
                form.submit();
            }, 450);
        });
    });
    // Acordeones: en escritorio se muestran todos abiertos; en celular, solo los marcados con "open"
    if (window.matchMedia('(min-width: 1024px)').matches) {
        $$('details[data-abierto-escritorio]').forEach(function (d) { d.open = true; });
    }
    // Campos que envían su formulario (GET) al cambiar: data-enviar-al-cambiar
    document.addEventListener('change', function (ev) {
        if (ev.target.hasAttribute && ev.target.hasAttribute('data-enviar-al-cambiar') && ev.target.form) { ev.target.form.submit(); }
    });
    // "Marcar todos": data-marcar-todos="nombre[]"
    document.addEventListener('change', function (ev) {
        var nombre = ev.target.getAttribute && ev.target.getAttribute('data-marcar-todos');
        if (!nombre) { return; }
        $$('input[type="checkbox"][name="' + nombre + '"]').forEach(function (c) { c.checked = ev.target.checked; });
    });
    // Imprimir / PDF: data-imprimir
    document.addEventListener('click', function (ev) {
        if (ev.target.closest && ev.target.closest('[data-imprimir]')) { window.print(); }
    });
    // Campos de solo lectura con links: se seleccionan al tocarlos
    document.addEventListener('focusin', function (ev) {
        if (ev.target.hasAttribute && ev.target.hasAttribute('data-seleccionar')) { ev.target.select(); }
    });
    // Copiar al portapapeles: data-copiar="texto"
    document.addEventListener('click', function (ev) {
        var b = ev.target.closest && ev.target.closest('[data-copiar]');
        if (!b || !navigator.clipboard) { return; }
        navigator.clipboard.writeText(b.getAttribute('data-copiar')).then(function () { toast('ok', 'Copiado al portapapeles'); });
    });

    /* ---------- Gráficos (Chart.js por CDN), con los colores del tema activo ---------- */
    var graficos = [];
    function css(variable) { return getComputedStyle(raiz).getPropertyValue(variable).trim(); }
    function dibujarGraficos() {
        graficos.forEach(function (g) { g.destroy(); });
        graficos = [];
        if (!window.Chart) { return; }           // si el CDN no cargó, quedan las tablas
        var paleta = [css('--accent'), css('--warn'), css('--info'), css('--bad')];
        window.Chart.defaults.color = css('--text-2');
        window.Chart.defaults.borderColor = css('--border');
        window.Chart.defaults.font.family = css('--font');
        $$('canvas[data-grafico]').forEach(function (cv) {
            var cfg;
            try { cfg = JSON.parse(cv.getAttribute('data-grafico')); } catch (e) { return; }
            graficos.push(new window.Chart(cv, {
                type: cfg.tipo || 'bar',
                data: {
                    labels: cfg.etiquetas,
                    datasets: cfg.series.map(function (s, i) {
                        return { label: s.nombre, data: s.datos, backgroundColor: paleta[i % paleta.length], borderRadius: 4 };
                    })
                },
                options: {
                    responsive: true, maintainAspectRatio: false, animation: false,
                    scales: { x: { stacked: !!cfg.apilado, grid: { display: false } }, y: { stacked: !!cfg.apilado, beginAtZero: true } },
                    plugins: { legend: { display: cfg.series.length > 1 } }
                }
            }));
        });
    }
    window.addEventListener('load', dibujarGraficos);
    document.addEventListener('tema-cambio', dibujarGraficos);

    /* ---------- PWA: service worker mínimo (instalable + pantalla sin conexión) ---------- */
    if ('serviceWorker' in navigator && (window.location.protocol === 'https:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1')) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(rutaBase('/sw.js'), { scope: rutaBase('/'), updateViaCache: 'none' }).then(function (reg) {
                // Al volver a abrir la app (celular), buscar si hay una versión nueva
                document.addEventListener('visibilitychange', function () {
                    if (document.visibilityState === 'visible') { reg.update().catch(function () {}); }
                });
            }).catch(function () { /* sin SW la app funciona igual */ });
        });
        // El SW nuevo avisa su versión; si esta pantalla es de una versión anterior, se ofrece recargar
        navigator.serviceWorker.addEventListener('message', function (ev) {
            if (ev.data && ev.data.tipo === 'version' && VERSION && ev.data.version !== VERSION) {
                toastAccion('Hay una versión nueva de la app.', 'Actualizar', function () { window.location.reload(); });
            }
        });
    }
})();

/* ---------- Planes en cuotas: la suma de las cuotas editadas tiene que dar el total ---------- */
(function () {
    'use strict';
    var caja = document.querySelector('[data-suma-cuotas]');
    if (!caja) { return; }
    var total = parseFloat(caja.getAttribute('data-total')) || 0;
    var estado = caja.querySelector('[data-suma-estado]');
    var campos = caja.querySelectorAll('[data-suma-cuota]');
    function fmt(n) { return n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function recalcular() {
        var suma = 0;
        campos.forEach(function (c) { suma += parseFloat(c.value) || 0; });
        var dif = Math.round((total - suma) * 100) / 100;
        if (Math.abs(dif) < 0.005) {
            estado.textContent = 'Suma: ' + fmt(suma) + ' — coincide con el total.';
            estado.classList.remove('error');
        } else {
            estado.textContent = 'Suma: ' + fmt(suma) + ' — ' + (dif > 0 ? 'faltan ' : 'sobran ') + fmt(Math.abs(dif)) + ' para llegar al total.';
            estado.classList.add('error');
        }
    }
    campos.forEach(function (c) { c.addEventListener('input', recalcular); });
    recalcular();
})();

/* ---------- Servicio por cantidad: el monto se previsualiza solo (cantidad × precio por unidad) ---------- */
(function () {
    'use strict';
    var caja = document.querySelector('[data-calc-cantidad]');
    if (!caja) { return; }
    var resultado = caja.querySelector('[data-calc-resultado]');
    var campos = caja.querySelectorAll('[data-calc-factor]');
    function recalcular() {
        var n = 1;
        campos.forEach(function (c) { n *= parseFloat(c.value) || 0; });
        resultado.textContent = n.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    campos.forEach(function (c) { c.addEventListener('input', recalcular); });
    recalcular();
})();

/* ---------- Sin estilos en línea (CSP): barras de progreso y QR del 2FA; y recarga al volver con "atrás" ---------- */
(function () {
    'use strict';
    // Barras: el ancho viaja en data-ancho y se aplica por CSSOM (permitido por la CSP; un style="" no)
    Array.prototype.forEach.call(document.querySelectorAll('[data-ancho]'), function (el) {
        var n = parseInt(el.getAttribute('data-ancho'), 10) || 0;
        el.style.width = Math.max(0, Math.min(100, n)) + '%';
    });
    // QR de la verificación en dos pasos: se dibuja acá, con una librería local (assets/vendor/qrcode); nada sale del panel
    Array.prototype.forEach.call(document.querySelectorAll('canvas[data-qr]'), function (cv) {
        if (typeof qrcode !== 'function') { return; }
        var qr = qrcode(0, 'M');
        qr.addData(cv.getAttribute('data-qr'));
        qr.make();
        var n = qr.getModuleCount(), margen = 4, px = 8;
        cv.width = cv.height = (n + 2 * margen) * px;
        var ctx = cv.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cv.width, cv.height);
        ctx.fillStyle = '#000';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) { ctx.fillRect((c + margen) * px, (r + margen) * px, px, px); }
            }
        }
    });
    // Si el navegador mostró una página privada desde su caché de "atrás/adelante" (bfcache), se vuelve a pedir:
    // así, después de cerrar sesión, "atrás" no muestra datos del usuario anterior.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted && document.querySelector('meta[name="csrf-token"]')) { window.location.reload(); }
    });
})();
