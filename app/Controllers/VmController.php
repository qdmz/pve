<?php
class VmController {
    private $pdo;
    private $auth;
    private $pveService;

    public function __construct(PDO $pdo, AuthMiddleware $auth) {
        $this->pdo = $pdo;
        $this->auth = $auth;
        $this->pveService = new PveApiService($pdo);
    }

    // 获取用户的虚拟机列表
    public function getUserVms($userId, $refresh = false) {
        $isAdmin = (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin');
        
        if ($isAdmin) {
            $stmt = $this->pdo->query(
                "SELECT v.*, n.name as node_name, u.username 
                 FROM vms v 
                 LEFT JOIN pve_nodes n ON v.node_id = n.id 
                 LEFT JOIN users u ON v.user_id = u.id 
                 ORDER BY v.created_at DESC"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT v.*, n.name as node_name 
                 FROM vms v 
                 LEFT JOIN pve_nodes n ON v.node_id = n.id 
                 WHERE v.user_id = ? 
                 ORDER BY v.created_at DESC"
            );
            $stmt->execute([$userId]);
        }
        
        $vms = $stmt->fetchAll();
        
        // 获取每个虚拟机的状态（默认使用本地缓存，可选择刷新）
        foreach ($vms as &$vm) {
            if ($refresh) {
                $vm['realtime_status'] = $this->getVmRealtimeStatus($vm['id']);
                // 更新本地缓存状态
                $this->pdo->prepare("UPDATE vms SET status = ? WHERE id = ?")
                    ->execute([$vm['realtime_status']['status'] ?? $vm['status'], $vm['id']]);
            } else {
                $vm['realtime_status'] = [
                    'status' => $vm['status'],
                    'qemu' => [
                        'cpu' => 0,
                        'mem' => 0
                    ]
                ];
            }
            if (isset($vm['expires_at']) && $vm['expires_at']) {
                $vm['days_remaining'] = $this->calculateDaysRemaining($vm['expires_at']);
            }
        }
        
        return $vms;
    }

    // 获取单个虚拟机详情
    public function getVmDetail($vmId, $userId, $refresh = false) {
        $isAdmin = (isset($_SESSION['user']) && $_SESSION['user']['role'] === 'admin');
        
        if ($isAdmin) {
            $stmt = $this->pdo->prepare(
                "SELECT v.*, n.name as node_name, n.api_url, n.api_user, n.api_token, u.username 
                 FROM vms v 
                 LEFT JOIN pve_nodes n ON v.node_id = n.id 
                 LEFT JOIN users u ON v.user_id = u.id 
                 WHERE v.id = ?"
            );
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT v.*, n.name as node_name, n.api_url, n.api_user, n.api_token 
                 FROM vms v 
                 LEFT JOIN pve_nodes n ON v.node_id = n.id 
                 WHERE v.id = ? AND v.user_id = ?"
            );
            $stmt->execute([$vmId, $userId]);
        }
        
        if (!$isAdmin) $stmt->execute([$vmId, $userId]);
        else $stmt->execute([$vmId]);
        
        $vm = $stmt->fetch();
        
        if (!$vm) {
            throw new Exception("虚拟机不存在或无权访问");
        }
        
        // 获取状态（默认使用本地缓存，可选择刷新）
        if ($refresh) {
            $vm['realtime_status'] = $this->getVmRealtimeStatus($vmId);
            // 更新本地缓存状态
            $this->pdo->prepare("UPDATE vms SET status = ? WHERE id = ?")
                ->execute([$vm['realtime_status']['status'] ?? $vm['status'], $vmId]);
        } else {
            $vm['realtime_status'] = [
                'status' => $vm['status'],
                'qemu' => [
                    'cpu' => 0,
                    'mem' => 0
                ]
            ];
        }
        
        // 计算剩余天数
        if (isset($vm['expires_at']) && $vm['expires_at']) {
            $vm['days_remaining'] = $this->calculateDaysRemaining($vm['expires_at']);
        }
        
        // 获取详细配置信息
        $config = $this->pveService->getVmConfig($vmId);
        if ($config) {
            $vm['config'] = $config;
            // 提取关键配置信息
            $vm['cpu_count'] = $config['cores'] ?? $config['cpus'] ?? 0;
            $vm['memory_size'] = $config['memory'] ?? $config['mem'] ?? 0;
            $vm['disk_size'] = $config['rootfs'] ?? $config['scsi0'] ?? 0;
            $vm['os'] = $config['ostemplate'] ?? $config['template'] ?? 'Unknown';
            $vm['root_password'] = $config['password'] ?? 'Not set';
        }
        
        // 获取网络信息
        $networkInfo = $this->pveService->getVmNetworkInfo($vmId);
        if ($networkInfo) {
            $vm['network'] = $networkInfo;
            // 提取IP地址
            $ips = [];
            foreach ($networkInfo as $iface) {
                if (isset($iface['ip-addresses']) && is_array($iface['ip-addresses'])) {
                    foreach ($iface['ip-addresses'] as $ipInfo) {
                        if (isset($ipInfo['ip-address'])) {
                            $ips[] = $ipInfo['ip-address'];
                        }
                    }
                } elseif (isset($iface['ip'])) {
                    $ips[] = $iface['ip'];
                }
            }
            $vm['ip_addresses'] = $ips;
        }
        
        return $vm;
    }

