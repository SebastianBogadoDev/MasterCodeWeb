"use strict";

(function () {
  const modal = document.getElementById("mcw-pago-modal");
  if (!modal) return;

  const body = document.body;
  const overlay = document.getElementById("mcw-modal-overlay");
  const closeBtn = document.getElementById("mcw-modal-volver");
  const successBackBtn = document.getElementById("mcw-exito-volver");
  const grid = document.getElementById("mcw-modal-grid");
  const success = document.getElementById("mcw-modal-exito");

  const resName = document.getElementById("mcw-res-nombre");
  const resPrice = document.getElementById("mcw-res-precio");
  const resDays = document.getElementById("mcw-res-dias");
  const resFeatures = document.getElementById("mcw-res-features");
  const refCode = document.getElementById("mcw-ref-codigo");
  const successRef = document.getElementById("mcw-exito-ref");
  const priceRefs = document.querySelectorAll(".mcw-precio-ref");
  const planRefs = document.querySelectorAll(".mcw-plan-ref");

  const tabs = Array.from(document.querySelectorAll(".mcw-tab"));
  const panels = Array.from(document.querySelectorAll(".mcw-form-panel"));

  const openModal = (planButton) => {
    const planName = planButton.dataset.nombre || "Plan personalizado";
    const planPrice = planButton.dataset.precio || "";
    const planDays = planButton.dataset.dias || "";
    const features = (planButton.dataset.features || "").split("|").filter(Boolean);

    if (resName) resName.textContent = planName;
    if (resPrice) resPrice.textContent = planPrice ? `${planPrice}€` : "A consultar";
    if (resDays) resDays.textContent = planDays || "Entrega según alcance";

    if (resFeatures) {
      resFeatures.innerHTML = "";
      features.forEach((item) => {
        const li = document.createElement("li");
        li.textContent = item;
        resFeatures.appendChild(li);
      });
    }

    const generatedRef = `MCW-${Date.now().toString().slice(-6)}`;
    if (refCode) refCode.textContent = generatedRef;
    if (successRef) successRef.textContent = generatedRef;

    priceRefs.forEach((el) => {
      el.textContent = planPrice ? `${planPrice}€` : "A consultar";
    });

    planRefs.forEach((el) => {
      el.textContent = planName;
    });

    grid?.removeAttribute("hidden");
    success?.setAttribute("hidden", "");
    modal.removeAttribute("hidden");
    body.classList.add("modal-open");
  };

  const closeModal = () => {
    modal.setAttribute("hidden", "");
    body.classList.remove("modal-open");
  };

  const activateTab = (targetId) => {
    tabs.forEach((tab) => {
      const selected = tab.getAttribute("aria-controls") === targetId;
      tab.classList.toggle("mcw-tab--active", selected);
      tab.setAttribute("aria-selected", selected ? "true" : "false");
      tab.tabIndex = selected ? 0 : -1;
    });

    panels.forEach((panel) => {
      const active = panel.id === targetId;
      if (active) {
        panel.removeAttribute("hidden");
      } else {
        panel.setAttribute("hidden", "");
      }
    });
  };

  document.querySelectorAll(".mcw-pago-btn").forEach((btn) => {
    btn.addEventListener("click", () => openModal(btn));
  });

  tabs.forEach((tab) => {
    tab.addEventListener("click", () => activateTab(tab.getAttribute("aria-controls")));
  });

  [overlay, closeBtn, successBackBtn].forEach((el) => {
    el?.addEventListener("click", closeModal);
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.hasAttribute("hidden")) {
      closeModal();
    }
  });
})();
