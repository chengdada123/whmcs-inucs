/whmcs/
└── modules/
    └── servers/
        └── incus/
            ├── incus.php             # 模块主文件，包含所有WHMCS钩子函数
            ├── lib/                  # 存放核心逻辑和API交互的库文件夹
            │   └── IncusAPI.php      # 封装了所有与Incus API交互的类
            ├── templates/            # 存放客户端区域（Client Area）的自定义模板
            │   └── clientarea.tpl    # 自定义客户端页面的模板文件
            ├── hooks.php             # (可选) 用于更深度集成的钩子文件
            ├── logo.gif              # 在产品设置页面显示的模块Logo
            └── README.md             # 项目说明文档
            
            
# WHMCS Incus 供应模块

这是一个WHMCS设计的供应模块，用于自动化管理Incus容器实例。

## 主要功能

- **自动化部署**: 自动创建、暂停 (停止)、恢复 (启动) 和终止Incus容器。
- **客户端管理**: 客户可以在WHMCS客户端区域进行开机、关机、重启、重装系统等操作。
- **快照管理**: 客户可以创建、恢复和删除自己的容器快照。
- **资源配置**: 管理员可以在WHMCS产品设置中，通过可配置选项 (Configurable Options) 让客户自定义CPU、内存、硬盘大小。
- **网络支持**: 支持基于Incus `managed` 网络的NAT模式，并能自动获取和显示IPv4/IPv6地址。

## 安全须知
- **证书认证**: 模块使用TLS客户端证书与Incus API进行通信，请确保您的证书和密钥文件存放在Web服务器无法直接访问的安全目录，并配置好文件权限。
- **输入验证**: 客户端输入的快照名称经过了基本的字符验证，防止恶意输入。
- **代码审查**: 本模块代码为开发起点，在用于生产环境前，请务必进行完整的代码审查和安全测试。

## 安装与配置
1.  **安装incus主体并批量开设lxc容器，具体参考**: https://virt.spiritlhl.net/guide/incus/incus_install.html
2.  **上传文件**: 将`incus`整个文件夹上传到您的WHMCS安装目录下的 `modules/servers/` 中。

3.  **生成Incus客户端证书**:
    在您的Incus服务器上，为WHMCS服务器生成一个客户端证书:
    ```bash
    openssl genrsa -out whmcs.key 4096
openssl req -new -key whmcs.key -out whmcs.csr -subj "/CN=whmcs"
openssl x509 -req -in whmcs.csr -signkey whmcs.key -out whmcs.crt -days 3650
#添加信任
incus config trust add-certificate ./whmcs.crt 
    ```
    这会生成 `whmcs.crt` 和 `whmcs.key` 两个文件。将这两个文件安全地上传到您的WHMCS服务器上（例如 `/etc/whmcs/certs/`）。

4.  **配置WHMCS服务器**:
    - 进入 WHMCS 管理后台 -> Setup -> Products/Services -> Servers。
    - 点击 "Add New Server"。
    - **Name**: 任意名称 (e.g., Incus Node 1)。
    - **Hostname**: Incus服务器的IP或域名。
    - **IP Address**: Incus服务器的IP地址。
    - 在 "Server Details" 部分:
        - **Type**: 选择 `Incus Provisioning Module`。
        - **Username**: 留空。
        - **Password**: 填写 `client_key_path` 的绝对路径。
        - **Access Hash**: 填写 `client_cert_path` 的绝对路径。
        - **Secure**: 如果您的Incus API使用了可信的SSL证书，请勾选此项。
    - 保存更改。

5.  **配置WHMCS产品**:
    - 进入 Setup -> Products/Services -> Products/Services。
    - 创建或编辑一个产品，切换到 "Module Settings" 选项卡。
    - **Module Name**: 选择 `Incus Provisioning Module`。
    - **Server Group**: 选择您刚才创建的服务器组。
    - **配置模块选项**: 填写默认的网络和存储池名称。
    - **创建可配置选项 (Configurable Options)** (可选，但推荐):
        - 创建一个名为 `CPU` 的选项，值为核心数 (e.g., 1, 2, 4)。
        - 创建一个名为 `Memory` 的选项，值为内存大小MB (e.g., 512, 1024, 2048)。
        - 创建一个名为 `Disk` 的选项，值为磁盘大小GB (e.g., 10, 20, 50)。
        - 创建一个名为 `OS` 的选项，值为Incus中存在的镜像别名 (e.g., `ubuntu/22.04`, `debian/12`)。
    - 保存产品设置。

现在，客户下单购买此产品后，模块就会自动在Incus上创建容器了。            