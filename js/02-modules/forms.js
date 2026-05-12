/* =====================================================
   FORMS MODULE
   Gestiona #budgetForm y #contactForm.
   Valida campos, envía via EmailJS y muestra feedback.
===================================================== */

import { sendLead } from "./leads.js";

const EMAILJS_PUBLIC_KEY     = "hj2hf3j06xO8X87jx";
const EMAILJS_SERVICE_ID     = "service_f7sdyih";
const EMAILJS_TEMPLATE_OWNER = "template_r4lrntv";   // owner notification
const EMAILJS_TEMPLATE_REPLY = "template_keh06xb";   // customer confirmation
const COOLDOWN_MS         = 60_000; // 60 s entre envíos
const COOLDOWN_KEY        = "mcw_last_submit";

export function initForms() {

  if (window.emailjs) {
    window.emailjs.init({ publicKey: EMAILJS_PUBLIC_KEY });
  }

  const form = document.getElementById("budgetForm") || document.getElementById("contactForm");
  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    clearErrors(form);

    // ── Anti-spam: honeypot ──────────────────────────
    const hp = form.querySelector('[name="_hp"]');
    if (hp && hp.value) return;

    // ── Anti-spam: cooldown ──────────────────────────
    const last = parseInt(localStorage.getItem(COOLDOWN_KEY) || "0", 10);
    if (Date.now() - last < COOLDOWN_MS) {
      showToast("Por favor, espera un momento antes de enviar otro mensaje.", "error");
      return;
    }

    if (!validateForm(form)) return;

    await submitForm(form);
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
  }

  if (email && !isValidEmail(email.value)) {
    showError(email, "Introduce un email válido");
    firstInvalid = firstInvalid || email;
    valid = false;
  }

  if (mensaje && mensaje.value.trim().length < 10) {
    showError(mensaje, "El mensaje debe tener al menos 10 caracteres");
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


/* ========================
   ENVÍO
======================== */

async function submitForm(form) {
  const btn    = form.querySelector('[type="submit"]');
  const status = document.getElementById("form-status");

  const originalText = btn.textContent;
  btn.textContent = "Enviando...";
  btn.disabled = true;
  btn.classList.add("is-loading");

  const nombre        = form.querySelector('[name="nombre"]')?.value.trim()      ?? "";
  const email         = form.querySelector('[name="email"]')?.value.trim()       ?? "";
  const prefijo       = form.querySelector('[name="prefijo"]')?.value             ?? "";
  const telefonoRaw   = form.querySelector('[name="telefono"]')?.value.trim()     ?? "";
  const telefono      = telefonoRaw ? `${prefijo}${telefonoRaw.replace(/\s+/g, "")}` : "";
  const mensaje       = form.querySelector('[name="mensaje"]')?.value.trim()     ?? "";
  const tipo          = form.querySelector('[name="tipo"]')?.value                ?? "";
  const presupuesto   = form.querySelector('[name="presupuesto"]')?.value         ?? "";

  // ── EmailJS ─────────────────────────────────────────
  let emailSent = false;
  if (window.emailjs) {
    const params = { nombre, email, telefono, mensaje, tipo, presupuesto, origen: form.id };
    try {
      // 1. Owner notification
      await window.emailjs.send(EMAILJS_SERVICE_ID, EMAILJS_TEMPLATE_OWNER, params);
      emailSent = true;
      localStorage.setItem(COOLDOWN_KEY, String(Date.now()));

      // 2. Customer confirmation (non-blocking — owner notification already succeeded)
      window.emailjs.send(EMAILJS_SERVICE_ID, EMAILJS_TEMPLATE_REPLY, params)
        .catch(err => console.warn("[MCW] Confirmation email error:", err));
    } catch (err) {
      console.warn("[MCW] EmailJS error:", err);
    }
  }

  // ── Lead interno (secundario, no bloqueante) ────────
  await sendLead({
    nombre,
    email,
    plan: tipo || "contacto",
    precio: presupuesto,
    origen: form.id
  });

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

  // Forzar reflow para activar la transición de entrada
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

  const error = document.createElement("small");
  error.className = "form-error";
  error.setAttribute("role", "alert");
  error.textContent = message;

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
  });
}
