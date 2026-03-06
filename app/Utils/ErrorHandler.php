<?php
class ErrorHandler {
    private $logger;
    private $debugMode;
    
    public function __construct(PDO $pdo, $debugMode = false) {
        $this->logger = new Logger($pdo, Logger::ERROR);
        $this->debugMode = $debugMode;
    }
    
    public function register() {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }
    
    public function handleError($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $errorTypes = [
            E_ERROR => 'ERROR',
            E_WARNING => 'WARNING',
            E_PARSE => 'PARSE',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'CORE_ERROR',
            E_CORE_WARNING => 'CORE_WARNING',
            E_COMPILE_ERROR => 'COMPILE_ERROR',
            E_COMPILE_WARNING => 'COMPILE_WARNING',
            E_USER_ERROR => 'USER_ERROR',
            E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE',
            E_STRICT => 'STRICT',
            E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'USER_DEPRECATED'
        ];
        
        $errorType = $errorTypes[$errno] ?? 'UNKNOWN';
        
        $this->logger->error("PHP Error: $errorType - $errstr", [
            'errno' => $errno,
            'errfile' => $errfile,
            'errline' => $errline
        ]);
        
        if ($this->debugMode) {
            $this->displayError($errno, $errstr, $errfile, $errline);
        }
        
        return true;
    }
    
    public function handleException($exception) {
        $this->logger->logException($exception);
        
        if ($this->isApiRequest()) {
            $this->displayApiError($exception);
        } else {
            $this->displayHtmlError($exception);
        }
    }
    
    public function handleShutdown() {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logger->critical("Fatal Error", [
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);
            
            if ($this->isApiRequest()) {
                $this->displayApiError($error);
            } else {
                $this->displayHtmlError($error);
            }
        }
    }
    
    private function isApiRequest() {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return strpos($uri, '/api') === 0;
    }
    
    private function displayApiError($error) {
        if (is_array($error)) {
            $message = $error['message'] ?? '服务器内部错误';
            $code = 500;
        } else {
            $message = $error->getMessage() ?? '服务器内部错误';
            $code = $error->getCode() >= 400 ? $error->getCode() : 500;
        }
        
        http_response_code($code);
        header('Content-Type: application/json');
        
        $response = [
            'success' => false,
            'message' => $this->debugMode ? $message : '服务器内部错误，请稍后重试'
        ];
        
        if ($this->debugMode) {
            $response['debug'] = [
                'file' => is_array($error) ? $error['file'] : $error->getFile(),
                'line' => is_array($error) ? $error['line'] : $error->getLine(),
                'trace' => is_array($error) ? [] : explode("\n", $error->getTraceAsString())
            ];
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private function displayHtmlError($error) {
        if (is_array($error)) {
            $message = $error['message'] ?? '服务器内部错误';
        } else {
            $message = $error->getMessage() ?? '服务器内部错误';
        }
        
        http_response_code(500);
        
        echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>错误 - PVE 虚拟机管理系统</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0">错误</h4>
                    </div>
                    <div class="card-body">
                        <p class="lead">' . htmlspecialchars($this->debugMode ? $message : '服务器内部错误，请稍后重试') . '</p>';
        
        if ($this->debugMode) {
            echo '                        <div class="alert alert-info">
                            <strong>调试信息：</strong>
                            <pre>';
            
            if (is_array($error)) {
                echo htmlspecialchars("文件: " . $error['file'] . "\n");
                echo htmlspecialchars("行号: " . $error['line'] . "\n");
                echo htmlspecialchars("类型: " . $error['type']);
            } else {
                echo htmlspecialchars("文件: " . $error->getFile() . "\n");
                echo htmlspecialchars("行号: " . $error->getLine() . "\n");
                echo htmlspecialchars("消息: " . $error->getMessage() . "\n");
                echo htmlspecialchars("追踪:\n" . $error->getTraceAsString());
            }
            
            echo '                        </pre>
                        </div>';
        }
        
        echo '                        <a href="/" class="btn btn-primary mt-3">返回首页</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>';
        exit;
    }
    
    private function displayError($errno, $errstr, $errfile, $errline) {
        echo '<div style="background: #f8d7da; color: #721c24; padding: 15px; margin: 10px; border-radius: 5px;">';
        echo '<strong>PHP Error:</strong> ' . htmlspecialchars($errstr) . '<br>';
        echo '<strong>File:</strong> ' . htmlspecialchars($errfile) . '<br>';
        echo '<strong>Line:</strong> ' . htmlspecialchars($errline) . '<br>';
        echo '<strong>Code:</strong> ' . htmlspecialchars($errno);
        echo '</div>';
    }
    
    public function logRequest($method, $uri, $params = []) {
        $this->logger->logRequest($method, $uri, $params);
    }
    
    public function logResponse($statusCode, $response = []) {
        $this->logger->logResponse($statusCode, $response);
    }
    
    public function setDebugMode($debugMode) {
        $this->debugMode = $debugMode;
    }
}