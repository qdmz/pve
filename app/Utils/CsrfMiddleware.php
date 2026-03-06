<?php
class CsrfMiddleware {
    private $session;
    
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $this->session = &$_SESSION;
    }
    
    public function generateToken() {
        if (!isset($this->session['csrf_token'])) {
            $this->session['csrf_token'] = bin2hex(random_bytes(32));
            $this->session['csrf_token_time'] = time();
        }
        return $this->session['csrf_token'];
    }
    
    public function validateToken($token) {
        if (!isset($this->session['csrf_token'])) {
            return false;
        }
        
        if (!isset($this->session['csrf_token_time'])) {
            return false;
        }
        
        $tokenAge = time() - $this->session['csrf_token_time'];
        if ($tokenAge > 3600) {
            unset($this->session['csrf_token']);
            unset($this->session['csrf_token_time']);
            return false;
        }
        
        return hash_equals($this->session['csrf_token'], $token);
    }
    
    public function refreshToken() {
        unset($this->session['csrf_token']);
        unset($this->session['csrf_token_time']);
        return $this->generateToken();
    }
    
    public function getTokenFromRequest() {
        $headers = getallheaders();
        
        if (isset($headers['X-CSRF-Token'])) {
            return $headers['X-CSRF-Token'];
        }
        
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        
        if (isset($_POST['csrf_token'])) {
            return $_POST['csrf_token'];
        }
        
        if (isset($_GET['csrf_token'])) {
            return $_GET['csrf_token'];
        }
        
        return null;
    }
    
    public function requireValidToken() {
        $token = $this->getTokenFromRequest();
        
        if ($token === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF token缺失'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (!$this->validateToken($token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'CSRF token无效或已过期'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    public function addTokenToHeaders() {
        $token = $this->generateToken();
        header('X-CSRF-Token: ' . $token);
        return $token;
    }
}