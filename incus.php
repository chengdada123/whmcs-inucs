<?php
/**
 * WHMCS Incus 供应模块
 */

use WHMCS\Database\Capsule;
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (file_exists(__DIR__ . '/lib/IncusAPI.php')) {
    require_once __DIR__ . '/lib/IncusAPI.php';
}

function incus_MetaData() {
    return [
        'DisplayName' => 'Incus NAT Module',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

function incus_ConfigOptions() {
    return [
        "profile_name" => [
            "FriendlyName" => "Incus Profile Name",
            "Type" => "text",
            "Size" => "30",
            "Description" => "The Incus Profile corresponding to this product, e.g., plan-64m",
        ],
        "bandwidth_limit_enabled" => [
            "FriendlyName" => "启用流量限制 (Enable Bandwidth Limit)",
            "Type" => "yesno",
            "Description" => "勾选以启用该产品的流量统计和限制功能",
        ],
        "bandwidth_quota_gb" => [
            "FriendlyName" => "每月流量配额 (GB)",
            "Type" => "text",
       
             "Size" => "10",
            "Default" => "100",
            "Description" => "每月可用的总流量，单位为 GB",
        ],
        "bandwidth_mode" => [
            "FriendlyName" => "流量计算方式 (Bandwidth Accounting)",
            "Type" => "dropdown",
            "Options" => [
  
              'out' => '仅计算出站流量 (Outbound Only)',
                'in' => '仅计算入站流量 (Inbound Only)',
                'total' => '计算出站+入站总流量 (Total Traffic)',
            ],
            "Description" => "选择如何计算已用流量",
        ],
        
        
        "bandwidth_reset_cycle" => [
            "FriendlyName" => "流量重置周期 (Reset Cycle)",
            "Type" => "dropdown",
            "Options" => [
                'billing_date' => '按账单日重置 (On Billing Date)',
                'first_of_month' => '按自然月重置 (1st of Month)',
    
            ],
            "Description" => "选择每月重置流量的日期",
        ],
    ];
}
if (!function_exists('_incus_init_api')) {
    function _incus_init_api($params) {
        $hostname = $params['serverhostname'];
        $serverUrl = 'https://' . $hostname . ':8443';
        $certPath = $params['serveraccesshash'];
        $keyPath = $params['serverpassword'];
        $verifySsl = (bool)$params['serversecure'];
        return new IncusAPI($serverUrl, $certPath, $keyPath, $verifySsl);
    }
}

function _incus_apply_network_limit($api, $instanceName, $limit = '1kbit') {
    $instanceDetails = $api->getInstance($instanceName);
    $devices = $instanceDetails['metadata']['devices'];
    $networkDeviceName = null;

    // 动态查找第一个非回环网络接口
    foreach ($devices as $deviceName => $device) {
        if (isset($device['type']) && $device['type'] === 'nic' && $deviceName !== 'lo') {
            $networkDeviceName = $deviceName;
            break;
        }
    }

    if ($networkDeviceName) {
        $devices[$networkDeviceName]['limits.egress'] = $limit;
        $config = ['devices' => $devices];
        $api->updateInstance($instanceName, $config);
        return true;
    }
    return false;
}

function _incus_remove_network_limit($api, $instanceName) {
    $instanceDetails = $api->getInstance($instanceName);
    $devices = $instanceDetails['metadata']['devices'];
    $networkDeviceName = null;
    // 动态查找第一个非回环网络接口
    foreach ($devices as $deviceName => $device) {
        if (isset($device['type']) && $device['type'] === 'nic' && $deviceName !== 'lo') {
            $networkDeviceName = $deviceName;
            break;
        }
    }

    if ($networkDeviceName && isset($devices[$networkDeviceName]['limits.egress'])) {
        unset($devices[$networkDeviceName]['limits.egress']);
        $config = ['devices' => $devices];
        $api->updateInstance($instanceName, $config);
        return true;
    }
    return false;
}

function incus_CreateAccount($params) {
    // logModuleCall('incus', __FUNCTION__, $params, '---调试参数---');
    // $serverGroupId = $params['gid'];
    // if (!$serverGroupId) {
    //     return "产品未分配到任何服务器组。";
    // }
    $serverGroupId = $params['gid'] ?? null;
    if (!$serverGroupId && isset($params['model']->product->gid)) {
        $serverGroupId = $params['model']->product->gid;
    }    
    try {
        $servers = Capsule::table('tblservers')
            ->join('tblservergroupsrel', 'tblservers.id', '=', 'tblservergroupsrel.serverid')
            ->where('tblservergroupsrel.groupid', $serverGroupId)
            ->where('tblservers.disabled', 0)
            ->select('tblservers.*')
            ->get();
        if ($servers->isEmpty()) {
            return "No active servers found in the assigned server group.";
        }
    } catch (Exception $e) {
        logModuleCall('incus', __FUNCTION__, 'ServerLookup', $e->getMessage(), $e->getTraceAsString());
        return "An error occurred while looking for servers in the group.";
    }
    $profileToFind = $params['configoption1'];
    if (empty($profileToFind)) {
        return "Incus Profile Name is not specified in product module settings.";
    }
    $foundInstanceName = null;
    $foundServerParams = null;
    foreach ($servers as $server) {
        try {
            $currentServerParams = $params;
            $currentServerParams['serverid'] = $server->id;
            $currentServerParams['serverhostname'] = $server->hostname;
            $currentServerParams['serveraccesshash'] = $server->accesshash;
            $currentServerParams['serverpassword'] = decrypt($server->password);
            $currentServerParams['serversecure'] = $server->secure;
            $api = _incus_init_api($currentServerParams);
            $allInstancesResponse = $api->getInstances();
            $allInstancesOnServer = !empty($allInstancesResponse['metadata']) ? array_map('basename', $allInstancesResponse['metadata']) : [];
            $matchingProfileInstances = [];
            foreach ($allInstancesOnServer as $instanceName) {
                try {
                    $instanceDetails = $api->getInstance($instanceName);
                    if (isset($instanceDetails['metadata']['profiles']) && in_array($profileToFind, $instanceDetails['metadata']['profiles'])) {
                        $matchingProfileInstances[] = $instanceName;
                    }
                } catch (Exception $e) { /* 记录日志并跳过 */ }
            }
            if (empty($matchingProfileInstances)) {
                continue;
            }
            $assignedInstancesOnThisServer = [];
            $query = Capsule::table('tblhosting')->where('server', $server->id)->whereIn('domainstatus', ['Active', 'Suspended'])->get(['domain']);
            foreach ($query as $service) {
                if (!empty($service->domain)) $assignedInstancesOnThisServer[] = $service->domain;
            }
            $assignedInstancesOnThisServer = array_unique($assignedInstancesOnThisServer);
            foreach ($matchingProfileInstances as $instanceName) {
                if (!in_array($instanceName, $assignedInstancesOnThisServer)) {
                    $foundInstanceName = $instanceName;
                    $foundServerParams = $currentServerParams;
                    break;
                }
            }
            if ($foundInstanceName) {
                break;
            }
        } catch (Exception $e) {
            logModuleCall('incus', __FUNCTION__, "ServerID: {$server->id}", $e->getMessage());
            continue;
        }
    }
     if ($foundInstanceName === null || $foundServerParams === null) {
        return "Out of Stock: No available containers matching the profile '{$profileToFind}' could be found on any server in the group.";
    }
    try {
        $api = _incus_init_api($foundServerParams);
        $userProvidedPassword = $params['password'];
        if (empty($userProvidedPassword)) return "Password was not provided by the client during checkout.";
        $state = $api->getInstanceState($foundInstanceName);
        if ($state['metadata']['status'] !== 'Running') {
            $api->setInstanceState($foundInstanceName, 'start');
            sleep(10);
        }
        $command = ['/bin/sh', '-c', "echo 'root:{$userProvidedPassword}' | chpasswd"];
        $api->executeCommand($foundInstanceName, $command);
        $instanceDetails = $api->getInstance($foundInstanceName);
        $instanceState = $api->getInstanceState($foundInstanceName);
        $publicIp4 = '';
        $privateIp4 = '';
        if (isset($instanceState['metadata']['network']['eth0']['addresses'])) {
            foreach ($instanceState['metadata']['network']['eth0']['addresses'] as $address) {
                if ($address['family'] === 'inet' && $address['scope'] === 'global') {
                    $privateIp4 = $address['address'];
                    break;
                }
            }
        }
        if (isset($instanceDetails['metadata']['devices'])) {
            foreach ($instanceDetails['metadata']['devices'] as $device) {
                if ($device['type'] === 'proxy' && isset($device['listen'])) {
                    $parts = explode(':', $device['listen']);
                    if (count($parts) === 3) {
                        $publicIp4 = $parts[1];
                        break;
                    }
                }
            }
        }
        Capsule::table('tblhosting')->where('id', '=', $params['serviceid'])->update([
            'server' => $foundServerParams['serverid'],
            'dedicatedip' => $publicIp4,
            'assignedips' => $privateIp4,
           
             'domain' => $foundInstanceName,
        ]);
        if ($params['configoption2'] == 'on' || $params['configoption2'] == 'yes') {
            $serviceData = Capsule::table('tblhosting')->where('id', $params['serviceid'])->first();
            $nextDueDate = $serviceData->nextduedate;
            if ($params['configoption5'] == 'first_of_month') {
                $resetDate = date('Y-m-01', strtotime('+1 month', strtotime($nextDueDate)));
            } else {
                $resetDate = date('Y-m-d', strtotime('+1 month', strtotime($nextDueDate)));
            }
            Capsule::table('mod_incus_nat_usage')->updateOrInsert(
                ['service_id' => $params['serviceid']],
                [
                    'container_name' => $foundInstanceName,
                    'last_update' => 0, 'last_bytes_in' => 0, 'last_bytes_out' => 0,
    
                    'usage_bytes_in' => 0, 'usage_bytes_out' => 0,
                    'usage_reset_date' => $resetDate, 'is_limited' => 0, 'history' => json_encode([]),
                ]
            );
        }
    } catch (Exception $e) {
        logModuleCall('incus', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "Allocation Failed on ServerID {$foundServerParams['serverid']}: " . $e->getMessage();
    }
    return 'success';
}

function incus_TerminateAccount($params) {
    try {
        $api = _incus_init_api($params);
$instanceName = $params['domain'];
        if (empty($instanceName)) {
            return 'Instance name not found, skipping recycle operation.';
}
        $instanceDetails = $api->getInstance($instanceName);
if (isset($instanceDetails['metadata']['source'])) {
            $originalSource = $instanceDetails['metadata']['source'];
            $api->setInstanceState($instanceName, 'stop', 60);
if ($originalSource) {
                _incus_remove_network_limit($api, $instanceName);
$api->rebuildInstance($instanceName, $originalSource);
            }
        } else {
            $api->setInstanceState($instanceName, 'stop', 60);
}
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'dedicatedip' => '', 'assignedips' => '', 'domain' => '',
        ]);
Capsule::table('mod_incus_nat_usage')->where('service_id', $params['serviceid'])->delete();
    } catch (Exception $e) {
        // 如果实例未找到或服务器无法访问，则继续本地终止。
        if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'cURL Error') !== false) {
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                'dedicatedip' => '', 'assignedips' => '', 'domain' => '',
            ]);
