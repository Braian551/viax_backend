<?php

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('viax_load_env')) {
    function viax_load_env(): void {
        static $loaded = false;
        if ($loaded) {
            return;
        }

        $candidatePaths = [
            dirname(__DIR__),
            __DIR__,
        ];

        foreach ($candidatePaths as $path) {
            $envPath = $path . '/.env';
            if (file_exists($envPath)) {
                $dotenv = Dotenv\Dotenv::createImmutable($path);
                $dotenv->safeLoad();
                break;
            }
        }

        $loaded = true;
    }
}

if (!function_exists('env_value')) {
    function env_value(string $key, $default = null) {
        viax_load_env();

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return $value;
    }
}

viax_load_env();
