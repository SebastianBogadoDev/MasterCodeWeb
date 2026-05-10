# PROJECT OVERVIEW — MasterCodeWeb

**Version:** 1.0 — May 2026  
**Domain:** [https://www.mastercodeweb.com](https://www.mastercodeweb.com)  
**Location:** Algarrobo, Málaga, Spain  
**Contact:** contact@mastercodeweb.com · +34 680 762 047

---

## Table of Contents

1. [Project Summary](#1-project-summary)
2. [Architecture](#2-architecture)
3. [Folder Structure](#3-folder-structure)
4. [Technologies Used](#4-technologies-used)
5. [EmailJS Integration](#5-emailjs-integration)
6. [SEO Implementation](#6-seo-implementation)
7. [Responsive Design](#7-responsive-design)
8. [Accessibility](#8-accessibility)
9. [Anti-Spam Protections](#9-anti-spam-protections)
10. [Performance Optimizations](#10-performance-optimizations)
11. [Pending Tasks](#11-pending-tasks)
12. [Deployment Status](#12-deployment-status)
13. [Environment & Config Requirements](#13-environment--config-requirements)
14. [DNS & Email Configuration](#14-dns--email-configuration)
15. [Coding Conventions](#15-coding-conventions)
16. [Reusable Components & Modules](#16-reusable-components--modules)
17. [Future Roadmap](#17-future-roadmap)

---

## 1. Project Summary

MasterCodeWeb is a professional web design and development agency website targeting small businesses, freelancers, and growing companies across Spain. The site markets and sells web design services with pricing plans starting from €349, from Málaga.

The project is a **static multi-page website** (no framework, no SSR) built with vanilla HTML5, CSS custom properties, and ES Modules (JavaScript). It is hosted as static files and delivers email leads via EmailJS. Payments are wired but temporarily disabled pending backend activation.

**Service offer:**
- Professional web design (starting €349)
- SEO optimization
- Online stores
- Monthly maintenance from €19/month

---

## 2. Architecture

```
Static site (no framework)
├── HTML pages (multi-page, no SPA)
├── CSS — modular architecture (main.css imports all layers)
├── JS — ES Modules with lazy page detection
│   ├── Core layer (main.js → init.js)
│   ├── Module layer (menu, forms, cookies, search, etc.)
│   ├── Page layer (home, servicios, presupuesto, blog, etc.)
│   └── Utils layer (helpers, dom, events)
└── Static assets (images in .webp, SVG icons, branding)
```

**Key architectural decisions:**

- **Zero build step** — No bundler (Webpack/Vite) is used. All JS files are loaded as native ES Modules via `<script type="module">`.
- **Single CSS entry point** — `css/main.css` uses `@import` to assemble all layers; only this file is linked in HTML.
- **Route-based JS init** — `init.js` reads `window.location.pathname` and calls the relevant page init function. No router library required.
- **Fail-safe init wrapper** — All module inits are wrapped in `safeInit()` so a broken module never crashes the rest of the app.
- **Secondary backend channel** — `leads.js` silently POSTs to `/api/leads` as a secondary CRM channel independent of EmailJS. It fails silently and never blocks the user.

---

## 3. Folder Structure

```
mastercodeweb/
│
├── index.html                          # Home page
├── 404.html                            # Custom error page
├── favicon.ico
├── robots.txt
├── sitemap.xml
├── .gitignore
│
├── pages/                              # Main pages
│   ├── servicios.html
│   ├── precios.html
│   ├── presupuesto.html
│   ├── contacto.html
│   ├── blog.html
│   ├── diseno-web-malaga.html          # SEO landing
│   ├── seo-malaga.html                 # SEO landing
│   ├── checkout.html
│   ├── checkout-basico.html
│   ├── checkout-premium.html
│   ├── success.html                    # Post-payment confirmation
│   └── cancel.html                    # Payment cancellation
│
├── servicios/                          # Service detail pages
│   ├── diseno-web-profesional.html
│   ├── tienda-online-profesional.html
│   └── optimizacion-seo-tecnica.html
│
├── blog/                               # Blog articles
│   ├── diseno-web-profesional-guia.html
│   ├── diseno-web-profesional-vs-plantillas.html
│   ├── crear-tienda-online-profesional.html
│   ├── seo-tecnico-core-web-vitals.html
│   └── template-articulo.html          # Template for new posts
│
├── guias/                              # Long-form SEO content hub
│   ├── index.html
│   ├── diseno-web/
│   │   ├── index.html
│   │   ├── diseno-web-profesional.html
│   │   ├── diseno-web-malaga.html
│   │   ├── crear-web-empresa.html
│   │   ├── cuanto-cuesta-una-web.html
│   │   ├── errores-diseno-web.html
│   │   ├── errores-que-hacen-que-tu-web-no-venda.html
│   │   ├── como-conseguir-clientes-con-tu-web.html
│   │   └── por-que-necesitas-una-web-optimizada.html
│   ├── seo/
│   │   ├── index.html
│   │   ├── que-es-seo.html
│   │   ├── seo-tecnico-web.html
│   │   ├── posicionar-en-google.html
│   │   └── errores-seo.html
│   └── negocio-online/
│       ├── index.html
│       ├── conseguir-clientes-web.html
│       ├── vender-online.html
│       ├── ingresos-online.html
│       └── errores-negocio.html
│
├── legal/
│   ├── aviso_legal.html
│   ├── politica_privacidad.html
│   └── politica_cookies.html
│
├── css/
│   ├── main.css                        # Entry point — imports all layers
│   ├── base/
│   │   ├── reset.css
│   │   ├── variables.css               # All CSS custom properties
│   │   ├── typography.css
│   │   ├── accessibility.css           # Focus styles, print, reduced motion
│   │   └── responsive.css
│   ├── layout/
│   │   ├── header.css
│   │   ├── hero.css
│   │   ├── sections.css
│   │   ├── grid.css
│   │   └── footer.css
│   ├── components/
│   │   ├── buttons.css
│   │   ├── cards.css
│   │   ├── badges.css
│   │   ├── breadcrumbs.css
│   │   ├── forms.css
│   │   ├── cta.css
│   │   ├── cookies.css
│   │   └── whatsapp.css
│   ├── pages/
│   │   ├── home.css
│   │   ├── servicios.css
│   │   ├── presupuesto.css
│   │   ├── blog.css
│   │   ├── precios.css
│   │   └── checkout.css
│   └── utilities/
│       ├── helpers.css
│       ├── animations.css
│       └── responsive.css              # Global responsive breakpoints
│
├── js/
│   ├── 01-core/
│   │   ├── main.js                     # Entry point — DOMContentLoaded
│   │   └── init.js                     # Module + page orchestrator
│   ├── 02-modules/
│   │   ├── menu.js                     # Mobile overlay menu
│   │   ├── forms.js                    # EmailJS form submission + validation
│   │   ├── leads.js                    # Silent backend lead capture
│   │   ├── payments.js                 # Payment buttons (currently disabled)
│   │   ├── cookies.js                  # Cookie consent banner
│   │   ├── search.js                   # Real-time header search
│   │   ├── whatsapp.js                 # WhatsApp floating widget
│   │   ├── breadcrumbs.js              # Dynamic breadcrumbs
│   │   ├── faq.js                      # FAQ accordion
│   │   └── scroll-reveal.js            # IntersectionObserver reveal
│   ├── 03-pages/
│   │   ├── home.js
│   │   ├── servicios.js
│   │   ├── presupuesto.js              # Dynamic budget calculator
│   │   ├── blog.js
│   │   ├── contacto.js
│   │   └── checkout.js
│   ├── 04-utils/
│   │   ├── helpers.js                  # capitalize, formatPrice, debounce, uid
│   │   ├── dom.js
│   │   └── events.js
│   └── 05-config/
│       └── settings.js                 # Central config (siteName, WhatsApp, etc.)
│
└── assets/
    └── img/
        └── branding/
            ├── logo-mastercodeweb.svg
            ├── favicon.png
            ├── apple_touch_icon.png
            ├── og/                     # Open Graph images (1200×630, .webp)
            │   ├── og-home.webp
            │   ├── og-servicios.webp
            │   ├── og-contacto.webp
            │   ├── og-presupuesto.webp
            │   └── og-blog.webp
            ├── home/
            │   ├── hero/
            │   └── cards/
            ├── iconos/
            │   ├── beneficios/
            │   ├── caracteristicas/
            │   └── otros/
            ├── redes_sociales/         # Social media icons (.webp)
            └── servicios/
                ├── cards/
                └── proceso/
```

---

## 4. Technologies Used

| Layer | Technology | Notes |
|---|---|---|
| Markup | HTML5 | Semantic elements (`<header>`, `<main>`, `<article>`, `<nav>`, `<footer>`) |
| Styles | CSS3 / Custom Properties | No preprocessor. Modular via `@import` |
| Scripting | Vanilla JavaScript (ES2022) | ES Modules, async/await, IntersectionObserver |
| Email | EmailJS v4 | CDN-loaded, client-side email delivery |
| Fonts | Google Fonts — Inter | 400, 600, 700, 900 weights |
| Images | WebP + SVG | WebP for photos/OG images; inline SVG for icons |
| Version control | Git | Project tracked via git |
| Dev server | Live Server (VS Code) | Base URL: `http://127.0.0.1:5500` |
| Hosting | TBD (static hosting) | Domain: mastercodeweb.com |
| Analytics | TBD | Not yet configured |
| Payments | Stripe (planned) | Backend endpoint planned, currently redirects to `/pages/presupuesto.html` |

---

## 5. EmailJS Integration

EmailJS is the **primary contact channel** for all form submissions. It operates entirely client-side — no custom server is required.

### Credentials (hardcoded in `js/02-modules/forms.js`)

```js
const EMAILJS_PUBLIC_KEY  = "hj2hf3j06xO8X87jx";
const EMAILJS_SERVICE_ID  = "service_f7sdyih";
const EMAILJS_TEMPLATE_ID = "template_r4lrntv";
```

> **Security note:** These are public-facing EmailJS keys, not secret server keys. They are safe to be visible in client-side code, but EmailJS templates should be configured with a domain allowlist to restrict abuse.

### How it works

1. EmailJS SDK is loaded via CDN (`<script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/...">`) and initialized with the public key at form init time.
2. On form submit, `forms.js` calls `window.emailjs.send()` with a template parameters object containing: `nombre`, `email`, `telefono`, `mensaje`, `tipo`, `presupuesto`, `origen`.
3. On success, the cooldown timestamp is written to `localStorage` under the key `mcw_last_submit` to prevent rapid resubmissions.
4. In parallel (non-blocking), a silent POST is made to `/api/leads` via `leads.js` for internal tracking.

### Template variables expected by EmailJS

| Variable | Source field |
|---|---|
| `nombre` | `input[name="nombre"]` |
| `email` | `input[name="email"]` |
| `telefono` | `select[name="prefijo"]` + `input[name="telefono"]` |
| `mensaje` | `textarea[name="mensaje"]` |
| `tipo` | `select[name="tipo"]` |
| `presupuesto` | `select[name="presupuesto"]` |
| `origen` | Form element id (`budgetForm` or `contactForm`) |

### Forms using EmailJS

- `#budgetForm` — `/pages/presupuesto.html`
- `#contactForm` — `/pages/contacto.html`

---

## 6. SEO Implementation

SEO is implemented across multiple layers, from HTML meta tags to structured data and content strategy.

### On-page meta tags (all pages)

Every page includes:
- `<title>` with keyword + brand pattern (e.g., `Diseño Web Profesional desde 349€ | MasterCodeWeb`)
- `<meta name="description">` — unique per page, 150–160 characters
- `<meta name="robots" content="index, follow">` (or `noindex` for system pages)
- `<link rel="canonical">` — absolute URL for every indexable page
- `<meta name="theme-color" content="#0d6cf2">`

### Open Graph (all pages)

Full OG implementation:
- `og:type`, `og:title`, `og:description`, `og:url`, `og:site_name`, `og:locale` (`es_ES`)
- `og:image` — dedicated `.webp` images at 1200×630 per section
- Twitter Card: `summary_large_image`

### Structured Data (JSON-LD)

| Page | Schema type |
|---|---|
| `index.html` | `ProfessionalService` |
| `pages/contacto.html` | `ContactPage` |
| `pages/precios.html` | `FAQPage` |
| Service pages | `Service` |

### Technical SEO files

- **`sitemap.xml`** — 13 URLs with `lastmod`, `changefreq`, and `priority` values. Covers main pages, service detail pages, SEO landing pages, and guides. Linked in `robots.txt`.
- **`robots.txt`** — Allows all robots on the site. Explicitly disallows: `/pages/success.html`, `/pages/cancel.html`, `/404.html`, `/js/`, `/css/`, `/assets/icons/`, `/admin/`, `/private/`.

### SEO content strategy

Three-tier content architecture:
1. **Main pages** — `/pages/servicios.html`, `/pages/precios.html` (commercial intent)
2. **Service landing pages** — `/servicios/diseno-web-profesional.html`, etc. (specific service + local SEO)
3. **Content hub** — `/guias/` with 15+ articles across three topic clusters: *Diseño Web*, *SEO*, *Negocio Online*
4. **Local SEO landing pages** — `/pages/diseno-web-malaga.html`, `/pages/seo-malaga.html`

---

## 7. Responsive Design

The site uses a **mobile-first** approach with responsive overrides handled in `css/utilities/responsive.css` and component-level media queries.

### Breakpoints

| Breakpoint | Width | Target |
|---|---|---|
| Mobile | ≤ 768px | Default styles + overrides |
| Desktop (large) | ≥ 1440px | Larger font size, more hero padding |
| 4K | ≥ 1920px | Font scale to 1.1rem, container max-width 90rem |

### Mobile menu

The mobile menu is implemented as a **full-screen overlay** (`#mobileOverlay`) rendered outside `<header>` to avoid stacking-context issues caused by `backdrop-filter`. Controlled by `js/02-modules/menu.js`:
- Opens/closes via `#menuToggle` button
- Closes on `Escape` key, overlay link click, or viewport resize above 768px
- ARIA: `aria-expanded`, `aria-hidden`, `aria-label` toggled on each state change
- Body scroll locked (`overflow: hidden`) while open

### Responsive behaviors

- Hero `h1` uses `clamp(1.9rem, 7vw, 2.4rem)` for fluid typography
- CTA button groups stack vertically at ≤ 768px with `width: 100%; max-width: 320px`
- Budget form grid collapses to single column at ≤ 768px
- Navigation hides and is replaced by hamburger menu at ≤ 768px

---

## 8. Accessibility

Implemented to target **WCAG 2.1 Level AA** compliance.

### Focus management

Custom focus-visible styles in `css/base/accessibility.css`:
```css
a:focus-visible,
button:focus-visible,
input:focus-visible,
textarea:focus-visible,
select:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px rgba(13, 108, 242, 0.65);
  border-radius: var(--radius-sm);
}
```

### Reduced motion

Blanket `prefers-reduced-motion: reduce` media query disables all animations/transitions and forces `.reveal` elements visible immediately.

### ARIA attributes in use

| Element | ARIA |
|---|---|
| Main nav | `aria-label="Menú principal"` |
| Mobile nav | `aria-label="Navegación móvil"` |
| Menu toggle | `aria-expanded`, `aria-label` toggled |
| Mobile overlay | `aria-hidden`, `role="dialog"` |
| Search input | `aria-controls`, `aria-autocomplete`, `aria-expanded` |
| Search dropdown | `role="listbox"`, `aria-label` |
| Search items | `role="option"`, `aria-selected` |
| Cookie banner | `role="dialog"`, `aria-live="polite"` |
| WhatsApp widget | `role="region"`, `role="dialog"` |
| Form errors | `role="alert"` |
| Toast messages | `role="status"`, `aria-live="polite"` |
| All decorative SVGs | `aria-hidden="true"` |
| All social links | `aria-label` with descriptive text |
| Logo links | `aria-label="MasterCodeWeb - Inicio"` |

### Print stylesheet

Complete print-optimized stylesheet in `css/base/accessibility.css`:
- Hides navigation, CTAs, cookie banner, WhatsApp widget
- Sets body to white background, black text, 12pt font
- Shows URLs in-line after anchor elements (`a::after { content: " (" attr(href) ")" }`)
- Prevents orphaned page breaks on cards and sections

### Screen-reader helpers

- `.sr-only` utility class applied to visually hidden labels (e.g., search label)
- All icon-only buttons and links have descriptive `aria-label` attributes

---

## 9. Anti-Spam Protections

Two client-side anti-spam layers are implemented in `js/02-modules/forms.js`:

### Honeypot field

A hidden field (`input[name="_hp"]`) is injected in the form. If it contains any value when the form is submitted (indicating a bot auto-filled it), the submission is silently dropped with no user feedback — bots receive no signal that they were detected.

```js
const hp = form.querySelector('[name="_hp"]');
if (hp && hp.value) return;
```

### Rate-limit cooldown (localStorage)

After a successful EmailJS submission, a Unix timestamp is saved in `localStorage` under the key `mcw_last_submit`. On every subsequent submit attempt, the elapsed time is checked:

```js
const COOLDOWN_MS = 60_000; // 60 seconds
const last = parseInt(localStorage.getItem(COOLDOWN_KEY) || "0", 10);
if (Date.now() - last < COOLDOWN_MS) {
  showToast("Por favor, espera un momento antes de enviar otro mensaje.", "error");
  return;
}
```

> Note: This cooldown is client-side only and can be bypassed by clearing localStorage. For production hardening, complement with EmailJS domain restrictions and, optionally, a server-side rate limiter.

---

## 10. Performance Optimizations

### Asset formats

- All photographic images use **WebP** format for superior compression.
- All UI icons are inline **SVG** — zero network requests, infinitely scalable.
- Open Graph images are dedicated `.webp` files at exactly 1200×630.

### Font loading

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/...">
```
- DNS pre-connection established before the font request
- Font stylesheet preloaded to avoid render-blocking

### CSS

- Single `<link rel="stylesheet">` in `<head>` — no render-blocking inline scripts
- CSS custom properties used throughout — no runtime JS required for theming

### JavaScript

- `<script type="module" src="/js/01-core/main.js">` deferred by default (module scripts are deferred)
- All modules use ES Modules — tree-shakeable by nature if a bundler is added later
- No third-party UI libraries — zero jQuery, zero component frameworks
- Page-specific init only runs if the URL matches (`path.includes(match)`)

### Scroll Reveal

`IntersectionObserver` with `threshold: 0.12` drives the `.reveal` / `.is-visible` class-toggle animation. Elements are unobserved after they appear — no ongoing polling.

### Core Web Vitals considerations

- No layout shift caused by fonts (`font-display: swap` implied by Google Fonts `display=swap` parameter)
- No render-blocking scripts in `<head>`
- WhatsApp widget auto-shows after a 2-second delay (throttled, shown only once per session)

---

## 11. Pending Tasks

### Critical

- [ ] **Activate payments backend** — `payments.js` currently redirects all payment button clicks to `/pages/presupuesto.html`. The Stripe checkout endpoint (`/api/checkout`) is built and commented out, awaiting a live Node.js/Express backend.
- [ ] **Activate `/api/leads` backend** — `leads.js` silently POSTs to `/api/leads` which returns a 404 in the current static deployment. A backend must be deployed to capture leads to a database.
- [ ] **Configure EmailJS domain restriction** — Set an allowed domain in the EmailJS dashboard to prevent credential abuse from external sites.

### High priority

- [ ] **Update `js/05-config/settings.js`** — The `whatsapp.phone` field still contains a placeholder value (`"34600000000"`). The real number is hardcoded in multiple files but not centralized in settings yet.
- [ ] **sitemap.xml lastmod dates** — All entries show `2026-04-04`. Should be updated on each deploy or generated dynamically.
- [ ] **`sitemap.xml` missing URLs** — `/guias/seo/optimizar-velocidad-web.html` is referenced in the sitemap but the file does not exist in the repository.
- [ ] **Remove debug `console.log` statements** — `cookies.js` contains development `console.log` calls that should be removed before production.

### Medium priority

- [ ] **Analytics integration** — No analytics tool is currently configured. Consider Google Analytics 4 or a privacy-respecting alternative (Plausible, Fathom).
- [ ] **Server-side rate limiting** — Complement the client-side cooldown with server-side rate limiting once the backend is live.
- [ ] **Sitemap auto-generation** — As the guide content grows, sitemap.xml maintenance becomes error-prone. Consider a build-time generator.
- [ ] **Breadcrumb structured data** — `breadcrumbs.js` generates visual breadcrumbs but JSON-LD `BreadcrumbList` markup is not yet emitted.

### Low priority

- [ ] **`js/05-config/settings.js` expansion** — Centralize the EmailJS credentials, base URL (currently `http://127.0.0.1:5500`), and phone number into this file.
- [ ] **Blog pagination** — The blog index currently lists all articles statically. As post count grows, client-side or server-side pagination will be needed.
- [ ] **Image lazy loading** — Add `loading="lazy"` to below-the-fold images across all pages.
- [ ] **`template-articulo.html` enforcement** — Ensure all future blog articles follow the existing template for consistent SEO and structure.

---

## 12. Deployment Status

| Item | Status |
|---|---|
| Domain | `mastercodeweb.com` — registered |
| Hosting | TBD — static hosting required |
| HTTPS/SSL | Required on hosting provider |
| CI/CD | None configured |
| Current dev URL | `http://127.0.0.1:5500` (Live Server) |
| Payment processing | Disabled — redirects to contact form |
| Lead API | Disabled — no active backend |
| EmailJS | Active — configured with real service/template IDs |

The site is currently in local development. The static site can be deployed to any static hosting platform (Vercel, Netlify, GitHub Pages, Cloudflare Pages) without modification, since it requires no build step. The `/api/` endpoints require a separate backend deployment.

---

## 13. Environment & Config Requirements

This project has **no runtime environment variables** — it is a static site. All configuration is embedded in source files.

### Files requiring manual updates before production

| File | Variable | Current value | Action |
|---|---|---|---|
| `js/05-config/settings.js` | `baseUrl` | `http://127.0.0.1:5500` | Change to `https://www.mastercodeweb.com` |
| `js/05-config/settings.js` | `whatsapp.phone` | `"34600000000"` | Update to `"34680762047"` |
| `js/02-modules/forms.js` | `EMAILJS_PUBLIC_KEY` | `"hj2hf3j06xO8X87jx"` | Verify / rotate if needed |
| `js/02-modules/forms.js` | `EMAILJS_SERVICE_ID` | `"service_f7sdyih"` | Verify active in EmailJS dashboard |
| `js/02-modules/forms.js` | `EMAILJS_TEMPLATE_ID` | `"template_r4lrntv"` | Verify template exists |
| `js/02-modules/cookies.js` | Debug logs | `console.log(...)` | Remove before launch |

### Future backend environment variables (when activated)

```env
# server/.env (not yet committed)
STRIPE_SECRET_KEY=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
DATABASE_URL=postgresql://...
PORT=3000
NODE_ENV=production
```

---

## 14. DNS & Email Configuration

### Domain

- **Registrar:** TBD
- **Primary domain:** `mastercodeweb.com`
- **Canonical:** `https://www.mastercodeweb.com` (with `www`)
- All pages use absolute canonical URLs pointing to `https://www.mastercodeweb.com/...`

### Required DNS records

| Type | Name | Value | Purpose |
|---|---|---|---|
| A / CNAME | `@` | Hosting IP or CNAME | Root domain |
| CNAME | `www` | Hosting CNAME target | www subdomain |
| TXT | `@` | SPF record | Email authentication |
| CNAME / TXT | `_domainkey` | DKIM record | Email authentication |
| TXT | `@` | DMARC record | Email policy |

### Email

- **Contact address:** `contact@mastercodeweb.com`
- **Referenced in:** footer, legal pages, form error messages, EmailJS reply-to
- Email delivery is handled by EmailJS — the address `contact@mastercodeweb.com` must exist and be accessible. It should be configured on the domain's email provider (Google Workspace, Zoho Mail, etc.).

### EmailJS domain allowlist

Configure `www.mastercodeweb.com` (and optionally `127.0.0.1` during development) as allowed origins in the EmailJS dashboard under the service settings to prevent key abuse.

---

## 15. Coding Conventions

### HTML

- Language declared: `<html lang="es">`
- Semantic sectioning: `<header>`, `<main>`, `<section>`, `<article>`, `<footer>`, `<nav>`
- Active nav link marked with class `active` on the current page's `<a>` element
- External links: always `target="_blank" rel="noopener noreferrer"`
- All icon SVGs marked `aria-hidden="true"` when decorative
- Schema JSON-LD in `<script type="application/ld+json">` blocks in `<head>` or at end of `<body>`

### CSS

- **Custom properties** for all design tokens (colors, spacing, typography, shadows, z-indexes)
- **BEM-inspired naming**: `.header__container`, `.hero__actions`, `.why-card__body`
- **Utility classes**: `.sr-only`, `.reveal`, `.is-visible`, `.is-loading`, `.is-active`, `.is-open`
- **Layer order in `main.css`**: base → layout → components → pages → utilities → responsive (responsive always last)
- No `!important` except in the `prefers-reduced-motion` block where it is semantically required

### JavaScript

- **ES Modules** throughout — `import`/`export`, no global namespace pollution
- **Named exports** for all init functions: `export function initMenu()`
- **Async/await** for all async operations
- **Optional chaining** (`?.`) used for safe DOM queries
- **Nullish coalescing** (`??`) used for default fallback values
- Module init functions are side-effect free until called — all init code is inside the exported function
- No external libraries or frameworks — zero runtime dependencies
- Numbered folder prefixes (`01-core`, `02-modules`, etc.) communicate load order intent

### File naming

- HTML files: `kebab-case.html`
- CSS files: `kebab-case.css`
- JS files: `kebab-case.js`
- Image assets: `snake_case.webp` / `snake_case.svg`

---

## 16. Reusable Components & Modules

### CSS components (drop-in classes)

| Component | File | Class pattern |
|---|---|---|
| Buttons | `css/components/buttons.css` | `.btn`, `.btn--primary`, `.btn--secondary` |
| Cards | `css/components/cards.css` | `.card`, `.service-card`, `.why-card`, `.expect-card` |
| Badges | `css/components/badges.css` | `.badge`, `.hero__badge` |
| Breadcrumbs | `css/components/breadcrumbs.css` | `.breadcrumbs`, `.breadcrumbs__item` |
| Forms | `css/components/forms.css` | `.form-group`, `.form-error`, `.form-status` |
| CTA sections | `css/components/cta.css` | `.home-cta`, `.services-cta` |
| Cookie banner | `css/components/cookies.css` | `#cookieBanner`, `.cookie-inner`, `.cookie-btn` |
| WhatsApp widget | `css/components/whatsapp.css` | `#wa-widget`, `#wa-card`, `.wa-header` |

### JS modules (import and call)

| Module | Init function | Responsibility |
|---|---|---|
| `js/02-modules/menu.js` | `initMenu()` | Mobile overlay menu, ARIA state, body scroll lock |
| `js/02-modules/forms.js` | `initForms()` | EmailJS submit, validation, honeypot, cooldown, toast |
| `js/02-modules/leads.js` | `initLeads()` + `sendLead()` | Silent POST to `/api/leads` |
| `js/02-modules/payments.js` | `initPayments()` | Payment button listeners (currently stub) |
| `js/02-modules/cookies.js` | `initCookies()` | Cookie consent banner, localStorage persistence |
| `js/02-modules/search.js` | `initSearch()` | Real-time site search with keyboard nav |
| `js/02-modules/whatsapp.js` | `initWhatsapp()` | WhatsApp floating card, auto-show once |
| `js/02-modules/breadcrumbs.js` | `initBreadcrumbs()` | Dynamic breadcrumb generation |
| `js/02-modules/faq.js` | `initFaq()` | FAQ accordion expand/collapse |
| `js/02-modules/scroll-reveal.js` | `initScrollReveal()` | IntersectionObserver `.reveal` animations |

### JS utilities

| Utility | File | Exports |
|---|---|---|
| String/price helpers | `js/04-utils/helpers.js` | `capitalize`, `formatPrice`, `debounce`, `uid` |
| DOM helpers | `js/04-utils/dom.js` | DOM query utilities |
| Event helpers | `js/04-utils/events.js` | Custom event utilities |

### Page init functions

| Page | Function | File |
|---|---|---|
| Home | `initHome()` | `js/03-pages/home.js` |
| Servicios | `initServicios()` | `js/03-pages/servicios.js` |
| Presupuesto | `initPresupuesto()` | `js/03-pages/presupuesto.js` |
| Blog / Guías | `initBlog()` | `js/03-pages/blog.js` |
| Contacto | `initContacto()` | `js/03-pages/contacto.js` |
| Checkout | `initCheckout()` | `js/03-pages/checkout.js` |

### Safe initialization wrapper

```js
function safeInit(fn, name) {
  try {
    fn();
  } catch (error) {
    console.warn(`[MCW] Error en ${name}:`, error);
  }
}
```

Wrap any new module call in `safeInit()` when adding it to `init.js`. This prevents one broken module from crashing others.

### Adding a new page

1. Create `pages/my-page.html` using an existing page as template
2. Add full `<head>` SEO block (title, description, canonical, OG, Twitter Card)
3. Include JSON-LD structured data appropriate to the page type
4. Create `js/03-pages/my-page.js` and export `initMyPage()`
5. Import and register it in `init.js` with a route matcher
6. Add the URL to `sitemap.xml`
7. Add the page to the search index in `js/02-modules/search.js`

---

## 17. Future Roadmap

### Phase 1 — Production launch (immediate)

- Activate static hosting on Vercel, Netlify, or Cloudflare Pages
- Point `mastercodeweb.com` DNS to hosting
- Configure SSL/HTTPS
- Set EmailJS allowed domain to `www.mastercodeweb.com`
- Fix `settings.js` placeholder values
- Remove debug `console.log` from `cookies.js`

### Phase 2 — Backend activation (short-term)

- Deploy Node.js/Express backend for:
  - `POST /api/leads` — CRM lead capture (store in PostgreSQL or Airtable)
  - `POST /api/checkout` — Stripe checkout session creation
  - Webhook handling for payment events
- Enable payment buttons in `payments.js`
- Implement server-side form rate limiting

### Phase 3 — Analytics & conversion tracking (short-term)

- Integrate Google Analytics 4 or Plausible Analytics
- Set up Google Search Console and submit sitemap
- Configure conversion events: form submissions, WhatsApp clicks, plan selection

### Phase 4 — Content & SEO growth (medium-term)

- Publish remaining guide articles (5–10 planned per cluster)
- Add `BreadcrumbList` JSON-LD to breadcrumb pages
- Add `Article` schema to all blog and guide pages
- Implement client-side or static-generated blog pagination
- Build out `/pages/diseno-web-malaga.html` and `/pages/seo-malaga.html` for local search

### Phase 5 — UX enhancements (medium-term)

- Testimonials/reviews section with real client quotes and `Review` schema
- Portfolio / case studies section
- Exit-intent lead capture or scroll-triggered CTA
- Lazy loading (`loading="lazy"`) on all below-fold images
- Service Worker for offline support and faster repeat visits

### Phase 6 — Internationalization (long-term)

- English version at `/en/` for international clients
- `hreflang` tags for bilingual pages

### Phase 7 — Admin panel (long-term)

- Simple CMS or headless CMS (Decap CMS, Sanity) for blog/guide content management without code editing
- Lead dashboard connected to the backend database

---

*Document generated: May 10, 2026*  
*Working directory: `~/Library/Mobile Documents/com~apple~CloudDocs/2026/mastercodeweb/`*
