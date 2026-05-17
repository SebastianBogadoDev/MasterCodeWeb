/* =====================================================
   CHECKOUT PAGE
   Gestiona copia de IBAN.
   El botón Stripe (.mcw-pago-btn) lo gestiona payments.js.
===================================================== */

export function initCheckout() {
  initIbanCopy();
}


/* ── Copiar IBAN al portapapeles ─────────────── */

function initIbanCopy() {

  const btn = document.getElementById("copyIban");
  if (!btn) return;

  btn.addEventListener("click", async () => {

    const iban = btn.dataset.iban;
    if (!iban) return;

    try {
      await navigator.clipboard.writeText(iban);
      btn.textContent = "✓ Copiado";
      btn.classList.add("copied");
      setTimeout(() => {
        btn.textContent = "Copiar";
        btn.classList.remove("copied");
      }, 2000);
    } catch {
      // Fallback para navegadores sin clipboard API
      btn.textContent = "Copia manualmente";
    }

  });

}
