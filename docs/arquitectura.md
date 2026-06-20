# Arquitectura técnica — MasterCodeWeb

## Stack

| Capa | Tecnología | Versión |
|------|-----------|---------|
| Servidor | LiteSpeed (Hostinger Shared Hosting) | Apache-compatible |
| Backend | PHP | 8+ |
| Frontend | Vanilla JS ES6 modules | Sin bundler |
| CSS | Modular manual + bundles concatenados | Sin preprocesador |
| Dependencias PHP | `stripe/stripe-php` vía Composer | Vendorizado en `/vendor/` |
| Deploy | Git push a `main` → producción automática | Sin CI/CD formal |
| Caché | LiteSpeed Cache (HTML 1h TTL, assets 1 mes) | `.htaccess` |
| Compresión | Brotli (CSS/JS/HTML) | Hostinger |

## Estructura de directorios

```
mastercodeweb/
├── index.html                    ← Homepage (raíz del dominio)
├── pages/                        ← Páginas principales (~30 HTML)
│   ├── servicios.html, precios.html, presupuesto.html, contacto.html
│   ├── blog.html, portfolio.html, sobre-nosotros.html, reviews.html
│   ├── diseno-web-malaga.html    ← Landing local Málaga
│   ├── diseno-web-{ciudad}.html  ← 7 landings locales adicionales
│   ├── seo-malaga.html           ← Landing SEO local
│   ├── desarrollo-web-malaga.html, mantenimiento-web-malaga.html
│   ├── tienda-online-malaga.html
│   ├── checkout-{plan}.html      ← 6 páginas checkout (noindex)
│   ├── acceso-cliente.html, cliente.html, customer-portal.html
│   └── demos/                   ← 10 demos sectoriales (noindex)
├── servicios/                   ← 3 páginas de servicio profundas
│   ├── diseno-web-profesional.html
│   ├── optimizacion-seo-tecnica.html
│   └── tienda-online-profesional.html
├── guias/                       ← 20 guías SEO (hub de contenido)
│   ├── diseno-web/  (8 artículos + index.html)
│   ├── seo/         (5 artículos + index.html)
│   └── negocio-online/ (4 artículos + index.html)
├── blog/                        ← 2 artículos (sistema separado)
├── legal/                       ← aviso_legal, politica_cookies, politica_privacidad
├── api/                         ← 9 endpoints PHP
├── config/                      ← bootstrap.php + módulos de config
├── css/                         ← 35 archivos fuente + 2 bundles
├── js/                          ← 20 módulos ES6
├── assets/                      ← ~22 MB (imágenes, SVGs, branding)
├── storage/logs/                ← stripe.log (append-only)
├── vendor/                      ← stripe/stripe-php (Composer, no tocar)
└── docs/                        ← Esta documentación técnica
```

## Frontend JS — arquitectura de módulos

```
js/01-core/main.js           ← Entry point único (type="module")
    └── js/01-core/init.js   ← Router + imports estáticos
          ├── js/02-modules/  ← 12 módulos funcionales (todos cargados en TODAS las páginas)
          │   ├── analytics.js       GA4 + Clarity lazy
          │   ├── cookies.js         Consent Mode v2
          │   ├── menu.js            Navegación + mobile overlay
          │   ├── search.js          Búsqueda con dropdown
          │   ├── faq.js             Acordeón FAQ
          │   ├── whatsapp.js        Botón flotante WhatsApp
          │   ├── scroll-reveal.js   Animaciones al scroll
          │   ├── breadcrumbs.js     Breadcrumbs automáticos
          │   ├── reviews.js         Carga reviews del API
          │   ├── payments.js        Stripe Checkout + portal
          │   ├── forms.js           Formulario contacto + presupuesto
          │   └── breadcrumbs.js     Schema BreadcrumbList
          └── js/03-pages/    ← 6 módulos de página (carga condicional por path)
              ├── home.js            Animaciones homepage
              ├── blog.js            Filtros + cards blog
              ├── contacto.js        Lazy-load Turnstile
              ├── presupuesto.js     Calculadora presupuesto
              ├── servicios.js       Tabs servicios
              └── checkout.js        Plan selector + checkout flow
```

