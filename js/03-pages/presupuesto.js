/* =====================================================
   PRESUPUESTO PAGE
   Selección + cálculo dinámico
===================================================== */

export function initPresupuesto() {

  const page = document.querySelector(".budget");
  if (!page) return;

  console.log("💰 Presupuesto cargado");

  const STORAGE_KEY = "budget-mode";

  const modeButtons = document.querySelectorAll(".budget-mode__btn");
  const checkboxes = document.querySelectorAll(".budget-check input");
  const totalElement = document.getElementById("budgetTotal");

  /* ========================
     MODO (ÚNICO / MENSUAL)
  ======================== */

  modeButtons.forEach(btn => {
    btn.addEventListener("click", () => {
      const mode = btn.dataset.mode;

      document.body.setAttribute("data-budget-mode", mode);

      localStorage.setItem(STORAGE_KEY, mode);

      modeButtons.forEach(b => b.classList.remove("is-active"));
      btn.classList.add("is-active");

      updateTotal();
    });
  });

  /* ========================
     CARGAR MODO GUARDADO
  ======================== */

  const savedMode = localStorage.getItem(STORAGE_KEY) || "one";

  document.body.setAttribute("data-budget-mode", savedMode);

  modeButtons.forEach(btn => {
    if (btn.dataset.mode === savedMode) {
      btn.classList.add("is-active");
    }
  });

  /* ========================
     CHECKBOXES
  ======================== */

  checkboxes.forEach(input => {
    input.addEventListener("change", updateTotal);
  });

  /* ========================
     CALCULAR TOTAL
  ======================== */

  function updateTotal() {
    let total = 0;

    const mode = document.body.getAttribute("data-budget-mode");

    checkboxes.forEach(input => {
      if (input.checked) {

        const price = mode === "monthly"
          ? input.dataset.monthly
          : input.dataset.price;

        total += parseInt(price || 0);
      }
    });

    renderTotal(total, mode);
  }

  /* ========================
     MOSTRAR TOTAL
  ======================== */

  function renderTotal(total, mode) {

    if (!totalElement) return;

    if (total === 0) {
      totalElement.textContent = "0€";
      return;
    }

    if (mode === "monthly") {
      totalElement.textContent = `${total}€/mes`;
    } else {
      totalElement.textContent = `${total}€`;
    }
  }

  /* ========================
     INIT
  ======================== */

  updateTotal();
}