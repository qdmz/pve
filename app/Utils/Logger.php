<?php
class Logger {
    private $pdo;
    private $minLevel;
    
    const DEBUG = 'debug';
    const INFO = 'info';
    const WARNING = 'warning';
    const ERROR = 'error';
    const CRITICAL = 'critical';
    
    public function __construct(PDO $pdo, $minLevel = self::DEBUG) {
        $this->pdo = $pdo;
        $this->minLevel = $minLevel;
    }
    
    public function debug($message, $context = []) {
        $this->log(self::DEBUG, $message, $context);
    }
    
    public function info($message, $context = []) {
        $this->log(self::INFO, $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log(self::WARNING, $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log(self::ERROR, $message, $context);
    }
    
    public function critical($message, $context = []) {
        $this->log(self::CRITICAL, $message, $context);
    }
    
    private function log($level, $message, $context = []) {
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $userId = isset($_SESSION['user']) ? $_SESSION['user']['id'] : null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $stmt = $this->pdo->prepare(
            "INSERT INTO system_logs (level, message, context, user_id, ip_address, user_agent) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        
        $stmt->execute([
            $level,
            $message,
            json_encode($context, JSON_UNESCAPED_UNICODE),
            $userId,
            $ipAddress,
            $userAgent
        ]);
        
        $this->writeToFile($level, $message, $context);
    }
    
    private function shouldLog($level) {
        $levels = [
            self::DEBUG => 0,
            self::INFO => 1,
            self::WARNING => 2,
            self::ERROR => 3,
            self::CRITICAL => 4
        ];
        
        return $levels[$level] >= $levels[$this->minLevel];
    }
    
    private function writeToFile($level, $message, $context = []) {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        $logLine = sprintf(
            "[%s] [%s] %s%s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $contextStr
        );
        
        file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    public function logException(Exception $e, $context = []) {
        $context['exception'] = [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
        
        $this->error($e->getMessage(), $context);
    }
    
    public function logRequest($method, $uri, $params = []) {
        $this->info("API Request", [
            'method' => $method,
            'uri' => $uri,
            'params' => $params
        ]);
    }
    
    public function logResponse($statusCode, $response = []) {
        $this->info("API Response", [
            'status_code' => $statusCode,
            'response' => $response
        ]);
    }
    
    public function logAuth($action, $success, $userId = null) {
        $this->info("Auth Action", [
            'action' => $action,
            'success' => $success,
            'user_id' => $userId
        ]);
    }
    
    public function logPveApi($action, $success, $details = []) {
        $this->info("PVE API Action", [
            'action' => $action,
            'success' => $success,
            'details' => $details
        ]);
    }
    
    public function cleanupOldLogs($days = 30) {
        $stmt = $this->pdo->prepare(
            "DELETE FROM system_logs 
             WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        );
        $stmt->execute([$days]);
        
        $this->info("Cleaned up old logs", ['days' => $days]);
    }
    
    public function getLogs($level = null, $limit = 100, $offset = 0) {
        $sql = "SELECT * FROM system_logs";
        $params = [];
        
        if ($level !== null) {
            $sql .= " WHERE level = ?";
            $params[] = $level;
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $logs = $stmt->fetchAll();
        
        foreach ($logs as &$log) {
            $log['context'] = json_decode($log['context'], true);
        }
        
        return $logs;
    }
}