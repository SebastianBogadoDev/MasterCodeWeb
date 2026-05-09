/* =====================================================
   SEARCH MODULE
   Buscador en tiempo real con accesibilidad de teclado
===================================================== */

export function initSearch() {
  const input = document.getElementById("searchInput");
  const dropdown = document.getElementById("searchDropdown");

  if (!input || !dropdown) return;

  /* ========================
     DATA (PÁGINAS INDEXADAS)
  ======================== */

  const pages = [
    { title: "Inicio", url: "/index.html", category: "Página" },
    { title: "Servicios", url: "/servicios.html", category: "Página" },
    { title: "Precios", url: "/precios.html", category: "Página" },
    { title: "Presupuesto", url: "/presupuesto.html", category: "Página" },
    { title: "Contacto", url: "/contacto.html", category: "Página" },
    { title: "Blog", url: "/blog.html", category: "Blog" },
    { title: "Diseño Web Málaga", url: "/diseno-web-malaga.html", category: "Servicio" },
    { title: "SEO Málaga", url: "/seo-malaga.html", category: "Servicio" },
    { title: "Diseño Web Profesional", url: "/servicios/diseno-web-profesional.html", category: "Servicio" },
    { title: "Tienda Online", url: "/servicios/tienda-online-profesional.html", category: "Servicio" },
    { title: "SEO Técnico", url: "/servicios/optimizacion-seo-tecnica.html", category: "Servicio" }
  ];

  let activeIndex = -1;

  /* ========================
     EVENTO INPUT
  ======================== */

  input.addEventListener("input", () => {
    const value = input.value.trim().toLowerCase();
    activeIndex = -1;

    if (!value) {
      hideDropdown();
      return;
    }

    const results = pages.filter(page =>
      page.title.toLowerCase().includes(value) ||
      page.category.toLowerCase().includes(value)
    );

    renderResults(results, value);
  });

  /* ========================
     NAVEGACIÓN CON TECLADO
  ======================== */

  input.addEventListener("keydown", (e) => {
    const items = dropdown.querySelectorAll(".search-dropdown__item");
    if (!items.length) return;

    if (e.key === "ArrowDown") {
      e.preventDefault();
      activeIndex = Math.min(activeIndex + 1, items.length - 1);
      updateActive(items);
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      activeIndex = Math.max(activeIndex - 1, 0);
      updateActive(items);
    } else if (e.key === "Enter" && activeIndex >= 0) {
      e.preventDefault();
      items[activeIndex]?.click();
    } else if (e.key === "Escape") {
      hideDropdown();
      input.blur();
    }
  });

  function updateActive(items) {
    items.forEach((item, i) => {
      const isActive = i === activeIndex;
      item.setAttribute("aria-selected", isActive);
      item.classList.toggle("is-active", isActive);
    });

    if (activeIndex >= 0) {
      items[activeIndex].scrollIntoView({ block: "nearest" });
    }
  }

  /* ========================
     RENDER RESULTADOS
  ======================== */

  function renderResults(results, query) {
    dropdown.innerHTML = "";
    activeIndex = -1;

    if (results.length === 0) {
      const empty = document.createElement("li");
      empty.className = "search-dropdown__empty";
      empty.textContent = "No se encontraron resultados";
      dropdown.appendChild(empty);
      showDropdown();
      return;
    }

    results.forEach((result, i) => {
      const item = document.createElement("li");
      item.className = "search-dropdown__item";
      item.setAttribute("role", "option");
      item.setAttribute("aria-selected", "false");
      item.setAttribute("tabindex", "-1");

      const cat = document.createElement("span");
      cat.className = "search-dropdown__cat";
      cat.textContent = result.category;

      const title = document.createElement("span");
      title.className = "search-dropdown__title";
      title.innerHTML = highlight(result.title, query); // safe: query is from input, not URL

      item.appendChild(cat);
      item.appendChild(title);

      item.addEventListener("click", () => {
        window.location.href = result.url;
      });

      dropdown.appendChild(item);
    });

    showDropdown();
  }

  /* ========================
     HIGHLIGHT TEXTO
  ======================== */

  function highlight(text, query) {
    // Escapamos el query antes de usarlo en regex
    const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const regex = new RegExp(`(${escaped})`, "gi");
    return text.replace(regex, "<mark>$1</mark>");
  }

  /* ========================
     VISIBILIDAD
  ======================== */

  function showDropdown() {
    dropdown.removeAttribute("hidden");
    input.setAttribute("aria-expanded", "true");
  }

  function hideDropdown() {
    dropdown.setAttribute("hidden", true);
    input.setAttribute("aria-expanded", "false");
    activeIndex = -1;
  }

  /* ========================
     CLICK FUERA
  ======================== */

  document.addEventListener("click", (e) => {
    if (!e.target.closest(".header-search-wrap")) {
      hideDropdown();
    }
  });
}
