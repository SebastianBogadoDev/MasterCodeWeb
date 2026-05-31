<?php
/* config/error-handler.php — manejo global de errores PHP
   Requiere: appLog() y APP_ENV definidos (bootstrap.php carga esto al final). */

function registerErrorHandlers(): void
{
    // Convertir errores PHP en excepciones (excepto E_DEPRECATED / E_STRICT)
    set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
        if (!(error_reporting() & $errno)) {
            return false; // respetar @ operator
        }
        appLog('ERROR', 'php', $errstr, [
            'file' => basename($errfile),
            'line' => $errline,
            'code' => $errno,
        ]);
        // No lanzar excepción — solo loguear y continuar (evita romper código legacy)
        return true;
    }, E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_NOTICE);

    // Capturar excepciones no manejadas
    set_exception_handler(function (\Throwable $e): void {
        appLog('CRITICAL', 'exception', $e->getMessage(), [
            'class' => get_class($e),
            'file'  => basename($e->getFile()),
            'line'  => $e->getLine(),
        ]);

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }

        // En producción: mensaje genérico. En test/local: detalle completo.
        $isProduction = defined('APP_ENV') && APP_ENV === 'production';

        echo json_encode(
            $isProduction
                ? ['error' => 'Error interno del servidor.']
                : [
                    'error' => $e->getMessage(),
                    'class' => get_class($e),
                    'file'  => $e->getFile(),
                    'line'  => $e->getLine(),
                    'trace' => array_slice(
                        array_map(fn(array $f) => ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?'), $e->getTrace()),
                        0, 5
                    ),
                ]
        );
    });

    // Capturar errores fatales (E_ERROR, E_PARSE) que no llegan a set_error_handler
    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            appLog('CRITICAL', 'fatal', $err['message'], [
                'file' => basename($err['file']),
                'line' => $err['line'],
            ]);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Error interno del servidor.']);
            }
        }
    });
}
