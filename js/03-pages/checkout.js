/* =====================================================
   CHECKOUT PAGE
   Gestiona tabs de métodos de pago y copia de IBAN.
   El botón Stripe (.mcw-pago-btn) lo gestiona payments.js.
===================================================== */

export function initCheckout() {

  initPayTabs();
  initIbanCopy();

}


/* ── Tabs de método de pago ──────────────────── */

function initPayTabs() {

  const tabBtns = document.querySelectorAll(".pay-tab-btn");
  const panels  = document.querySelectorAll(".pay-panel");

  if (!tabBtns.length) return;

  tabBtns.forEach(btn => {
    btn.addEventListener("click", () => {

      const target = btn.dataset.tab;

      tabBtns.forEach(b => {
        b.classList.remove("is-active");
        b.setAttribute("aria-selected", "false");
      });
      panels.forEach(p => p.classList.remove("is-active"));

      btn.classList.add("is-active");
      btn.setAttribute("aria-selected", "true");

      const panel = document.getElementById(target);
      if (panel) panel.classList.add("is-active");

    });
  });

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
