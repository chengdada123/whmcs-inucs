<?php

if (!class_exists('IncusAPI')) {
    /**
     * Incus API 包装器类
     * 处理与 Incus API 的所有 cURL 通信，包括异步操作。
     */
    class IncusAPI {
        private $remote_url;
        private $ssl_cert_path;
        private $ssl_key_path;
        private $verify_peer;

        public function __construct($remote_url, $ssl_cert_path, $ssl_key_path, $verify_peer = true) {
            if (empty($remote_url) || empty($ssl_cert_path) || empty($ssl_key_path)) {
                throw new Exception("API connection parameters are missing.");
            }
            if (!file_exists($ssl_cert_path) || !file_exists($ssl_key_path)) {
                throw new Exception("Client certificate or key file not found at the specified path. Cert: {$ssl_cert_path}, Key: {$ssl_key_path}");
            }
            $this->remote_url = rtrim($remote_url, '/');
            $this->ssl_cert_path = $ssl_cert_path;
            $this->ssl_key_path = $ssl_key_path;
            $this->verify_peer = (bool)$verify_peer;
        }

        public function request($method, $endpoint, $data = null) {
            $url = $this->remote_url . $endpoint;
            $ch = curl_init();
            
            $options = [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_SSLCERT => $this->ssl_cert_path,
                CURLOPT_SSLKEY => $this->ssl_key_path,
                CURLOPT_SSL_VERIFYPEER => $this->verify_peer,
                CURLOPT_SSL_VERIFYHOST => $this->verify_peer ? 2 : 0,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
            ];

            if ($data !== null) {
                $payload = json_encode($data);
                $options[CURLOPT_POSTFIELDS] = $payload;
                if (!in_array('Content-Length: ' . strlen($payload), $options[CURLOPT_HTTPHEADER])) {
                    $options[CURLOPT_HTTPHEADER][] = 'Content-Length: ' . strlen($payload);
                }
            }

            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new Exception("cURL Error: " . $error);
            }
            
            $decoded = json_decode($response, true);
            if ($decoded === null && !empty($response)) {
                 if ($http_code >= 400) throw new Exception("API Error (non-JSON): " . $response);
                 return $response;
            }
            
            if ($http_code >= 400) {
                $errorMessage = isset($decoded['error']) ? $decoded['error'] : "Received HTTP status code {$http_code}";
                if ($http_code === 404) $errorMessage = "Resource not found at {$endpoint} (404)";
                throw new Exception("API Error: " . $errorMessage);
            }

            return $decoded;
        }

        private function waitForOperation($operationUrl, $timeout = 300) {
            $startTime = time();
            while (time() - $startTime < $timeout) {
                try {
                    $op = $this->request('GET', $operationUrl);
                    if (isset($op['metadata']['status'])) {
                         $status = $op['metadata']['status'];
                         if ($status === 'Success') return $op;
                         if (in_array($status, ['Failure', 'Cancelled'])) {
                            $err = isset($op['metadata']['err']) ? $op['metadata']['err'] : 'Unknown operation failure.';
                            throw new Exception("Operation failed: " . $err);
                         }
                    }
                    sleep(2);
                } catch (Exception $e) {
                     if (strpos($e->getMessage(), '404') !== false) {
                         return ['status' => 'Success', 'message' => 'Operation likely completed too fast to track.'];
                     }
                     throw $e;
                }
            }
            throw new Exception("Operation timed out after {$timeout} seconds for {$operationUrl}.");
        }
        
        private function processResponse($response) {
            if (isset($response['type']) && $response['type'] === 'async' && isset($response['operation'])) {
                return $this->waitForOperation($response['operation']);
            }
            return $response;
        }

        public function createInstance($name, $config) {
            $payload = ['name' => $name, 'source' => $config['source'], 'type' => 'container',
                        'config' => $config['config'], 'devices' => $config['devices'], 'profiles' => $config['profiles']];
            return $this->processResponse($this->request('POST', '/1.0/instances', $payload));
        }

        public function getInstance($name) { return $this->request('GET', "/1.0/instances/" . rawurlencode($name)); }
        public function getInstanceState($name) { return $this->request('GET', "/1.0/instances/" . rawurlencode($name) . "/state"); }

        public function setInstanceState($name, $action, $timeout = 60) {
            $payload = ['action' => $action, 'timeout' => $timeout, 'force' => true];
            return $this->processResponse($this->request('PUT', "/1.0/instances/" . rawurlencode($name) . "/state", $payload));
        }

        public function updateInstance($name, $config) {
            return $this->processResponse($this->request('PATCH', "/1.0/instances/" . rawurlencode($name), $config));
        }

        public function deleteInstance($name) {
            return $this->processResponse($this->request('DELETE', "/1.0/instances/" . rawurlencode($name)));
        }
        
        public function rebuildInstance($name, $source) {
            $payload = ['source' => $source];
            return $this->processResponse($this->request('POST', "/1.0/instances/" . rawurlencode($name) . "/rebuild", $payload));
        }

        public function listSnapshots($instanceName) { return $this->request('GET', "/1.0/instances/" . rawurlencode($instanceName) . "/snapshots"); }
        public function createSnapshot($instanceName, $snapshotName) {
            $payload = ['name' => $snapshotName];
            return $this->processResponse($this->request('POST', "/1.0/instances/" . rawurlencode($instanceName) . "/snapshots", $payload));
        }
        public function restoreSnapshot($instanceName, $snapshotName) {
            $payload = ['action' => 'restore'];
            return $this->processResponse($this->request('PUT', "/1.0/instances/" . rawurlencode($instanceName) . "/snapshots/" . rawurlencode($snapshotName), $payload));
        }
        public function deleteSnapshot($instanceName, $snapshotName) {
            return $this->processResponse($this->request('DELETE', "/1.0/instances/" . rawurlencode($instanceName) . "/snapshots/" . rawurlencode($snapshotName)));
        }
        public function getInstances() {
            return $this->request('GET', '/1.0/instances');
        }
        public function getStorageVolume($poolName, $volumeType, $volumeName) {
            return $this->request('GET', "/1.0/storage-pools/" . rawurlencode($poolName) . "/volumes/" . rawurlencode($volumeType) . "/" . rawurlencode($volumeName));
        }
        public function executeCommand($instanceName, $command) {
            $payload = [
                'command' => $command,
                'environment' => ['HOME' => '/root', 'USER' => 'root'],
                'wait-for-websocket' => false,
                'interactive' => false,
            ];
            return $this->processResponse($this->request('POST', "/1.0/instances/" . rawurlencode($instanceName) . "/exec", $payload));
        }
        public function listImages() {
            return $this->request('GET', '/1.0/images');
        }
        public function getInstanceMetrics($name) {
            return $this->request('GET', "/1.0/instances/" . rawurlencode($name) . "/metrics");
        }
        public function getGlobalMetrics() {
            // 这个接口返回的是纯文本，而不是JSON
            return $this->request('GET', '/1.0/metrics');
        }
        public function getStorageVolumeUsage($poolName, $instanceName) {
            // 容器的存储卷类型是 'container'
            $volumeType = 'container';
            return $this->request('GET', "/1.0/storage-pools/" . rawurlencode($poolName) . "/volumes/" . rawurlencode($volumeType) . "/" . rawurlencode($instanceName));
        }        
    }
}