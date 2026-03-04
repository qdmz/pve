<?php
return [
    // 应用配置
    'app' => [
        'name' => env('APP_NAME', 'PVE 虚拟机管理系统'),
        'url' => env('APP_URL', 'http://localhost'),
        'timezone' => 'Asia/Shanghai',
        'debug' => env('APP_DEBUG', true),
    ],
    
    // 数据库配置
    'database' => [
        'host' => env('DB_HOST', 'localhost'),
        'name' => env('DB_NAME', 'pve_manager'),
        'user' => env('DB_USER', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    ],
    
    // 安全配置
    'security' => [
        'session_lifetime' => 86400, // 24小时
        'csrf_token_name' => 'csrf_token',
        'password_min_length' => 8,
    ],
    
    // 支付配置
    'payment' => [
        'alipay' => [
            'app_id' => env('ALIPAY_APP_ID', ''),
            'private_key' => env('ALIPAY_PRIVATE_KEY', ''),
            'public_key' => env('ALIPAY_PUBLIC_KEY', ''),
        ],
        'wechat' => [
            'appid' => env('WECHAT_APPID', ''),
            'mch_id' => env('WECHAT_MCH_ID', ''),
            'api_key' => env('WECHAT_API_KEY', ''),
        ],
    ],
    
    // PVE 配置
    'pve' => [
        'default_timeout' => 30,
        'sync_interval' => 300, // 5分钟
    ],
];
