# PVE 虚拟机管理系统 API 文档

## 基本信息

- **基础URL**: `http://your-domain.com/api`
- **认证方式**: Session
- **数据格式**: JSON
- **字符编码**: UTF-8

## 通用响应格式

### 成功响应
```json
{
  "success": true,
  "data": {},
  "message": "操作成功"
}
```

### 错误响应
```json
{
  "success": false,
  "message": "错误信息"
}
```

### HTTP状态码
- `200` - 成功
- `400` - 请求参数错误
- `401` - 未授权
- `403` - 禁止访问（CSRF错误、频率限制）
- `404` - 资源不存在
- `429` - 请求过于频繁
- `500` - 服务器内部错误

## 认证相关

### 用户注册
- **接口**: `POST /api/register`
- **频率限制**: 5次/小时
- **请求参数**:
  ```json
  {
    "username": "string (3-50字符)",
    "email": "email",
    "password": "string (最少8位，包含大小写字母和数字)"
  }
  ```
- **响应示例**:
  ```json
  {
    "success": true,
    "message": "注册成功，请检查邮箱激活账户"
  }
  ```

### 用户登录
- **接口**: `POST /api/login`
- **频率限制**: 10次/15分钟
- **请求参数**:
  ```json
  {
    "email": "email",
    "password": "string"
  }
  ```
- **响应示例**:
  ```json
  {
    "success": true,
    "user": {
      "id": 1,
      "username": "admin",
      "role": "admin"
    }
  }
  ```

### 用户登出
- **接口**: `POST /api/logout`
- **响应示例**:
  ```json
  {
    "success": true,
    "message": "退出成功"
  }
  ```

### 获取用户信息
- **接口**: `GET /api/user/info`
- **需要登录**: 是
- **响应示例**:
  ```json
  {
    "success": true,
    "user": {
      "id": 1,
      "username": "admin",
      "email": "admin@example.com",
      "role": "admin"
    },
    "balance": 100.00
  }
  ```

## 虚拟机管理

### 获取虚拟机列表
- **接口**: `GET /api/vms`
- **需要登录**: 是
- **查询参数**:
  - `refresh` (可选): `1` - 刷新实时状态
- **响应示例**:
  ```json
  {
    "success": true,
    "vms": [
      {
        "id": 1,
        "vmid": 100,
        "name": "VM-12345678",
        "node_name": "node1",
        "status": "running",
        "type": "lxc",
        "expires_at": "2024-04-05 00:00:00",
        "days_remaining": 30,
        "realtime_status": {
          "status": "running",
          "qemu": {
            "cpu": 0.25,
            "mem": 524288
          }
        }
      }
    ]
  }
  ```

### 获取虚拟机详情
- **接口**: `GET /api/vms/:id`
- **需要登录**: 是
- **路径参数**: `id` - 虚拟机ID
- **查询参数**:
  - `refresh` (可选): `1` - 刷新实时状态
- **响应示例**:
  ```json
  {
    "success": true,
    "vm": {
      "id": 1,
      "vmid": 100,
      "name": "VM-12345678",
      "node_name": "node1",
      "status": "running",
      "type": "lxc",
      "expires_at": "2024-04-05 00:00:00",
      "days_remaining": 30,
      "realtime_status": {
        "status": "running",
        "cpu": 25.5,
        "memory": 512,
        "disk": 20,
        "network": {
          "in": "1024 KB/s",
          "out": "2048 KB/s"
        }
      }
    }
  }
  ```

### 控制虚拟机
- **接口**: `POST /api/vms/:id/control`
- **需要登录**: 是
- **路径参数**: `id` - 虚拟机ID
- **请求参数**:
  ```json
  {
    "action": "start|stop|restart|shutdown|pause|resume"
  }
  ```
- **响应示例**:
  ```json
  {
    "success": true,
    "message": "操作成功"
  }
  ```

