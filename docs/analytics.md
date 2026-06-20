# Analytics — Documentación técnica

## Google Analytics 4

- **ID de propiedad:** G-1KC6M4JG6V
- **Implementación:** `js/02-modules/analytics.js` — cargado en todas las páginas
- **Consent Mode v2:** activo — `analytics_storage: denied` por defecto hasta consentimiento explícito en el banner de cookies (`js/02-modules/cookies.js`)

### Eventos GA4 personalizados implementados

| Evento | Dónde se dispara | Parámetros |
|--------|-----------------|------------|
| `envio_formulario` | `forms.js` — tras envío exitoso | `form_type: 'contacto'\|'presupuesto'` |
| `begin_checkout` | `payments.js` — al iniciar checkout Stripe | `plan, precio` |
| `page_view` | Automático GA4 | — |
| `scroll` | Automático GA4 | `percent_scrolled` |

### CHECKOUT_PLAN_MAP (analytics.js)

```javascript
const CHECKOUT_PLAN_MAP = {
  'basico':      { nombre: 'Básico',      precio: 349 },
  'profesional': { nombre: 'Profesional', precio: 599 },
  'premium':     { nombre: 'Premium',     precio: 999 },
  'mant-basico': { nombre: 'Mant. Básico',  precio: 29 },
  'mant-pro':    { nombre: 'Mant. Pro',     precio: 49 },
  'mant-premium':{ nombre: 'Mant. Premium', precio: 79 },
};
```

### Consent Mode v2

El consentimiento se gestiona con cookie `mcw_cookie_consent`:
- `accepted` → `analytics_storage: granted` + `ad_storage: granted`
- `rejected` → permanece denegado
- Sin cookie → denegado (default)

GA4 usa Consent Mode v2 para modelado de conversiones aunque el usuario rechace cookies.

## Microsoft Clarity

- **Project ID:** `x84br63jrn`
- **Implementación:** `js/02-modules/analytics.js` — lazy-loaded después del primer user interaction (no bloquea LCP)
- **Función:** Heatmaps, session recordings, click maps
- **Dashboard:** app.clarity.ms

### Eventos Clarity personalizados

Clarity recibe automáticamente clics, scroll, rage clicks y dead clicks. No hay eventos personalizados adicionales implementados actualmente.

## Landing attribution tracking

`js/02-modules/analytics.js` guarda en `sessionStorage` la landing page inicial y la fuente de tráfico (`utm_source`, `utm_medium`, `utm_campaign`) para atribuir conversiones al formulario correcto aunque el usuario navegue entre páginas.

## Procedimientos

### Si GA4 no recibe datos
1. Verificar que el banner de cookies no está bloqueando (`analytics_storage: granted` en `window.dataLayer`)
2. Verificar en Google Tag Assistant que los eventos se disparan
3. El ID `G-1KC6M4JG6V` está hardcoded en `analytics.js` — no usar .env para esto

### Si Clarity no carga
1. Verificar CSP en `.htaccess` — el dominio de Clarity debe estar en `script-src`
2. Clarity se carga lazy (no en el primer render) — es normal que no aparezca en Lighthouse

### Añadir nuevo evento GA4
```javascript
// En el módulo correspondiente:
if (window.gtag) {
  window.gtag('event', 'nombre_evento', {
    parametro_1: valor,
    parametro_2: valor,
  });
}
```
Usar `if (window.gtag)` como guardia — gtag puede no estar disponible si el usuario rechazó cookies.
