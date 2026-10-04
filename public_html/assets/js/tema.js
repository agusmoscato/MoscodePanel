/*
 * tema.js — Se carga en el <head>, SIN defer, para aplicar el tema y el estado del sidebar
 * antes del primer pintado (evita el parpadeo de tema equivocado).
 * Preferencia: localStorage 'tema' ('dark' | 'light'); si no hay, la del sistema; si no se sabe, oscuro.
 * También aplica el "ojito" (montos ocultos) antes de pintar: ver más abajo.
 */
(function () {
    'use strict';
    var raiz = document.documentElement;
    var COLORES = { dark: '#141517', light: '#F3F4F6' };

    function leer(clave) {
        try { return window.localStorage.getItem(clave); } catch (e) { return null; }
    }
    function escribir(clave, valor) {
        try { window.localStorage.setItem(clave, valor); } catch (e) { /* modo privado: no se guarda */ }
    }
    function delSistema() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
    }
    function aplicar(tema) {
        raiz.setAttribute('data-theme', tema);
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', COLORES[tema]); }
        document.dispatchEvent(new CustomEvent('tema-cambio', { detail: tema }));
    }

    /* "Ojito" (ocultar montos): también antes del primer pintado, para que los números no se vean ni un instante.
       Preferencia del dispositivo: localStorage 'montos-ocultos' ('1' | '0'). Si el usuario eligió en Mi cuenta
       "Ocultar montos al abrir la app" (data-montos-al-abrir="1" en <html>, solo en las pantallas del panel), la
       primera pantalla de cada apertura arranca oculta. "Apertura" = pestaña o app nueva: se marca en sessionStorage,
       que dura mientras la pestaña/app sigue abierta. El portal del cliente no carga este archivo. */
    var alAbrir = raiz.getAttribute('data-montos-al-abrir');
    var ocultos = leer('montos-ocultos') === '1';
    if (alAbrir === null) {
        // Fuera del panel (login, 404 sin sesión): la próxima pantalla del panel cuenta como una apertura nueva
        try { window.sessionStorage.removeItem('montos-sesion'); } catch (e) { /* sin storage */ }
    } else {
        var apertura = true;          // sin sessionStorage, cada pantalla cuenta como apertura (lo más seguro)
        try {
            apertura = window.sessionStorage.getItem('montos-sesion') !== '1';
            window.sessionStorage.setItem('montos-sesion', '1');
        } catch (e) { apertura = true; }
        if (alAbrir === '1' && apertura) {
            ocultos = true;
            escribir('montos-ocultos', '1');
        }
    }
    if (ocultos) { raiz.classList.add('montos-ocultos'); }
    window.moscodeMontos = {
        ocultos: function () { return raiz.classList.contains('montos-ocultos'); },
        alternar: function () {
            var oc = raiz.classList.toggle('montos-ocultos');
            escribir('montos-ocultos', oc ? '1' : '0');
            document.dispatchEvent(new CustomEvent('montos-cambio', { detail: oc }));
            return oc;
        }
    };

    var guardado = leer('tema') || raiz.getAttribute('data-tema-usuario');
    var actual = (guardado === 'dark' || guardado === 'light') ? guardado : delSistema();
    raiz.setAttribute('data-theme', actual);
    if (leer('sidebar-min') === '1') { raiz.classList.add('sidebar-min'); }

    window.moscodeTema = {
        actual: function () { return raiz.getAttribute('data-theme'); },
        alternar: function () {
            var nuevo = this.actual() === 'light' ? 'dark' : 'light';
            escribir('tema', nuevo);
            aplicar(nuevo);
        },
        colapsarSidebar: function () {
            var min = raiz.classList.toggle('sidebar-min');
            escribir('sidebar-min', min ? '1' : '0');
            return min;
        }
    };

    // Si el usuario nunca eligió, acompañar los cambios del sistema
    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: light)');
        var alCambiar = function () { if (!leer('tema')) { aplicar(delSistema()); } };
        if (mq.addEventListener) { mq.addEventListener('change', alCambiar); }
    }
    // El <meta theme-color> se crea antes de este script; si no existiera aún, se ajusta al cargar
    document.addEventListener('DOMContentLoaded', function () {
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) { meta.setAttribute('content', COLORES[raiz.getAttribute('data-theme')]); }
    });
})();
