/* =====================================================
   FORMS MODULE
   Gestiona #budgetForm y #contactForm.
   Valida campos, verifica Turnstile, envía via Resend.
===================================================== */

import { TURNSTILE_VERIFY_URL } from '../security.js';
import { getLandingAttribution } from './analytics.js';


const COOLDOWN_MS         = 60_000;         // 60 s between submissions
const COOLDOWN_KEY        = "mcw_last_submit";
const ABUSE_KEY           = "mcw_abuse";    // { count, windowStart }
const ABUSE_WINDOW_MS     = 86_400_000;     // 24 h rolling window
const ABUSE_MAX           = 5;              // max submissions per 24 h
const MAX_FAILURES        = 3;              // session failure cap

let _formOpenTime  = 0;
let _failureCount  = 0;
let _hasInteracted = false; // set to true on first genuine human input

export function initForms() {

  const form = document.getElementById("budgetForm") || document.getElementById("contactForm");
  if (!form) return;

  _formOpenTime = Date.now();

  // ── Human interaction signal (bots never move the mouse or press keys)
  const markInteracted = () => { _hasInteracted = true; };
  document.addEventListener("mousemove", markInteracted, { once: true, passive: true });
  document.addEventListener("keydown",   markInteracted, { once: true, passive: true });
  document.addEventListener("touchstart",markInteracted, { once: true, passive: true });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    clearErrors(form);

    // ── Anti-spam: honeypot (decoy "website" field) ──────
    const hp = form.querySelector('[name="website"]');
    if (hp && hp.value) return;

    // ── Anti-bot: form must be open ≥ 2 s (bots submit instantly)
    if (Date.now() - _formOpenTime < 2000) return;

    // ── Anti-bot: require genuine human interaction ──────
    if (!_hasInteracted) return;

    // ── Session failure cap ──────────────────────────────
    if (_failureCount >= MAX_FAILURES) {
      showToast("Demasiados intentos fallidos. Escríbenos a contact@mastercodeweb.com.", "error");
      return;
    }

    // ── Anti-spam: tab-level cooldown (sessionStorage) ───
    const tabLast = parseInt(sessionStorage.getItem(COOLDOWN_KEY) || "0", 10);
    if (Date.now() - tabLast < COOLDOWN_MS) {
      showToast("Por favor, espera un momento antes de enviar otro mensaje.", "error");
      return;
    }

    // ── Anti-spam: global cooldown (localStorage) ────────
    const globalLast = parseInt(localStorage.getItem(COOLDOWN_KEY) || "0", 10);
    if (Date.now() - globalLast < COOLDOWN_MS) {
      showToast("Por favor, espera un momento antes de enviar otro mensaje.", "error");
      return;
    }

    // ── Anti-abuse: 24 h rolling submission limit ────────
    if (isAbuseLimitReached()) {
      showToast("Has alcanzado el límite de mensajes por hoy. Escríbenos a contact@mastercodeweb.com.", "error");
      return;
    }

    if (!validateForm(form)) return;

    // ── Duplicate submission guard ───────────────────────
    const emailVal   = (form.querySelector('[name="email"]')?.value   ?? "").trim();
    const mensajeVal = (form.querySelector('[name="mensaje"]')?.value ?? "").trim();
    const submitHash = btoa(`${emailVal}|${mensajeVal}`).slice(0, 32);
    const lastHash   = sessionStorage.getItem("mcw_last_hash");
    if (lastHash && lastHash === submitHash) {
      showToast("Ya enviaste este mensaje. Te responderemos en breve.", "info");
      return;
    }

    // ── Cloudflare Turnstile verification ────────────────
    const tsWidget = form.querySelector('.cf-turnstile');
    const tsErrEl  = form.querySelector('.turnstile-error');
    const tsToken  = document.querySelector('[name="cf-turnstile-response"]')?.value ?? "";

    // Si el widget está presente pero el token está vacío, el usuario no completó el challenge
    if (tsWidget && !tsToken) {
      if (tsErrEl) tsErrEl.classList.add("turnstile-error--visible");
      showToast("Completa la verificación de seguridad antes de enviar.", "error");
      tsWidget.scrollIntoView({ behavior: "smooth", block: "center" });
      return;
    }
    if (tsErrEl) tsErrEl.classList.remove("turnstile-error--visible");

    if (tsToken) {
      const tsOk = await verifyTurnstile(tsToken);
      if (!tsOk) {
        if (tsErrEl) tsErrEl.classList.add("turnstile-error--visible");
        showToast("Verificación de seguridad fallida. Recarga la página e inténtalo de nuevo.", "error");
        if (window.turnstile) window.turnstile.reset();
        return;
      }
    }

    await submitForm(form, submitHash);
  });

  form.querySelectorAll("input, textarea").forEach((input) => {
    input.addEventListener("input", () => {
      input.style.borderColor = "";
      input.removeAttribute("aria-invalid");
      const err = input.parentElement.querySelector(".form-error");
      if (err) err.remove();
    });
  });

  form.querySelector('[name="privacy"]')?.addEventListener("change", () => {
    const next = form.querySelector('.form-check')?.nextElementSibling;
    if (next?.classList.contains("form-error")) next.remove();
    form.querySelector('[name="privacy"]')?.removeAttribute("aria-invalid");
  });
}