    // 控制虚拟机（启动、停止、重启等）
    public function controlVm($vmId, $action, $userId) {
        // 验证权限
        $this->checkVmAccess($vmId, $userId);
        
        // 验证操作类型
        $validActions = ['start', 'stop', 'restart', 'shutdown', 'pause', 'resume'];
        if (!in_array($action, $validActions)) {
            throw new Exception("无效的操作");
        }
        
        // 执行操作
        $result = $this->pveService->controlVm($vmId, $action);
        
        if ($result === false) {
            throw new Exception("操作失败");
        }
        
        // 记录审计日志
        $this->auth->logAudit('vm_control', [
            'vm_id' => $vmId,
            'action' => $action
        ]);
        
        // 更新数据库状态
        $statusMap = [
            'start' => 'running',
            'stop' => 'stopped',
            'restart' => 'running',
            'pause' => 'paused',
            'resume' => 'running',
            'shutdown' => 'stopped'
        ];
        
        $newStatus = $statusMap[$action];
        $this->pdo->prepare(
            "UPDATE vms SET status = ?, last_sync = NOW() WHERE id = ?"
        )->execute([$newStatus, $vmId]);
        
        return true;
    }

    // 配置端口转发
    public function configurePortForwarding($vmId, $rule, $userId) {
        $this->checkVmAccess($vmId, $userId);
        
        // 验证规则格式
        if (!isset($rule['action']) || !isset($rule['type']) || !isset($rule['dport']) || !isset($rule['dest'])) {
            throw new Exception("端口转发规则格式错误");
        }
        
        $result = $this->pveService->configurePortForwarding($vmId, $rule);
        
        if ($result === false) {
            throw new Exception("配置失败");
        }
        
        $this->auth->logAudit('vm_port_forward', [
            'vm_id' => $vmId,
            'rule' => $rule
        ]);
        
        return true;
    }

    // 获取虚拟机统计信息
    public function getVmStats($vmId, $userId) {
        $this->checkVmAccess($vmId, $userId);
        
        $vm = $this->getVmDetail($vmId, $userId);
        
        return [
            'id' => $vm['id'],
            'name' => $vm['name'],
            'status' => $vm['status'],
            'realtime_status' => $vm['realtime_status'],
            'cpu_usage' => $this->getCpuUsage($vmId),
            'memory_usage' => $this->getMemoryUsage($vmId),
            'disk_usage' => $this->getDiskUsage($vmId),
            'network_io' => $this->getNetworkIO($vmId)
        ];
    }

