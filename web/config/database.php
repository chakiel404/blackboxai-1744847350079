<?php
class Database {
    private $host = "localhost";
    private $db_name = "elearning";
    private $username = "root";
    private $password = "";
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new mysqli($this->host, $this->username, $this->password, $this->db_name);
            
            if ($this->conn->connect_error) {
                throw new Exception("Database connection error: " . $this->conn->connect_error);
            }
            
            $this->conn->set_charset("utf8");
            return $this->conn;
        } catch(Exception $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    public function beginTransaction() {
        return $this->conn->begin_transaction();
    }
    
    public function commit() {
        return $this->conn->commit();
    }
    
    public function rollback() {
        return $this->conn->rollback();
    }
}
