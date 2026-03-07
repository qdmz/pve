<?php
require_once 'app/Services/PveApiService.php';

// 直接硬编码数据库连接信息
$pdo = new PDO(
    'mysql:host=db;dbname=pve_manager;charset=utf8',
    'root',
    'rootpass123'
);

// 创建PVE API服务实例
$pveService = new PveApiService($pdo);

// 获取所有节点
$nodes = $pveService->getAllNodes();
echo "Found " . count($nodes) . " nodes:\n";

foreach ($nodes as $node) {
    echo "\nNode: " . $node['name'] . " (ID: " . $node['id'] . ")\n";
    echo "API URL: " . $node['api_url'] . "\n";
    
    // 测试连接到PVE API
    echo "Testing connection to PVE API...\n";
    
    $auth = "{$node['api_user']}={$node['api_token']}";
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
    if ($response) {
        echo "  Connection successful!\n";
        $data = json_decode($response, true);
        if (isset($data['data'])) {
            echo "  Found " . count($data['data']) . " nodes in PVE cluster:\n";
            foreach ($data['data'] as $n) {
                echo "    - " . $n['node'] . " (status: " . $n['status'] . ")\n";
            }
        }
    } else {
        $error = error_get_last();
        echo "  Connection failed: " . $error['message'] . "\n";
    }
    
    // 测试获取存储信息
    echo "Testing storage access...\n";
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['data']) && !empty($data['data'])) {
            $nodeName = $data['data'][0]['node'];
            $storageUrl = rtrim($node['api_url'], '/') . "/api2/json/nodes/{$nodeName}/storage";
            $response = @file_get_contents($storageUrl, false, $context);
            if ($response) {
                echo "  Storage access successful!\n";
                $storageData = json_decode($response, true);
                if (isset($storageData['data'])) {
                    echo "  Found " . count($storageData['data']) . " storage items:\n";
                    foreach ($storageData['data'] as $storage) {
                        echo "    - " . $storage['storage'] . " (type: " . $storage['type'] . ")\n";
                    }
                }
            } else {
                $error = error_get_last();
                echo "  Storage access failed: " . $error['message'] . "\n";
            }
        }
    }
}

echo "\nTest completed.\n";
?>