/* ========================
   VALIDACIÓN
======================== */

function validateForm(form) {
  let valid = true;
  let firstInvalid = null;

  const nombre  = form.querySelector('[name="nombre"]');
  const email   = form.querySelector('[name="email"]');
  const mensaje = form.querySelector('[name="mensaje"]');
  const privacy = form.querySelector('[name="privacy"]');

  if (nombre && nombre.value.trim().length < 2) {
    showError(nombre, "El nombre es obligatorio");
    firstInvalid = firstInvalid || nombre;
    valid = false;
  } else if (nombre && nombre.value.trim().length > 100) {
    showError(nombre, "El nombre no puede superar los 100 caracteres");
    firstInvalid = firstInvalid || nombre;
    valid = false;
  }

  if (email && !isValidEmail(email.value)) {
    showError(email, "Introduce un email válido");
    firstInvalid = firstInvalid || email;
    valid = false;
  } else if (email && email.value.trim().length > 200) {
    showError(email, "Dirección de email demasiado larga");
    firstInvalid = firstInvalid || email;
    valid = false;
  }

  if (mensaje && mensaje.value.trim().length < 10) {
    showError(mensaje, "El mensaje debe tener al menos 10 caracteres");
    firstInvalid = firstInvalid || mensaje;
    valid = false;
  } else if (mensaje && mensaje.value.trim().length > 5000) {
    showError(mensaje, "El mensaje no puede superar los 5.000 caracteres");
    firstInvalid = firstInvalid || mensaje;
    valid = false;
  }

  if (privacy && !privacy.checked) {
    showPrivacyError(form);
    firstInvalid = firstInvalid || privacy;
    valid = false;
  }

  if (firstInvalid) firstInvalid.focus();
  return valid;
}

function isValidEmail(value) {
  return /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(value.trim());
}

function sanitize(str) {
  return str.replace(/[\u0000-\u001F\u007F]/g, " ").replace(/\s+/g, " ").trim();
}


/* ========================
   TURNSTILE
======================== */

async function verifyTurnstile(token) {
  try {
    const res = await fetch(TURNSTILE_VERIFY_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token }),
    });
    if (!res.ok) return false;
    const data = await res.json();
    return data.success === true;
  } catch {
    // Network error — fail open (don't block the user if our server is unreachable)
    return true;
  }
}


/* ========================
   ABUSE TRACKING
======================== */

function isAbuseLimitReached() {
  try {
    const raw  = localStorage.getItem(ABUSE_KEY);
    const data = raw ? JSON.parse(raw) : { count: 0, windowStart: Date.now() };
    if (Date.now() - data.windowStart > ABUSE_WINDOW_MS) {
      // Window expired — reset
      localStorage.setItem(ABUSE_KEY, JSON.stringify({ count: 0, windowStart: Date.now() }));
      return false;
    }
    return data.count >= ABUSE_MAX;
  } catch {
    return false;
  }
}

function recordAbuse() {
  try {
    const raw  = localStorage.getItem(ABUSE_KEY);
    const data = raw ? JSON.parse(raw) : { count: 0, windowStart: Date.now() };
    if (Date.now() - data.windowStart > ABUSE_WINDOW_MS) {
      localStorage.setItem(ABUSE_KEY, JSON.stringify({ count: 1, windowStart: Date.now() }));
    } else {
      data.count += 1;
      localStorage.setItem(ABUSE_KEY, JSON.stringify(data));
    }
  } catch { /* storage unavailable — skip */ }
}


/* ========================
   ENVÍO
======================== */

