# SEO — Documentación técnica

## Implementación actual

### Schema.org
- **Homepage (`index.html`):** `ProfessionalService` + `LocalBusiness` + `PostalAddress` + `GeoCoordinates` + `FAQPage` — 38 declaraciones JSON-LD
- **Páginas de servicio:** `Service` schema con precios y áreas geográficas
- **Guías y artículos:** `Article` + `FAQPage` + `BreadcrumbList`
- **Landings locales:** `LocalBusiness` + `areaServed`
- **Total páginas con Schema:** ~43 de 63 indexables

### Open Graph / Twitter Card
- Todas las páginas principales tienen OG completo (9 meta tags): `og:title`, `og:description`, `og:url`, `og:type`, `og:image`, `og:image:width`, `og:image:height`, `og:site_name`, `og:locale`
- Imágenes OG: `/assets/img/branding/og/og-home.webp` (1200×630px)

### Canonicals
- Todas las páginas tienen `<link rel="canonical">` apuntando a su propia URL con `https://www.mastercodeweb.com/`
- Páginas noindex: checkout (6), demos (10), sistema (cancel, success)

### Sitemap
- Archivo: `sitemap.xml` en la raíz
- URLs: 63 URLs indexables
- Declarado en `robots.txt`
- **Actualizar manualmente** al añadir nuevas páginas

### Robots.txt
```
User-agent: * → Allow: /
Disallow: /pages/success.html, /pages/cancel.html
Disallow: /pages/checkout-*.html
Disallow: /pages/demos/
Disallow: /_templates/, /admin/, /private/, /api/, /logs/
Sitemap: https://www.mastercodeweb.com/sitemap.xml
```

### Breadcrumbs
- Implementados en HTML en 8 páginas principales
- Generados automáticamente con Schema `BreadcrumbList` en guías y artículos

## Canibalizaciones conocidas (documentadas en Auditoría Fase 2)

| URLs en conflicto | Keyword | Prioridad de posicionamiento |
|-------------------|---------|------------------------------|
| `/guias/diseno-web/diseno-web-malaga.html` vs `/pages/diseno-web-malaga.html` | "diseño web Málaga" | La landing `/pages/` debe ganar (intent transaccional) |
| `/guias/diseno-web/diseno-web-profesional.html` vs `/servicios/diseno-web-profesional.html` | "diseño web profesional" | La subpágina de servicio debe ganar |
| `/blog/diseno-web-profesional-guia.html` vs `/guias/diseno-web/diseno-web-profesional.html` | "diseño web profesional guía" | Redundancia — evaluar consolidar |
| `/guias/seo/seo-tecnico-web.html` vs `/servicios/optimizacion-seo-tecnica.html` | "SEO técnico" | La guía = informacional, el servicio = transaccional |

## Performance SEO (Core Web Vitals)

| Página | Performance móvil | LCP | FCP | CLS |
|--------|-----------------|-----|-----|-----|
| `contacto.html` | ~85-90 (estimado post-optimización) | ~2.5s | ~1.8s | 0 |
| `index.html` | ~74 (pre-optimización) | ~2.8s | ~2.1s | 0 |
| Otras páginas | Variable | Variable | Variable | 0 |

La última optimización (P5.1) creó `css/contacto.bundle.css` específico (120 KB) con fuentes Inter self-hosted y eliminó la dependencia de Google Fonts en contacto.html.

## Procedimientos SEO

### Añadir nueva página indexable
1. Crear el HTML con title, meta description, H1, canonical, Schema.org, Open Graph
2. Añadir la URL a `sitemap.xml`
3. Verificar que robots.txt no la bloquea
4. Añadir enlaces internos desde páginas relevantes (al menos 2-3 enlaces entrantes)
5. Comprobar en Google Search Console que se indexa correctamente

### Actualizar sitemap.xml
El sitemap está en la raíz. Cada entrada sigue este formato:
```xml
<url>
  <loc>https://www.mastercodeweb.com/pages/nueva-pagina.html</loc>
  <lastmod>2026-06-20</lastmod>
  <changefreq>monthly</changefreq>
  <priority>0.8</priority>
</url>
```

### Estructura de título recomendada
- Páginas comerciales: `{Keyword principal} | MasterCodeWeb` (máx 60 chars)
- Páginas de servicio: `{Servicio específico} | {Diferenciador} | MasterCodeWeb` (máx 70 chars)
- Artículos/guías: `{Pregunta o intención} · MasterCodeWeb` (máx 70 chars)

### Jerarquía de contenido (canon interno)
```
Homepage (brand + conversión general)
  ├── servicios.html (hub de servicios)
  │   ├── servicios/diseno-web-profesional.html (profundidad)
  │   ├── servicios/tienda-online-profesional.html
  │   └── servicios/optimizacion-seo-tecnica.html
  ├── precios.html (conversión con precios)
  ├── guias/ (hub de contenido — SEO informacional)
  │   ├── guias/diseno-web/ (8 artículos)
  │   ├── guias/seo/ (5 artículos)
  │   └── guias/negocio-online/ (4 artículos)
  └── landings locales/ (SEO local por ciudad)
```
