<?php

spl_autoload_register(function ($class) {
    $prefix = 'PortalRepase\\Bridge\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative).'.php';
    if (is_file($file)) {
        require $file;
    }
});
