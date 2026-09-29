<?php

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

$runtimeFile = dirname(__DIR__).'/vendor/autoload_runtime.php';
$autoloadFile = dirname(__DIR__).'/vendor/autoload.php';

if (!file_exists($autoloadFile)) {
    http_response_code(503);
    echo "Application initializing, please refresh in a moment.";
    exit;
}

require_once $autoloadFile;

// Trust reverse proxies (Railway / cloud edge load balancers) so HTTPS and headers are honored
Request::setTrustedProxies(
    explode(',', $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? '127.0.0.1,REMOTE_ADDR,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),
    Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO
);

if (file_exists($runtimeFile)) {
    require_once $runtimeFile;
} elseif (file_exists($autoloadFile)) {
    if (file_exists(dirname(__DIR__).'/.env')) {
        (new \Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__).'/.env');
    }
    $kernel = new Kernel($_SERVER['APP_ENV'] ?? 'prod', (bool) ($_SERVER['APP_DEBUG'] ?? false));
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
    exit;
} else {
    http_response_code(503);
    echo "Application initializing, please refresh in a moment.";
    exit;
}

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
