<?php

use App\Kernel;

$runtimeFile = dirname(__DIR__).'/vendor/autoload_runtime.php';
$autoloadFile = dirname(__DIR__).'/vendor/autoload.php';

if (file_exists($runtimeFile)) {
    require_once $runtimeFile;
} elseif (file_exists($autoloadFile)) {
    require_once $autoloadFile;
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
