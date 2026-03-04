<?php
class AuthMiddleware {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // 检查管理员权限
    public function requireAdmin() {
        if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
            $this->redirectWithError("需要管理员权限");
        }
    }

    // 检查用户登录
    public function requireLogin() {
        if (!isset($_SESSION['user'])) {
            $this->redirectWithError("请先登录");
        }
    }

    // 重定向带错误信息
    private function redirectWithError($message) {
        $_SESSION['error'] = $message;
        header("Location: /login.php");
        exit;
    }

    // 记录审计日志
    public function logAudit($action, $details = []) {
        if (!isset($_SESSION['user'])) return;

        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_logs (user_id, action, details, ip_address) 
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $_SESSION['user']['id'],
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR']
        ]);
    }
}