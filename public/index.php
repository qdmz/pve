<?php
// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
require_once __DIR__ . '/../config/database.php';
$dbConfig = require __DIR__ . '/../config/database.php';

// 数据库连接
try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset={$dbConfig['charset']}";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['password'], $dbConfig['options']);
} catch (PDOException $e) {
    die('数据库连接失败: ' . $e->getMessage());
}

// 初始化中间件
$auth = new AuthMiddleware($pdo);

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
    'POST /api/register' => function() use ($pdo) {
        try {
            $data = json_decode(file_get_contents('php://input'), true);
            $controller = new UserController($pdo);
            $controller->register($data['username'], $data['email'], $data['password']);
            jsonResponse(['success' => true, 'message' => '注册成功，请检查邮箱激活账户']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
    },
    
    'POST /api/login' => function() use ($pdo) {
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
            $controller = new VmController($pdo, new AuthMiddleware($pdo));
            $vms = $controller->getUserVms($_SESSION['user']['id']);
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
            $controller = new VmController($pdo, new AuthMiddleware($pdo));
            $vm = $controller->getVmDetail($id, $_SESSION['user']['id']);
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
            $page = $_GET['page'] ?? 1;
            $search = $_GET['search'] ?? '';
            $users = $controller->getAllUsers($page, 20, $search);
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
