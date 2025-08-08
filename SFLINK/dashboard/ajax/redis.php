<?php
/**
 * Redis Connection for SFLINK.ID Dashboard
 * Optimized with Fallback System
 */

// Cek apakah Redis extension tersedia
if (!extension_loaded('redis')) {
    error_log("Redis extension not installed, using fallback");
    
    // Mock Redis class untuk fallback
    class MockRedis {
        private $data = [];
        
        public function get($key) {
            // Selalu return false untuk force database query
            return false;
        }
        
        public function set($key, $value, $ttl = 0) {
            // Simpan di memory sementara (hilang setelah request)
            $this->data[$key] = $value;
            return true;
        }
        
        public function del($key) {
            unset($this->data[$key]);
            return true;
        }
        
        public function exists($key) {
            return isset($this->data[$key]);
        }
        
        public function ping() {
            return false;
        }
        
        public function flushAll() {
            $this->data = [];
            return true;
        }
    }
    
    $redis = new MockRedis();
    
} else {
    // Redis extension ada, coba connect
    try {
        $redis = new Redis();
        
        // Konfigurasi koneksi Redis
        $redisHost = '127.0.0.1';      // Redis server host
        $redisPort = 6379;             // Redis server port
        $redisTimeout = 3;             // Connection timeout
        
        // Connect ke Redis server
        $connected = $redis->connect($redisHost, $redisPort, $redisTimeout);
        
        if (!$connected) {
            throw new Exception("Failed to connect to Redis server");
        }
        
        // Optional: Set password jika Redis server punya auth
        // $redis->auth('your_redis_password');
        
        // Select database (0-15, default 0)
        $redis->select(0);
        
        // Set connection options
        $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
        $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
        
        // Optional: Set key prefix untuk avoid collision
        $redis->setOption(Redis::OPT_PREFIX, 'sflink:');
        
        // Test ping untuk memastikan koneksi work
        $pingResult = $redis->ping();
        if (!$pingResult || $pingResult !== '+PONG') {
            throw new Exception("Redis ping test failed");
        }
        
        // Connection successful
        error_log("Redis connected successfully to {$redisHost}:{$redisPort}");
        
    } catch (Exception $e) {
        // Redis connection failed, use fallback
        error_log("Redis connection error: " . $e->getMessage());
        
        // Fallback Mock Redis
        class MockRedis {
            private $data = [];
            
            public function get($key) {
                return $this->data[$key] ?? false;
            }
            
            public function set($key, $value, $ttl = 0) {
                $this->data[$key] = $value;
                return true;
            }
            
            public function del($key) {
                unset($this->data[$key]);
                return true;
            }
            
            public function exists($key) {
                return isset($this->data[$key]);
            }
            
            public function ping() {
                return false;
            }
            
            public function flushAll() {
                $this->data = [];
                return true;
            }
        }
        
        error_log("Using MockRedis fallback due to connection failure");
        $redis = new MockRedis();
    }
}

// Helper functions untuk easier cache management
function setDashboardCache($key, $data, $ttl = 300) {
    global $redis;
    try {
        return $redis->set($key, json_encode($data), $ttl);
    } catch (Exception $e) {
        error_log("Redis SET error for key '{$key}': " . $e->getMessage());
        return false;
    }
}

function getDashboardCache($key) {
    global $redis;
    try {
        $data = $redis->get($key);
        return $data ? json_decode($data, true) : false;
    } catch (Exception $e) {
        error_log("Redis GET error for key '{$key}': " . $e->getMessage());
        return false;
    }
}

function deleteDashboardCache($key) {
    global $redis;
    try {
        return $redis->del($key);
    } catch (Exception $e) {
        error_log("Redis DEL error for key '{$key}': " . $e->getMessage());
        return false;
    }
}

function clearUserDashboardCache($userId) {
    global $redis;
    try {
        $keys = [
            "dashboard:main:{$userId}",
            "dashboard:heavy:{$userId}",
            "dashboard:charts:{$userId}",
            "recent_links:{$userId}"
        ];
        
        $deleted = 0;
        foreach ($keys as $key) {
            if ($redis->del($key)) {
                $deleted++;
            }
        }
        
        error_log("Cleared {$deleted} cache keys for user {$userId}");
        return $deleted;
        
    } catch (Exception $e) {
        error_log("Clear user cache error for user {$userId}: " . $e->getMessage());
        return false;
    }
}

// Function untuk monitoring Redis health
function getRedisInfo() {
    global $redis;
    try {
        if (method_exists($redis, 'info')) {
            return $redis->info();
        } else {
            return ['status' => 'mock_redis', 'connected' => false];
        }
    } catch (Exception $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
?>