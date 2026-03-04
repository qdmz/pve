<?php
class UserController {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // 用户注册
    public function register($username, $email, $password) {
        // 验证输入
        if (strlen($password) < 8) {
            throw new Exception("密码长度至少8位");
        }

        // 检查唯一性
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception("邮箱已被注册");
        }

        // 创建用户
        $token = bin2hex(random_bytes(50));
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, activation_token, activation_expires) 
             VALUES (?, ?, ?, ?, NOW() + INTERVAL 1 HOUR)"
        );
        $stmt->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $token
        ]);

        // 发送激活邮件
        $this->sendActivationEmail($email, $token);
    }

    // 邮件激活
    public function activate($token) {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users 
             WHERE activation_token = ? 
             AND activation_expires > NOW() 
             AND status = 'pending'"
        );
        $stmt->execute([$token]);
        
        if (!$user = $stmt->fetch()) {
            throw new Exception("无效或过期的激活链接");
        }

        $this->pdo->prepare(
            "UPDATE users 
             SET status = 'active', activation_token = NULL 
             WHERE id = ?"
        )->execute([$user['id']]);
    }

    // 登录验证
    public function login($email, $password) {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, password_hash, role, status 
             FROM users WHERE email = ?"
        );
        $stmt->execute([$email]);
        
        if (!$user = $stmt->fetch()) {
            throw new Exception("邮箱或密码错误");
        }

        if ($user['status'] !== 'active') {
            throw new Exception("账户未激活或已被禁用");
        }

        if (!password_verify($password, $user['password_hash'])) {
            throw new Exception("邮箱或密码错误");
        }

        // 更新登录时间
        $this->pdo->prepare(
            "UPDATE users SET last_login = NOW() WHERE id = ?"
        )->execute([$user['id']]);

        return $user;
    }

    // 密码重置
    public function requestPasswordReset($email) {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users WHERE email = ?"
        );
        $stmt->execute([$email]);
        
        if (!$user = $stmt->fetch()) {
            throw new Exception("邮箱未注册");
        }

        $token = bin2hex(random_bytes(50));
        $this->pdo->prepare(
            "UPDATE users 
             SET reset_token = ?, reset_expires = NOW() + INTERVAL 1 HOUR 
             WHERE id = ?"
        )->execute([$token, $user['id']]);

        $this->sendResetEmail($email, $token);
    }

    public function resetPassword($token, $newPassword) {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users 
             WHERE reset_token = ? 
             AND reset_expires > NOW()"
        );
        $stmt->execute([$token]);
        
        if (!$user = $stmt->fetch()) {
            throw new Exception("无效或过期的重置链接");
        }

        $this->pdo->prepare(
            "UPDATE users 
             SET password_hash = ?, reset_token = NULL 
             WHERE id = ?"
        )->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            $user['id']
        ]);
    }

    // 私有方法
    private function sendActivationEmail($email, $token) {
        $subject = "请激活您的账户";
        $activationLink = "https://".$_SERVER['HTTP_HOST']."/activate.php?token=$token";
        $message = "点击激活链接完成注册: $activationLink";
        
        // 实际使用应使用更安全的邮件发送方式
        mail($email, $subject, $message);
    }

    private function sendResetEmail($email, $token) {
        $subject = "密码重置请求";
        $resetLink = "https://".$_SERVER['HTTP_HOST']."/reset-password.php?token=$token";
        $message = "点击链接重置密码: $resetLink";
        
        mail($email, $subject, $message);
    }
}