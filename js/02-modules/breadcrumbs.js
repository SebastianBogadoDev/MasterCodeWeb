/* =====================================================
   BREADCRUMBS MODULE
   Generación automática basada en la URL
===================================================== */

export function initBreadcrumbs() {
  const container = document.querySelector(".breadcrumbs");
  if (!container) return;

  // Garantiza aria-label en páginas donde no esté en el HTML estático
  if (!container.hasAttribute("aria-label")) {
    container.setAttribute("aria-label", "Ruta de navegación");
  }

  const path = window.location.pathname;

  // Limpia la ruta
  const segments = path.split("/").filter(Boolean);

  const ol = document.createElement("ol");

  // Inicio siempre
  const homeItem = document.createElement("li");
  const homeLink = document.createElement("a");
  homeLink.href = "/index.html";
  homeLink.textContent = "Inicio";
  homeItem.appendChild(homeLink);

  // Si es index o ruta raíz, solo mostramos "Inicio"
  if (segments.length === 0 || segments[0] === "index.html") {
    homeItem.setAttribute("aria-current", "page");
    homeItem.removeChild(homeLink);
    homeItem.textContent = "Inicio";
    ol.appendChild(homeItem);
    container.replaceChildren(ol); // reemplaza el <ol> estático del HTML
    return;
  }

  ol.appendChild(homeItem);

  let currentPath = "";

  segments.forEach((segment, index) => {
    currentPath += `/${segment}`;

    // Nombre legible: sin extensión, guiones → espacios, capitalizado
    const name = segment
      .replace(".html", "")
      .replace(/-/g, " ")
      .replace(/\b\w/g, l => l.toUpperCase());

    const item = document.createElement("li");

    if (index === segments.length - 1) {
      // Último elemento: sin enlace, marca página actual
      item.setAttribute("aria-current", "page");
      item.textContent = name;
    } else {
      const link = document.createElement("a");
      link.href = currentPath;
      link.textContent = name; // textContent previene XSS
      item.appendChild(link);
    }

    ol.appendChild(item);
  });

  // replaceChildren reemplaza el <ol> estático del HTML — evita duplicación
  container.replaceChildren(ol);
}
