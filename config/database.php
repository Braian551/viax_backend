<?php
// backend/config/database.php

// Incluir configuración de timezone (establece UTC por defecto)
require_once __DIR__ . '/timezone.php';
require_once __DIR__ . '/bootstrap.php';

class Database {
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Load from environment variables with defaults for development
        $this->host = env_value('DB_HOST', 'localhost');
        $this->port = env_value('DB_PORT', '5432');
        $this->db_name = env_value('DB_NAME', 'viax');
        $this->username = env_value('DB_USER', 'postgres');
        $this->password = env_value('DB_PASS', 'root');
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