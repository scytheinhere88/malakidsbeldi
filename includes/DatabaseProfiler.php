<?php

class DatabaseProfiler {
    private static array $queries = [];
    private static int $threshold = 100;

    public static function setThreshold(int $ms): void {
        self::$threshold = $ms;
    }

    public static function profile(callable $callback, string $queryName = 'query') {
        $start = microtime(true);
        $result = $callback();
        $end = microtime(true);

        $duration = round(($end - $start) * 1000, 2);

        self::$queries[] = [
            'query' => $queryName,
            'duration_ms' => $duration,
            'timestamp' => date('Y-m-d H:i:s'),
            'is_slow' => $duration >= self::$threshold
        ];

        if ($duration >= self::$threshold) {
            error_log("SLOW QUERY [{$duration}ms]: {$queryName}");
        }

        return $result;
    }

    public static function getQueries(): array {
        return self::$queries;
    }

    public static function getSlowQueries(): array {
        return array_filter(self::$queries, fn($q) => $q['is_slow']);
    }

    public static function getStats(): array {
        if (empty(self::$queries)) {
            return [
                'total' => 0,
                'slow' => 0,
                'avg_duration' => 0,
                'max_duration' => 0
            ];
        }

        $durations = array_column(self::$queries, 'duration_ms');
        return [
            'total' => count(self::$queries),
            'slow' => count(self::getSlowQueries()),
            'avg_duration' => round(array_sum($durations) / count($durations), 2),
            'max_duration' => max($durations),
            'min_duration' => min($durations)
        ];
    }

    public static function reset(): void {
        self::$queries = [];
    }
}

function profileQuery(PDO $pdo, string $sql, array $params = [], string $queryName = null): array {
    $queryName = $queryName ?: substr($sql, 0, 60);

    return DatabaseProfiler::profile(function() use ($pdo, $sql, $params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }, $queryName);
}

function profileQuerySingle(PDO $pdo, string $sql, array $params = [], string $queryName = null) {
    $queryName = $queryName ?: substr($sql, 0, 60);

    return DatabaseProfiler::profile(function() use ($pdo, $sql, $params) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }, $queryName);
}

function profileQueryExecute(PDO $pdo, string $sql, array $params = [], string $queryName = null): bool {
    $queryName = $queryName ?: substr($sql, 0, 60);

    return DatabaseProfiler::profile(function() use ($pdo, $sql, $params) {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    }, $queryName);
}
