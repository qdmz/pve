# PVE 虚拟机管理系统

一个基于 Proxmox VE API 的虚拟机管理系统，支持创建、管理和监控虚拟机。

## 功能特性

- ✅ 虚拟机创建与管理
- ✅ 节点管理与监控
- ✅ 网络配置管理
- ✅ 系统模板管理
- ✅ 用户管理
- ✅ 订单管理
- ✅ 支付集成
- ✅ 备份管理
- ✅ 审计日志

## 技术栈

- **后端**: PHP 8.1+
- **前端**: Vue 3
- **数据库**: MySQL
- **容器化**: Docker
- **虚拟化平台**: Proxmox VE

## 系统要求

- Docker 20.0+ 和 Docker Compose 1.29+
- PHP 8.1+
- MySQL 8.0+
- Proxmox VE 7.0+

## 快速开始

### 1. 克隆项目

```bash
git clone https://github.com/qdmz/pve.git
cd pve
```

### 2. 配置环境变量

复制 `.env.example` 文件为 `.env` 并修改相关配置：

```bash
cp .env.example .env
# 编辑 .env 文件，设置数据库连接信息和其他配置
```

### 3. 启动服务

使用 Docker Compose 启动服务：

```bash
docker-compose up -d
```

### 4. 初始化数据库

```bash
docker exec -it pve-php php database/init.php
```

### 5. 访问系统

打开浏览器，访问 `http://localhost:8080`，使用默认管理员账号登录：

- **用户名**: admin
- **密码**: admin123

## 项目结构

```
├── app/
│   ├── Controllers/     # 控制器
│   ├── Services/        # 服务层
│   ├── Utils/           # 工具类
│   └── Middleware/      # 中间件
├── config/              # 配置文件
├── database/            # 数据库脚本
├── docker/              # Docker 配置
├── logs/                # 日志文件
├── public/              # 前端文件
│   ├── index.html       # 主页面
│   └── index.php        # API 入口
├── docker-compose.yml   # Docker Compose 配置
└── README.md            # 项目说明
```

## Proxmox VE 配置

1. **创建 API Token**:
   - 登录 Proxmox VE Web 界面
   - 进入 `Datacenter` > `Permissions` > `API Tokens`
   - 点击 `Add` 创建新的 API Token
   - 记录下 `Token ID` 和 `Token Secret`

2. **添加节点**:
   - 登录系统管理后台
   - 进入 `节点管理` > `添加节点`
   - 填写节点名称、API URL、API 用户和 API Token
   - 点击 `保存` 完成节点添加

## 虚拟机创建流程

1. **选择节点**:
   - 从可用节点列表中选择一个节点

2. **配置虚拟机**:
   - 填写虚拟机名称、CPU、内存、硬盘等配置
   - 选择操作系统模板
   - 配置网络和 IP 地址

3. **确认创建**:
   - 点击 `创建虚拟机` 按钮
   - 系统会调用 Proxmox VE API 创建虚拟机
   - 创建完成后，虚拟机将显示在列表中

## 网络配置

系统支持多种网络配置方式：

- **DHCP**: 自动获取 IP 地址
- **静态 IP**: 手动设置 IP 地址、子网掩码和网关

## 备份管理

系统支持创建和管理虚拟机备份：

1. **创建备份**:
   - 选择要备份的虚拟机
   - 填写备份名称
   - 点击 `创建备份` 按钮

2. **恢复备份**:
   - 选择要恢复的备份
   - 点击 `恢复备份` 按钮

3. **删除备份**:
   - 选择要删除的备份
   - 点击 `删除备份` 按钮

## 系统日志

系统会记录所有操作日志，包括：

- 用户登录/登出
- 虚拟机创建/删除/修改
- 节点添加/删除/修改
- 网络配置更改
- 备份操作

## 故障排除

### 1. 虚拟机创建失败

- 检查 Proxmox VE API 连接是否正常
- 检查节点状态是否在线
- 检查模板文件是否存在
- 检查网络配置是否正确

### 2. 网络连接问题

- 检查节点网络配置
- 检查防火墙设置
- 检查 API Token 权限

### 3. 权限错误

- 检查 API Token 权限设置
- 确保 Token 具有足够的权限执行操作

## 安全建议

1. **使用强密码**:
   - 为所有用户设置强密码
   - 定期更新密码

2. **限制 API 访问**:
   - 只允许受信任的 IP 访问 Proxmox VE API
   - 使用 HTTPS 保护 API 通信

3. **定期备份**:
   - 定期备份虚拟机
   - 定期备份系统数据库

4. **更新系统**:
   - 定期更新 Proxmox VE
   - 定期更新系统组件

## 贡献

欢迎提交 Issue 和 Pull Request 来改进这个项目！

## 许可证

MIT License

## 联系方式

- **GitHub**: [https://github.com/qdmz/pve](https://github.com/qdmz/pve)
- **Email**: contact@example.com
