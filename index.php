<?php
/**
 * WHMCS Incus 模块 - AJAX 请求路由器
 * 此文件作为所有客户端 AJAX 入口。
 */

// 引导 WHMCS
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/incus.php';

// 从请求中获取服务ID
$serviceId = isset($_REQUEST['serviceid']) ? (int)$_REQUEST['serviceid'] : 0;

if (!$serviceId) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Service ID is required.']);
    exit();
}

// 构建模块函数的 $params 数组。
try {
    $service = WHMCS\Database\Capsule::table('tblhosting')->where('id', $serviceId)->first();
    if (!$service) {
        throw new Exception('Service not found.');
    }
    
    $server = WHMCS\Database\Capsule::table('tblservers')->where('id', $service->server)->first();
    if (!$server) {
        throw new Exception('Associated server not found.');
    }

    $params = [
        'serviceid' => $service->id,
        'serverhostname' => $server->hostname,
        'serveraccesshash' => $server->accesshash,
        'serverpassword' => decrypt($server->password),
        'serversecure' => $server->secure,
        'domain' => $service->domain,
    ];

    // 为需要的函数添加产品配置选项到参数中
    $product = WHMCS\Database\Capsule::table('tblproducts')->where('id', $service->packageid)->first();
    for ($i = 1; $i <= 24; $i++) {
        $key = 'configoption' . $i;
        $params[$key] = $product->$key;
    }

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Failed to load service parameters: ' . $e->getMessage()]);
    exit();
}

// 将请求路由到 incus.php 中的适当函数
try {
    $response = incus_ajax_router($params);
} catch (Exception $e) {
    // 如果发生任何未捕获的异常，将其格式化为 JSON 错误 防止 HTTP 500
    header('HTTP/1.1 500 Internal Server Error');
    $response = [
        'error' => 'A fatal error occurred: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
}

// 返回最终的 JSON 响应
header('Content-Type: application/json');
echo json_encode($response);
exit();