    // 重装系统
    public function reinstallVm($vmId, $template, $userId) {
        $this->checkVmAccess($vmId, $userId);
        
        $vm = $this->getVmDetail($vmId, $userId);
        
        // 停止虚拟机
        $this->controlVm($vmId, 'stop', $userId);
        
        // 调用PVE API重装（需要实现）
        // 这里简化处理，实际应该调用PVE模板重装API
        $auth = base64_encode("{$vm['api_user']}!{$vm['api_token']}");
        $url = "{$vm['api_url']}/api2/json/nodes/{$vm['node_name']}/lxc/{$vm['vmid']}/template";
        
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: PVEAPIToken=$auth\r\nContent-Type: application/json",
                'content' => json_encode(['ostemplate' => $template])
            ]
        ]);
        
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            throw new Exception("重装失败");
        }
        
        $this->auth->logAudit('vm_reinstall', [
            'vm_id' => $vmId,
            'template' => $template
        ]);
        
        return true;
    }

    // 重置密码
    public function resetVmPassword($vmId, $newPassword, $userId) {
        $this->checkVmAccess($vmId, $userId);
        
        $vm = $this->getVmDetail($vmId, $userId);
        
        // 调用PVE API重置密码
        $auth = base64_encode("{$vm['api_user']}!{$vm['api_token']}");
        $url = "{$vm['api_url']}/api2/json/nodes/{$vm['node_name']}/lxc/{$vm['vmid']}/config";
        
        // 获取当前配置
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: PVEAPIToken=$auth\r\n"
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if (!$response) {
            throw new Exception("获取配置失败");
        }
        
        $data = json_decode($response, true);
        $config = $data['data'];
        
        // 更新密码
        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => "Authorization: PVEAPIToken=$auth\r\nContent-Type: application/json",
                'content' => json_encode([
                    'password' => $newPassword
                ])
            ]
        ]);
        
        $result = @file_get_contents($url, false, $context);
        
        if ($result === false) {
            throw new Exception("重置密码失败");
        }
        
        $this->auth->logAudit('vm_reset_password', [
            'vm_id' => $vmId
        ]);
        
        return true;
    }

    // 获取VNC连接信息
    public function getVncInfo($vmId, $userId) {
        $this->checkVmAccess($vmId, $userId);
        
        $vm = $this->getVmDetail($vmId, $userId);
        
        // 调用PVE API获取VNC信息
        $auth = base64_encode("{$vm['api_user']}!{$vm['api_token']}");
        $url = "{$vm['api_url']}/api2/json/nodes/{$vm['node_name']}/lxc/{$vm['vmid']}/vncwebsocket";
        
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: PVEAPIToken=$auth\r\n"
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if (!$response) {
            throw new Exception("获取VNC信息失败");
        }
        
        $data = json_decode($response, true);
        
        return [
            'port' => $data['data']['port'] ?? null,
            'ticket' => $data['data']['ticket'] ?? null,
            'host' => parse_url($vm['api_url'], PHP_URL_HOST)
        ];
    }

    // 检查虚拟机访问权限
    private function checkVmAccess($vmId, $userId) {
        if (!isset($_SESSION['user'])) {
            throw new Exception("未登录");
        }
        
        // 管理员可以访问所有虚拟机
        if ($_SESSION['user']['role'] === 'admin') {
            return true;
        }
        
        // 普通用户只能访问自己的虚拟机
        $stmt = $this->pdo->prepare("SELECT user_id FROM vms WHERE id = ?");
        $stmt->execute([$vmId]);
        $vm = $stmt->fetch();
        
        if (!$vm || $vm['user_id'] != $userId) {
            throw new Exception("无权访问该虚拟机");
        }
        
        return true;
    }

    // 获取虚拟机实时状态
    private function getVmRealtimeStatus($vmId) {
        return $this->pveService->getVmStatus($vmId);
    }

    // 计算剩余天数
    private function calculateDaysRemaining($expiresAt) {
        $expires = new DateTime($expiresAt);
        $now = new DateTime();
        $interval = $now->diff($expires);
        
        return $interval->format('%r%a'); // 正数表示剩余天数，负数表示已过期
    }

    // 获取CPU使用率
    private function getCpuUsage($vmId) {
        $vm = $this->getVmDetail($vmId, $_SESSION['user']['id']);
        $status = $this->pveService->getVmStatus($vmId);
        
        if ($status && isset($status['cpu'])) {
            return round($status['cpu'] * 100, 2) . '%';
        }
        
        return '0%';
    }

    // 获取内存使用率
    private function getMemoryUsage($vmId) {
        $vm = $this->getVmDetail($vmId, $_SESSION['user']['id']);
        $status = $this->pveService->getVmStatus($vmId);
        
        if ($status && isset($status['mem']) && isset($status['maxmem'])) {
            $usage = ($status['mem'] / $status['maxmem']) * 100;
            return round($usage, 2) . '%';
        }
        
        return '0%';
    }

    // 获取磁盘使用率
    private function getDiskUsage($vmId) {
        $vm = $this->getVmDetail($vmId, $_SESSION['user']['id']);
        $status = $this->pveService->getVmStatus($vmId);
        
        if ($status && isset($status['disk']) && isset($status['maxdisk'])) {
            $usage = ($status['disk'] / $status['maxdisk']) * 100;
            return round($usage, 2) . '%';
        }
        
        return '0%';
    }

    // 获取网络IO
    private function getNetworkIO($vmId) {
        $vm = $this->getVmDetail($vmId, $_SESSION['user']['id']);
        $status = $this->pveService->getVmStatus($vmId);
        
        if ($status) {
            return [
                'in' => isset($status['netin']) ? $this->formatBytes($status['netin']) . '/s' : '0 B/s',
                'out' => isset($status['netout']) ? $this->formatBytes($status['netout']) . '/s' : '0 B/s'
            ];
        }
        
        return [
            'in' => '0 B/s',
            'out' => '0 B/s'
        ];
    }

    // 格式化字节数
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
