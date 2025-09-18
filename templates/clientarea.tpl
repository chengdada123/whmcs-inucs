{* /modules/servers/incus/templates/clientarea.tpl *}
{if $notification}
    <div class="alert {if strpos($notification|lower, 'failed') !== false or strpos($notification|lower, 'error') !== false}alert-danger{else}alert-success{/if}">{$notification}</div>
{/if}

<div id="ajax-notification" style="display: none; margin-top: 15px;"></div>


{* 初始化所有变量 *}
{assign var="publicIp4" value=""}
{assign var="privateIp4" value="N/A"}
{assign var="publicIp6" value=""}
{assign var="privateIp6" value="N/A"}
{assign var="sshPort" value=""}
{assign var="natTcpPorts" value=""}
{assign var="natUdpPorts" value=""}

{* 1. 从代理设备获取公网 IPv4 和端口 *}
{foreach from=$instance.devices item=device key=deviceName}
    {if $device.type == 'proxy' and isset($device.listen)}
        {if strpos($device.listen, '[') === false}
            {$parts = ":"|explode:$device.listen}
            {if count($parts) == 3}
                {if !$publicIp4}{assign var="publicIp4" value=$parts[1]}{/if}
            {/if}
        {/if}
        {$port_parts = ":"|explode:$device.listen}
        {$port = $port_parts|@end}
        {if $deviceName == 'ssh-port'}{assign var="sshPort" value=$port}{/if}
        {if $deviceName == 'nattcp-ports'}{assign var="natTcpPorts" value=$port}{/if}
        {if $deviceName == 'natudp-ports'}{assign var="natUdpPorts" value=$port}{/if}
    {/if}
{/foreach}

{* 2. 从所有接口状态获取内网 IPv4 和所有 IPv6 *}
{foreach from=$state.network item=interface key=iface_name}
    {if $iface_name != 'lo' and isset($interface.addresses)}
        {foreach from=$interface.addresses item=address}
            {if $address.scope == 'global'}
                {if $address.family == 'inet'}
                    {assign var="privateIp4" value=$address.address}
                {elseif $address.family == 'inet6'}
                    {* 使用 truncate 修饰符检查公网 GUA（以 2xxx 或 3xxx 开头）*}
                    {$ip_prefix_char = $address.address|truncate:1:''}
                    {if ($ip_prefix_char == '2' or $ip_prefix_char == '3') and !$publicIp6}
                        {assign var="publicIp6" value=$address.address}
                    {* 检查私有 ULA（以 fdxx 开头）*}
                    {elseif $address.address|truncate:2:'' == 'fd' and $privateIp6 == "N/A"}
                        {assign var="privateIp6" value=$address.address}
                    {/if}
                {/if}
            {/if}
        {/foreach}
    {/if}
{/foreach}


