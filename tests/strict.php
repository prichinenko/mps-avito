<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');
set_error_handler(function ($severity, $message, $file, $line) {
    // Respect PHP's intentional @ probes (e.g. Smarty checks for a cache file).
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// RU: Ошибка видна в аннотациях CI, даже если скачивание журнала недоступно.
// EN: Surface fatal diagnostics in CI annotations even when log downloads fail.
if (getenv('GITHUB_ACTIONS') === 'true') {
    register_shutdown_function(static function () {
        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $message = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $last['message']);
            fwrite(STDERR, '::error::' . $message . PHP_EOL);
        }
    });
}
