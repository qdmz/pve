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

// 获取所有节点
$nodes = $pveService->getAllNodes();
echo "Found " . count($nodes) . " nodes:\n";

foreach ($nodes as $node) {
    echo "\nNode: " . $node['name'] . " (ID: " . $node['id'] . ")\n";
    echo "API URL: " . $node['api_url'] . "\n";
    
    // 获取节点模板
    $templates = $pveService->getNodeTemplates($node['id']);
    echo "Found " . count($templates) . " templates:\n";
    
    foreach ($templates as $template) {
        echo "  - " . $template['name'] . " (" . $template['size'] . " bytes)\n";
        echo "    Full path: " . $template['full_name'] . "\n";
    }
    
    if (empty($templates)) {
        echo "  No templates found!\n";
    }
}

echo "\nTest completed.\n";
?>