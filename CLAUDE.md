# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

MasterCodeWeb is a static HTML/CSS/JS marketing website for a web design agency in Málaga, Spain, with a Node.js/Express backend for Stripe payments and lead capture via email.

## Running the Project

**Frontend** — serve statically on port 5500 (the JS assumes this port locally):
```bash
npx http-server -p 5500
```

**Backend API** — requires Node.js ≥18:
```bash
cd server
cp .env.example .env      # fill in real values
npm install
npm run dev               # node --watch app.js
```

Server runs on `http://localhost:3000`. There is no build step, bundler, or test suite.

## Page Inventory

| File | Purpose |
|---|---|
| `index.html` | Homepage |
| `servicios.html` | Services overview |
| `precios.html` | Pricing + FAQ |
| `presupuesto.html` | Budget request form |
| `contacto.html` | Contact form + map |
| `blog.html` | Blog listing |
| `success.html` | Post-payment success |
| `cancel.html` | Post-payment cancel |
| `404.html` | Error page |

## Architecture

### Frontend (Vanilla JS ES Modules, no framework)

All pages load exactly: `<link rel="stylesheet" href="/css/main.css">` and `<script type="module" src="/js/01-core/main.js">`.

Page initialization flows through:
1. `js/01-core/main.js` — DOMContentLoaded entry point
2. `js/01-core/init.js` — registers all global modules via `safeInit()`, detects current page via `window.location.pathname`, loads matching page module
3. `js/03-pages/*.js` — page-specific logic (minimal — most logic is in modules)
4. `js/02-modules/*.js` — reusable feature modules

**Module ownership — do not mix:**
- `payments.js` — exclusively owns `.mcw-pago-btn` click → Stripe checkout + silent `sendLead()`
- `leads.js` — exports `sendLead()` only; no event listeners of its own
- `forms.js` — handles `#budgetForm` and `#contactForm`; validates fields (`nombre`, `email`, `mensaje`); calls `sendLead()` on success
- `scroll-reveal.js` — IntersectionObserver activating `.reveal` → `.is-visible`
- `cookies.js` — manages `#cookieBanner` and `#cookieModal`; requires both elements in HTML
- `menu.js` — hamburger toggle; requires `#menuToggle` and `#navMenu`; closes on Escape + click-outside
- `search.js` — requires `#searchInput` and `#searchDropdown` in HTML (only `index.html` has them)
- `whatsapp.js` — manages `#wa-card`; auto-shows once via localStorage

### CSS Architecture

`css/main.css` imports in this strict order: `base/` → `layout/` → `components/` → `pages/` → `utilities/responsive.css` (always last).

Key CSS patterns:
- Add class `reveal` to any element for scroll-triggered fade-in (stagger applies to nth-child 1–5)
- `.btn.is-loading` shows CSS spinner
- `.hero--small` for interior page heroes; `.hero--pro` for homepage
- `.service-detail__grid` / `--reverse` for alternating image+text layouts
- Plan items get automatic checkmarks via `ul li::before` in `.plan ul`

### Backend (`server/`)

Express API — entry: `server/app.js`:
- `POST /api/leads` — validates, calls `sendEmail()` (non-blocking if no credentials)
- `POST /api/payments/checkout` — price whitelist `[299, 599, 999]`; URLs from `process.env.BASE_URL`

CORS is restricted to a whitelist in `app.js`. Adding a new allowed origin requires editing that array.

### Environment variables (`server/.env`)

All documented in `server/.env.example`. Key ones:
- `BASE_URL` — frontend URL for Stripe redirects (`http://127.0.0.1:5500` locally, production domain in prod)
- `STRIPE_SECRET_KEY` — `sk_test_` in dev, `sk_live_` in prod
- `GMAIL_USER` / `GMAIL_PASSWORD` — Gmail App Password (not account password)

### Required HTML structure per page

Every page must have:
- `id="header"` on `<header>`
- `id="navMenu"` on `<nav>`
- `id="menuToggle"` on the hamburger button
- `id="cookieBanner"` with `#acceptCookies` and `#rejectCookies` buttons
- Single `<script type="module" src="/js/01-core/main.js">` at end of body
- Only `/css/main.css` — never individual CSS files

### Adding a new pricing plan

1. Add button in HTML with `.mcw-pago-btn` and `data-nombre` / `data-precio` attributes
2. Add the price to `VALID_PRICES` array in `server/routes/payments.js`
3. Update `sitemap.xml` if a new page is created
