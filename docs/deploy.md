# Deploy — Procedimientos y recuperación

## Pipeline de deploy actual

```
Edición local → git add → git commit → git push origin main → PRODUCCIÓN
```

El push a `main` desencadena el deploy automático en Hostinger. No hay pasos manuales, no hay CI/CD intermedio.

**Tiempo estimado de propagación:** 30-60 segundos para archivos PHP y JS.
**Caché HTML:** LiteSpeed puede servir HTML cacheado hasta 1 hora después del deploy. Para invalidar, usar Hostinger Panel → LiteSpeed Cache → Purge All.

## Acceso SSH / FTP

- Acceso vía Hostinger Panel o SSH (credenciales en gestor de contraseñas)
- La raíz del sitio en el servidor es `/public_html/`

## Variables de entorno (.env)

El archivo `.env` NO está en git (incluido en `.gitignore`). En caso de pérdida:
1. Acceder al servidor vía SSH/FTP
2. El `.env` original debe estar en `/public_html/.env`
3. Si no existe, recrearlo desde `.env.example` y rellenar con los valores de Stripe Dashboard y Resend Dashboard

## Procedimientos de recuperación

### Si el sitio cae completamente
1. Verificar en Hostinger Panel que el servidor está activo
2. Verificar que `index.html` existe en `/public_html/`
3. Verificar `.htaccess` — un error de sintaxis en `.htaccess` puede tirar Apache/LiteSpeed
4. Revisar logs de error en Hostinger Panel → File Manager → `error_log`

### Si un endpoint PHP falla (500 Internal Server Error)
1. Revisar `storage/logs/stripe.log` para ver el error PHP
2. Verificar que `vendor/autoload.php` existe (si no: subir `vendor/` vía FTP o ejecutar `composer install` localmente y subir)
3. Verificar que `.env` tiene todas las variables requeridas

### Si el CSS no se actualiza en producción
1. El bundle `main.bundle.css` tiene caché de 1 mes en el navegador
2. La versión en la URL (`?v=YYYYMMDD`) rompe la caché — si se actualizó el bundle pero no la versión, los usuarios verán el CSS viejo
3. Para forzar recarga: incrementar la versión en todos los HTML que referencian el bundle

### Si el LiteSpeed sirve HTML antiguo
- LiteSpeed cachea el HTML hasta 1 hora (`max-age=3600` en `.htaccess`)
- Para invalidar inmediatamente: Hostinger Panel → LiteSpeed Cache → Purge All
- El cache de assets (CSS/JS/imágenes) es de 1 mes — solo se invalida cambiando la versión en la URL

## Actualización de CSS bundle (procedimiento)

Cuando se modifica un archivo CSS fuente:

1. Regenerar `css/main.bundle.css`:
```bash
cat css/base/reset.css css/base/variables.css css/base/typography.css css/base/accessibility.css \
    css/layout/header.css css/layout/hero.css css/layout/sections.css css/layout/grid.css css/layout/footer.css \
    css/components/buttons.css css/components/cards.css css/components/badges.css css/components/breadcrumbs.css \
    css/components/forms.css css/components/cta.css css/components/cookies.css css/components/whatsapp.css \
    css/components/turnstile.css css/components/reviews.css \
    css/utilities/helpers.css css/utilities/animations.css css/utilities/responsive.css \
    css/pages/home.css css/pages/servicios.css css/pages/presupuesto.css css/pages/blog.css \
    css/pages/precios.css css/pages/sobre-nosotros.css css/pages/portfolio.css css/pages/demos.css \
    css/pages/checkout.css css/pages/cliente.css > css/main.bundle.css
```

2. Actualizar versión en todos los HTML que usan `main.bundle.css` (actualmente ~67 archivos):
```bash
find . -name "*.html" -not -path "./_templates/*" | xargs sed -i '' 's/main.bundle.css?v=[0-9a-z]*/main.bundle.css?v=YYYYMMDD/g'
```

3. Si también se modificó `css/contacto.bundle.css`, regenerarlo por separado (ver composición en `docs/arquitectura.md`).

## Backups

- El repositorio git es el backup principal (todo el código)
- El `.env` NO está en git — backup manual en gestor de contraseñas
- Los logs en `storage/logs/stripe.log` no están en git — backup opcional
- Los datos de reviews en `api/data/` están en git (actualmente vacíos)

## Checklist antes de hacer push

- [ ] CSS bundle actualizado si se modificaron archivos fuente CSS
- [ ] Versión `?v=` actualizada en los HTML afectados
- [ ] Nuevas páginas añadidas al `sitemap.xml`
- [ ] Variables `.env` nuevas documentadas en `.env.example`
- [ ] No se han commiteado credenciales, `.env`, o archivos de debug
