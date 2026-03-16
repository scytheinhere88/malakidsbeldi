<?php

class QueryCache {
    private static array $cache = [];
    private static int $hits = 0;
    private static int $misses = 0;
    private static $redis = null;
    private static bool $redisEnabled = false;

    private static function initRedis(): void {
        if (self::$redis === null && extension_loaded('redis')) {
            try {
                self::$redis = new Redis();
                $host = $_ENV['REDIS_HOST'] ?? 'localhost';
                $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
                self::$redis->connect($host, $port, 2);
                if (!empty($_ENV['REDIS_PASSWORD'])) {
                    self::$redis->auth($_ENV['REDIS_PASSWORD']);
                }
                self::$redisEnabled = true;
            } catch (Exception $e) {
                self::$redis = null;
                self::$redisEnabled = false;
            }
        }
    }

    public static function get(string $key) {
        self::initRedis();

        if (self::$redisEnabled && self::$redis) {
            try {
                $data = self::$redis->get("qc:{$key}");
                if ($data !== false) {
                    self::$hits++;
                    return unserialize($data);
                }
            } catch (Exception $e) {
            }
        }

        if (isset(self::$cache[$key])) {
            self::$hits++;
            $entry = self::$cache[$key];
            if ($entry['expires'] > time()) {
                return $entry['data'];
            }
            unset(self::$cache[$key]);
        }
        self::$misses++;
        return null;
    }

    public static function set(string $key, $data, int $ttlSeconds = 300): void {
        self::initRedis();

        if (self::$redisEnabled && self::$redis) {
            try {
                self::$redis->setex("qc:{$key}", $ttlSeconds, serialize($data));
            } catch (Exception $e) {
            }
        }

        self::$cache[$key] = [
            'data' => $data,
            'expires' => time() + $ttlSeconds,
            'created' => time()
        ];

        if (count(self::$cache) > 1000) {
            self::cleanup();
        }
    }

    public static function delete(string $key): void {
        self::initRedis();

        if (self::$redisEnabled && self::$redis) {
            try {
                self::$redis->del("qc:{$key}");
            } catch (Exception $e) {
            }
        }

        unset(self::$cache[$key]);
    }

    public static function clear(): void {
        self::initRedis();

        if (self::$redisEnabled && self::$redis) {
            try {
                $keys = self::$redis->keys("qc:*");
                if (!empty($keys)) {
                    self::$redis->del($keys);
                }
            } catch (Exception $e) {
            }
        }

        self::$cache = [];
    }

    public static function clearPattern(string $pattern): void {
        self::initRedis();

        if (self::$redisEnabled && self::$redis) {
            try {
                $keys = self::$redis->keys("qc:{$pattern}*");
                if (!empty($keys)) {
                    self::$redis->del($keys);
                }
            } catch (Exception $e) {
            }
        }

        foreach (array_keys(self::$cache) as $key) {
            if (str_starts_with($key, $pattern)) {
                unset(self::$cache[$key]);
            }
        }
    }

    public static function remember(string $key, callable $callback, int $ttlSeconds = 300) {
        $cached = self::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $data = $callback();
        self::set($key, $data, $ttlSeconds);
        return $data;
    }

    private static function cleanup(): void {
        $now = time();
        foreach (self::$cache as $key => $entry) {
            if ($entry['expires'] <= $now) {
                unset(self::$cache[$key]);
            }
        }

        if (count(self::$cache) > 1000) {
            $sorted = self::$cache;
            uasort($sorted, fn($a, $b) => $a['created'] - $b['created']);
            self::$cache = array_slice($sorted, -500, null, true);
        }
    }

    public static function getStats(): array {
        $now = time();
        $expired = 0;
        foreach (self::$cache as $entry) {
            if ($entry['expires'] <= $now) {
                $expired++;
            }
        }

        return [
            'entries' => count(self::$cache),
            'expired' => $expired,
            'hits' => self::$hits,
            'misses' => self::$misses,
            'hit_rate' => self::$hits + self::$misses > 0
                ? round((self::$hits / (self::$hits + self::$misses)) * 100, 2)
                : 0
        ];
    }
}

function cachedUserQuota(int $uid): array {
    return QueryCache::remember("user_quota_{$uid}", function() use ($uid) {
        return getUserQuota($uid);
    }, 60);
}

function cachedUserPlan(int $uid): string {
    return QueryCache::remember("user_plan_{$uid}", function() use ($uid) {
        $user = currentUser();
        return $user['plan'] ?? 'free';
    }, 300);
}

function invalidateUserCache(int $uid): void {
    QueryCache::delete("user_quota_{$uid}");
    QueryCache::delete("user_plan_{$uid}");
}