### 获取虚拟机统计
- **接口**: `GET /api/vms/:id/stats`
- **需要登录**: 是
- **路径参数**: `id` - 虚拟机ID
- **响应示例**:
  ```json
  {
    "success": true,
    "vm": {
      "id": 1,
      "name": "VM-12345678",
      "status": "running",
      "cpu_usage": "25.5%",
      "memory_usage": "50.0%",
      "disk_usage": "20.0%",
      "network_io": {
        "in": "1024 KB/s",
        "out": "2048 KB/s"
      }
    }
  }
  ```

## 产品管理

### 获取产品列表
- **接口**: `GET /api/products`
- **响应示例**:
  ```json
  {
    "success": true,
    "products": [
      {
        "id": 1,
        "name": "入门套餐",
        "description": "1核2G内存",
        "price": 10.00,
        "vm_config": {
          "cores": 1,
          "memory": 2048,
          "disk": 20
        },
        "duration_days": 30,
        "status": "active"
      }
    ]
  }
  ```

### 创建订单
- **接口**: `POST /api/orders/create`
- **需要登录**: 是
- **请求参数**:
  ```json
  {
    "product_id": 1
  }
  ```
- **响应示例**:
  ```json
  {
    "success": true,
    "order_id": 123
  }
  ```

## 支付管理

### 获取支付网关
- **接口**: `GET /api/payment/gateways`
- **响应示例**:
  ```json
  {
    "success": true,
    "gateways": [
      {
        "id": 1,
        "name": "alipay",
        "description": "支付宝",
        "enabled": true
      },
      {
        "id": 2,
        "name": "wechat",
        "description": "微信支付",
        "enabled": true
      }
    ]
  }
  ```

### 处理支付
- **接口**: `POST /api/payment/process`
- **需要登录**: 是
- **请求参数**:
  ```json
  {
    "order_id": 123,
    "gateway_id": 1
  }
  ```
- **响应示例**:
  ```json
  {
    "success": true,
    "payment_url": "https://payment.example.com/pay?..."
  }
  ```

### 支付回调
- **接口**: `GET|POST /api/payment/notify`
- **请求参数**: 根据支付网关不同而不同
- **响应**: `success` 或 `fail`

### 支付返回
- **接口**: `GET /api/payment/return`
- **查询参数**: `order_id` - 订单ID
- **响应示例**:
  ```json
  {
    "success": true,
    "message": "支付成功"
  }
  ```

## 管理员接口

### 获取仪表板统计
- **接口**: `GET /api/admin/stats`
- **需要登录**: 是
- **需要管理员**: 是
- **响应示例**:
  ```json
  {
    "success": true,
    "stats": {
      "users": {
        "total_users": 100,
        "active_users": 85
      },
      "vms": {
        "total_vms": 50,
        "running_vms": 35
      },
      "orders": {
        "total_orders": 200,
        "paid_orders": 180,
        "total_revenue": 5000.00
      },
      "monthly": {
        "monthly_revenue": 1000.00,
        "monthly_orders": 40
      }
    }
  }
  ```

### 用户管理

#### 获取用户列表
- **接口**: `GET /api/admin/users`
- **需要管理员**: 是
- **查询参数**:
  - `page` (可选): 页码，默认1
  - `limit` (可选): 每页数量，默认20
  - `search` (可选): 搜索关键词

#### 创建用户
- **接口**: `POST /api/admin/users`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "username": "string (3-50字符)",
    "email": "email",
    "password": "string (最少8位)",
    "role": "user|admin",
    "status": "active|disabled|pending"
  }
  ```

#### 更新用户
- **接口**: `PUT /api/admin/users/:id`
- **需要管理员**: 是
- **请求参数**: 同创建用户

#### 删除用户
- **接口**: `DELETE /api/admin/users/:id`
- **需要管理员**: 是

#### 更新用户状态
- **接口**: `POST /api/admin/users/status`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "user_id": 1,
    "status": "active|disabled"
  }
  ```

### 产品管理

#### 获取产品列表
- **接口**: `GET /api/admin/products`
- **需要管理员**: 是

