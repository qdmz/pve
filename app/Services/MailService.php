<?php
class MailService {
    private $config;
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->config = $this->loadConfig();
    }

    private function loadConfig() {
        $stmt = $this->pdo->prepare("SELECT `key`, `value` FROM site_config WHERE `key` LIKE 'smtp_%'");
        $stmt->execute();
        $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'host' => $configs['smtp_host'] ?? 'localhost',
            'port' => $configs['smtp_port'] ?? 587,
            'user' => $configs['smtp_user'] ?? '',
            'pass' => $configs['smtp_pass'] ?? '',
            'from' => $configs['smtp_from'] ?? 'noreply@example.com',
            'encryption' => $configs['smtp_encryption'] ?? 'tls',
            'timeout' => 30
        ];
    }

    public function send($to, $subject, $body, $isHtml = false) {
        $headers = [
            'From' => $this->config['from'],
            'To' => $to,
            'Subject' => '=?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version' => '1.0',
            'Content-Type' => $isHtml ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8',
            'Date' => date('r'),
            'Message-ID' => $this->generateMessageId(),
            'X-Mailer' => 'PHP/' . phpversion(),
            'X-Priority' => '3'
        ];

        $headerString = '';
        foreach ($headers as $key => $value) {
            $headerString .= "$key: $value\r\n";
        }

        return mail($to, $subject, $body, $headerString);
    }

    public function sendActivationEmail($email, $token, $username = '') {
        $subject = '请激活您的账户';
        $activationLink = $this->getSiteUrl() . "/activate.php?token=$token";
        
        $body = $this->renderTemplate('activation', [
            'username' => $username,
            'activation_link' => $activationLink,
            'site_name' => $this->getSiteName()
        ]);

        return $this->send($email, $subject, $body, true);
    }

    public function sendPasswordResetEmail($email, $token, $username = '') {
        $subject = '密码重置请求';
        $resetLink = $this->getSiteUrl() . "/reset-password.php?token=$token";
        
        $body = $this->renderTemplate('password_reset', [
            'username' => $username,
            'reset_link' => $resetLink,
            'site_name' => $this->getSiteName()
        ]);

        return $this->send($email, $subject, $body, true);
    }

    public function sendOrderConfirmation($email, $orderData) {
        $subject = '订单确认 - #' . $orderData['id'];
        
        $body = $this->renderTemplate('order_confirmation', [
            'order' => $orderData,
            'site_name' => $this->getSiteName()
        ]);

        return $this->send($email, $subject, $body, true);
    }

    public function sendVmCreated($email, $vmData) {
        $subject = '虚拟机创建成功';
        
        $body = $this->renderTemplate('vm_created', [
            'vm' => $vmData,
            'site_name' => $this->getSiteName()
        ]);

        return $this->send($email, $subject, $body, true);
    }

    public function sendVmExpiring($email, $vmData) {
        $subject = '虚拟机即将过期';
        
        $body = $this->renderTemplate('vm_expiring', [
            'vm' => $vmData,
            'site_name' => $this->getSiteName()
        ]);

        return $this->send($email, $subject, $body, true);
    }

    private function renderTemplate($template, $data) {
        $templates = [
            'activation' => $this->getActivationTemplate(),
            'password_reset' => $this->getPasswordResetTemplate(),
            'order_confirmation' => $this->getOrderConfirmationTemplate(),
            'vm_created' => $this->getVmCreatedTemplate(),
            'vm_expiring' => $this->getVmExpiringTemplate()
        ];

        $content = $templates[$template] ?? '';
        
        foreach ($data as $key => $value) {
            $content = str_replace("{{$key}}", $value, $content);
        }

        return $content;
    }

    private function getActivationTemplate() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #007bff; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{site_name}}</h1>
        </div>
        <div class="content">
            <h2>欢迎注册！</h2>
            <p>您好{{username}}，</p>
            <p>感谢您注册 {{site_name}}。请点击下面的按钮激活您的账户：</p>
            <div style="text-align: center;">
                <a href="{{activation_link}}" class="button">激活账户</a>
            </div>
            <p>或者复制以下链接到浏览器中打开：</p>
            <p style="word-break: break-all;">{{activation_link}}</p>
            <p>此链接将在1小时后过期。</p>
        </div>
        <div class="footer">
            <p>如果您没有注册此账户，请忽略此邮件。</p>
            <p>&copy; ' . date('Y') . ' {{site_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }

    private function getPasswordResetTemplate() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { display: inline-block; padding: 12px 24px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{site_name}}</h1>
        </div>
        <div class="content">
            <h2>密码重置请求</h2>
            <p>您好{{username}}，</p>
            <p>我们收到了您的密码重置请求。请点击下面的按钮重置您的密码：</p>
            <div style="text-align: center;">
                <a href="{{reset_link}}" class="button">重置密码</a>
            </div>
            <p>或者复制以下链接到浏览器中打开：</p>
            <p style="word-break: break-all;">{{reset_link}}</p>
            <p>此链接将在1小时后过期。</p>
            <p>如果您没有请求重置密码，请忽略此邮件。</p>
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' {{site_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }

    private function getOrderConfirmationTemplate() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #28a745; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .order-info { background: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{site_name}}</h1>
        </div>
        <div class="content">
            <h2>订单确认</h2>
            <p>感谢您的购买！</p>
            <div class="order-info">
                <p><strong>订单号：</strong>{{order.id}}</p>
                <p><strong>产品：</strong>{{order.product_name}}</p>
                <p><strong>金额：</strong>{{order.amount}}</p>
                <p><strong>状态：</strong>{{order.status}}</p>
            </div>
            <p>您的虚拟机将在支付成功后自动创建。</p>
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' {{site_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }

    private function getVmCreatedTemplate() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #17a2b8; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .vm-info { background: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{site_name}}</h1>
        </div>
        <div class="content">
            <h2>虚拟机创建成功</h2>
            <p>您的虚拟机已成功创建！</p>
            <div class="vm-info">
                <p><strong>虚拟机名称：</strong>{{vm.name}}</p>
                <p><strong>虚拟机ID：</strong>{{vm.vmid}}</p>
                <p><strong>状态：</strong>{{vm.status}}</p>
            </div>
            <p>您现在可以登录系统管理您的虚拟机。</p>
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' {{site_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }

    private function getVmExpiringTemplate() {
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #ffc107; color: #333; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .vm-info { background: white; padding: 15px; margin: 20px 0; border-radius: 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>{{site_name}}</h1>
        </div>
        <div class="content">
            <h2>虚拟机即将过期</h2>
            <p>您的虚拟机即将过期，请注意续费。</p>
            <div class="vm-info">
                <p><strong>虚拟机名称：</strong>{{vm.name}}</p>
                <p><strong>虚拟机ID：</strong>{{vm.vmid}}</p>
                <p><strong>过期时间：</strong>{{vm.expires_at}}</p>
            </div>
            <p>请及时续费以避免服务中断。</p>
        </div>
        <div class="footer">
            <p>&copy; ' . date('Y') . ' {{site_name}}. All rights reserved.</p>
        </div>
    </div>
</body>
</html>';
    }

    private function generateMessageId() {
        return '<' . md5(uniqid(time())) . '@' . parse_url($this->config['from'], PHP_URL_HOST) . '>';
    }

    private function getSiteUrl() {
        $stmt = $this->pdo->prepare("SELECT `value` FROM site_config WHERE `key` = 'site_url'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['value'] : 'http://localhost';
    }

    private function getSiteName() {
        $stmt = $this->pdo->prepare("SELECT `value` FROM site_config WHERE `key` = 'site_name'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['value'] : 'PVE 虚拟机管理系统';
    }
}