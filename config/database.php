<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $this->host = "localhost";
        $this->db_name = "elearning";
        $this->username = "root";
        $this->password = "";
    }

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
            throw new Exception("Database connection error: " . $e->getMessage());
        }
    }
    
    // Add proper transaction methods 
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
?> 