Capsule::table('mod_incus_nat_usage')->where('service_id', $params['serviceid'])->delete();
            return 'success';
        }
        logModuleCall('incus', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
return "Recycle Failed: " . $e->getMessage();
    }
    return 'success';
}

function incus_listImages($api) {
    $images = [];
    try {
        $imagesResponse = $api->listImages();
        if (isset($imagesResponse['metadata']) && is_array($imagesResponse['metadata'])) {
            foreach ($imagesResponse['metadata'] as $imageUrl) {
                $imageData = $api->request('GET', $imageUrl);
                if (isset($imageData['metadata']['aliases']) && !empty($imageData['metadata']['aliases'])) {
                    $images[] = [
                        'alias' => $imageData['metadata']['aliases'][0]['name'],
                        'description' => $imageData['metadata']['properties']['description']
                      
                       ?? $imageData['metadata']['aliases'][0]['name']
                    ];
                }
            }
        }
    } catch (Exception $e) {
        logModuleCall('incus', 'listImages', 'Failed to fetch images', $e->getMessage());
    }
    return $images;
}

function incus_ClientArea($params) {
    try {
        $api = _incus_init_api($params);
        $instanceName = $params['domain'];
        if (empty($instanceName)) {
            return '<div class="alert alert-info">Server details will be available shortly after activation.</div>';
        }
        $notification = '';
        if (isset($_POST['mod_action'])) {
            $action = $_POST['mod_action'];
            try {
                if (in_array($action, ['start', 'stop', 'restart'])) {
                    $api->setInstanceState($instanceName, $action);
                }
                header("Location: clientarea.php?action=productdetails&id={$params['serviceid']}&success=1");
                exit;
            } catch (Exception $e) {
                $notification = 'Action failed: ' .
                $e->getMessage();
            }
        }
        if (isset($_GET['success'])) {
            $notification = "Action completed successfully.";
        }
        $instanceDetails = $api->getInstance($instanceName);
        $state = $api->getInstanceState($instanceName);
        $images = incus_listImages($api);
        $bandwidth = [];
        if (($params['configoption2'] ?? 'off') == 'on') {
            $usage = Capsule::table('mod_incus_nat_usage')->where('service_id', $params['serviceid'])->first();
            if ($usage) {
                $quotaBytes = (intval($params['configoption3']) ?: 100) * 1024 * 1024 * 1024;
                $mode = $params['configoption4'] ?: 'out';
                $usedIn = $usage->usage_bytes_in;
                $usedOut = $usage->usage_bytes_out;
                if ($mode == 'out') $totalUsed = $usedOut;
                elseif ($mode == 'in') $totalUsed = $usedIn;
                else $totalUsed = $usedIn + $usedOut;
                $bandwidth = [
                    'enabled' => true, 'quota_gb' => $params['configoption3'], 'quota_bytes' => $quotaBytes,
                    'used_in_bytes' => $usedIn, 'used_out_bytes' => $usedOut, 'total_used_bytes' => $totalUsed,
                    'percentage' => $quotaBytes > 0 ?
                    round(($totalUsed / $quotaBytes) * 100, 2) : 0,
                ];
            }
        }
        if (empty($bandwidth)) {
            $bandwidth = ['enabled' => false];
        }
        return [
            'templatefile' => 'templates/clientarea',
            'vars' => [
                'notification' => $notification, 'LANG' => $params['LANG'],
                'instance' => $instanceDetails['metadata'], 'state' => $state['metadata'],
                'password' => htmlspecialchars(decrypt($params['password'])),
   
                 'images' => $images, 'bandwidth' => $bandwidth,
            ],
        ];
    } catch (Exception $e) {
        logModuleCall('incus', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return '<div class="alert alert-danger">Could not connect to the server to get instance details: ' . $e->getMessage() . '</div>';
    }
}

function incus_SuspendAccount($params) {
    try {
        $api = _incus_init_api($params);
$instanceName = $params['domain'];
        if (empty($instanceName)) return 'Instance name not found, cannot suspend.';
        $api->setInstanceState($instanceName, 'stop');
} catch (Exception $e) {
        // 如果实例不存在（404）或服务器无法访问（cURL 错误），将其视为成功。
        if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'cURL Error') !== false) {
            logModuleCall('incus', __FUNCTION__, $params, 'Instance not found or server unreachable. Marking as suspended successfully.', $e->getMessage());
            return 'success';
        }
        logModuleCall('incus', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
return "Suspend Failed: " . $e->getMessage();
    }
    return 'success';
}

