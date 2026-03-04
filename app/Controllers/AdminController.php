<?php
class AdminController {
    private $pdo;
    private $auth;

    public function __construct(PDO $pdo, AuthMiddleware $auth) {
        $this->pdo = $pdo;
        $this->auth = $auth;
        $this->auth->requireAdmin();
    }

    // ========== 用户管理 ==========
    
    // 获取所有用户
    public function getAllUsers($page = 1, $limit = 20, $search = '') {
        $offset = ($page - 1) * $limit;
        
        if ($search) {
            $stmt = $this->pdo->prepare(
                "SELECT id, username, email, role, status, created_at, last_login 
                 FROM users 
                 WHERE username LIKE ? OR email LIKE ? 
                 ORDER BY created_at DESC 
                 LIMIT ? OFFSET ?"
            );
            $searchParam = "%$search%";
            $stmt->execute([$searchParam, $searchParam, $limit, $offset]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT id, username, email, role, status, created_at, last_login 
                 FROM users 
                 ORDER BY created_at DESC 
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$limit, $offset]);
        }
        
        return $stmt->fetchAll();
    }

    // 修改用户状态
    public function updateUserStatus($userId, $status) {
        if (!in_array($status, ['active', 'disabled'])) {
            throw new Exception("无效的状态");
        }
        
        $stmt = $this->pdo->prepare(
            "UPDATE users SET status = ? WHERE id = ?"
        );
        $stmt->execute([$status, $userId]);
        
        // 记录审计日志
        $this->auth->logAudit('update_user_status', [
            'user_id' => $userId,
            'status' => $status
        ]);
        
        return $stmt->rowCount() > 0;
    }

    // 代用户登录
    public function loginUserAs($userId) {
        $stmt = $this->pdo->prepare(
            "SELECT id, username, email, role FROM users WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception("用户不存在");
        }
        
        // 记录审计日志
        $this->auth->logAudit('login_as_user', [
            'target_user_id' => $userId
        ]);
        
        // 设置会话
        $_SESSION['user'] = $user;
        $_SESSION['admin_mode'] = true;
        
        return true;
    }

    // 获取用户详情
    public function getUserDetail($userId) {
        $stmt = $this->pdo->prepare(
            "SELECT u.*, ub.balance, ub.total_recharge, COUNT(DISTINCT o.id) as order_count, COUNT(DISTINCT v.id) as vm_count
             FROM users u
             LEFT JOIN user_balance ub ON u.id = ub.user_id
             LEFT JOIN orders o ON u.id = o.user_id
             LEFT JOIN vms v ON u.id = v.user_id
             WHERE u.id = ?
             GROUP BY u.id"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    // ========== 产品管理 ==========
    
    // 创建产品
    public function createProduct($data) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO products (name, description, price, vm_config, duration_days, status) 
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['name'],
            $data['description'],
            $data['price'],
            json_encode($data['vm_config']),
            $data['duration_days'],
            'active'
        ]);
        
        $this->auth->logAudit('create_product', ['product_id' => $this->pdo->lastInsertId()]);
        return $this->pdo->lastInsertId();
    }

    // 更新产品
    public function updateProduct($productId, $data) {
        $sql = "UPDATE products SET name = ?, description = ?, price = ?, vm_config = ?, duration_days = ?";
        $params = [
            $data['name'],
            $data['description'],
            $data['price'],
            json_encode($data['vm_config']),
            $data['duration_days']
        ];
        
        if (isset($data['status'])) {
            $sql .= ", status = ?";
            $params[] = $data['status'];
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $productId;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $this->auth->logAudit('update_product', ['product_id' => $productId]);
        return $stmt->rowCount() > 0;
    }

    // 删除产品
    public function deleteProduct($productId) {
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        
        $this->auth->logAudit('delete_product', ['product_id' => $productId]);
        return $stmt->rowCount() > 0;
    }

    // ========== 兑换码管理 ==========
    
    // 生成兑换码
    public function generateRedeemCodes($count, $type, $productId = null, $amount = null, $expiresDays = 30) {
        $generated = [];
        
        for ($i = 0; $i < $count; $i++) {
            do {
                $code = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12));
            } while ($this->isCodeExists($code));
            
            $stmt = $this->pdo->prepare(
                "INSERT INTO redeem_codes (code, product_id, amount, expires_at) 
                 VALUES (?, ?, ?, NOW() + INTERVAL ? DAY)"
            );
            $stmt->execute([$code, $productId, $amount, $expiresDays]);
            
            $generated[] = $code;
        }
        
        $this->auth->logAudit('generate_redeem_codes', [
            'count' => $count,
            'type' => $type
        ]);
        
        return $generated;
    }

    // 检查兑换码是否存在
    private function isCodeExists($code) {
        $stmt = $this->pdo->prepare("SELECT id FROM redeem_codes WHERE code = ?");
        $stmt->execute([$code]);
        return $stmt->fetch() !== false;
    }

    // ========== 订单管理 ==========
    
    // 获取所有订单
    public function getAllOrders($page = 1, $limit = 20, $status = null) {
        $offset = ($page - 1) * $limit;
        
        $sql = "SELECT o.*, u.username, p.name as product_name 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                LEFT JOIN products p ON o.product_id = p.id";
        
        if ($status) {
            $sql .= " WHERE o.status = ?";
        }
        
        $sql .= " ORDER BY o.created_at DESC LIMIT ? OFFSET ?";
        
        $stmt = $this->pdo->prepare($sql);
        $params = [];
        if ($status) {
            $params[] = $status;
        }
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // 删除订单
    public function deleteOrder($orderId) {
        $stmt = $this->pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        
        $this->auth->logAudit('delete_order', ['order_id' => $orderId]);
        return $stmt->rowCount() > 0;
    }

    // ========== 虚拟机管理 ==========
    
    // 获取所有虚拟机
    public function getAllVms($page = 1, $limit = 20, $search = '') {
        $offset = ($page - 1) * $limit;
        
        if ($search) {
            $stmt = $this->pdo->prepare(
                "SELECT v.*, u.username, n.name as node_name 
                 FROM vms v 
                 LEFT JOIN users u ON v.user_id = u.id 
                 LEFT JOIN pve_nodes n ON v.node_id = n.id 
                 WHERE v.name LIKE ? OR v.vmid LIKE ? 
                 ORDER BY v.created_at DESC 
                 LIMIT ? OFFSET ?"
            );
            $searchParam = "%$search%";
            $stmt->execute([$searchParam, $searchParam, $limit, $offset]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT v.*, u.username, n.name as node_name 
                 FROM vms v 
                 LEFT JOIN users u ON v.user_id = u.id 
                 LEFT JOIN pve_nodes n ON v.node_id = n.id 
                 ORDER BY v.created_at DESC 
                 LIMIT ? OFFSET ?"
            );
            $stmt->execute([$limit, $offset]);
        }
        
        return $stmt->fetchAll();
    }

    // 转移虚拟机归属
    public function transferVm($vmId, $newUserId) {
        // 验证新用户
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->execute([$newUserId]);
        if (!$stmt->fetch()) {
            throw new Exception("目标用户不存在");
        }
        
        // 获取当前归属
        $stmt = $this->pdo->prepare("SELECT user_id FROM vms WHERE id = ?");
        $stmt->execute([$vmId]);
        $vm = $stmt->fetch();
        
        if (!$vm) {
            throw new Exception("虚拟机不存在");
        }
        
        // 更新归属
        $stmt = $this->pdo->prepare(
            "UPDATE vms SET user_id = ? WHERE id = ?"
        );
        $stmt->execute([$newUserId, $vmId]);
        
        $this->auth->logAudit('transfer_vm', [
            'vm_id' => $vmId,
            'from_user' => $vm['user_id'],
            'to_user' => $newUserId
        ]);
        
        return true;
    }

    // 删除虚拟机记录（不会实际删除PVE中的虚拟机）
    public function deleteVmRecord($vmId) {
        $stmt = $this->pdo->prepare("DELETE FROM vms WHERE id = ?");
        $stmt->execute([$vmId]);
        
        $this->auth->logAudit('delete_vm', ['vm_id' => $vmId]);
        return $stmt->rowCount() > 0;
    }

    // ========== 节点管理 ==========
    
    // 添加节点
    public function addNode($data) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO pve_nodes (name, api_url, api_user, api_token) 
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['name'],
            $data['api_url'],
            $data['api_user'],
            $data['api_token']
        ]);
        
        $this->auth->logAudit('add_node', ['node_id' => $this->pdo->lastInsertId()]);
        return $this->pdo->lastInsertId();
    }

    // 更新节点
    public function updateNode($nodeId, $data) {
        $stmt = $this->pdo->prepare(
            "UPDATE pve_nodes 
             SET name = ?, api_url = ?, api_user = ?, api_token = ? 
             WHERE id = ?"
        );
        $stmt->execute([
            $data['name'],
            $data['api_url'],
            $data['api_user'],
            $data['api_token'],
            $nodeId
        ]);
        
        $this->auth->logAudit('update_node', ['node_id' => $nodeId]);
        return $stmt->rowCount() > 0;
    }

    // 删除节点
    public function deleteNode($nodeId) {
        $stmt = $this->pdo->prepare("DELETE FROM pve_nodes WHERE id = ?");
        $stmt->execute([$nodeId]);
        
        $this->auth->logAudit('delete_node', ['node_id' => $nodeId]);
        return $stmt->rowCount() > 0;
    }

    // 同步节点虚拟机
    public function syncNodeVms($nodeId) {
        $pveService = new PveApiService($this->pdo);
        $count = $pveService->syncVmsFromNode($nodeId);
        
        $this->auth->logAudit('sync_node_vms', ['node_id' => $nodeId, 'synced_count' => $count]);
        return $count;
    }

    // ========== 配置管理 ==========
    
    // 更新站点配置
    public function updateSiteConfig($key, $value, $description = '') {
        $stmt = $this->pdo->prepare(
            "INSERT INTO site_config (`key`, `value`, description) 
             VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE `value` = ?, description = ?, updated_at = NOW()"
        );
        $stmt->execute([$key, $value, $description, $value, $description]);
        
        $this->auth->logAudit('update_config', ['key' => $key]);
        return true;
    }

    // 获取站点配置
    public function getSiteConfig($key) {
        $stmt = $this->pdo->prepare("SELECT `value` FROM site_config WHERE `key` = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        return $result ? $result['value'] : null;
    }

    // 获取所有配置
    public function getAllConfigs() {
        $stmt = $this->pdo->query("SELECT * FROM site_config ORDER BY `key`");
        return $stmt->fetchAll();
    }

    // ========== 统计数据 ==========
    
    // 获取仪表板统计
    public function getDashboardStats() {
        $stats = [];
        
        // 用户统计
        $stmt = $this->pdo->query(
            "SELECT 
                COUNT(*) as total_users,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users
             FROM users"
        );
        $stats['users'] = $stmt->fetch();
        
        // 虚拟机统计
        $stmt = $this->pdo->query(
            "SELECT 
                COUNT(*) as total_vms,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_vms
             FROM vms"
        );
        $stats['vms'] = $stmt->fetch();
        
        // 订单统计
        $stmt = $this->pdo->query(
            "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_revenue
             FROM orders"
        );
        $stats['orders'] = $stmt->fetch();
        
        // 收入统计（本月）
        $stmt = $this->pdo->query(
            "SELECT 
                SUM(amount) as monthly_revenue,
                COUNT(*) as monthly_orders
             FROM orders 
             WHERE status = 'paid' 
             AND YEAR(created_at) = YEAR(NOW()) 
             AND MONTH(created_at) = MONTH(NOW())"
        );
        $stats['monthly'] = $stmt->fetch();
        
        return $stats;
    }
}
