/* =====================================================
   SECURITY CONFIG — MasterCodeWeb
   Punto central de configuración de seguridad.

   ╔══════════════════════════════════════════════════╗
   ║  CLOUDFLARE TURNSTILE — CÓMO ACTIVARLO           ║
   ╠══════════════════════════════════════════════════╣
   ║  1. Abre Cloudflare Dashboard → Turnstile        ║
   ║     https://dash.cloudflare.com → Turnstile      ║
   ║                                                  ║
   ║  2. Copia tu Site Key (clave pública)            ║
   ║     → ponla en TURNSTILE_SITEKEY (abajo)         ║
   ║     → también en data-sitekey de cada <form>:   ║
   ║       pages/presupuesto.html (línea ~208)        ║
   ║       pages/contacto.html   (línea ~187)         ║
   ║       pages/acceso-cliente.html                  ║
   ║                                                  ║
   ║  3. Copia tu Secret Key (clave privada)          ║
   ║     → NUNCA la pongas aquí ni en el HTML         ║
   ║     → SOLO en Hostinger:                         ║
   ║       File Manager → public_html/api/config.php  ║
   ║       define('TURNSTILE_SECRET', '0x...');       ║
   ╚══════════════════════════════════════════════════╝
===================================================== */

// ── Site Key pública (seguro exponerla en cliente) ──
// ↓↓↓ CAMBIA ESTE VALOR por tu Site Key real ↓↓↓
export const TURNSTILE_SITEKEY = "0x4AAAAAADTLFOtKJi78PDdI";

// ── Endpoint de verificación server-side ────────────
// Definido en api/verify-turnstile.php
// La Secret Key SOLO existe en ese archivo (servidor)
export const TURNSTILE_VERIFY_URL = "/api/verify-turnstile.php";
