# Escalabilidad — Análisis de módulos futuros

> SOLO DOCUMENTACIÓN. Ningún módulo de este documento está implementado.
> Actualizado: junio 2026.

---

## CRM (gestión de leads)

**Estado actual:** Los leads llegan por email (Resend) sin seguimiento estructurado. No hay base de datos de leads.

**Propuesta:** Cada envío de `api/send-form.php` también escribe en `storage/leads.sqlite` (SQLite).

| Campo | Detalle |
|-------|---------|
| Esfuerzo | 1-2 días (PHP + SQLite, sin dependencias nuevas) |
| Riesgo | BAJO — SQLite es estable, PHP lo soporta nativo |
| Dependencias | SQLite (incluido en PHP 8+) |
| Prioridad | **MEDIA** — útil cuando leads superen 20-30/mes |
| Prerequisito | Ninguno — puede añadirse independientemente |

**Consideración de escalado:** SQLite aguanta hasta ~50-100 leads/día sin problema en Hostinger. Si el volumen crece más, migrar a MySQL (disponible en el mismo hosting).

**Riesgo de no hacerlo:** Sin registro de leads, si un email de Resend no llega, el lead se pierde sin posibilidad de recuperación.

---

## Área de clientes (privada)

**Estado actual:** `pages/acceso-cliente.html` y `pages/cliente.html` existen pero son placeholders. El Stripe Customer Portal cubre la gestión de suscripciones y facturas.

**Lo que ya funciona con Stripe Portal:**
- Ver y descargar facturas
- Cambiar tarjeta de crédito
- Cancelar suscripción
- Ver historial de pagos

**Lo que faltaría en un área de clientes real:**
- Ver estado del proyecto (en desarrollo / entregado / en mantenimiento)
- Descargar archivos del proyecto (contratos, assets)
- Enviar mensajes/tickets de soporte
- Ver historial de cambios en la web

| Campo | Detalle |
|-------|---------|
| Esfuerzo | 4-6 semanas (auth PHP sessions, UI, base de datos de proyectos) |
| Riesgo | ALTO — introduce autenticación nueva, superficie de ataque mayor |
| Dependencias | SQLite o MySQL, PHP sessions, sistema de roles |
| Prioridad | **BAJA a corto plazo** — Stripe Portal cubre el 80% de las necesidades |
| Prerequisito | Tener >10 clientes activos de mantenimiento para justificar el esfuerzo |

**Recomendación:** Documentar en el email post-compra que las facturas y gestión de suscripción están en el Stripe Customer Portal (enlace directo). Retrasar el área de clientes propia hasta que sea una petición recurrente de clientes reales.

---

## Reservas online

**Estado actual:** No existe. El contacto es vía formulario o WhatsApp.

**Necesidad:** Permitir a prospectos reservar una llamada de consulta gratuita de 30 minutos para discutir su proyecto.

| Campo | Detalle |
|-------|---------|
| Esfuerzo | 1 día (integración de Calendly, Cal.com, o TidyCal como iframe) |
| Riesgo | MUY BAJO — solo un iframe externo, sin backend propio |
| Dependencias | Cuenta en Calendly/Cal.com (gratis en tier básico) |
| Prioridad | **MEDIA** — aumenta la tasa de conversión de prospectos indecisos |
| Prerequisito | Ninguno |

**Implementación recomendada:** Embed de Calendly en `pages/presupuesto.html` o `pages/contacto.html` como alternativa al formulario. Cal.com (self-hosteable) si se prefiere privacidad total.

---

## Facturación

**Estado actual:** Stripe genera PDFs de factura automáticamente para todos los pagos. Los clientes pueden descargarlas desde el Customer Portal.

**Lo que falta:** El email post-pago debería incluir el enlace directo a la factura PDF de Stripe.

| Campo | Detalle |
|-------|---------|
| Esfuerzo | 2-4 horas (actualizar `api/webhook.php` para incluir factura URL en el email) |
| Riesgo | MUY BAJO |
| Dependencias | API de Stripe (`invoice.payment_succeeded` webhook event) |
| Prioridad | **ALTA** — los clientes esperan recibir su factura por email |
| Prerequisito | Verificar que `checkout.session.completed` incluye `invoice_id` para obtener la URL |