#### 创建产品
- **接口**: `POST /api/admin/products`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "name": "string",
    "description": "string",
    "price": 10.00,
    "vm_config": {
      "cores": 1,
      "memory": 2048,
      "disk": 20
    },
    "duration_days": 30
  }
  ```

#### 更新产品
- **接口**: `PUT /api/admin/products/:id`
- **需要管理员**: 是

#### 删除产品
- **接口**: `DELETE /api/admin/products/:id`
- **需要管理员**: 是

### 订单管理

#### 获取订单列表
- **接口**: `GET /api/admin/orders`
- **需要管理员**: 是
- **查询参数**:
  - `page` (可选): 页码
  - `limit` (可选): 每页数量
  - `status` (可选): 订单状态筛选

#### 更新订单状态
- **接口**: `PUT /api/admin/orders/:id/status`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "status": "pending|paid|cancelled|vm_created"
  }
  ```

### 虚拟机管理

#### 获取所有虚拟机
- **接口**: `GET /api/admin/vms`
- **需要管理员**: 是
- **查询参数**:
  - `page` (可选): 页码
  - `limit` (可选): 每页数量
  - `search` (可选): 搜索关键词

#### 转移虚拟机
- **接口**: `POST /api/admin/vms/transfer`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "vm_id": 1,
    "new_user_id": 2
  }
  ```

### 节点管理

#### 获取节点列表
- **接口**: `GET /api/admin/nodes`
- **需要管理员**: 是

#### 获取节点详情
- **接口**: `GET /api/admin/nodes/:id`
- **需要管理员**: 是
- **响应示例**:
  ```json
  {
    "success": true,
    "node": {
      "id": 1,
      "name": "node1",
      "api_url": "https://pve.example.com:8006/api2/json",
      "status": "online",
      "vm_count": 25,
      "container_count": 10,
      "cpu_usage": "45.5%",
      "memory_usage": "65.0%",
      "disk_usage": "55.0%",
      "network_traffic": "512 KB/s",
      "last_sync": "2024-03-05 12:00:00"
    }
  }
  ```

#### 添加节点
- **接口**: `POST /api/admin/nodes`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "name": "string",
    "api_url": "https://pve.example.com:8006/api2/json",
    "api_user": "root@pam",
    "api_token": "token_value"
  }
  ```

#### 更新节点
- **接口**: `PUT /api/admin/nodes/:id`
- **需要管理员**: 是

#### 删除节点
- **接口**: `DELETE /api/admin/nodes/:id`
- **需要管理员**: 是

#### 同步节点虚拟机
- **接口**: `POST /api/admin/nodes/:id/sync`
- **需要管理员**: 是
- **响应示例**:
  ```json
  {
    "success": true,
    "synced_count": 5,
    "message": "节点同步成功"
  }
  ```

### 备份管理

#### 获取备份列表
- **接口**: `GET /api/admin/backups`
- **需要管理员**: 是
- **查询参数**:
  - `page` (可选): 页码
  - `limit` (可选): 每页数量
  - `node_id` (可选): 节点ID筛选
  - `vm_id` (可选): 虚拟机ID筛选
  - `status` (可选): 备份状态筛选

#### 创建备份
- **接口**: `POST /api/admin/backups`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "vm_id": 1,
    "name": "backup-20240305"
  }
  ```

#### 恢复备份
- **接口**: `POST /api/admin/backups/:id/restore`
- **需要管理员**: 是

#### 删除备份
- **接口**: `DELETE /api/admin/backups/:id`
- **需要管理员**: 是

### 网络配置管理

#### 获取网络配置列表
- **接口**: `GET /api/admin/networks`
- **需要管理员**: 是

#### 创建网络配置
- **接口**: `POST /api/admin/networks`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "name": "string",
    "type": "bridge|vlan|bond",
    "node_id": 1,
    "status": "active|inactive",
    "config": "{}"
  }
  ```

#### 更新网络配置
- **接口**: `PUT /api/admin/networks/:id`
- **需要管理员**: 是

#### 删除网络配置
- **接口**: `DELETE /api/admin/networks/:id`
- **需要管理员**: 是

### 系统配置

#### 获取配置列表
- **接口**: `GET /api/admin/configs`
- **需要管理员**: 是

