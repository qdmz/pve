<?php
class PveApiService {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    // 获取所有节点
    public function getAllNodes() {
        $stmt = $this->pdo->query("SELECT * FROM pve_nodes");
        return $stmt->fetchAll();
    }

    // 获取节点详情
    public function getNodeDetails($nodeId) {
        $stmt = $this->pdo->prepare("SELECT * FROM pve_nodes WHERE id = ?");
        $stmt->execute([$nodeId]);
        return $stmt->fetch();
    }

    // 添加新节点
    public function addNode($name, $apiUrl, $apiUser, $apiToken) {
        $stmt = $this->pdo->prepare(
            "INSERT INTO pve_nodes (name, api_url, api_user, api_token) 
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$name, $apiUrl, $apiUser, $apiToken]);
    }

    // 更新节点状态
    public function updateNodeStatus($nodeId, $status) {
        $this->pdo->prepare(
            "UPDATE pve_nodes 
             SET status = ?, last_sync = NOW() 
             WHERE id = ?"
        )->execute([$status, $nodeId]);
    }

    // 获取节点虚拟机列表
    public function getVmList($nodeId) {
        $node = $this->getNodeDetails($nodeId);
        if (!$node) return [];

        $auth = base64_encode("{$node['api_user']}!{$node['api_token']}");
        $url = "{$node['api_url']}/api2/json/nodes";

        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: PVEAPIToken=$auth\r\n"
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!$response) return [];

        $data = json_decode($response, true);
        return $data['data'] ?? [];
    }

    // 控制虚拟机
    public function controlVm($vmId, $action) {
        $stmt = $this->pdo->prepare("SELECT v.vmid, n.* FROM vms v 
                                   JOIN pve_nodes n ON v.node_id = n.id 
                                   WHERE v.id = ?");
        $stmt->execute([$vmId]);
        $vm = $stmt->fetch();

        if (!$vm) return false;

        $auth = base64_encode("{$vm['api_user']}!{$vm['api_token']}");
        $url = "{$vm['api_url']}/api2/json/nodes/{$vm['name']}/lxc/{$vm['vmid']}/$action";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: PVEAPIToken=$auth\r\n"
            ]
        ]);

        return @file_get_contents($url, false, $context);
    }

    // 虚拟机状态查询
    public function getVmStatus($vmId) {
        $stmt = $this->pdo->prepare("SELECT v.vmid, n.* FROM vms v 
                                   JOIN pve_nodes n ON v.node_id = n.id 
                                   WHERE v.id = ?");
        $stmt->execute([$vmId]);
        $vm = $stmt->fetch();

        if (!$vm) return false;

        $auth = base64_encode("{$vm['api_user']}!{$vm['api_token']}");
        $url = "{$vm['api_url']}/api2/json/nodes/{$vm['name']}/lxc/{$vm['vmid']}/status/current";

        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: PVEAPIToken=$auth\r\n"
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!$response) return false;

        $data = json_decode($response, true);
        return $data['data'] ?? false;
    }

    // 创建虚拟机
    public function createVm($nodeId, $vmConfig) {
        $node = $this->getNodeDetails($nodeId);
        if (!$node) return false;

        $auth = base64_encode("{$node['api_user']}!{$node['api_token']}");
        $url = "{$node['api_url']}/api2/json/nodes/{$node['name']}/lxc";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: PVEAPIToken=$auth\r\nContent-Type: application/json",
                'content' => json_encode($vmConfig)
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        if (!$response) return false;

        $data = json_decode($response, true);
        return $data['data'] ?? false;
    }

    // 配置端口转发
    public function configurePortForwarding($vmId, $rule) {
        $stmt = $this->pdo->prepare("SELECT v.vmid, n.* FROM vms v 
                                   JOIN pve_nodes n ON v.node_id = n.id 
                                   WHERE v.id = ?");
        $stmt->execute([$vmId]);
        $vm = $stmt->fetch();

        if (!$vm) return false;

        $auth = base64_encode("{$vm['api_user']}!{$vm['api_token']}");
        $url = "{$vm['api_url']}/api2/json/nodes/{$vm['name']}/lxc/{$vm['vmid']}/firewall/rules";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: PVEAPIToken=$auth\r\nContent-Type: application/json",
                'content' => json_encode($rule)
            ]
        ]);

        return @file_get_contents($url, false, $context);
    }

    // 同步节点虚拟机到数据库
    public function syncVmsFromNode($nodeId) {
        $vmList = $this->getVmList($nodeId);
        $node = $this->getNodeDetails($nodeId);
        
        $syncedCount = 0;
        foreach ($vmList as $vmItem) {
            if (isset($vmItem['vmid']) && isset($vmItem['name'])) {
                // 检查是否已存在
                $stmt = $this->pdo->prepare("SELECT id FROM vms WHERE vmid = ? AND node_id = ?");
                $stmt->execute([$vmItem['vmid'], $nodeId]);
                
                if (!$stmt->fetch()) {
                    // 插入新虚拟机记录
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO vms (vmid, node_id, name, status, config) 
                         VALUES (?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $vmItem['vmid'],
                        $nodeId,
                        $vmItem['name'],
                        $vmItem['status'] ?? 'stopped',
                        json_encode($vmItem)
                    ]);
                    $syncedCount++;
                }
            }
        }
        
        $this->updateNodeStatus($nodeId, 'online');
        return $syncedCount;