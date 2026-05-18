/* =====================================================
   CHECKOUT PAGE
   Gestiona copia de IBAN.
   El botón Stripe (.mcw-pago-btn) lo gestiona payments.js.
===================================================== */

export function initCheckout() {
  initMaintToggle();
  initIbanCopy();
}


/* ── Mantenimiento upsell toggle ─────────────── */

function initMaintToggle() {
  const toggle = document.getElementById('addMaintenance');
  if (!toggle) return;

  const planPrice  = Number(toggle.dataset.planPrice  ?? 0);
  const maintPrice = Number(toggle.dataset.maintPrice ?? 0);
  const totalEl    = document.getElementById('totalAmount');
  const noteEl     = document.getElementById('paymentNote');
  const hintEl     = document.getElementById('maintHint');
  const afterEl    = document.getElementById('maintAfter');
  const upsellEl   = document.getElementById('maintUpsell');

  function update() {
    const on = toggle.checked;
    if (totalEl) totalEl.textContent = on ? `${planPrice + maintPrice} €` : `${planPrice} €`;
    if (noteEl)  noteEl.textContent  = on
      ? 'IVA incluido · Pago único + primer mes de mantenimiento'
      : 'IVA incluido · Pago único';
    if (hintEl)   hintEl.hidden  =  on;
    if (afterEl)  afterEl.hidden = !on;
    if (upsellEl) upsellEl.classList.toggle('is-active', on);
  }

  toggle.addEventListener('change', update);
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
