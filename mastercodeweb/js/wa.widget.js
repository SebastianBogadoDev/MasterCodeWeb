/**
 * wa-widget.js — WhatsApp Widget flotante (nuevo diseño)
 * MasterCodeWeb · /js/wa-widget.js
 * ─────────────────────────────────────────────────────────────
 * · Auto-abre el popup tras 4 s (una sola vez por sesión)
 * · Toggle al pulsar el botón flotante verde
 * · Cierra al pulsar la × interior, clic fuera, o tecla Escape
 * · Oculta el badge de notificación tras la primera apertura
 * · Sin dependencias externas. IIFE para evitar colisiones.
 */

(function () {
  'use strict';

  /* ── Referencias al DOM ─────────────────────────────── */
  var widget   = document.getElementById('wa-widget');
  var popup    = document.getElementById('wa-popup');
  var toggle   = document.getElementById('wa-toggle');
  var btnClose = document.getElementById('wa-popup-close');
  var badge    = document.getElementById('wa-badge');

  /* Salida rápida si el widget no existe en la página */
  if (!widget || !popup || !toggle) return;

  /* ── Estado ─────────────────────────────────────────── */
  var abierto = false;

  /* ── Funciones ──────────────────────────────────────── */
  function abrir() {
    abierto = true;
    popup.hidden = false;
    widget.classList.add('wa--open');
    toggle.setAttribute('aria-expanded', 'true');
    toggle.setAttribute('aria-label', 'Cerrar chat de WhatsApp');
    if (badge) badge.hidden = true;
    /* Foco accesible en el botón de cerrar */
    if (btnClose) {
      setTimeout(function () { btnClose.focus(); }, 80);
    }
  }

  function cerrar() {
    abierto = false;
    popup.hidden = true;
    widget.classList.remove('wa--open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Abrir chat de WhatsApp');
    toggle.focus();
  }

  function alternar() {
    abierto ? cerrar() : abrir();
  }

  /* ── Listeners ──────────────────────────────────────── */

  /* Botón toggle principal */
  toggle.addEventListener('click', alternar);

  /* Botón × interior del popup */
  if (btnClose) {
    btnClose.addEventListener('click', cerrar);
  }

  /* Clic fuera del widget → cierra */
  document.addEventListener('click', function (e) {
    if (abierto && !widget.contains(e.target)) {
      cerrar();
    }
  });

  /* Tecla Escape → cierra */
  document.addEventListener('keydown', function (e) {
    if (abierto && (e.key === 'Escape' || e.keyCode === 27)) {
      e.preventDefault();
      cerrar();
    }
  });

  /* ── Auto-apertura (una vez por sesión, tras 4 s) ───── */
  if (!sessionStorage.getItem('wa_mostrado')) {
    setTimeout(function () {
      if (!abierto) {
        abrir();
        sessionStorage.setItem('wa_mostrado', '1');
      }
    }, 4000);
  } else {
    /* Visita posterior: oculta el badge directamente */
    if (badge) badge.hidden = true;
  }

}());