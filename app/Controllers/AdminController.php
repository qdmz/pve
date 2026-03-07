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
            $searchParam = "%$search%";
            $stmt = $this->pdo->prepare(
                "SELECT id, username, email, role, status, created_at, last_login 
                 FROM users 
                 WHERE username LIKE ? OR email LIKE ? 
                 ORDER BY created_at DESC 
                 LIMIT " . (int)$limit . " OFFSET " . (int)$offset
            );
            $stmt->execute([$searchParam, $searchParam]);
        } else {
            $stmt = $this->pdo->query(
                "SELECT id, username, email, role, status, created_at, last_login 
                 FROM users 
                 ORDER BY created_at DESC 
                 LIMIT " . (int)$limit . " OFFSET " . (int)$offset
            );
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

    // 创建用户
    public function createUser($data) {
        // 检查邮箱是否已存在
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            throw new Exception("邮箱已存在");
        }
        
        // 检查用户名是否已存在
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$data['username']]);
        if ($stmt->fetch()) {
            throw new Exception("用户名已存在");
        }
        
        // 创建用户
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password_hash, role, status) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $data['username'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['role'],
            $data['status']
        ]);
        
        $userId = $this->pdo->lastInsertId();
        
        // 初始化用户余额
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_balance (user_id, balance, total_recharge) 
             VALUES (?, 0, 0)"
        );
        $stmt->execute([$userId]);
        
        $this->auth->logAudit('create_user', ['user_id' => $userId]);
        return $userId;
    }

    // 更新用户
    public function updateUser($userId, $data) {
        $sql = "UPDATE users SET username = ?, email = ?, role = ?, status = ?";
        $params = [
            $data['username'],
            $data['email'],
            $data['role'],
            $data['status']
        ];
        
        // 如果提供了密码，则更新密码
        if (!empty($data['password'])) {
            $sql .= ", password_hash = ?";
            $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $userId;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $this->auth->logAudit('update_user', ['user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    // 删除用户
    public function deleteUser($userId) {
        // 检查是否有虚拟机
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM vms WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("该用户还有虚拟机，无法删除");
        }
        
        // 检查是否有订单
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception("该用户还有订单，无法删除");
        }
        
        // 开始事务
        $this->pdo->beginTransaction();
        
        try {
            // 删除用户余额
            $stmt = $this->pdo->prepare("DELETE FROM user_balance WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            // 删除用户
            $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            
            $this->pdo->commit();
            $this->auth->logAudit('delete_user', ['user_id' => $userId]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
        
        if ($status) {
            $stmt = $this->pdo->prepare(
                "SELECT o.*, u.username, p.name as product_name 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                LEFT JOIN products p ON o.product_id = p.id
                WHERE o.status = ?
                ORDER BY o.created_at DESC 
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset
            );
            $stmt->execute([$status]);
        } else {
            $stmt = $this->pdo->query(
                "SELECT o.*, u.username, p.name as product_name 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                LEFT JOIN products p ON o.product_id = p.id
                ORDER BY o.created_at DESC 
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset
            );
        }
        
        return $stmt->fetchAll();
    }

    // 删除订单
    public function deleteOrder($orderId) {
        $stmt = $this->pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        
        $this->auth->logAudit('delete_order', ['order_id' => $orderId]);
        return $stmt->rowCount() > 0;
    }

    // 获取订单详情
    public function getOrderDetail($orderId) {
        // 检查用户权限，普通用户只能查看自己的订单
        if (isset($_SESSION['user']) && $_SESSION['user']['role'] !== 'admin') {
            $stmt = $this->pdo->prepare(
                "SELECT o.*, u.username, p.name as product_name 
                 FROM orders o 
                 JOIN users u ON o.user_id = u.id 
                 LEFT JOIN products p ON o.product_id = p.id
                 WHERE o.id = ? AND o.user_id = ?"
            );
            $stmt->execute([$orderId, $_SESSION['user']['id']]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT o.*, u.username, p.name as product_name 
                 FROM orders o 
                 JOIN users u ON o.user_id = u.id 
                 LEFT JOIN products p ON o.product_id = p.id
                 WHERE o.id = ?"
            );
            $stmt->execute([$orderId]);
        }
        
        $order = $stmt->fetch();
        
        if (!$order) {
            throw new Exception("订单不存在");
        }
        
        return $order;
    }

    // 更新订单状态
    public function updateOrderStatus($orderId, $status) {
        if (!in_array($status, ['pending', 'paid', 'cancelled'])) {
            throw new Exception("无效的订单状态");
        }
        
        $stmt = $this->pdo->prepare(
            "UPDATE orders SET status = ?"
        );
        
        $params = [$status];
        
        if ($status === 'paid') {
            $stmt->queryString .= ", paid_at = NOW()";
        }
        
        $stmt->queryString .= " WHERE id = ?";
        $params[] = $orderId;
        
        $stmt->execute($params);
        
        $this->auth->logAudit('update_order_status', [
            'order_id' => $orderId,
            'status' => $status
        ]);
        
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

    // 创建虚拟机
    public function createVm($vmData) {
        // 验证数据
        if (!isset($vmData['node_id'], $vmData['name'], $vmData['cpu'], $vmData['memory'], $vmData['disk'], $vmData['os'], $vmData['password'], $vmData['user_id'], $vmData['networks'])) {
            throw new Exception('缺少必要参数');
        }

        // 生成唯一的VMID
        $stmt = $this->pdo->query("SELECT MAX(vmid) as max_vmid FROM vms");
        $result = $stmt->fetch();
        $vmid = $result && $result['max_vmid'] ? (int)$result['max_vmid'] + 1 : 100;

        // 调用PVE API创建虚拟机
        $pveService = new PveApiService($this->pdo);
        $node = $pveService->getNodeDetails($vmData['node_id']);

        if (!$node) {
            throw new Exception('节点不存在');
        }

        // 构建虚拟机配置
        $osTemplate = $vmData['os'];
        // 确保操作系统模板路径不重复添加文件后缀
        if (!preg_match('/\.(tar\.gz|tar\.xz|tgz)$/', $osTemplate)) {
            $osTemplate .= '.tar.gz';
        }
        
        $vmConfig = [
            'vmid' => $vmid,
            'hostname' => $vmData['name'],
            'cores' => $vmData['cpu'],
            'memory' => $vmData['memory'],
            'rootfs' => "local:{$vmData['disk']}",
            'ostemplate' => "local:vztmpl/{$osTemplate}",
            'password' => $vmData['password'],
            'onboot' => 1,
            'start' => 1
        ];

        // 添加网络配置
        foreach ($vmData['networks'] as $index => $network) {
            if (!empty($vmData['ip'])) {
                // 使用静态IP配置，包含IP地址、子网掩码和网关
                $vmConfig["net{$index}"] = "name=eth{$index},bridge={$network},ip={$vmData['ip']}/24,gw=154.9.237.254";
            } else {
                // 使用DHCP
                $vmConfig["net{$index}"] = "name=eth{$index},bridge={$network},ip=dhcp";
            }
        }

        // 创建虚拟机
        $result = $pveService->createVm($vmData['node_id'], $vmConfig);

        if (!$result) {
            // 记录详细错误信息
            $logger = new Logger($this->pdo, Logger::ERROR);
            $logger->error('VM creation failed: PVE API returned false');
            $logger->error('VM Config: ' . json_encode($vmConfig));
            throw new Exception('PVE API创建虚拟机失败');
        }

        // 验证结果
        if (!is_array($result) || !isset($result['vmid'])) {
            $logger = new Logger($this->pdo, Logger::ERROR);
            $logger->error('VM creation failed: Invalid API response');
            $logger->error('API Response: ' . json_encode($result));
            throw new Exception('PVE API创建虚拟机失败：无效的API响应');
        }

        // 保存到数据库
        $stmt = $this->pdo->prepare(
            "INSERT INTO vms (vmid, node_id, user_id, name, type, status, config)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $result['vmid'],
            $vmData['node_id'],
            $vmData['user_id'],
            $vmData['name'],
            'lxc',
            'running',
            json_encode($vmConfig)
        ]);

        $vmId = $this->pdo->lastInsertId();
        $this->auth->logAudit('create_vm', ['vm_id' => $vmId, 'name' => $vmData['name'], 'pve_vmid' => $result['vmid']]);
        return $vmId;
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

    // 获取节点详情
    public function getNodeDetail($nodeId) {
        $stmt = $this->pdo->prepare("SELECT * FROM pve_nodes WHERE id = ?");
        $stmt->execute([$nodeId]);
        $node = $stmt->fetch();
        
        if (!$node) {
            throw new Exception("节点不存在");
        }
        
        // 统计该节点上的虚拟机数量
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as vm_count FROM vms WHERE node_id = ? AND type = 'kvm'");
        $stmt->execute([$nodeId]);
        $node['vm_count'] = $stmt->fetchColumn();
        
        // 统计该节点上的容器数量
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as container_count FROM vms WHERE node_id = ? AND type = 'lxc'");
        $stmt->execute([$nodeId]);
        $node['container_count'] = $stmt->fetchColumn();
        
        // 获取节点系统状态
        $pveService = new PveApiService($this->pdo);
        $nodeStatus = $this->getNodeStatus($nodeId);
        
        if ($nodeStatus) {
            $node['cpu_usage'] = isset($nodeStatus['cpu']) ? round($nodeStatus['cpu'] * 100, 2) . '%' : '0%';
            $node['memory_usage'] = isset($nodeStatus['mem']) && isset($nodeStatus['maxmem']) 
                ? round(($nodeStatus['mem'] / $nodeStatus['maxmem']) * 100, 2) . '%' 
                : '0%';
            $node['disk_usage'] = isset($nodeStatus['rootfs']) && isset($nodeStatus['rootfs']['used']) && isset($nodeStatus['rootfs']['total'])
                ? round(($nodeStatus['rootfs']['used'] / $nodeStatus['rootfs']['total']) * 100, 2) . '%'
                : '0%';
            $node['network_traffic'] = isset($nodeStatus['network']) 
                ? $this->formatNetworkTraffic($nodeStatus['network']) 
                : '0 KB/s';
        } else {
            $node['cpu_usage'] = '0%';
            $node['memory_usage'] = '0%';
            $node['disk_usage'] = '0%';
            $node['network_traffic'] = '0 KB/s';
        }
        
        return $node;
    }

    // 获取节点状态
    private function getNodeStatus($nodeId) {
        $node = $this->getNodeDetails($nodeId);
        if (!$node) return false;

        $auth = "{$node['api_user']}={$node['api_token']}";
        // 首先获取PVE集群中的实际节点名称
        $nodesUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes";
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: PVEAPIToken=$auth\r\n",
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]
        ]);

        $response = @file_get_contents($nodesUrl, false, $context);
        if (!$response) return false;

        $data = json_decode($response, true);
        if (!isset($data['data']) || empty($data['data'])) return false;

        $nodeName = $data['data'][0]['node'];
        
        // 获取节点状态
        $statusUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/status";
        $response = @file_get_contents($statusUrl, false, $context);
        if (!$response) return false;

        $statusData = json_decode($response, true);
        return $statusData['data'] ?? false;
    }

    // 格式化网络流量
    private function formatNetworkTraffic($network) {
        $totalIn = 0;
        $totalOut = 0;
        
        foreach ($network as $iface => $stats) {
            if (isset($stats['in'])) $totalIn += $stats['in'];
            if (isset($stats['out'])) $totalOut += $stats['out'];
        }
        
        $total = $totalIn + $totalOut;
        return $this->formatBytes($total) . '/s';
    }

    // 格式化字节数
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
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

    // 保存基本设置
    public function saveBasicConfig($data) {
        foreach ($data as $key => $value) {
            $this->updateSiteConfig('basic_' . $key, $value);
        }
        return true;
    }

    // 保存支付设置
    public function savePaymentConfig($data) {
        foreach ($data as $key => $value) {
            $this->updateSiteConfig('payment_' . $key, $value);
        }
        return true;
    }

    // 保存邮件设置
    public function saveEmailConfig($data) {
        foreach ($data as $key => $value) {
            $this->updateSiteConfig('email_' . $key, $value);
        }
        return true;
    }

    // 保存高级设置
    public function saveAdvancedConfig($data) {
        foreach ($data as $key => $value) {
            $this->updateSiteConfig('advanced_' . $key, $value);
        }
        return true;
    }

    // 获取审计日志
    public function getAuditLogs($page = 1, $limit = 20, $search = '', $userId = '', $date = '') {
        $offset = ($page - 1) * $limit;
        $params = [];
        $where = [];
        
        if ($search) {
            $where[] = "action LIKE ?";
            $params[] = "%$search%";
        }
        
        if ($userId) {
            $where[] = "user_id = ?";
            $params[] = $userId;
        }
        
        if ($date) {
            $where[] = "DATE(created_at) = ?";
            $params[] = $date;
        }
        
        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
        
        // 获取总数
        $countQuery = "SELECT COUNT(*) as total FROM audit_logs $whereClause";
        $stmt = $this->pdo->prepare($countQuery);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
        
        // 获取日志列表
        $query = "SELECT al.*, u.username FROM audit_logs al LEFT JOIN users u ON al.user_id = u.id $whereClause ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($query);
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
        
        // 格式化详情
        foreach ($logs as &$log) {
            $log['details'] = json_decode($log['details'], true);
        }
        
        return [
            'logs' => $logs,
            'total' => $total
        ];
    }

    // 备份管理相关方法
    public function getBackups($page = 1, $limit = 20, $nodeId = '', $vmId = '', $status = '') {
        $offset = ($page - 1) * $limit;
        $params = [];
        $where = [];
        
        if ($nodeId) {
            $where[] = "b.node_id = ?";
            $params[] = $nodeId;
        }
        
        if ($vmId) {
            $where[] = "b.vm_id = ?";
            $params[] = $vmId;
        }
        
        if ($status) {
            $where[] = "b.status = ?";
            $params[] = $status;
        }
        
        $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
        
        // 获取总数
        $countQuery = "SELECT COUNT(*) as total FROM backups b $whereClause";
        $stmt = $this->pdo->prepare($countQuery);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();
        
        // 获取备份列表
        $query = "SELECT b.*, n.name as node_name, v.name as vm_name FROM backups b LEFT JOIN pve_nodes n ON b.node_id = n.id LEFT JOIN vms v ON b.vm_id = v.id $whereClause ORDER BY b.created_at DESC LIMIT ? OFFSET ?";
        $stmt = $this->pdo->prepare($query);
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $backups = $stmt->fetchAll();
        
        return [
            'backups' => $backups,
            'total' => $total
        ];
    }

    public function createBackup($vmId, $name) {
        // 验证虚拟机存在
        $stmt = $this->pdo->prepare("SELECT * FROM vms WHERE id = ?");
        $stmt->execute([$vmId]);
        $vm = $stmt->fetch();
        
        if (!$vm) {
            throw new Exception("虚拟机不存在");
        }
        
        // 生成备份名称
        $backupName = $name ?: "backup-" . date('Ymd-His');
        
        // 调用PVE API创建备份
        $pveService = new PveApiService($this->pdo);
        $result = $pveService->createBackup($vm['node_id'], $vm['vmid'], $backupName);
        
        if (!$result) {
            throw new Exception("备份创建失败");
        }
        
        // 保存到数据库
        $stmt = $this->pdo->prepare(
            "INSERT INTO backups (node_id, vm_id, name, status, created_at) 
             VALUES (?, ?, ?, 'completed', NOW())"
        );
        $stmt->execute([$vm['node_id'], $vmId, $backupName]);
        
        $this->auth->logAudit('create_backup', ['vm_id' => $vmId, 'backup_name' => $backupName]);
        return $this->pdo->lastInsertId();
    }

    public function downloadBackup($backupId) {
        // 验证备份存在
        $stmt = $this->pdo->prepare("SELECT * FROM backups WHERE id = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();
        
        if (!$backup) {
            throw new Exception("备份不存在");
        }
        
        // 调用PVE API获取备份文件
        $pveService = new PveApiService($this->pdo);
        $filePath = $pveService->getBackupFile($backup['node_id'], $backup['name']);
        
        if (!$filePath) {
            throw new Exception("备份文件不存在");
        }
        
        // 设置下载头
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($filePath));
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public function restoreBackup($backupId) {
        // 验证备份存在
        $stmt = $this->pdo->prepare("SELECT * FROM backups WHERE id = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();
        
        if (!$backup) {
            throw new Exception("备份不存在");
        }
        
        // 调用PVE API恢复备份
        $pveService = new PveApiService($this->pdo);
        $result = $pveService->restoreBackup($backup['node_id'], $backup['name']);
        
        if (!$result) {
            throw new Exception("备份恢复失败");
        }
        
        $this->auth->logAudit('restore_backup', ['backup_id' => $backupId]);
        return true;
    }

    public function deleteBackup($backupId) {
        // 验证备份存在
        $stmt = $this->pdo->prepare("SELECT * FROM backups WHERE id = ?");
        $stmt->execute([$backupId]);
        $backup = $stmt->fetch();
        
        if (!$backup) {
            throw new Exception("备份不存在");
        }
        
        // 调用PVE API删除备份
        $pveService = new PveApiService($this->pdo);
        $result = $pveService->deleteBackup($backup['node_id'], $backup['name']);
        
        if (!$result) {
            throw new Exception("备份删除失败");
        }
        
        // 从数据库中删除
        $stmt = $this->pdo->prepare("DELETE FROM backups WHERE id = ?");
        $stmt->execute([$backupId]);
        
        $this->auth->logAudit('delete_backup', ['backup_id' => $backupId]);
        return true;
    }

    // ========== 统计数据 ==========
    
    // 获取仪表板统计（管理员）
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

    // 获取用户个人统计
    public function getUserStats($userId) {
        $stats = [];
        
        // 虚拟机统计（用户自己的）
        $stmt = $this->pdo->prepare(
            "SELECT 
                COUNT(*) as total_vms,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running_vms
             FROM vms
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $vmsStats = $stmt->fetch();
        $stats['vms'] = $vmsStats ? $vmsStats : ['total_vms' => 0, 'running_vms' => 0];
        
        // 订单统计（用户自己的）
        $stmt = $this->pdo->prepare(
            "SELECT 
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_orders,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_revenue
             FROM orders
             WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $ordersStats = $stmt->fetch();
        $stats['orders'] = $ordersStats ? $ordersStats : ['total_orders' => 0, 'paid_orders' => 0, 'total_revenue' => 0];
        
        // 收入统计（用户本月）
        $stmt = $this->pdo->prepare(
            "SELECT 
                SUM(amount) as monthly_revenue,
                COUNT(*) as monthly_orders
             FROM orders 
             WHERE user_id = ?
             AND status = 'paid' 
             AND YEAR(created_at) = YEAR(NOW()) 
             AND MONTH(created_at) = MONTH(NOW())"
        );
        $stmt->execute([$userId]);
        $monthlyStats = $stmt->fetch();
        $stats['monthly'] = $monthlyStats ? $monthlyStats : ['monthly_revenue' => 0, 'monthly_orders' => 0];
        
        return $stats;
    }

    // 网络配置管理相关方法
    public function getNetworks() {
        $stmt = $this->pdo->prepare("SELECT n.*, pn.name as node_name FROM networks n LEFT JOIN pve_nodes pn ON n.node_id = pn.id ORDER BY n.id DESC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function createNetwork($data) {
        if (!isset($data['name']) || !isset($data['type']) || !isset($data['node_id'])) {
            throw new Exception("缺少必要参数");
        }
        
        $stmt = $this->pdo->prepare(
            "INSERT INTO networks (name, type, node_id, status, config, created_at) 
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $data['name'],
            $data['type'],
            $data['node_id'],
            $data['status'] ?? 'active',
            $data['config'] ?? ''
        ]);
        
        $networkId = $this->pdo->lastInsertId();
        $this->auth->logAudit('create_network', ['network_id' => $networkId, 'name' => $data['name']]);
        return $networkId;
    }

    public function updateNetwork($networkId, $data) {
        // 验证网络配置存在
        $stmt = $this->pdo->prepare("SELECT * FROM networks WHERE id = ?");
        $stmt->execute([$networkId]);
        $network = $stmt->fetch();
        
        if (!$network) {
            throw new Exception("网络配置不存在");
        }
        
        $stmt = $this->pdo->prepare(
            "UPDATE networks 
             SET name = ?, type = ?, node_id = ?, status = ?, config = ? 
             WHERE id = ?"
        );
        $stmt->execute([
            $data['name'],
            $data['type'],
            $data['node_id'],
            $data['status'] ?? 'active',
            $data['config'] ?? '',
            $networkId
        ]);
        
        $this->auth->logAudit('update_network', ['network_id' => $networkId, 'name' => $data['name']]);
        return true;
    }

    public function deleteNetwork($networkId) {
        // 验证网络配置存在
        $stmt = $this->pdo->prepare("SELECT * FROM networks WHERE id = ?");
        $stmt->execute([$networkId]);
        $network = $stmt->fetch();
        
        if (!$network) {
            throw new Exception("网络配置不存在");
        }
        
        // 从数据库中删除
        $stmt = $this->pdo->prepare("DELETE FROM networks WHERE id = ?");
        $stmt->execute([$networkId]);
        
        $this->auth->logAudit('delete_network', ['network_id' => $networkId]);
        return true;
    }
}
