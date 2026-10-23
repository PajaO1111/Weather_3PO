<?php
declare(strict_types=1);

use OuBrix\Weather\Http\View;

/** View::baseUrl – veřejná URL i tehdy, když SCRIPT_NAME obsahuje vnitřní cestu hostingu (WEDOS). */
return static function (): int {
    $backup = $_SERVER;
    $base = static function (string $script, string $uri): string {
        $_SERVER['SCRIPT_NAME'] = $script;
        $_SERVER['REQUEST_URI'] = $uri;
        return View::baseUrl();
    };
    try {
        $wedos = '/domains/oubrix.eu/Weather/public/index.php';
        check($base($wedos, '/Weather/public/') === '/Weather/public', 'WEDOS: adresář');
        check($base($wedos, '/Weather/public/index.php?x=1') === '/Weather/public', 'WEDOS: index.php');
        check($base('/Weather/public/index.php', '/Weather/public/') === '/Weather/public', 'běžný server');
        check($base('/index.php', '/') === '', 'kořen webu');
        check($base($wedos, '/Weather/public/index.php/"><script>/') === '/domains/oubrix.eu/Weather/public', 'podvržená URI se nepoužije');
    } finally {
        $_SERVER = $backup;
    }
    return 5;
};