function incus_UnsuspendAccount($params) {
    try {
        $api = _incus_init_api($params);
$instanceName = $params['domain'];
        if (empty($instanceName)) return 'Instance name not found, cannot unsuspend.';
        $usage = Capsule::table('mod_incus_nat_usage')->where('service_id', $params['serviceid'])->first();
if ($usage && $usage->is_limited) {
            return "Cannot unsuspend: Service is currently suspended due to exceeding bandwidth limit.";
}
        $api->setInstanceState($instanceName, 'start');
} catch (Exception $e) {
        // 如果实例不存在（404）或服务器无法访问（cURL 错误）， 则没有什么需要取消暂停的。将其视为成功。
        if (strpos($e->getMessage(), '404') !== false || strpos($e->getMessage(), 'cURL Error') !== false) {
            logModuleCall('incus', __FUNCTION__, $params, 'Instance not found or server unreachable. Marking as unsuspended successfully.', $e->getMessage());
            return 'success';
        }
        logModuleCall('incus', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
return "Unsuspend Failed: " . $e->getMessage();
    }
    return 'success';
}

function incus_ChangePassword($params) {
    try {
        $api = _incus_init_api($params);
        $instanceName = $params['domain'];
        $newPassword = $params['password'];
        if (empty($instanceName) || empty($newPassword)) {
            return "Instance details or new password missing.";
        }
        $state = $api->getInstanceState($instanceName);
        if ($state['metadata']['status'] !== 'Running') {
            return "Instance is not running.";
        }
        $command = ['/bin/sh', '-c', "echo 'root:{$newPassword}' | chpasswd"];
        $api->executeCommand($instanceName, $command);
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update(['password' => encrypt($newPassword)]);
    } catch (Exception $e) {
        logModuleCall('incus', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "API Error: " . $e->getMessage();
    }
    return 'success';
}

function incus_AdminCustomButtonArray() {
    return [];
}

// --- AJAX 请求路由器和处理程序 ---

function incus_ajax_router($params) {
    $ca = new WHMCS\ClientArea();
    if (!$ca->isLoggedIn()) {
        return ['error' => 'Authorization required.'];
    }
    $service = Capsule::table('tblhosting')->where('id', $params['serviceid'])->first();
    if (is_null($service) || $service->userid != $ca->getUserID()) {
        return ['error' => 'Permission Denied.'];
    }
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    try {
        switch ($action) {
            case 'metrics':
                return incus_get_metrics($params);
            case 'changepw':
                return incus_do_changepw($params);
            case 'reinstall':
                return incus_do_reinstall($params);
            default:
                return ['error' => 'Unknown action.'];
        }
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

function incus_get_metrics($params) {
    $instanceName = $params['domain'];
    if (empty($instanceName)) {
        return ['error' => 'Instance not assigned.'];
    }
    $api = _incus_init_api($params);
    $parsedMetrics = [];
    $instanceState = $api->getInstanceState($instanceName);
    if (isset($instanceState['metadata']['memory'])) {
        $parsedMetrics['incus_memory_usage_bytes'] = $instanceState['metadata']['memory']['usage'];
        $parsedMetrics['incus_memory_limit_bytes'] = $instanceState['metadata']['memory']['total'];
    }
    if (isset($instanceState['metadata']['disk']['root'])) {
        $parsedMetrics['disk_usage_bytes'] = $instanceState['metadata']['disk']['root']['usage'];
    }
    $instanceDetails = $api->getInstance($instanceName);
    $rootDevice = $instanceDetails['metadata']['devices']['root'] ?? null;
    if ($rootDevice && isset($rootDevice['size'])) {
        $sizeStr = $rootDevice['size'];
        $sizeBytes = 0;
        if (preg_match('/(\d+)\s*GB/i', $sizeStr, $matches)) {
            $sizeBytes = $matches[1] * 1024 * 1024 * 1024;
        } elseif (preg_match('/(\d+)\s*MB/i', $sizeStr, $matches)) {
            $sizeBytes = $matches[1] * 1024 * 1024;
        }
        $parsedMetrics['disk_limit_bytes'] = $sizeBytes;
    }
    $metricsText = $api->getGlobalMetrics();
    $lines = explode("\n", $metricsText);

    // 初始化网络流量计数器
    $parsedMetrics['incus_network_received_bytes_total'] = 0;
    $parsedMetrics['incus_network_sent_bytes_total'] = 0;

    foreach ($lines as $line) {
        if (empty($line) || $line[0] === '#' || strpos($line, "name=\"{$instanceName}\"") === false) {
            continue;
        }
        if (strpos($line, ' ') === false) {
            continue;
        }
        list($key, $value) = explode(" ", $line, 2);
        if (strpos($key, 'incus_cpu_seconds_total') === 0) {
            $parsedMetrics['incus_cpu_seconds_total'] = ($parsedMetrics['incus_cpu_seconds_total'] ?? 0) + (float)$value;
        
        // 将各个接口的流量累加到总数中
        } elseif (strpos($key, 'incus_network_receive_bytes_total{') === 0 && strpos($key, 'device="lo"') === false) {
            $parsedMetrics['incus_network_received_bytes_total'] += (float)$value;
        } elseif (strpos($key, 'incus_network_transmit_bytes_total{') === 0 && strpos($key, 'device="lo"') === false) {
            $parsedMetrics['incus_network_sent_bytes_total'] += (float)$value;

        } elseif (strpos($key, 'incus_disk_read_bytes_total{') !== false) {
             $parsedMetrics['incus_disk_read_bytes_total'] = ($parsedMetrics['incus_disk_read_bytes_total'] ?? 0) + (float)$value;
        } elseif (strpos($key, 'incus_disk_written_bytes_total{') !== false) {
            $parsedMetrics['incus_disk_written_bytes_total'] = ($parsedMetrics['incus_disk_written_bytes_total'] ?? 0) + (float)$value;
        }
    }
    $bandwidth = [];
    if (($params['configoption2'] ?? 'off') == 'on') {
        $usage = Capsule::table('mod_incus_nat_usage')->where('service_id', $params['serviceid'])->first();
        if ($usage) {
            $quotaBytes = (intval($params['configoption3']) ?: 100) * 1024 * 1024 * 1024;
            $mode = $params['configoption4'] ?: 'out';
            $usedIn = $usage->usage_bytes_in;
            $usedOut = $usage->usage_bytes_out;
            if ($mode == 'out') $totalUsed = $usedOut;
            elseif ($mode == 'in') $totalUsed = $usedIn;
            else $totalUsed = $usedIn + $usedOut;
            $bandwidth = [
                'enabled' => true,
                'quota_gb' => $params['configoption3'],
                'quota_bytes' => $quotaBytes,
                'used_in_bytes' => $usedIn,
                'used_out_bytes' => $usedOut,
                'total_used_bytes' => $totalUsed,
                'percentage' => $quotaBytes > 0 ?
                round(($totalUsed / $quotaBytes) * 100, 2) : 0,
            ];
        }
    }
    if (empty($bandwidth)) {
        $bandwidth = ['enabled' => false];
    }
    $parsedMetrics['bandwidth'] = $bandwidth;
    return $parsedMetrics;
}

function incus_do_changepw($params) {
    $newPassword = trim($_POST['new_password'] ?? '');
    if (empty($newPassword)) {
        return ['success' => false, 'error' => 'Password cannot be empty.'];
    }
    $params['password'] = $newPassword;
    $result = incus_ChangePassword($params);
    if ($result === 'success') {
        return ['success' => true, 'message' => 'Password has been successfully changed!'];
    }
    return ['success' => false, 'error' => $result];
}


function incus_do_reinstall($params) {
    $imageAlias = trim($_POST['image_alias'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    if (empty($imageAlias) || empty($newPassword)) {
        return ['success' => false, 'error' => '您必须选择一个操作系统镜像并提供一个新密码。'];
    }
    try {
        ignore_user_abort(true);
        set_time_limit(300);
        $api = _incus_init_api($params);
        $instanceName = $params['domain'];
        
        // fastcgi_finish_request(); 
        
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update(['password' => encrypt($newPassword)]);
        $state = $api->getInstanceState($instanceName);
        if ($state['metadata']['status'] === 'Running') {
            $api->setInstanceState($instanceName, 'stop', 120);
        }
        $source = ['type' => 'image', 'alias' => $imageAlias];
        $api->rebuildInstance($instanceName, $source);
        $api->setInstanceState($instanceName, 'start', 120);
        sleep(20); 
        $command = ['/bin/sh', '-c', "echo 'root:{$newPassword}' | chpasswd"];
        $api->executeCommand($instanceName, $command);
    } catch (Exception $e) {
        logModuleCall('incus-ajax-reinstall', 'Reinstall-Error', $params['serviceid'], $e->getMessage());
        return ['success' => false, 'error' => '重装失败: ' . $e->getMessage()];
    }
    return ['success' => true, 'message' => '操作系统正在重装中，页面稍后将自动刷新。'];
}