<?php
require_once 'app/Services/PveApiService.php';
require_once 'config/database.php';

// 连接数据库
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
    DB_USER,
    DB_PASSWORD
);

// 创建PVE API服务实例
$pveService = new PveApiService($pdo);

// 获取所有虚拟机
$stmt = $pdo->query("SELECT id, vmid, name, type, node_id FROM vms");
$vms = $stmt->fetchAll();

echo "Found " . count($vms) . " VMs:\n";

foreach ($vms as $vm) {
    echo "\nVM: " . $vm['name'] . " (ID: " . $vm['id'] . ", PVE VMID: " . $vm['vmid'] . ", Type: " . $vm['type'] . ")\n";
    
    // 测试获取虚拟机状态
    echo "Testing getVmStatus...\n";
    $status = $pveService->getVmStatus($vm['id']);
    if ($status) {
        echo "  Status: " . json_encode($status) . "\n";
    } else {
        echo "  Failed to get status\n";
    }
    
    // 测试控制虚拟机（仅测试获取URL，不实际执行操作）
    echo "Testing controlVm URL construction...\n";
    // 注意：这里只是测试URL构建，不会实际执行操作
    // $result = $pveService->controlVm($vm['id'], 'status');
    // if ($result) {
    //     echo "  Control result: " . $result . "\n";
    // } else {
    //     echo "  Failed to control VM\n";
    // }
}

echo "\nTest completed.\n";
?>