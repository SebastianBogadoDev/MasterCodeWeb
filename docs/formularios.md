# Formularios — Documentación técnica

## Formularios existentes

| Formulario | Página | ID HTML | Endpoint |
|-----------|--------|---------|----------|
| Contacto | `pages/contacto.html` | `#contactForm` | POST `/api/send-form.php` |
| Presupuesto | `pages/presupuesto.html` | `#budgetForm` | POST `/api/send-form.php` |

Ambos formularios comparten lógica en `js/02-modules/forms.js`.

## Flujo de envío

```
Usuario → rellena campos → submit
  ↓
forms.js:
  1. Honeypot check   — campo .form-group--hp no debe estar relleno
  2. Time check       — mínimo 2 segundos desde que se abrió el form
  3. Human check      — debe haber habido interacción real (keydown/click/touchstart)
  4. Cooldown check   — 60 segundos entre envíos
  5. Abuse limit      — máximo 5 envíos en 24 horas (localStorage)
  6. Turnstile check  — token cf-turnstile-response debe estar presente y no vacío
  ↓
POST /api/send-form.php
  1. rateLimitIp('form', 5, 86400)  — máx 5 envíos/24h por IP (server-side)
  2. validateCsrf()                  — token CSRF en header X-CSRF-Token
  3. sanitize() todos los campos
  4. POST /api/verify-turnstile.php  — verifica token con Cloudflare API
  5. Construcción del email HTML
  6. Resend API (Bearer token)       — envía email a destinatario configurado en .env
  7. Devuelve { ok: true } o { error: "..." }
  ↓
forms.js:
  → Toast notification success/error
  → GA4 event 'envio_formulario' (solo en éxito)
```

## Anti-spam multicapa

| Capa | Dónde | Qué detecta |
|------|-------|-------------|
| Honeypot | Client + Server | Bots que rellenan campos ocultos |
| Time check | Client | Bots que envían en <2 segundos |
| Human interaction | Client | Bots sin eventos de teclado/ratón |
| Cooldown 60s | Client (localStorage) | Spam repetido por humanos |
| Abuse limit 5/24h | Client (localStorage) | Intentos masivos desde mismo browser |
| Rate limit 5/24h IP | Server (/tmp) | Bots que evitan el browser |
| CSRF token | Server | Peticiones cross-origin |
| Turnstile | Client + Server | Bots + scrapers avanzados |

## Variables de entorno requeridas

```
RESEND_API_KEY=re_...             # API key de Resend
RESEND_FROM=noreply@mastercodeweb.com
RESEND_TO=rsbbaez@gmail.com       # Email destinatario
CSRF_SECRET=...                   # Salt para tokens CSRF
CF_TURNSTILE_SECRET=...           # Secreto de Cloudflare Turnstile
```

## Turnstile (lazy-load en contacto.html)

El script de Turnstile NO se carga al abrir la página. Se carga de forma lazy en el primer `focusin` o `touchstart` sobre el formulario (implementado en `js/03-pages/contacto.js`). Esto mejora LCP al eliminar un script bloqueante de 3rd party.

Si el usuario envía el formulario antes de que Turnstile haya renderizado el widget, `forms.js` muestra un error y hace scroll al widget para que el usuario lo complete.

## Email templates

Los emails se generan como HTML en `api/send-form.php`. La plantilla usa tabla-based layout (compatible con clientes de email como Gmail, Outlook). Enviados desde Resend con dominio `mastercodeweb.com`.

## Procedimiento si el formulario no envía

1. Verificar `storage/logs/stripe.log` (los errores de send-form también se loguean aquí)
2. Verificar `RESEND_API_KEY` en `.env`
3. Verificar que el dominio `mastercodeweb.com` está verificado en Resend Dashboard
4. Verificar la respuesta del endpoint directamente: `curl -X POST /api/send-form.php -d '{"name":"test"}'`
5. Si Turnstile falla: verificar `CF_TURNSTILE_SECRET` en `.env` + que el widget ID en el HTML coincide con el configurado en Cloudflare Dashboard
