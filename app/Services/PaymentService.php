<?php
class PaymentService {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // 获取可用支付方式
    public function getAvailableGateways() {
        $stmt = $this->pdo->query(
            "SELECT * FROM payment_gateways WHERE enabled = TRUE"
        );
        return $stmt->fetchAll();
    }

    // 获取所有产品
    public function getAllProducts() {
        $stmt = $this->pdo->query(
            "SELECT * FROM products WHERE status = 'active' ORDER BY price"
        );
        return $stmt->fetchAll();
    }

    // 获取产品详情
    public function getProduct($productId) {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM products WHERE id = ? AND status = 'active'"
        );
        $stmt->execute([$productId]);
        return $stmt->fetch();
    }

    // 创建订单
    public function createOrder($userId, $productId) {
        $product = $this->getProduct($productId);
        if (!$product) {
            throw new Exception("产品不存在或已下架");
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO orders (user_id, product_id, amount) 
             VALUES (?, ?, ?)"
        );
        $stmt->execute([
            $userId,
            $productId,
            $product['price']
        ]);

        return $this->pdo->lastInsertId();
    }

    // 处理支付回调
    public function handleCallback($gateway, $data) {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM payment_gateways WHERE name = ?"
        );
        $stmt->execute([$gateway]);
        
        if (!$gatewayConfig = $stmt->fetch()) {
            throw new Exception("支付网关配置错误");
        }

        // 验证签名
        if (!$this->verifySignature($data, $gatewayConfig['config']['secret'], $gateway)) {
            throw new Exception("签名验证失败");
        }

        // 更新订单状态
        if ($data['status'] === 'paid' || $data['trade_status'] === 'TRADE_SUCCESS') {
            $orderId = $data['order_id'] ?? $data['out_trade_no'];
            
            $stmt = $this->pdo->prepare(
                "UPDATE orders SET status = 'paid' WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$orderId]);
            
            // 创建虚拟机
            $this->createVmFromOrder($orderId);
            
            return true;
        }
        
        return false;
    }

    // 从订单创建虚拟机
    private function createVmFromOrder($orderId) {
        $stmt = $this->pdo->prepare(
            "SELECT o.*, p.vm_config, p.duration_days, 
                    (SELECT id FROM pve_nodes ORDER BY id ASC LIMIT 1) as node_id
             FROM orders o 
             JOIN products p ON o.product_id = p.id 
             WHERE o.id = ?"
        );
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) return false;

        // 使用 PVE API 创建虚拟机
        $pveService = new PveApiService($this->pdo);
        $vmConfig = json_decode($order['vm_config'], true);
        
        // 生成唯一的 VMID
        $vmid = $this->generateVmid();
        $vmConfig['vmid'] = $vmid;

        // 调用 PVE API 创建虚拟机
        $result = $pveService->createVm($order['node_id'], $vmConfig);

        if ($result) {
            // 保存到数据库
            $stmt = $this->pdo->prepare(
                "INSERT INTO vms (vmid, node_id, user_id, name, type, status, config, expires_at) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? DAY)"
            );
            $stmt->execute([
                $vmid,
                $order['node_id'],
                $order['user_id'],
                "VM-" . substr(md5($orderId), 0, 8),
                'lxc',
                'running',
                json_encode($vmConfig),
                $order['duration_days']
            ]);

            // 更新订单状态
            $this->pdo->prepare("UPDATE orders SET vm_id = ? WHERE id = ?")
                ->execute([$this->pdo->lastInsertId(), $orderId]);

            return $this->pdo->lastInsertId();
        }

        return false;
    }

    // 生成唯一的 VMID
    private function generateVmid() {
        $stmt = $this->pdo->query("SELECT MAX(vmid) as max_vmid FROM vms");
        $result = $stmt->fetch();
        
        if ($result && $result['max_vmid']) {
            return (int)$result['max_vmid'] + 1;
        }
        
        return 100; // 起始VMID
    }

    // 生成支付签名
    public function generatePaymentSign($params, $secret, $gateway = 'standard') {
        ksort($params);
        $signStr = '';
        
        foreach ($params as $key => $value) {
            if ($value !== '' && $value !== null) {
                $signStr .= "$key=$value&";
            }
        }
        
        $signStr .= "key=" . $secret;
        
        switch ($gateway) {
            case 'md5':
                return md5($signStr);
            case 'sha256':
                return hash('sha256', $signStr);
            default:
                return md5($signStr);
        }
    }

    // 验证签名
    private function verifySignature($data, $secret, $gateway) {
        $sign = $data['sign'] ?? '';
        unset($data['sign']);
        
        $expectedSign = $this->generatePaymentSign($data, $secret, $gateway);
        
        return hash_equals($expectedSign, $sign);
    }

    // 获取用户余额
    public function getUserBalance($userId) {
        $stmt = $this->pdo->prepare("SELECT balance FROM user_balance WHERE user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return $result ? $result['balance'] : 0;
    }

    // 扣除余额
    public function deductBalance($userId, $amount, $orderId) {
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE user_balance 
                 SET balance = balance - ? 
                 WHERE user_id = ? AND balance >= ?"
            );
            $stmt->execute([$amount, $userId, $amount]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception("余额不足");
            }
            
            // 记录交易
            $stmt = $this->pdo->prepare(
                "INSERT INTO transactions (user_id, type, amount, order_id, created_at) 
                 VALUES (?, 'deduct', ?, ?, NOW())"
            );
            $stmt->execute([$userId, $amount, $orderId]);
            
            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollback();
            throw $e;
        }
    }

    // 生成支付链接
    public function generatePaymentUrl($order, $gateway, $gatewayConfig) {
        $baseUrl = $gatewayConfig['url'] ?? '';
        
        switch ($gateway['name']) {
            case 'alipay':
                $params = [
                    'out_trade_no' => $order['id'],
                    'subject' => '产品购买',
                    'total_amount' => $order['amount'],
                    'notify_url' => $gatewayConfig['notify_url'] ?? '',
                    'return_url' => $gatewayConfig['return_url'] ?? ''
                ];
                break;
            case 'wechat':
                $params = [
                    'out_trade_no' => $order['id'],
                    'body' => '产品购买',
                    'total_fee' => $order['amount'] * 100, // 微信支付以分为单位
                    'notify_url' => $gatewayConfig['notify_url'] ?? '',
                    'return_url' => $gatewayConfig['return_url'] ?? ''
                ];
                break;
            case 'epay':
                $params = [
                    'pid' => $gatewayConfig['pid'],
                    'type' => $gatewayConfig['type'] ?? 'alipay',
                    'out_trade_no' => $order['id'],
                    'notify_url' => $gatewayConfig['notify_url'] ?? '',
                    'return_url' => $gatewayConfig['return_url'] ?? '',
                    'name' => '产品购买',
                    'money' => $order['amount'],
                    'sitename' => $gatewayConfig['sitename'] ?? ''
                ];
                // 生成签名
                $params['sign'] = $this->generatePaymentSign($params, $gatewayConfig['key']);
                $params['sign_type'] = 'MD5';
                break;
            default:
                throw new Exception("不支持的支付方式");
        }
        
        // 构建支付链接
        $queryString = http_build_query($params);
        return $baseUrl . '?' . $queryString;
    }
}