**Nota de arquitectura:** Los imports de `js/02-modules/` son estáticos — se descargan en TODAS las páginas aunque no los use. `payments.js` (~18 KB) y `reviews.js` cargán en la página de contacto aunque no los necesite. Pendiente: implementar dynamic imports para eliminar ~64 KB de JS no utilizado por página (Tarea C, deferred).

## CSS — pipeline actual

```
css/
├── base/         reset, variables, typography, accessibility, responsive
├── layout/       header, hero, sections, grid, footer
├── components/   buttons, cards, badges, breadcrumbs, forms, cta, cookies,
│                 whatsapp, turnstile, reviews
├── pages/        blog, checkout, cliente, demos, home, portfolio, precios,
│                 presupuesto, servicios, sobre-nosotros
├── utilities/    animations, helpers, responsive
├── fonts/        inter.css + inter-latin.woff2 + inter-latin-ext.woff2
└── main.css      ← Archivo orquestador (no usado en producción directamente)

Bundles compilados:
├── css/main.bundle.css           259 KB — usado por todas las páginas excepto contacto
└── css/contacto.bundle.css       120 KB — usado solo por contacto.html (sin CSS de páginas ajenas)
```

**Actualización de bundles:** Los bundles son concatenación manual. Cuando se modifica un archivo fuente:
1. Regenerar el bundle con `cat` o editor
2. Incrementar la versión en todos los HTML que lo referencian (`v=YYYYMMDD`)
3. El script de actualización masiva usa `sed` sobre los 67 HTML que usan `main.bundle.css`

## Archivos críticos

| Archivo | Rol | Riesgo si se corrompe |
|---------|-----|----------------------|
| `config/bootstrap.php` | Punto de entrada único para todo el backend PHP | CRÍTICO — todos los endpoints dejan de funcionar |
| `.env` | Credenciales Stripe, Resend, CSRF salt | CRÍTICO — nunca commitear, no existe en git |
| `vendor/` | stripe/stripe-php SDK | ALTO — requiere `composer install` para regenerar |
| `css/main.bundle.css` | CSS de 66 páginas | ALTO — regenerar con `cat` desde `/css/` |
| `js/01-core/init.js` | Routing JS de toda la app | ALTO — un error rompe toda la interactividad |
| `.htaccess` | Seguridad, redirects, caché, compresión | ALTO — un error puede tirar el sitio |
| `sitemap.xml` | Indexación Google | MEDIO — actualizar al añadir páginas |
| `api/data/reviews-approved.json` | Reviews públicas | MEDIO — si se corrompe, la página de reviews falla |
| `storage/logs/stripe.log` | Log de pagos | BAJO — no afecta funcionalidad, pero pérdida de auditoría |

## Seguridad

- **CSRF:** token generado en `config/csrf.php`, validado en cada endpoint POST
- **Rate limiting:** por IP en `/tmp/mcw_rl_{context}_{ip}.json` (efímero — riesgo en producción compartida)
- **Stripe webhooks:** verificación HMAC-SHA256 con `STRIPE_WEBHOOK_SECRET` de `.env`
- **Turnstile:** verificación server-side en `api/verify-turnstile.php` antes de enviar email
- **Anti-spam formularios:** honeypot + tiempo mínimo (2s) + límite 5 envíos/24h por IP
- **Headers HTTP:** HSTS, X-Frame-Options, X-Content-Type-Options, CSP, Referrer-Policy (en `.htaccess`)
- **Acceso bloqueado:** `vendor/`, `config/`, `storage/`, `.env`, dotfiles, `_templates/`

## Dependencias externas en producción

| Servicio | Uso | Impacto si cae |
|---------|-----|----------------|
| Hostinger | Hosting PHP + LiteSpeed | CRÍTICO |
| Stripe | Pagos + suscripciones + portal | ALTO — checkout no funciona |
| Resend | Email transaccional | MEDIO — formularios no envían email |
| Cloudflare Turnstile | Anti-spam | BAJO — formulario bloquea envío si no se carga |
| Google Analytics 4 | Analytics | BAJO — solo analytics, no afecta funcionalidad |
| Microsoft Clarity | Heatmaps | BAJO — solo analytics |
