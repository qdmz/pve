<?php
return [
    'host' => 'db',
    'dbname' => 'pve_manager',
    'user' => 'root',
    'password' => 'rootpass123',
    'charset' => 'utf8mb4',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
];