async function submitForm(form, submitHash) {
  const btn    = form.querySelector('[type="submit"]');
  const status = document.getElementById("form-status");

  const originalText = btn.textContent;
  btn.textContent = "Enviando...";
  btn.disabled = true;
  btn.classList.add("is-loading");

  const nombre        = sanitize(form.querySelector('[name="nombre"]')?.value      ?? "");
  const negocio       = sanitize(form.querySelector('[name="negocio"]')?.value     ?? "");
  const email         = (form.querySelector('[name="email"]')?.value ?? "").trim();
  const prefijo       = form.querySelector('[name="prefijo"]')?.value              ?? "";
  const telefonoRaw   = (form.querySelector('[name="telefono"]')?.value ?? "").trim();
  const telefono      = telefonoRaw ? `${prefijo}${telefonoRaw.replace(/\s+/g, "")}` : "";
  const mensaje       = sanitize(form.querySelector('[name="mensaje"]')?.value     ?? "");
  const tipo          = sanitize(form.querySelector('[name="tipo"]')?.value        ?? "");
  const presupuesto   = sanitize(form.querySelector('[name="presupuesto"]')?.value ?? "");
  const demo_ref      = sanitize(form.querySelector('[name="demo_ref"]')?.value    ?? "");
  const paleta_ref    = sanitize(form.querySelector('[name="paleta_ref"]')?.value  ?? "");
  const colores_ref   = sanitize(form.querySelector('[name="colores_ref"]')?.value ?? "");
  const vista_ref     = sanitize(form.querySelector('[name="vista_ref"]')?.value   ?? "");

  // ── Resend (via /api/send-form.php) ─────────────────
  let emailSent = false;
  try {
    const res = await fetch("/api/send-form.php", {
      method:  "POST",
      headers: { "Content-Type": "application/json" },
      body:    JSON.stringify({
        nombre, negocio, email, telefono, mensaje, tipo, presupuesto,
        demo_ref, paleta_ref, colores_ref, vista_ref,
        origen: form.id,
      }),
    });
    const data = await res.json();
    if (data.success) {
      emailSent = true;

      // Record successful submission in both stores
      const now = String(Date.now());
      localStorage.setItem(COOLDOWN_KEY, now);
      sessionStorage.setItem(COOLDOWN_KEY, now);
      sessionStorage.setItem("mcw_last_hash", submitHash);
      recordAbuse();

      // GA4 conversion event
      if (typeof window.gtag === "function") {
        const attr = getLandingAttribution();
        window.gtag("event", "envio_formulario", {
          event_category: "captacion",
          form_id:        form.id,
          landing_origen: attr.origen   || '',
          ciudad:         attr.ciudad   || '',
          servicio:       attr.servicio || '',
          demo_ref,
          paleta_ref,
          vista_ref,
        });
      }
    }
  } catch (err) {
    console.warn("[MCW] send-form error:", err);
  }

  // ── Feedback visual ─────────────────────────────────
  btn.classList.remove("is-loading");

  if (emailSent) {
    btn.textContent = "✔ Mensaje enviado";
    if (status) {
      status.textContent = `Mensaje enviado correctamente. Gracias ${nombre}, te responderemos en menos de 24 horas.`;
      status.style.color = "var(--color-green, #22c55e)";
    }
    showToast(`Mensaje enviado. Te respondemos en menos de 24h.`, "success");
    form.reset();
  } else {
    _failureCount++;
    btn.textContent = originalText;
    btn.disabled = false;
    if (status) {
      status.textContent = "No se pudo enviar. Escríbenos a contact@mastercodeweb.com.";
      status.style.color = "var(--color-error, #ef4444)";
    }
    showToast("Error al enviar. Inténtalo de nuevo o usa el email directamente.", "error");
    return;
  }

  setTimeout(() => {
    btn.disabled = false;
    btn.textContent = originalText;
    if (status) { status.textContent = ""; status.style.color = ""; }
  }, 5000);
}


/* ========================
   TOAST
======================== */

function showToast(message, type = "success") {
  const existing = document.getElementById("mcw-toast");
  if (existing) existing.remove();

  const toast = document.createElement("div");
  toast.id = "mcw-toast";
  toast.setAttribute("role", "status");
  toast.setAttribute("aria-live", "polite");
  toast.className = `mcw-toast mcw-toast--${type}`;
  toast.textContent = message;

  document.body.appendChild(toast);

  // Force reflow to trigger enter transition
  toast.getBoundingClientRect();
  toast.classList.add("mcw-toast--visible");

  setTimeout(() => {
    toast.classList.remove("mcw-toast--visible");
    toast.addEventListener("transitionend", () => toast.remove(), { once: true });
  }, 4500);
}


/* ========================
   UX ERRORES
======================== */

function showError(input, message) {
  input.style.borderColor = "var(--color-error)";
  input.setAttribute("aria-invalid", "true");

  const errorId = `err-${input.name || input.id}`;
  const error = document.createElement("small");
  error.id = errorId;
  error.className = "form-error";
  error.setAttribute("role", "alert");
  error.textContent = message;

  input.setAttribute("aria-describedby", errorId);
  input.parentElement.appendChild(error);
  input.focus();
}

function showPrivacyError(form) {
  const privacy = form.querySelector('[name="privacy"]');
  const anchor  = form.querySelector(".form-check") || privacy?.parentElement;
  if (!anchor) return;

  const error = document.createElement("small");
  error.className = "form-error";
  error.setAttribute("role", "alert");
  error.textContent = "Debes aceptar la política de privacidad";

  privacy?.setAttribute("aria-invalid", "true");
  anchor.insertAdjacentElement("afterend", error);
}

function clearErrors(form) {
  form.querySelectorAll(".form-error").forEach((el) => el.remove());
  form.querySelectorAll("input, textarea, select").forEach((el) => {
    el.style.borderColor = "";
    el.removeAttribute("aria-invalid");
    el.removeAttribute("aria-describedby");
  });
}
