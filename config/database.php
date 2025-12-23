<?php
// backend/config/database.php

class Database {
    private $host;
    private $port;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        // Configuración para PostgreSQL local
        $this->host = 'localhost';
        $this->port = '5432';          // Puerto por defecto de PostgreSQL
        $this->db_name = 'viax';
        $this->username = 'postgres';   // Usuario por defecto de PostgreSQL
        $this->password = 'root';
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
        } catch(PDOException $exception) {
            throw new Exception("Error de conexión PostgreSQL: " . $exception->getMessage());
        }

        return $this->conn;
    }
}
?>