**Nota:** Para facturación conforme a la ley española (con número de factura correlativo, datos fiscales del cliente, IVA desglosado), Stripe Invoicing cubre todos estos requisitos si se configura correctamente desde el Dashboard.

---

## SaaS / Suscripciones de software

**Estado actual:** La base Stripe ya soporta suscripciones mensuales (mantenimiento web). Los planes `mant-*` son el primer paso hacia un modelo SaaS.

**Para un SaaS real habría que añadir:**
- Autenticación de usuarios (JWT o PHP sessions)
- Provisioning automático de cuenta al suscribirse
- Control de acceso por plan (features gate)
- Webhook `customer.subscription.updated` para actualizar accesos en tiempo real
- Dashboard de uso por cliente

| Campo | Detalle |
|-------|---------|
| Esfuerzo | 6-12 semanas según complejidad del producto |
| Riesgo | ALTO — reescritura parcial del backend |
| Dependencias | Base de datos relacional (MySQL), autenticación robusta |
| Prioridad | **MUY BAJA** — requiere definir primero qué "producto SaaS" se ofrece |
| Prerequisito | Producto definido, >50 clientes potenciales identificados |

---

## APIs externas (integraciones)

**Integraciones candidatas por ROI:**

| API | Uso | Esfuerzo | Prioridad |
|-----|-----|---------|-----------|
| Notion API | CRM de leads en Notion | 1-2 días | MEDIA |
| Airtable API | CRM visual con automatizaciones | 1-2 días | MEDIA |
| Slack webhooks | Notificación de leads en tiempo real | 2h | ALTA |
| Google Search Console API | Dashboard SEO integrado | 3-5 días | BAJA |
| Hotjar/PostHog | Analytics de comportamiento alternativo a Clarity | 1 día | BAJA |

**Recomendación inmediata:** Añadir un webhook de Slack que reciba una notificación cuando se envíe un formulario de presupuesto. Costo: 2 horas. ROI: saber en tiempo real cuando llega un lead sin depender de que llegue el email.

---

## Blog 100+ artículos (SSG)

**Estado actual:** 22 artículos en HTML estático manual. Cada nuevo artículo requiere crear el HTML, actualizar blog.html, actualizar sitemap.xml manualmente.

**El problema:** A partir de ~30-40 artículos, el coste de mantenimiento crece linealmente y se vuelve insostenible sin automatización.

**Solución recomendada — Astro SSG:**
- Contenido en Markdown (`.md` files)
- Build genera HTML idéntico al actual (mismos CSS, mismos JS, misma estructura)
- Zero cambios visibles al usuario
- Zero cambios a .htaccess, sitemap, robots.txt (Astro los genera)
- Actualización automática del índice del blog

| Campo | Detalle |
|-------|---------|
| Esfuerzo | 2-3 semanas (migrar 22 artículos + configurar Astro) |
| Riesgo | MEDIO — cambio de pipeline, pero output HTML idéntico |
| Dependencias | Node.js (solo en desarrollo, no en producción), npm |
| Prioridad | **ALTA cuando se superen 30 artículos** |
| Prerequisito | Establecer flujo de escritura de contenido regular |

**Alternativa de menor esfuerzo:** Script PHP/Python que genera HTML desde un JSON central de artículos. Menos potente que Astro pero cero dependencias de Node.js. Esfuerzo: 1-2 días.

---

## Priorización global (resumen)

| Módulo | Prioridad | Cuándo empezar |
|--------|-----------|----------------|
| Email de bienvenida con factura Stripe | ALTA | Ya |
| Slack webhook de leads | ALTA | Ya |
| SQLite para leads | MEDIA | Cuando supere 20 leads/mes |
| Reservas online (Calendly) | MEDIA | Cuando se quiera aumentar conversión de llamadas |
| SSG para blog (Astro) | ALTA | Al superar 30 artículos |
| Área de clientes propia | BAJA | Al superar 10 clientes de mantenimiento activos |
| SaaS | MUY BAJA | Al definir el producto y tener >50 prospectos |
