/* =====================================================
   BLOG PAGE
===================================================== */

export function initBlog() {
  initReadingProgress();
  initFloatCta();
}

/* ── Barra de progreso de lectura ─────────────── */

function initReadingProgress() {
  const bar = document.getElementById("article-progress");
  if (!bar) return;

  window.addEventListener("scroll", () => {
    const docH = document.documentElement.scrollHeight - window.innerHeight;
    const pct  = docH > 0 ? Math.min(100, (window.scrollY / docH) * 100) : 0;
    bar.style.setProperty("--progress", `${pct}%`);
  }, { passive: true });
}

/* ── CTA flotante ─────────────────────────────── */

function initFloatCta() {
  const cta  = document.getElementById("article-float-cta");
  if (!cta) return;

  const sentinel = document.querySelector(".article-cta-box");
  if (!sentinel) return;

  const observer = new IntersectionObserver(
    ([entry]) => cta.classList.toggle("is-visible", !entry.isIntersecting),
    { rootMargin: "0px 0px -80px 0px" }
  );

  // Aparece cuando el hero sale de vista
  const hero = document.querySelector(".hero--small");
  if (hero) {
    const showObserver = new IntersectionObserver(
      ([entry]) => {
        if (!entry.isIntersecting) cta.classList.add("is-ready");
      },
      { rootMargin: "-60px 0px 0px 0px" }
    );
    showObserver.observe(hero);
  }

  // Desaparece cuando el CTA final es visible
  observer.observe(sentinel);
}
