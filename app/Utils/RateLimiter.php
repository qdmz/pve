<?php
class RateLimiter {
    private $pdo;
    private $maxRequests;
    private $timeWindow;
    
    public function __construct(PDO $pdo, $maxRequests = 60, $timeWindow = 60) {
        $this->pdo = $pdo;
        $this->maxRequests = $maxRequests;
        $this->timeWindow = $timeWindow;
    }
    
    public function check($identifier, $limit = null, $window = null) {
        $limit = $limit ?? $this->maxRequests;
        $window = $window ?? $this->timeWindow;
        
        $ip = $this->getClientIp();
        $key = $identifier . ':' . $ip;
        
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as request_count 
             FROM rate_limits 
             WHERE identifier = ? 
             AND created_at > NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute([$key, $window]);
        $result = $stmt->fetch();
        
        $requestCount = $result['request_count'] ?? 0;
        
        if ($requestCount >= $limit) {
            $this->logRateLimitExceeded($key, $requestCount, $limit);
            return false;
        }
        
        $this->recordRequest($key);
        return true;
    }
    
    public function recordRequest($identifier) {
        $ip = $this->getClientIp();
        $stmt = $this->pdo->prepare(
            "INSERT INTO rate_limits (identifier, ip_address, created_at) 
             VALUES (?, ?, NOW())"
        );
        $stmt->execute([$identifier, $ip]);
        
        $this->cleanupOldRecords();
    }
    
    public function cleanupOldRecords() {
        $stmt = $this->pdo->prepare(
            "DELETE FROM rate_limits 
             WHERE created_at < NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute([$this->timeWindow * 2]);
    }
    
    public function getRemainingRequests($identifier, $limit = null, $window = null) {
        $limit = $limit ?? $this->maxRequests;
        $window = $window ?? $this->timeWindow;
        
        $ip = $this->getClientIp();
        $key = $identifier . ':' . $ip;
        
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as request_count 
             FROM rate_limits 
             WHERE identifier = ? 
             AND created_at > NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute([$key, $window]);
        $result = $stmt->fetch();
        
        $requestCount = $result['request_count'] ?? 0;
        return max(0, $limit - $requestCount);
    }
    
    public function getRetryAfter($identifier, $window = null) {
        $window = $window ?? $this->timeWindow;
        
        $ip = $this->getClientIp();
        $key = $identifier . ':' . $ip;
        
        $stmt = $this->pdo->prepare(
            "SELECT created_at 
             FROM rate_limits 
             WHERE identifier = ? 
             ORDER BY created_at DESC 
             LIMIT 1"
        );
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return 0;
        }
        
        $oldestRequest = strtotime($result['created_at']);
        $windowEnd = $oldestRequest + $window;
        $retryAfter = max(0, $windowEnd - time());
        
        return $retryAfter;
    }
    
    public function getClientIp() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED'];
        } elseif (isset($_SERVER['HTTP_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_FORWARDED'])) {
            $ip = $_SERVER['HTTP_FORWARDED'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        return $this->validateIp($ip);
    }
    
    private function validateIp($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $ip;
        }
        
        return '127.0.0.1';
    }
    
    private function logRateLimitExceeded($identifier, $requestCount, $limit) {
        $ip = $this->getClientIp();
        $logMessage = sprintf(
            'Rate limit exceeded: %s - %d/%d requests from %s',
            $identifier,
            $requestCount,
            $limit,
            $ip
        );
        
        error_log($logMessage);
        
        if (class_exists('Logger')) {
            $logger = new Logger($this->pdo);
            $logger->warning($logMessage, [
                'identifier' => $identifier,
                'request_count' => $requestCount,
                'limit' => $limit,
                'ip' => $ip
            ]);
        }
    }
    
    public function setHeaders($identifier, $limit = null, $window = null) {
        $limit = $limit ?? $this->maxRequests;
        $window = $window ?? $this->timeWindow;
        
        $remaining = $this->getRemainingRequests($identifier, $limit, $window);
        $retryAfter = $this->getRetryAfter($identifier, $window);
        
        header("X-RateLimit-Limit: $limit");
        header("X-RateLimit-Remaining: $remaining");
        header("X-RateLimit-Reset: " . (time() + $window));
        
        if ($retryAfter > 0) {
            header("Retry-After: $retryAfter");
        }
    }
}