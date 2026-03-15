<?php

class SoftDelete {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function softDelete($table, $id, $deletedBy = null, $reason = null) {
        $sql = "UPDATE {$table}
                SET deleted_at = NOW()";

        $params = ['id' => $id];

        if ($deletedBy !== null) {
            $sql .= ", deleted_by = :deleted_by";
            $params['deleted_by'] = $deletedBy;
        }

        if ($reason !== null) {
            $sql .= ", deletion_reason = :reason";
            $params['reason'] = $reason;
        }

        $sql .= " WHERE id = :id AND deleted_at IS NULL";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    public function restore($table, $id) {
        $sql = "UPDATE {$table}
                SET deleted_at = NULL, deleted_by = NULL, deletion_reason = NULL
                WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function permanentDelete($table, $id) {
        $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = :id");
        return $stmt->execute(['id' => $id]);
    }

    public function getDeleted($table, $limit = 100, $offset = 0) {
        $sql = "SELECT * FROM {$table}
                WHERE deleted_at IS NOT NULL
                ORDER BY deleted_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cleanupOldDeleted($table, $daysOld = 90) {
        $sql = "DELETE FROM {$table}
                WHERE deleted_at IS NOT NULL
                AND deleted_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['days' => $daysOld]);

        return $stmt->rowCount();
    }

    public function getDeletedCount($table) {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as count FROM {$table} WHERE deleted_at IS NOT NULL"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$row['count'];
    }

    public static function addSoftDeleteClause(&$query) {
        if (strpos(strtoupper($query), 'WHERE') !== false) {
            $query = str_replace('WHERE', 'WHERE deleted_at IS NULL AND', $query);
        } else {
            $whereParts = explode('ORDER BY', $query);
            $query = $whereParts[0] . ' WHERE deleted_at IS NULL';
            if (isset($whereParts[1])) {
                $query .= ' ORDER BY ' . $whereParts[1];
            }
        }
    }
}