#### 更新配置
- **接口**: `POST /api/admin/configs`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "key": "config_key",
    "value": "config_value",
    "description": "配置描述"
  }
  ```

#### 保存基本设置
- **接口**: `POST /api/admin/configs/basic`
- **需要管理员**: 是

#### 保存支付设置
- **接口**: `POST /api/admin/configs/payment`
- **需要管理员**: 是

#### 保存邮件设置
- **接口**: `POST /api/admin/configs/email`
- **需要管理员**: 是

#### 保存高级设置
- **接口**: `POST /api/admin/configs/advanced`
- **需要管理员**: 是

### 兑换码管理

#### 生成兑换码
- **接口**: `POST /api/admin/redeem-codes`
- **需要管理员**: 是
- **请求参数**:
  ```json
  {
    "count": 10,
    "type": "product|amount",
    "product_id": 1,
    "amount": 50.00,
    "expires_days": 30
  }
  ```
- **响应示例**:
  ```json
  {
    "success": true,
    "codes": [
      "ABC123DEF456",
      "GHI789JKL012"
    ],
    "message": "兑换码生成成功"
  }
  ```

### 日志管理

#### 获取审计日志
- **接口**: `GET /api/admin/logs`
- **需要管理员**: 是
- **查询参数**:
  - `page` (可选): 页码
  - `limit` (可选): 每页数量
  - `search` (可选): 搜索关键词
  - `user_id` (可选): 用户ID筛选
  - `date` (可选): 日期筛选

## 错误代码说明

| 错误代码 | 说明 |
|---------|------|
| 400 | 请求参数错误 |
| 401 | 未授权或认证失败 |
| 403 | 禁止访问（CSRF错误、频率限制） |
| 404 | 资源不存在 |
| 429 | 请求过于频繁 |
| 500 | 服务器内部错误 |

## 安全说明

1. **CSRF保护**: 所有POST请求需要包含有效的CSRF token
2. **频率限制**: 部分接口有频率限制
3. **会话管理**: 使用Session进行用户认证
4. **输入验证**: 所有输入都经过验证和过滤
5. **SQL注入防护**: 使用PDO预处理语句
6. **XSS防护**: 输出时进行HTML转义

## 使用示例

### JavaScript示例
```javascript
// 登录示例
axios.post('/api/login', {
  email: 'user@example.com',
  password: 'password123'
})
.then(response => {
  if (response.data.success) {
    console.log('登录成功', response.data.user);
    // 保存用户信息到本地存储
    localStorage.setItem('user', JSON.stringify(response.data.user));
  }
})
.catch(error => {
  console.error('登录失败', error);
});

// 获取虚拟机列表
axios.get('/api/vms')
.then(response => {
  if (response.data.success) {
    console.log('虚拟机列表', response.data.vms);
  }
})
.catch(error => {
  console.error('获取虚拟机列表失败', error);
});

// 控制虚拟机
axios.post('/api/vms/1/control', {
  action: 'start'
})
.then(response => {
  if (response.data.success) {
    alert('启动成功');
  }
})
.catch(error => {
  console.error('启动失败', error);
});
```

### cURL示例
```bash
# 登录
curl -X POST http://your-domain.com/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}'

# 获取虚拟机列表
curl http://your-domain.com/api/vms

# 控制虚拟机
curl -X POST http://your-domain.com/api/vms/1/control \
  -H "Content-Type: application/json" \
  -d '{"action":"start"}'
```

## 注意事项

1. 所有时间戳都使用ISO 8601格式：`YYYY-MM-DDTHH:MM:SS`
2. 所有金额都使用2位小数
3. 分页从1开始
4. 需要登录的接口会检查Session
5. 需要管理员的接口会检查用户角色
6. 频率限制的接口会在响应头中包含 `X-RateLimit-*` 信息
7. 错误响应包含详细的错误信息用于调试

## 版本历史

- **v1.0** - 初始版本
  - 基础用户认证
  - 虚拟机管理
  - 产品购买
  - 支付集成

- **v2.0** - 安全增强版本
  - CSRF保护
  - 频率限制
  - 输入验证
  - 日志系统
  - 错误处理
  - 邮件服务优化

## 联系方式

如有问题，请联系技术支持：
- 邮箱：support@example.com
- 文档：https://docs.example.com