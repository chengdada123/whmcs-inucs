<?php
/**
 * Incus 模块独立定时任务文件
 * 此脚本设计为由系统定时任务直接执行。
 * 它手动引导 WHMCS 环境以获取其函数和数据库访问权限。
 */

// --- 引导 WHMCS 环境 ---
$whmcs_path = realpath(dirname(__FILE__) . '/../../../');
if (file_exists($whmcs_path . '/init.php')) {
    require_once $whmcs_path . '/init.php';
} else {
    die("Could not find WHMCS init.php file. Please check the path.");
}

// 确保加载其他必需的模块文件
require_once __DIR__ . '/incus.php';
if (!class_exists('IncusAPI') && file_exists(__DIR__ . '/lib/IncusAPI.php')) {
    require_once __DIR__ . '/lib/IncusAPI.php';
}

// --- 主要执行逻辑---

logModuleCall('incus-standalone-cron', __FUNCTION__, 'Standalone cron job started.', '---');
try {
    // 使用 WHMCS 的数据库连接查询（Capsule ORM）
    $services = WHMCS\Database\Capsule::table('tblhosting as h')
        ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
        ->join('tblservers as s', 'h.server', '=', 's.id')
        ->where('p.servertype', 'incus')
        ->where('h.domainstatus', 'Active')
        ->where('p.configoption2', 'on')
        ->select('h.id as serviceid', 'h.domain as container_name', 's.hostname', 's.accesshash', 's.password as serverpassword', 's.secure')
        ->get();

    if ($services->isEmpty()) {
        logModuleCall('incus-standalone-cron', __FUNCTION__, 'No active services with bandwidth monitoring enabled were found. Exiting.', '');
        exit;
    }

    logModuleCall('incus-standalone-cron', __FUNCTION__, 'Found ' . $services->count() . ' services to process.', $services->pluck('container_name')->all());
    foreach ($services as $service) {
        try {
            $instanceName = $service->container_name;
            logModuleCall('incus-standalone-cron', __FUNCTION__, "Processing service ID: {$service->serviceid} (Container: {$instanceName})", '');

            $usageRecord = WHMCS\Database\Capsule::table('mod_incus_nat_usage')
                ->where('service_id', $service->serviceid)
                ->first();
            if (!$usageRecord || empty($instanceName)) {
                logModuleCall('incus-standalone-cron', __FUNCTION__, "Skipping service ID: {$service->serviceid}. Reason: No usage record found in DB or container name is empty.", '');
                continue;
            }
            
            // API 的参数数组现在使用 WHMCS 的 `decrypt` 函数
            $params = [
                'serverhostname' => $service->hostname,
                'serveraccesshash' => $service->accesshash,
                'serverpassword' => decrypt($service->serverpassword),
                'serversecure' => $service->secure,
            ];
            $api = _incus_init_api($params);

            $metricsText = $api->getGlobalMetrics();
            $lines = explode("\n", $metricsText);
            
            $currentIn = null;
            $currentOut = null;

            // 汇总所有非 lo 设备的流量
            foreach ($lines as $line) {
                if (empty($line) || $line[0] === '#' || strpos($line, "name=\"{$instanceName}\"") === false) continue;
                if (strpos($line, ' ') === false) continue;

                list($key, $value) = explode(" ", $line, 2);
                
                if (strpos($key, 'incus_network_receive_bytes_total{') === 0 && strpos($key, 'device="lo"') === false) {
                    $currentIn = ($currentIn ?? 0) + (int)$value;
                }
                
                if (strpos($key, 'incus_network_transmit_bytes_total{') === 0 && strpos($key, 'device="lo"') === false) {
                    $currentOut = ($currentOut ?? 0) + (int)$value;
                }
            }
            
            if ($currentIn === null || $currentOut === null) {
            // -- 代码修改区域 END --
                logModuleCall('incus-standalone-cron', __FUNCTION__, "Update skipped for {$instanceName}: Could not find network counters in metrics.", '');
                continue;
            }
            
            $lastIn = (int)$usageRecord->last_bytes_in;
            $lastOut = (int)$usageRecord->last_bytes_out;
            $deltaIn = ($currentIn >= $lastIn) ? ($currentIn - $lastIn) : $currentIn;
            $deltaOut = ($currentOut >= $lastOut) ? ($currentOut - $lastOut) : $currentOut;
            
            if ($deltaIn === 0 && $deltaOut === 0 && $usageRecord->last_update > 0) {
                continue; // 如果没有新流量则跳过更新
            }

            $newUsageIn = $usageRecord->usage_bytes_in + $deltaIn;
            $newUsageOut = $usageRecord->usage_bytes_out + $deltaOut;
            
            // 使用 WHMCS 的 Capsule 更新数据库
            WHMCS\Database\Capsule::table('mod_incus_nat_usage')
                ->where('id', $usageRecord->id)
                ->update([
                    'usage_bytes_in' => $newUsageIn,
                    'usage_bytes_out' => $newUsageOut,
                    'last_bytes_in' => $currentIn,
                    'last_bytes_out' => $currentOut,
                    'last_update' => time(),
                ]);

            logModuleCall('incus-standalone-cron', __FUNCTION__, "Database updated successfully for {$instanceName}.", "New Usage In: {$newUsageIn}, New Usage Out: {$newUsageOut}");
        } catch (Exception $e) {
            logModuleCall('incus-standalone-cron', __FUNCTION__, "Error processing service ID {$service->serviceid}:", $e->getMessage());
        }
    }

} catch (Exception $e) {
    logModuleCall('incus-standalone-cron', __FUNCTION__, 'A fatal error occurred:', $e->getMessage());
}

logModuleCall('incus-standalone-cron', __FUNCTION__, 'Standalone cron job finished.', '---');