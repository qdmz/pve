<?php
// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 自动加载
spl_autoload_register(function ($class) {
    $paths = [
        __DIR__ . '/../app/Controllers/',
        __DIR__ . '/../app/Models/',
        __DIR__ . '/../app/Services/',
        __DIR__ . '/../app/Utils/'
    ];
    
    foreach ($paths as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

// 启动会话
session_start();

// 加载配置
$dbConfig = require __DIR__ . '/../config/database.php';
$appConfig = require __DIR__ . '/../config/app.php';

// 数据库连接
try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], $dbConfig['options']);
} catch (PDOException $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

// 初始化错误处理
$errorHandler = new ErrorHandler($pdo, $appConfig['app']['debug']);
$errorHandler->register();

// 初始化日志
$logger = new Logger($pdo, Logger::INFO);

// 初始化中间件
$auth = new AuthMiddleware($pdo);
$csrf = new CsrfMiddleware();
$rateLimiter = new RateLimiter($pdo, 60, 60);

// 路由处理
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// 调试信息
if (strpos($uri, '/api') === false) {
    include __DIR__ . '/index.html';
    exit;
}

// JSON响应函数
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// 路由定义
$routes = [
    // 认证路由
    'POST /api/register' => function() use ($pdo, $rateLimiter) {
        $identifier = 'register:' . ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$rateLimiter->check($identifier, 5, 3600)) {
            jsonResponse(['success' => false, 'message' => '注册请求过于频繁，请1小时后再试'], 429);
        }
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new UserController($pdo);
            $controller->register($data['username'], $data['email'], $data['password']);
            jsonResponse(['success' => true, 'message' => '注册成功，请检查邮箱激活账户']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    },
    
    'POST /api/login' => function() use ($pdo, $rateLimiter) {
        $identifier = 'login:' . ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$rateLimiter->check($identifier, 10, 900)) {
            jsonResponse(['success' => false, 'message' => '登录请求过于频繁，请15分钟后再试'], 429);
        }
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new UserController($pdo);
            $user = $controller->login($data['email'], $data['password']);
            $_SESSION['user'] = $user;
            jsonResponse(['success' => true, 'user' => ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']]]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 401);
        }
    },
    
    'POST /api/logout' => function() {
        session_destroy();
        jsonResponse(['success' => true, 'message' => '退出成功']);
    },
    
    'GET /api/user/info' => function() use ($pdo) {
        if (!isset($_SESSION['user'])) {
            jsonResponse(['success' => false, 'message' => '未登录'], 401);
        }
        
        $stmt = $pdo->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user']['id']]);
        $user = $stmt->fetch();
        
        // 获取用户余额
        $paymentService = new PaymentService($pdo);
        $balance = $paymentService->getUserBalance($_SESSION['user']['id']);
        
        jsonResponse(['success' => true, 'user' => $user, 'balance' => $balance]);
    },
    
    // 虚拟机路由
    'GET /api/vms' => function() use ($pdo) {
        if (!isset($_SESSION['user'])) {
            jsonResponse(['success' => false, 'message' => '未登录'], 401);
        }
        
        try {
            $refresh = isset($_GET['refresh']) && $_GET['refresh'] == 1;
            $controller = new VmController($pdo, new AuthMiddleware($pdo));
            $vms = $controller->getUserVms($_SESSION['user']['id'], $refresh);
            jsonResponse(['success' => true, 'vms' => $vms]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/vms/:id' => function($id) use ($pdo) {
        if (!isset($_SESSION['user'])) {
            jsonResponse(['success' => false, 'message' => '未登录'], 401);
        }
        
        try {
            $refresh = isset($_GET['refresh']) && $_GET['refresh'] == 1;
            $controller = new VmController($pdo, new AuthMiddleware($pdo));
            $vm = $controller->getVmDetail($id, $_SESSION['user']['id'], $refresh);
            jsonResponse(['success' => true, 'vm' => $vm]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
        }
    },
    
    'POST /api/vms/:id/control' => function($id) use ($pdo) {
        if (!isset($_SESSION['user'])) {
            jsonResponse(['success' => false, 'message' => '未登录'], 401);
        }
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new VmController($pdo, new AuthMiddleware($pdo));
            $controller->controlVm($id, $data['action'], $_SESSION['user']['id']);
            jsonResponse(['success' => true, 'message' => '操作成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 产品路由
    'GET /api/products' => function() use ($pdo) {
        try {
            $service = new PaymentService($pdo);
            $products = $service->getAllProducts();
            jsonResponse(['success' => true, 'products' => $products]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/orders/create' => function() use ($pdo) {
        if (!isset($_SESSION['user'])) {
            jsonResponse(['success' => false, 'message' => '未登录'], 401);
        }
        
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $service = new PaymentService($pdo);
            $orderId = $service->createOrder($_SESSION['user']['id'], $data['product_id']);
            jsonResponse(['success' => true, 'order_id' => $orderId]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 管理员路由
    'GET /api/admin/users' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = $_GET['search'] ?? '';
            $users = $controller->getAllUsers($page, $limit, $search);
            jsonResponse(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/admin/stats' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $stats = $controller->getDashboardStats();
            jsonResponse(['success' => true, 'stats' => $stats]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/vms/transfer' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->transferVm($data['vm_id'], $data['new_user_id']);
            jsonResponse(['success' => true, 'message' => '转移成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 用户管理路由
    'POST /api/admin/users/status' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->updateUserStatus($data['user_id'], $data['status']);
            jsonResponse(['success' => true, 'message' => '状态更新成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/users/login-as' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->loginUserAs($data['user_id']);
            jsonResponse(['success' => true, 'message' => '登录成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/admin/users/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $user = $controller->getUserDetail($id);
            jsonResponse(['success' => true, 'user' => $user]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/users' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $userId = $controller->createUser($data);
            jsonResponse(['success' => true, 'user_id' => $userId, 'message' => '用户创建成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'PUT /api/admin/users/:id' => function($id) use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->updateUser($id, $data);
            jsonResponse(['success' => true, 'message' => '用户更新成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'DELETE /api/admin/users/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->deleteUser($id);
            jsonResponse(['success' => true, 'message' => '用户删除成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 产品管理路由
    'POST /api/admin/products' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $productId = $controller->createProduct($data);
            jsonResponse(['success' => true, 'product_id' => $productId, 'message' => '产品创建成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'PUT /api/admin/products/:id' => function($id) use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->updateProduct($id, $data);
            jsonResponse(['success' => true, 'message' => '产品更新成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'DELETE /api/admin/products/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->deleteProduct($id);
            jsonResponse(['success' => true, 'message' => '产品删除成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 订单管理路由
    'GET /api/admin/orders' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? null;
            $orders = $controller->getAllOrders($page, $limit, $status);
            jsonResponse(['success' => true, 'orders' => $orders]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'DELETE /api/admin/orders/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->deleteOrder($id);
            jsonResponse(['success' => true, 'message' => '订单删除成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/admin/orders/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $order = $controller->getOrderDetail($id);
            jsonResponse(['success' => true, 'order' => $order]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'PUT /api/admin/orders/:id/status' => function($id) use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->updateOrderStatus($id, $data['status']);
            jsonResponse(['success' => true, 'message' => '订单状态更新成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 虚拟机管理路由
    'GET /api/admin/vms' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = $_GET['search'] ?? '';
            $vms = $controller->getAllVms($page, $limit, $search);
            jsonResponse(['success' => true, 'vms' => $vms]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'DELETE /api/admin/vms/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->deleteVmRecord($id);
            jsonResponse(['success' => true, 'message' => '虚拟机记录删除成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 节点管理路由
    'GET /api/admin/nodes' => function() use ($pdo) {
        try {
            $service = new PveApiService($pdo);
            $nodes = $service->getAllNodes();
            jsonResponse(['success' => true, 'nodes' => $nodes]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/nodes' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $nodeId = $controller->addNode($data);
            jsonResponse(['success' => true, 'node_id' => $nodeId, 'message' => '节点添加成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'PUT /api/admin/nodes/:id' => function($id) use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->updateNode($id, $data);
            jsonResponse(['success' => true, 'message' => '节点更新成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'DELETE /api/admin/nodes/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->deleteNode($id);
            jsonResponse(['success' => true, 'message' => '节点删除成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/admin/nodes/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $node = $controller->getNodeDetail($id);
            jsonResponse(['success' => true, 'node' => $node]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/nodes/:id/sync' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $count = $controller->syncNodeVms($id);
            jsonResponse(['success' => true, 'synced_count' => $count, 'message' => '节点同步成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 配置管理路由
    'GET /api/admin/configs' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $configs = $controller->getAllConfigs();
            jsonResponse(['success' => true, 'configs' => $configs]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/configs' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->updateSiteConfig($data['key'], $data['value'], $data['description'] ?? '');
            jsonResponse(['success' => true, 'message' => '配置更新成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/configs/basic' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->saveBasicConfig($data);
            jsonResponse(['success' => true, 'message' => '基本设置保存成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/configs/payment' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->savePaymentConfig($data);
            jsonResponse(['success' => true, 'message' => '支付设置保存成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/configs/email' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->saveEmailConfig($data);
            jsonResponse(['success' => true, 'message' => '邮件设置保存成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/configs/advanced' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->saveAdvancedConfig($data);
            jsonResponse(['success' => true, 'message' => '高级设置保存成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 兑换码管理路由
    'POST /api/admin/redeem-codes' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $codes = $controller->generateRedeemCodes(
                $data['count'],
                $data['type'],
                $data['product_id'] ?? null,
                $data['amount'] ?? null,
                $data['expires_days'] ?? 30
            );
            jsonResponse(['success' => true, 'codes' => $codes, 'message' => '兑换码生成成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/admin/logs' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = $_GET['search'] ?? '';
            $userId = $_GET['user_id'] ?? '';
            $date = $_GET['date'] ?? '';
            
            $result = $controller->getAuditLogs($page, $limit, $search, $userId, $date);
            jsonResponse(['success' => true, 'logs' => $result['logs'], 'total' => $result['total']]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 备份管理路由
    'GET /api/admin/backups' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $nodeId = $_GET['node_id'] ?? '';
            $vmId = $_GET['vm_id'] ?? '';
            $status = $_GET['status'] ?? '';
            
            $result = $controller->getBackups($page, $limit, $nodeId, $vmId, $status);
            jsonResponse(['success' => true, 'backups' => $result['backups'], 'total' => $result['total']]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/backups' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $backupId = $controller->createBackup($data['vm_id'], $data['name']);
            jsonResponse(['success' => true, 'backup_id' => $backupId, 'message' => '备份创建成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/admin/backups/:id/download' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->downloadBackup($id);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/backups/:id/restore' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->restoreBackup($id);
            jsonResponse(['success' => true, 'message' => '备份恢复成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'DELETE /api/admin/backups/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->deleteBackup($id);
            jsonResponse(['success' => true, 'message' => '备份删除成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 网络配置管理路由
    'GET /api/admin/networks' => function() use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $networks = $controller->getNetworks();
            jsonResponse(['success' => true, 'networks' => $networks]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/admin/networks' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $networkId = $controller->createNetwork($data);
            jsonResponse(['success' => true, 'network_id' => $networkId, 'message' => '网络配置创建成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'PUT /api/admin/networks/:id' => function($id) use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->updateNetwork($id, $data);
            jsonResponse(['success' => true, 'message' => '网络配置更新成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'DELETE /api/admin/networks/:id' => function($id) use ($pdo) {
        try {
            $controller = new AdminController($pdo, new AuthMiddleware($pdo));
            $controller->deleteNetwork($id);
            jsonResponse(['success' => true, 'message' => '网络配置删除成功']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 支付回调路由
    'GET /api/payment/notify' => function() use ($pdo) {
        try {
            $gateway = $_GET['gateway'] ?? 'epay';
            $paymentService = new PaymentService($pdo);
            $result = $paymentService->handleCallback($gateway, $_GET);
            
            if ($result) {
                echo 'success';
            } else {
                echo 'fail';
            }
        } catch (Exception $e) {
            echo 'fail';
        }
    },
    
    'POST /api/payment/notify' => function() use ($pdo) {
        try {
            $gateway = $_POST['gateway'] ?? 'epay';
            $paymentService = new PaymentService($pdo);
            $result = $paymentService->handleCallback($gateway, $_POST);
            
            if ($result) {
                echo 'success';
            } else {
                echo 'fail';
            }
        } catch (Exception $e) {
            echo 'fail';
        }
    },
    
    'GET /api/payment/return' => function() use ($pdo) {
        try {
            $orderId = $_GET['order_id'] ?? $_GET['out_trade_no'];
            $paymentService = new PaymentService($pdo);
            
            // 检查订单状态
            $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if ($order && $order['status'] === 'paid') {
                jsonResponse(['success' => true, 'message' => '支付成功']);
            } else {
                jsonResponse(['success' => false, 'message' => '支付失败或订单不存在'], 400);
            }
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'GET /api/payment/gateways' => function() use ($pdo) {
        try {
            $paymentService = new PaymentService($pdo);
            $gateways = $paymentService->getAvailableGateways();
            jsonResponse(['success' => true, 'gateways' => $gateways]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    'POST /api/payment/process' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $paymentService = new PaymentService($pdo);
            
            // 验证订单存在
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$data['order_id']]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception("订单不存在");
            }
            
            if ($order['status'] !== 'pending') {
                throw new Exception("订单状态错误");
            }
            
            // 验证网关存在
            $stmt = $pdo->prepare("SELECT * FROM payment_gateways WHERE id = ? AND enabled = TRUE");
            $stmt->execute([$data['gateway_id']]);
            $gateway = $stmt->fetch();
            
            if (!$gateway) {
                throw new Exception("支付方式不存在或已禁用");
            }
            
            // 生成支付链接
            $gatewayConfig = json_decode($gateway['config'], true);
            $paymentUrl = $paymentService->generatePaymentUrl($order, $gateway, $gatewayConfig);
            
            jsonResponse(['success' => true, 'payment_url' => $paymentUrl]);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    },
    
    // 根路径路由
    'GET /' => function() {
        include __DIR__ . '/index.html';
        exit;
    }
];

// 路由匹配
foreach ($routes as $route => $handler) {
    [$routeMethod, $routePath] = explode(' ', $route, 2);
    
    // 替换路径参数
    $pattern = preg_replace('/\:([^\/]+)/', '([^/]+)', $routePath);
    $pattern = '#^' . $pattern . '$#';
    
    if ($method === $routeMethod && preg_match($pattern, $uri, $matches)) {
        array_shift($matches); // 移除完整匹配
        call_user_func_array($handler, $matches);
        exit;
    }
}

// 404处理
http_response_code(404);
echo json_encode(['success' => false, 'message' => '未找到请求的路由'], JSON_UNESCAPED_UNICODE);
