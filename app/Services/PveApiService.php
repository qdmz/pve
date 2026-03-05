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

        // 修复PVE API认证格式
        $apiUser = $node['api_user'];
        $apiToken = $node['api_token'];
        $auth = "{$apiUser}={$apiToken}";
        // 首先获取PVE集群中的实际节点名称
        $nodesUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes";
        
        error_log("API URL: " . $node['api_url']);
        error_log("API User: " . $apiUser);
        error_log("Nodes URL: " . $nodesUrl);
        
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
        if (!$response) {
            // 记录错误信息
            error_log("Failed to get nodes: " . print_r(error_get_last(), true));
            return [];
        }
        
        error_log("Nodes response: " . $response);
        
        $data = json_decode($response, true);
        if (!isset($data['data']) || empty($data['data'])) {
            error_log("No nodes found: " . print_r($data, true));
            return [];
        }
        
        // 打印所有找到的节点
        error_log("Found " . count($data['data']) . " nodes:");
        foreach ($data['data'] as $n) {
            error_log("  - " . $n['node'] . " (status: " . $n['status'] . ")");
        }
        
        // 使用第一个节点的名称
        $nodeName = $data['data'][0]['node'];
        error_log("Using node: " . $nodeName);
        
        // 获取KVM虚拟机
        $kvmUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/qemu";
        $lxcUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/lxc";
        $allUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/resources";

        error_log("KVM URL: " . $kvmUrl);
        error_log("LXC URL: " . $lxcUrl);
        error_log("All resources URL: " . $allUrl);

        $allVms = [];
        
        // 尝试获取所有资源（使用正确的API端点）
        $allUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}";
        error_log("Node info URL: " . $allUrl);
        $response = @file_get_contents($allUrl, false, $context);
        if ($response) {
            error_log("Node info response: " . $response);
            $data = json_decode($response, true);
            if (isset($data['data'])) {
                error_log("Node info: " . print_r($data['data'], true));
            }
        } else {
            error_log("Failed to get node info: " . print_r(error_get_last(), true));
        }
        
        // 尝试获取存储信息
        $storageUrl = rtrim($node['api_url'], '/') . "/api2/json/storage";
        error_log("Storage URL: " . $storageUrl);
        $response = @file_get_contents($storageUrl, false, $context);
        if ($response) {
            error_log("Storage response: " . $response);
            $data = json_decode($response, true);
            if (isset($data['data'])) {
                error_log("Found " . count($data['data']) . " storage items");
            }
        } else {
            error_log("Failed to get storage: " . print_r(error_get_last(), true));
        }
        
        // 尝试使用不同的API端点格式
        $kvmUrl3 = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/qemu/status";
        error_log("KVM status URL: " . $kvmUrl3);
        $response = @file_get_contents($kvmUrl3, false, $context);
        if ($response) {
            error_log("KVM status response: " . $response);
        } else {
            error_log("Failed to get KVM status: " . print_r(error_get_last(), true));
        }
        
        // 尝试使用更基本的API端点
        $kvmUrl4 = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}";
        error_log("Node base URL: " . $kvmUrl4);
        $response = @file_get_contents($kvmUrl4, false, $context);
        if ($response) {
            error_log("Node base response: " . $response);
        } else {
            error_log("Failed to get node base info: " . print_r(error_get_last(), true));
        }
        
        // 尝试获取节点状态
        $statusUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/status";
        error_log("Node status URL: " . $statusUrl);
        $response = @file_get_contents($statusUrl, false, $context);
        if ($response) {
            error_log("Node status response: " . $response);
        } else {
            error_log("Failed to get node status: " . print_r(error_get_last(), true));
        }
        
        // 获取KVM虚拟机
        $response = @file_get_contents($kvmUrl, false, $context);
        if ($response) {
            error_log("KVM response: " . $response);
            $data = json_decode($response, true);
            if (isset($data['data'])) {
                error_log("Found " . count($data['data']) . " KVM VMs");
                foreach ($data['data'] as $vm) {
                    error_log("  - KVM: " . $vm['vmid'] . " " . $vm['name'] . " (" . $vm['status'] . ")");
                    $allVms[] = [
                        'vmid' => $vm['vmid'],
                        'name' => $vm['name'],
                        'status' => $vm['status'],
                        'type' => 'kvm'
                    ];
                }
            } else {
                error_log("No KVM VMs found: " . print_r($data, true));
            }
        } else {
            error_log("Failed to get KVM VMs: " . print_r(error_get_last(), true));
        }
        
        // 获取LXC容器
        $response = @file_get_contents($lxcUrl, false, $context);
        if ($response) {
            error_log("LXC response: " . $response);
            $data = json_decode($response, true);
            if (isset($data['data'])) {
                error_log("Found " . count($data['data']) . " LXC containers");
                foreach ($data['data'] as $vm) {
                    error_log("  - LXC: " . $vm['vmid'] . " " . $vm['name'] . " (" . $vm['status'] . ")");
                    $allVms[] = [
                        'vmid' => $vm['vmid'],
                        'name' => $vm['name'],
                        'status' => $vm['status'],
                        'type' => 'lxc'
                    ];
                }
            } else {
                error_log("No LXC containers found: " . print_r($data, true));
            }
        } else {
            error_log("Failed to get LXC containers: " . print_r(error_get_last(), true));
        }
        
        // 尝试使用不同的API端点格式
        $kvmUrl2 = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/qemu?full=1";
        error_log("KVM URL with full=1: " . $kvmUrl2);
        $response = @file_get_contents($kvmUrl2, false, $context);
        if ($response) {
            error_log("KVM response (full=1): " . $response);
        } else {
            error_log("Failed to get KVM VMs (full=1): " . print_r(error_get_last(), true));
        }
        
        // 尝试使用XML格式
        $kvmUrlXml = rtrim($node['api_url'], '/') . "/api2/xml/nodes/{$nodeName}/qemu";
        error_log("KVM XML URL: " . $kvmUrlXml);
        $response = @file_get_contents($kvmUrlXml, false, $context);
        if ($response) {
            error_log("KVM XML response: " . $response);
        } else {
            error_log("Failed to get KVM VMs (XML): " . print_r(error_get_last(), true));
        }

        error_log("Total VMs found: " . count($allVms));
        return $allVms;
    }

    // 控制虚拟机
    public function controlVm($vmId, $action) {
        $stmt = $this->pdo->prepare("SELECT v.vmid, n.* FROM vms v 
                                   JOIN pve_nodes n ON v.node_id = n.id 
                                   WHERE v.id = ?");
        $stmt->execute([$vmId]);
        $vm = $stmt->fetch();

        if (!$vm) return false;

        $auth = "{$vm['api_user']}={$vm['api_token']}";
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

        $auth = "{$vm['api_user']}={$vm['api_token']}";
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

        // 修复PVE API认证格式
        $apiUser = $node['api_user'];
        $apiToken = $node['api_token'];
        $auth = "{$apiUser}={$apiToken}";
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

        $auth = "{$vm['api_user']}={$vm['api_token']}";
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
                $existingVm = $stmt->fetch();
                
                if (!$existingVm) {
                    // 插入新虚拟机记录
                    $stmt = $this->pdo->prepare(
                        "INSERT INTO vms (vmid, node_id, name, type, status, config, last_sync) 
                         VALUES (?, ?, ?, ?, ?, ?, NOW())"
                    );
                    $stmt->execute([
                        $vmItem['vmid'],
                        $nodeId,
                        $vmItem['name'],
                        $vmItem['type'] ?? 'lxc',
                        $vmItem['status'] ?? 'stopped',
                        json_encode($vmItem)
                    ]);
                    $syncedCount++;
                } else {
                    // 更新现有虚拟机记录
                    $stmt = $this->pdo->prepare(
                        "UPDATE vms SET name = ?, type = ?, status = ?, config = ?, last_sync = NOW() 
                         WHERE vmid = ? AND node_id = ?"
                    );
                    $stmt->execute([
                        $vmItem['name'],
                        $vmItem['type'] ?? 'lxc',
                        $vmItem['status'] ?? 'stopped',
                        json_encode($vmItem),
                        $vmItem['vmid'],
                        $nodeId
                    ]);
                }
            }
        }
        
        // 清理不存在的虚拟机记录
        $vmIds = array_column($vmList, 'vmid');
        if (!empty($vmIds)) {
            $placeholders = str_repeat('?,', count($vmIds) - 1) . '?';
            $stmt = $this->pdo->prepare(
                "DELETE FROM vms WHERE node_id = ? AND vmid NOT IN ($placeholders)"
            );
            $params = array_merge([$nodeId], $vmIds);
            $stmt->execute($params);
        }
        
        $this->updateNodeStatus($nodeId, 'online');
        return $syncedCount;
    }

    // 创建备份
    public function createBackup($nodeId, $vmid, $backupName) {
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
        $url = "{$node['api_url']}/api2/json/nodes/{$nodeName}/qemu/{$vmid}/backup";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: PVEAPIToken=$auth\r\nContent-Type: application/json",
                'content' => json_encode(['mode' => 'snapshot', 'compress' => 1, 'notes' => $backupName])
            ]
        ]);

        $response = @file_get_contents($url, false, $context);
        return $response ? true : false;
    }

    // 获取备份文件
    public function getBackupFile($nodeId, $backupName) {
        $node = $this->getNodeDetails($nodeId);
        if (!$node) return false;

        $auth = "{$node['api_user']}={$node['api_token']}";
        // 获取PVE集群中的实际节点名称
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
        
        // 获取存储列表
        $storageUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/storage";
        $response = @file_get_contents($storageUrl, false, $context);
        if (!$response) return false;

        $storageData = json_decode($response, true);
        if (!isset($storageData['data'])) return false;

        // 查找包含备份的存储
        foreach ($storageData['data'] as $storage) {
            if ($storage['content'] && strpos($storage['content'], 'vztmpl') !== false || strpos($storage['content'], 'backup') !== false) {
                $storageName = $storage['storage'];
                
                // 获取备份列表
                $backupListUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/storage/{$storageName}/content";
                $response = @file_get_contents($backupListUrl, false, $context);
                if ($response) {
                    $backupList = json_decode($response, true);
                    if (isset($backupList['data'])) {
                        foreach ($backupList['data'] as $backup) {
                            if (isset($backup['volid']) && strpos($backup['volid'], $backupName) !== false) {
                                // 返回备份文件的下载URL
                                return [
                                    'storage' => $storageName,
                                    'volid' => $backup['volid'],
                                    'size' => $backup['size'] ?? 0,
                                    'download_url' => rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/storage/{$storageName}/content/" . urlencode($backup['volid'])
                                ];
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    // 恢复备份
    public function restoreBackup($nodeId, $backupName) {
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
        
        // 获取备份文件信息
        $backupInfo = $this->getBackupFile($nodeId, $backupName);
        if (!$backupInfo) return false;

        // 解析备份文件名获取VMID
        if (preg_match('/(\d+)/', $backupName, $matches)) {
            $vmid = $matches[1];
        } else {
            return false;
        }

        // 恢复备份
        $restoreUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/lxc/{$vmid}/restore";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: PVEAPIToken=$auth\r\nContent-Type: application/json",
                'content' => json_encode([
                    'storage' => $backupInfo['storage'],
                    'volid' => $backupInfo['volid'],
                    'force' => 1
                ])
            ]
        ]);

        $response = @file_get_contents($restoreUrl, false, $context);
        return $response ? true : false;
    }

    // 删除备份
    public function deleteBackup($nodeId, $backupName) {
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
        
        // 获取备份文件信息
        $backupInfo = $this->getBackupFile($nodeId, $backupName);
        if (!$backupInfo) return false;

        // 删除备份文件
        $deleteUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/storage/{$backupInfo['storage']}/content/" . urlencode($backupInfo['volid']);
        $context = stream_context_create([
            'http' => [
                'method' => 'DELETE',
                'header' => "Authorization: PVEAPIToken=$auth\r\n"
            ]
        ]);

        $response = @file_get_contents($deleteUrl, false, $context);
        return $response ? true : false;
    }
} 