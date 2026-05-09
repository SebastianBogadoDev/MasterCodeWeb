"use strict";

(function () {
  const form = document.getElementById("budgetForm");
  const statusEl = document.getElementById("budgetFormStatus");

  if (!form || !statusEl) return;

  const serviceId = form.dataset.emailjsServiceId || "";
  const templateId = form.dataset.emailjsTemplateId || "";
  const publicKey = form.dataset.emailjsPublicKey || "";

  const hasEmailJsConfig =
    serviceId &&
    templateId &&
    publicKey &&
    ![serviceId, templateId, publicKey].some(value => value.startsWith("YOUR_"));

  if (window.emailjs && publicKey) {
    window.emailjs.init({ publicKey });
  }

  const setStatus = (message, tone) => {
    statusEl.textContent = message;
    statusEl.classList.remove("is-success", "is-error", "is-info");
    statusEl.classList.add(tone);
  };

  const getPhoneWithDialCode = () => {
    const phoneInput = document.getElementById("telefono");
    if (!phoneInput) return "";

    const iti = window.intlTelInputGlobals?.getInstance(phoneInput);
    if (iti) {
      return iti.getNumber() || phoneInput.value.trim();
    }

    return phoneInput.value.trim();
  };

  form.addEventListener("submit", async (event) => {
    if (event.defaultPrevented) return;

    event.preventDefault();

    if (!hasEmailJsConfig || !window.emailjs) {
      setStatus(
        "Configura EmailJS (service/template/public key) para activar el envío automático desde GitHub Pages.",
        "is-info"
      );
      return;
    }

    const submitBtn = form.querySelector("button[type='submit']");
    const payload = {
      from_name: form.nombre.value.trim(),
      from_email: form.email.value.trim(),
      phone: getPhoneWithDialCode(),
      services: form.servicios.value.trim(),
      message: form.mensaje.value.trim() || "Sin mensaje adicional",
      privacy_accepted: form.privacidad.checked ? "Sí" : "No",
      page_url: window.location.href
    };

    submitBtn?.setAttribute("disabled", "disabled");
    setStatus("Enviando solicitud...", "is-info");

    try {
      await window.emailjs.send(serviceId, templateId, payload);
      setStatus("✅ Solicitud enviada correctamente. Te responderé en menos de 24 horas.", "is-success");
      form.reset();
      document.getElementById("budgetTotal").textContent = "0€";
    } catch (error) {
      console.error("EmailJS error:", error);
      setStatus("❌ No se pudo enviar ahora mismo. Inténtalo de nuevo en unos minutos.", "is-error");
    } finally {
      submitBtn?.removeAttribute("disabled");
    }
  });
})();
