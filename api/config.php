<?php
/* api/config.php — proxy de compatibilidad hacia config/bootstrap.php
   Todos los archivos api/*.php usan require_once __DIR__ . '/config.php'
   sin necesidad de cambio. Las claves reales viven en .env (nunca en git). */

require_once dirname(__DIR__) . '/config/bootstrap.php';
