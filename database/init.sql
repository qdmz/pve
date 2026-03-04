-- 数据库初始化脚本
-- 自动导入 schema.sql

USE pve_manager;

-- 插入默认管理员账户（密码: admin123）
INSERT INTO users (username, email, password_hash, role, status) 
VALUES ('admin', 'admin@example.com', '$2y$10$tnNZInwXzzJqud0bvybxyONZgeA3jpU6ySqWvQC7YC4006pnsG5W2', 'admin', 'active');

-- 插入示例站点配置
INSERT INTO site_config (`key`, `value`, description) VALUES 
('site_name', 'PVE 虚拟机管理系统', '站点名称'),
('site_url', 'http://localhost', '站点URL'),
('currency', 'CNY', '货币单位'),
('smtp_host', 'smtp.example.com', 'SMTP服务器'),
('smtp_port', '587', 'SMTP端口'),
('smtp_user', '', 'SMTP用户'),
('smtp_pass', '', 'SMTP密码'),
('smtp_from', '', '发件人邮箱');

-- 插入示例产品
INSERT INTO products (name, description, price, vm_config, duration_days, status) VALUES 
('入门套餐', '1核2G内存', 10.00, '{"cores":1,"memory":2048,"disk":20}', 30, 'active'),
('标准套餐', '2核4G内存', 20.00, '{"cores":2,"memory":4096,"disk":40}', 30, 'active'),
('高级套餐', '4核8G内存', 40.00, '{"cores":4,"memory":8192,"disk":80}', 30, 'active');

-- 插入示例兑换码
INSERT INTO redeem_codes (code, product_id, amount, expires_at) VALUES 
('WELCOME2024', NULL, 50.00, DATE_ADD(NOW(), INTERVAL 365 DAY)),
('NEWUSER2024', 1, NULL, DATE_ADD(NOW(), INTERVAL 365 DAY));
