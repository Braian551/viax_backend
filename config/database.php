<?php
// backend/config/database.php

// Incluir configuración de timezone (establece UTC por defecto)
require_once __DIR__ . '/timezone.php';

class Database {
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Load .env file if exists
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) continue;
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($value);
                    putenv(trim($key) . '=' . trim($value));
                }
            }
        }

        // Load from environment variables with defaults for development
        $this->host = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? 'localhost';
        $this->port = getenv('DB_PORT') ?: $_ENV['DB_PORT'] ?? '5432';
        $this->db_name = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? 'viax';
        $this->username = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? 'postgres';
        $this->password = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? 'root';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "pgsql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name;
            $this->conn = new PDO(
                $dsn,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Establecer timezone de PostgreSQL a UTC para consistencia
            $this->conn->exec("SET timezone = 'UTC'");
        } catch(PDOException $exception) {
            throw new Exception("Error de conexión PostgreSQL: " . $exception->getMessage());
        }

        return $this->conn;
    }
}