<div class="row">
    {* --- 左列现在包含服务器信息 --- *}
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">服务器信息 (Server Information)</h3></div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <tbody>
                        <tr><td>主机名 (Hostname)</td><td>{$instance.name}</td></tr>
                        {if $publicIp4}<tr><td>公网 (Public) IPv4</td><td>{$publicIp4}</td></tr>{/if}
                        {if $publicIp6}<tr><td>公网 (Public) IPv6</td><td>{$publicIp6}</td></tr>{/if}
                        <!--
                        {if $privateIp4 != "N/A"}<tr><td>内网 (Private) IPv4</td><td>{$privateIp4}</td></tr>{/if}
                        {if $privateIp6 != "N/A"}<tr><td>内网 (Private) IPv6</td><td>{$privateIp6}</td></tr>{/if}
                        -->
                        <tr><td>用户名 (Username)</td><td>root</td></tr>
                        {if $sshPort}<tr><td>SSH 端口</td><td>{$sshPort}</td></tr>{/if}
                        {if $natTcpPorts}<tr><td>TCP 端口范围</td><td>{$natTcpPorts}</td></tr>{/if}
                        {if $natUdpPorts}<tr><td>UDP 端口范围</td><td>{$natUdpPorts}</td></tr>{/if}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {* --- 结束 --- *}

    {* --- 右列现在包含服务器管理和带宽 --- *}
    <div class="col-md-6">
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">服务器管理 (Server Management)</h3></div>
            <div class="panel-body">
                <p><strong>状态 (Status):</strong>
                    {if $state.status eq "Running"}
                        <span class="label label-success">运行中 (Running)</span>
                    {elseif $state.status eq "Stopped"}
                        <span class="label label-danger">已停止 (Stopped)</span>
                    {else}
                        <span class="label label-warning">{$state.status}</span>
                    {/if}
                </p>
                <form id="instanceActionForm" method="post" action="clientarea.php?action=productdetails&id={$serviceid}">
                    <input type="hidden" name="mod_action" id="mod_action_input">
                    <div class="btn-group" role="group">
                        {if $state.status neq "Running"}
                            <button type="button" onclick="submitAction('start')" class="btn btn-success"><i class="fas fa-play"></i> 启动</button>
                        {/if}
                        {if $state.status eq "Running"}
                            <button type="button" onclick="if(confirm('您确定要停止此服务器吗?')) submitAction('stop')" class="btn btn-danger"><i class="fas fa-stop"></i> 停止</button>
                            <button type="button" onclick="if(confirm('您确定要重启此服务器吗?')) submitAction('restart')" class="btn btn-warning"><i class="fas fa-sync"></i> 重启</button>
                        {/if}
                        {if $images}
                            <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#reinstallModal"><i class="fas fa-redo"></i> 重装</button>
                        {/if}
                        <button type="button" class="btn btn-info" data-toggle="modal" data-target="#changePasswordModal"><i class="fas fa-key"></i> 修改密码</button>
                    </div>
                </form>
            </div>
        </div>

        {if $bandwidth.enabled}
        <style>
        {literal}
            .bandwidth-info{display:flex;justify-content:space-between;font-size:16px;margin-bottom:10px}.bandwidth-split{display:flex;height:20px;border-radius:4px;overflow:hidden;background-color:#f5f5f5}.bandwidth-in{background-color:#5bc0de}.bandwidth-out{background-color:#d9534f}.bandwidth-legend{text-align:center;margin-top:10px}.legend-item{display:inline-block;margin:0 15px;font-size:14px}.legend-dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:5px;vertical-align:middle}
        {/literal}
        </style>
        <div class="panel panel-default">
            <div class="panel-heading"><h3 class="panel-title">流量使用情况 (Bandwidth Usage)</h3></div>
            <div class="panel-body">
                <div class="bandwidth-info">
                    <span id="bwUsed">...</span>
                    <span id="bwQuota">...</span>
                </div>
                <div class="progress" style="margin-bottom: 5px;">
                    <div id="bwProgressBar" class="progress-bar" role="progressbar" style="width: 0%; min-width: 2em;">0%</div>
                </div>
                <div class="bandwidth-split">
                    <div id="bwInBar" class="bandwidth-in" style="width: 50%;" data-toggle="tooltip" title="入站流量 (Inbound)"></div>
                    <div id="bwOutBar" class="bandwidth-out" style="width: 50%;" data-toggle="tooltip" title="出站流量 (Outbound)"></div>
                </div>
                <div class="bandwidth-legend">
                    <div class="legend-item"><span class="legend-dot" style="background-color: #5bc0de;"></span>入站 (IN): <strong id="bwInPercent">...</strong></div>
                    <div class="legend-item"><span class="legend-dot" style="background-color: #d9534f;"></span>出站 (OUT): <strong id="bwOutPercent">...</strong></div>
                </div>
            </div>
        </div>
        {/if}
    </div>
    {* --- 结束 --- *}
</div>

<div class="panel panel-default">
    <div class="panel-heading"><h3 class="panel-title">实时监控 (Real-Time Monitoring)</h3></div>
    <div class="panel-body">
        <div class="row">
            <div class="col-md-6"><h4>CPU 使用率 (CPU Usage)</h4><canvas id="cpuChart" height="150"></canvas></div>
            <div class="col-md-6">
                <h4>内存使用 (Memory Usage)</h4>
                <p><strong>已用:</strong> <span id="memUsed">...</span> / <span id="memTotal">...</span></p>
                <div class="progress"><div id="memProgressBar" class="progress-bar" role="progressbar" style="width: 0%;"></div></div>
                <h4 class="m-t-20">硬盘使用 (Disk Usage)</h4>
                <p><strong>已用:</strong> <span id="diskUsed">...</span> / <span id="diskTotal">...</span></p>
                <div class="progress"><div id="diskProgressBar" class="progress-bar progress-bar-info" role="progressbar" style="width: 0%;"></div></div>
            </div>
        </div>
        <hr>
         <div class="row">
            <div class="col-md-6">
                <h4 title="当前开机累计消耗的流量，重启后次数会清零重新统计">网络流量 (Network Traffic)</h4>
                <p><i class="fas fa-arrow-down text-info"></i> <strong>总接收:</strong> <span id="netRx">...</span></p>
                <p><i class="fas fa-arrow-up text-danger"></i> <strong>总发送:</strong> <span id="netTx">...</span></p>
            </div>
             <div class="col-md-6">
                <h4>磁盘 I/O (Disk I/O)</h4>
                <p><strong>总读取:</strong> <span id="diskRead">...</span></p>
                <p><strong>总写入:</strong> <span id="diskWrite">...</span></p>
            </div>
        </div>
    </div>
</div>

{if $images}
<div class="modal fade" id="reinstallModal" tabindex="-1" role="dialog" aria-labelledby="reinstallModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form>
                <div class="modal-header"><h4 class="modal-title" id="reinstallModalLabel">Reinstall Operating System</h4><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                <div class="modal-body">
                    <div id="reinstallAjax-notification" style="display: none;"></div>
                    <div class="alert alert-danger"><strong>Warning:</strong> This action will destroy all data on your server!</div>
                    <div class="form-group"><label for="ajax_image_alias">Select an OS Image:</label><select id="ajax_image_alias" class="form-control">{foreach from=$images item=image}<option value="{$image.alias}">{$image.description}</option>{/foreach}</select></div>
                    <div class="form-group"><label for="ajax_reinstall_password">Set New Root Password (Required):</label><div class="input-group"><input type="text" id="ajax_reinstall_password" class="form-control" placeholder="Enter or generate a new password" required><span class="input-group-btn"><button class="btn btn-default" type="button" onclick="generateRandomPassword('ajax_reinstall_password')"><i class="fas fa-random"></i> Generate</button></span></div></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="button" id="ajax-reinstall-submit" class="btn btn-danger" onclick="reinstallAjax()">Confirm Reinstall</button>
                </div>
            </form>
        </div>
    </div>
</div>
{/if}

<div class="modal fade" id="changePasswordModal" tabindex="-1" role="dialog" aria-labelledby="changePasswordModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form onsubmit="changePasswordAjax(); return false;">
                <div class="modal-header"><h4 class="modal-title" id="changePasswordModalLabel">Change Password</h4><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
                <div class="modal-body">
                    <div id="changePasswordAjax-notification" style="display: none;"></div>
                    <div class="form-group"><label for="ajax_new_password">New Password</label><div class="input-group"><input type="text" id="ajax_new_password" class="form-control" placeholder="Enter or generate a new password" required><span class="input-group-btn"><button class="btn btn-default" type="button" onclick="generateRandomPassword('ajax_new_password')"><i class="fas fa-random"></i> Generate</button></span></div></div>
                    <div class="form-group"><label for="ajax_confirm_password">Confirm New Password</label><input type="text" id="ajax_confirm_password" class="form-control" placeholder="Enter the new password again" required></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" id="ajax-changepw-submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@2.9.4"></script>
<script type="text/javascript">
    const isBandwidthEnabled = {if $bandwidth.enabled}true{else}false{/if};
    const staticBandwidthData = {
        total_used_bytes: {$bandwidth.total_used_bytes|default:0},
        quota_gb: {$bandwidth.quota_gb|default:0},
        percentage: {$bandwidth.percentage|default:0},
        used_in_bytes: {$bandwidth.used_in_bytes|default:0},
        used_out_bytes: {$bandwidth.used_out_bytes|default:0}
    };
    function submitAction(action) {
        document.getElementById('mod_action_input').value = action;
        document.getElementById('instanceActionForm').submit();
    }
    function formatBytes(bytes, decimals = 2) {
        if (bytes === undefined || bytes === null || bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
    let cpuChartInstance;
    const cpuChartData = {
        labels: [],
        datasets: [{
            label: 'CPU Usage (seconds)', data: [],
            backgroundColor: 'rgba(54, 162, 235, 0.2)', borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1, fill: true,
        }]
    };
    function initCpuChart() {
        const ctx = document.getElementById('cpuChart').getContext('2d');
        cpuChartInstance = new Chart(ctx, {
            type: 'line', data: cpuChartData, options: { scales: { xAxes: [{ ticks: { callback: function(value) { return new Date(value).toLocaleTimeString(); } } }], yAxes: [{ ticks: { beginAtZero: true, callback: function(value) { return value.toFixed(2) + 's'; } } }] }, tooltips: { callbacks: { label: function(tooltipItem) { return 'Usage: ' + tooltipItem.yLabel.toFixed(4) + 's'; } } } }
        });
    }
    let metricsInterval;
    function updateMetrics() {
        if ('{$state.status}' !== 'Running') {
            if (metricsInterval) clearInterval(metricsInterval);
            return;
        }
        fetch('modules/servers/incus/index.php?action=metrics&serviceid={$serviceid}')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error("API Error:", data.error);
                    return;
                }
                // 更新标准指标
                if (data.hasOwnProperty('incus_memory_usage_bytes')) {
                    const memUsed = data.incus_memory_usage_bytes, memTotal = data.incus_memory_limit_bytes, memPercent = memTotal > 0 ? (memUsed / memTotal) * 100 : 0;
                    document.getElementById('memUsed').textContent = formatBytes(memUsed);
                    document.getElementById('memTotal').textContent = formatBytes(memTotal);
                    document.getElementById('memProgressBar').style.width = memPercent + '%';
                }
                if (data.hasOwnProperty('disk_usage_bytes')) {
                    const diskUsed = data.disk_usage_bytes, diskTotal = data.disk_limit_bytes, diskPercent = diskTotal > 0 ? (diskUsed / diskTotal) * 100 : 0;
                    document.getElementById('diskUsed').textContent = formatBytes(diskUsed);
                    document.getElementById('diskTotal').textContent = formatBytes(diskTotal);
                    document.getElementById('diskProgressBar').style.width = diskPercent + '%';
                }
                 // 更新恢复的指标
                if (data.hasOwnProperty('incus_network_received_bytes_total')) document.getElementById('netRx').textContent = formatBytes(data.incus_network_received_bytes_total);
                if (data.hasOwnProperty('incus_network_sent_bytes_total')) document.getElementById('netTx').textContent = formatBytes(data.incus_network_sent_bytes_total);
                if (data.hasOwnProperty('incus_disk_read_bytes_total')) document.getElementById('diskRead').textContent = formatBytes(data.incus_disk_read_bytes_total);
                if (data.hasOwnProperty('incus_disk_written_bytes_total')) document.getElementById('diskWrite').textContent = formatBytes(data.incus_disk_written_bytes_total);
                // 更新 CPU 图表
                if (data.hasOwnProperty('incus_cpu_seconds_total')) {
                    const now = new Date().getTime(), cpuUsage = data.incus_cpu_seconds_total;
                    if (cpuChartData.labels.length > 20) { cpuChartData.labels.shift(); cpuChartData.datasets[0].data.shift(); }
                    cpuChartData.labels.push(now);
                    cpuChartData.datasets[0].data.push(cpuUsage);
                    cpuChartInstance.update();
                }
                // 更新带宽面板
                if (data.hasOwnProperty('bandwidth') && data.bandwidth.enabled) {
                    const bw = data.bandwidth;
                    document.getElementById('bwUsed').textContent = '已用: ' + formatBytes(bw.total_used_bytes);
                    document.getElementById('bwQuota').textContent = '总共: ' + bw.quota_gb + ' GB';
                    const percent = bw.percentage.toFixed(2);
                    const bwProgressBar = document.getElementById('bwProgressBar');
                    bwProgressBar.style.width = percent + '%';
                    bwProgressBar.textContent = percent + '%';
                    const totalBwForSplit = (bw.used_in_bytes + bw.used_out_bytes) || 1;
                    const inSplitPercent = (bw.used_in_bytes / totalBwForSplit) * 100;
                    const outSplitPercent = 100 - inSplitPercent;
                    document.getElementById('bwInBar').style.width = inSplitPercent + '%';
                    document.getElementById('bwOutBar').style.width = outSplitPercent + '%';
                    document.getElementById('bwInPercent').textContent = formatBytes(bw.used_in_bytes) + ' (' + inSplitPercent.toFixed(1) + '%)';
                    document.getElementById('bwOutPercent').textContent = formatBytes(bw.used_out_bytes) + ' (' + outSplitPercent.toFixed(1) + '%)';
                }
            })
            .catch(error => console.error('Error fetching metrics:', error));
    }
    function generateRandomPassword(elementId) {
        var length = 12, charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()", retVal = "";
        for (var i = 0, n = charset.length; i < length; ++i) { retVal += charset.charAt(Math.floor(Math.random() * n)); }
        document.getElementById(elementId).value = retVal;
        if(elementId === 'ajax_new_password') { document.getElementById('ajax_confirm_password').value = retVal; }
    }
    function changePasswordAjax() {
        var newPass = document.getElementById('ajax_new_password').value;
        var confirmPass = document.getElementById('ajax_confirm_password').value;
        var modalNotificationDiv = document.getElementById('changePasswordAjax-notification');
        var submitButton = document.getElementById('ajax-changepw-submit');
        if (!newPass || !confirmPass) {
            modalNotificationDiv.innerHTML = '<div class="alert alert-danger">Password fields cannot be empty.</div>';
            modalNotificationDiv.style.display = 'block';
            return;
        }
        if (newPass !== confirmPass) {
            modalNotificationDiv.innerHTML = '<div class="alert alert-danger">The passwords do not match.</div>';
            modalNotificationDiv.style.display = 'block';
            return;
        }
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        modalNotificationDiv.style.display = 'none';
        var formData = new FormData();
        formData.append('new_password', newPass);
        fetch('modules/servers/incus/index.php?action=changepw&serviceid={$serviceid}', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            var mainNotificationDiv = document.getElementById('ajax-notification');
            if (data.success) {
                $('#changePasswordModal').modal('hide');
                mainNotificationDiv.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
                mainNotificationDiv.style.display = 'block';
                setTimeout(function() { location.reload(); }, 2000);
            } else {
                modalNotificationDiv.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                modalNotificationDiv.style.display = 'block';
            }
        })
        .finally(() => {
            submitButton.disabled = false;
            submitButton.innerHTML = 'Save Changes';
        });
    }
    function reinstallAjax() {
        var imageAlias = document.getElementById('ajax_image_alias').value;
        var newPass = document.getElementById('ajax_reinstall_password').value;
        var modalNotificationDiv = document.getElementById('reinstallAjax-notification');
        var submitButton = document.getElementById('ajax-reinstall-submit');
        if (!imageAlias || !newPass) {
            modalNotificationDiv.innerHTML = '<div class="alert alert-danger">You must select an OS image and provide a new password.</div>';
            modalNotificationDiv.style.display = 'block';
            return;
        }
        submitButton.disabled = true;
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        modalNotificationDiv.style.display = 'none';
        var formData = new FormData();
        formData.append('image_alias', imageAlias);
        formData.append('new_password', newPass);
        // 第一次获取只是发送请求并获得立即的"已接受"响应
        fetch('modules/servers/incus/index.php?action=reinstall&serviceid={$serviceid}', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            var mainNotificationDiv = document.getElementById('ajax-notification');
            if (data.success) {
                $('#reinstallModal').modal('hide');
                mainNotificationDiv.innerHTML = '<div class="alert alert-success">' + data.message + '</div>';
                mainNotificationDiv.style.display = 'block';
                // 停止指标轮询，因为服务器正在重启
                 if (metricsInterval) clearInterval(metricsInterval);
                 setTimeout(function() { location.reload(); }, 5000); // 延迟后重新加载页面
            } else {
                modalNotificationDiv.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
                modalNotificationDiv.style.display = 'block';
                submitButton.disabled = false;
                submitButton.innerHTML = 'Confirm Reinstall';
            }
        })
        .catch(error => {
            console.error('AJAX Error:', error);
            modalNotificationDiv.innerHTML = '<div class="alert alert-danger">An unknown error occurred. Please check the browser console.</div>';
            modalNotificationDiv.style.display = 'block';
            submitButton.disabled = false;
            submitButton.innerHTML = 'Confirm Reinstall';
        });
    }
    document.addEventListener('DOMContentLoaded', function() {
        var actionsPanel = document.querySelector('[menuitemname="Service Details Actions"]');
        if (actionsPanel) { actionsPanel.style.display = 'none'; }
        if (typeof $ == 'function') {
            $('[data-toggle="tooltip"]').tooltip();
        }
        initCpuChart();
        if ('{$state.status}' === 'Running') {
            updateMetrics();
            metricsInterval = setInterval(updateMetrics, 10000);
        } else {
            const naFields = ['memUsed', 'memTotal', 'diskUsed', 'diskTotal', 'netRx', 'netTx', 'diskRead', 'diskWrite'];
            naFields.forEach(id => {
                const el = document.getElementById(id);
                if (el) el.textContent = 'N/A';
            });
            if (isBandwidthEnabled) {
                document.getElementById('bwUsed').textContent = '已用: ' + formatBytes(staticBandwidthData.total_used_bytes);
                document.getElementById('bwQuota').textContent = '总共: ' + staticBandwidthData.quota_gb + ' GB';
                const percent = staticBandwidthData.percentage.toFixed(2);
                const bwProgressBar = document.getElementById('bwProgressBar');
                bwProgressBar.style.width = percent + '%';
                bwProgressBar.textContent = percent + '%';
                const totalBwForSplit = (staticBandwidthData.used_in_bytes + staticBandwidthData.used_out_bytes) || 1;
                const inSplitPercent = (staticBandwidthData.used_in_bytes / totalBwForSplit) * 100;
                const outSplitPercent = 100 - inSplitPercent;
                document.getElementById('bwInBar').style.width = inSplitPercent + '%';
                document.getElementById('bwOutBar').style.width = outSplitPercent + '%';
                document.getElementById('bwInPercent').textContent = formatBytes(staticBandwidthData.used_in_bytes) + ' (' + inSplitPercent.toFixed(1) + '%)';
                document.getElementById('bwOutPercent').textContent = formatBytes(staticBandwidthData.used_out_bytes) + ' (' + outSplitPercent.toFixed(1) + '%)';
            }
        }
    });
</script>