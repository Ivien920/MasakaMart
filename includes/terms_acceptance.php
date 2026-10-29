<?php
/**
 * Ensures order-level policy acceptance fields exist without changing historical orders.
 */
if (!function_exists('ensure_terms_acceptance_schema')) {
    function ensure_terms_acceptance_schema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $columns = [
            'ALTER TABLE orders ADD COLUMN terms_version VARCHAR(20) NULL AFTER order_status',
            'ALTER TABLE orders ADD COLUMN terms_accepted_at DATETIME NULL AFTER terms_version',
        ];
        foreach ($columns as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), 'Duplicate column') === false) {
                    error_log('[terms] DDL skipped: ' . $e->getMessage());
                }
            }
        }
    }
}
