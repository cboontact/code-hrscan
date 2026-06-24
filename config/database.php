<?php
require_once __DIR__ . '/environment.php';

class Database
{
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $charset;
    public $conn;

    public function __construct()
    {
        $environmentDefaults = getEnvironmentDefaults();
        $defaults = [
            'host' => $environmentDefaults['db_host'],
            'db_name' => $environmentDefaults['db_name'],
            'username' => $environmentDefaults['db_user'],
            'password' => $environmentDefaults['db_pass'],
            'charset' => $environmentDefaults['db_charset'],
        ];

        $this->host = $this->getConfigValue('DB_HOST', $defaults['host']);
        $this->db_name = $this->getConfigValue('DB_NAME', $defaults['db_name']);
        $this->username = $this->getConfigValue('DB_USER', $defaults['username']);
        $this->password = $this->getConfigValue('DB_PASS', $defaults['password']);
        $this->charset = $this->getConfigValue('DB_CHARSET', $defaults['charset']);
    }

    private function getConfigValue($key, $default)
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            $value = $_SERVER[$key] ?? null;
        }

        return ($value === null || $value === '') ? $default : $value;
    }

    public function getConnection()
    {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $exception) {
            error_log("Connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed");
        }

        return $this->conn;
    }
}
?>
