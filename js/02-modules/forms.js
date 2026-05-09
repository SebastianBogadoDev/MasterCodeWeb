/* =====================================================
   FORMS MODULE
   Gestiona #budgetForm y #contactForm.
   Valida campos, envía leads y muestra feedback.
===================================================== */

import { sendLead } from "./leads.js";

export function initForms() {

  const form = document.getElementById("budgetForm") || document.getElementById("contactForm");

  if (!form) return;

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    clearErrors(form);

    if (!validateForm(form)) return;

    await submitForm(form);
  });

  // Limpiar error al corregir campo
  form.querySelectorAll("input, textarea").forEach((input) => {
    input.addEventListener("input", () => {
      input.style.borderColor = "";
      const err = input.parentElement.querySelector(".form-error");
      if (err) err.remove();
    });
  });
}


/* ========================
   VALIDACIÓN
======================== */

function validateForm(form) {
  let valid = true;

  const nombre = form.querySelector('[name="nombre"]');
  const email  = form.querySelector('[name="email"]');
  const mensaje = form.querySelector('[name="mensaje"]');

  if (nombre && !nombre.value.trim()) {
    showError(nombre, "El nombre es obligatorio");
    valid = false;
  }

  if (email && !isValidEmail(email.value)) {
    showError(email, "Introduce un email válido");
    valid = false;
  }

  if (mensaje && mensaje.value.trim().length < 10) {
    showError(mensaje, "El mensaje debe tener al menos 10 caracteres");
    valid = false;
  }

  return valid;
}

function isValidEmail(value) {
  // RFC 5321 compatible: local@domain.tld con mínimo 2 chars en TLD
  return /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/.test(value.trim());
}


/* ========================
   ENVÍO
======================== */

async function submitForm(form) {
  const btn = form.querySelector('[type="submit"]');
  const status = document.getElementById("form-status");

  const originalText = btn.textContent;
  btn.textContent = "Enviando...";
  btn.disabled = true;
  btn.classList.add("is-loading");

  const nombre   = form.querySelector('[name="nombre"]')?.value.trim() ?? "";
  const email    = form.querySelector('[name="email"]')?.value.trim() ?? "";
  const mensaje  = form.querySelector('[name="mensaje"]')?.value.trim() ?? "";
  const tipo     = form.querySelector('[name="tipo"]')?.value ?? "";
  const presupuesto = form.querySelector('[name="presupuesto"]')?.value ?? "";

  await sendLead({
    nombre,
    email,
    plan: tipo || "contacto",
    precio: presupuesto,
    origen: form.id
  });

  // Feedback visual
  btn.textContent = "✔ Mensaje enviado";
  btn.classList.remove("is-loading");

  if (status) {
    status.textContent = `Gracias ${nombre}, te responderemos en menos de 24 horas.`;
    status.setAttribute("style", "color: var(--color-green)");
  }

  form.reset();

  setTimeout(() => {
    btn.disabled = false;
    btn.textContent = originalText;
    if (status) status.textContent = "";
  }, 4000);
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

function clearErrors(form) {
  form.querySelectorAll(".form-error").forEach((el) => el.remove());
  form.querySelectorAll("input, textarea, select").forEach((el) => {
    el.style.borderColor = "";
  });
}
