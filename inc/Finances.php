<?php
/**
 * Finances Service Class
 * Handles analytics, trends, queries, and data quality metrics for the external Finances_2026 database table.
 */
class Finances {
    
    /**
     * Check connection to external database.
     */
    public static function isAvailable(): bool {
        return ExtDB::isAvailable();
    }

    /**
     * Build standard SQL WHERE clauses based on filter parameters.
     */
    private static function buildWhereClause(array $filters, array &$params): string {
        $where = [];

        if (!empty($filters['startDate'])) {
            $where[] = '"Date" >= :startDate';
            $params['startDate'] = $filters['startDate'];
        }

        if (!empty($filters['endDate'])) {
            $where[] = '"Date" <= :endDate';
            $params['endDate'] = $filters['endDate'];
        }

        if (!empty($filters['type']) && in_array(strtolower($filters['type']), ['income', 'expense'])) {
            $where[] = 'LOWER("Type") = :type';
            $params['type'] = strtolower($filters['type']);
        }

        if (!empty($filters['client'])) {
            $where[] = '"Client_id" ILIKE :client';
            $params['client'] = '%' . trim($filters['client']) . '%';
        }

        if (!empty($filters['description'])) {
            $where[] = '"Description" ILIKE :description';
            $params['description'] = '%' . trim($filters['description']) . '%';
        }

        return !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    }

    /**
     * Get primary overview statistics (Income, Expense, Profit, Averages, Counts).
     */
    public static function getOverviewStats(array $filters = []): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $sql = "
            SELECT 
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'income' THEN \"Amount\" ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'expense' THEN \"Amount\" ELSE 0 END), 0) AS total_expense,
                COUNT(*) AS total_transactions,
                COUNT(CASE WHEN LOWER(\"Type\") = 'income' THEN 1 END) AS income_count,
                COUNT(CASE WHEN LOWER(\"Type\") = 'expense' THEN 1 END) AS expense_count,
                COALESCE(AVG(CASE WHEN LOWER(\"Type\") = 'income' THEN \"Amount\" END), 0) AS avg_income,
                COALESCE(AVG(CASE WHEN LOWER(\"Type\") = 'expense' THEN \"Amount\" END), 0) AS avg_expense,
                COALESCE(MAX(CASE WHEN LOWER(\"Type\") = 'income' THEN \"Amount\" END), 0) AS max_income,
                COALESCE(MAX(CASE WHEN LOWER(\"Type\") = 'expense' THEN \"Amount\" END), 0) AS max_expense
            FROM \"Finances_2026\"
            {$where}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $totalIncome = (float)($row['total_income'] ?? 0);
        $totalExpense = (float)($row['total_expense'] ?? 0);
        $netProfit = $totalIncome - $totalExpense;
        $profitMargin = $totalIncome > 0 ? ($netProfit / $totalIncome) * 100 : 0;

        return [
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
            'net_profit' => $netProfit,
            'profit_margin' => round($profitMargin, 2),
            'total_transactions' => (int)($row['total_transactions'] ?? 0),
            'income_count' => (int)($row['income_count'] ?? 0),
            'expense_count' => (int)($row['expense_count'] ?? 0),
            'avg_income' => round((float)($row['avg_income'] ?? 0), 2),
            'avg_expense' => round((float)($row['avg_expense'] ?? 0), 2),
            'max_income' => (float)($row['max_income'] ?? 0),
            'max_expense' => (float)($row['max_expense'] ?? 0),
        ];
    }

    /**
     * Get monthly breakdown of Income vs Expenses.
     */
    public static function getMonthlyBreakdown(array $filters = []): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $sql = "
            SELECT 
                TO_CHAR(\"Date\"::date, 'YYYY-MM') AS month,
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'income' THEN \"Amount\" ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'expense' THEN \"Amount\" ELSE 0 END), 0) AS expense,
                COUNT(*) AS transactions
            FROM \"Finances_2026\"
            {$where}
            GROUP BY month
            ORDER BY month ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $inc = (float)$r['income'];
            $exp = (float)$r['expense'];
            $result[] = [
                'month' => $r['month'] ?: 'Unknown',
                'income' => $inc,
                'expense' => $exp,
                'net_profit' => $inc - $exp,
                'transactions' => (int)$r['transactions']
            ];
        }

        return $result;
    }

    /**
     * Get cash flow trend points (daily or weekly).
     */
    public static function getCashFlowTrends(array $filters = [], string $granularity = 'daily'): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $groupExpr = ($granularity === 'weekly') 
            ? "TO_CHAR(DATE_TRUNC('week', \"Date\"::date), 'YYYY-MM-DD')" 
            : "TO_CHAR(\"Date\"::date, 'YYYY-MM-DD')";

        $sql = "
            SELECT 
                {$groupExpr} AS period,
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'income' THEN \"Amount\" ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'expense' THEN \"Amount\" ELSE 0 END), 0) AS expense
            FROM \"Finances_2026\"
            {$where}
            GROUP BY period
            ORDER BY period ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        $cumulative = 0.0;

        foreach ($rows as $r) {
            $inc = (float)$r['income'];
            $exp = (float)$r['expense'];
            $net = $inc - $exp;
            $cumulative += $net;

            $result[] = [
                'period' => $r['period'] ?: 'Unknown',
                'income' => $inc,
                'expense' => $exp,
                'net_flow' => $net,
                'cumulative_balance' => round($cumulative, 2)
            ];
        }

        return $result;
    }

    /**
     * Categorize expenses based on Description keywords.
     */
    public static function getExpenseCategories(array $filters = []): array {
        $pdo = ExtDB::conn();
        $params = [];
        $filters['type'] = 'expense'; // Force expense filter
        $where = self::buildWhereClause($filters, $params);

        $sql = "
            SELECT \"Description\", \"Amount\"
            FROM \"Finances_2026\"
            {$where}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $categories = [
            'Server & Hosting' => 0.0,
            'Hardware & Routers' => 0.0,
            'Mobile & Referral' => 0.0,
            'Services & Transfers' => 0.0,
            'Miscellaneous' => 0.0
        ];

        foreach ($rows as $r) {
            $desc = mb_strtolower($r['Description'] ?? '');
            $amt = (float)($r['Amount'] ?? 0);

            if (str_contains($desc, 'zom') || str_contains($desc, 'vps') || str_contains($desc, 'server') || str_contains($desc, 'zarub') || str_contains($desc, 'host') || str_contains($desc, 'ip')) {
                $categories['Server & Hosting'] += $amt;
            } elseif (str_contains($desc, 'роутер') || str_contains($desc, 'доставка') || str_contains($desc, 'router') || str_contains($desc, 'оборудование')) {
                $categories['Hardware & Routers'] += $amt;
            } elseif (str_contains($desc, 'моб') || str_contains($desc, 'рефералка') || str_contains($desc, 'ref') || str_contains($desc, 'комиссия')) {
                $categories['Mobile & Referral'] += $amt;
            } elseif (str_contains($desc, 'перевод') || str_contains($desc, 'сервис') || str_contains($desc, 'pay') || str_contains($desc, 'card')) {
                $categories['Services & Transfers'] += $amt;
            } else {
                $categories['Miscellaneous'] += $amt;
            }
        }

        $formatted = [];
        foreach ($categories as $cat => $val) {
            if ($val > 0) {
                $formatted[] = [
                    'category' => $cat,
                    'amount' => round($val, 2)
                ];
            }
        }

        // Sort descending by amount
        usort($formatted, fn($a, $b) => $b['amount'] <=> $a['amount']);

        return $formatted;
    }

    /**
     * Get top clients by revenue and by transaction count.
     */
    public static function getTopClients(array $filters = [], int $limit = 10): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        // Revenue Top
        $revWhere = $where ? ($where . ' AND LOWER("Type") = \'income\' AND "Client_id" IS NOT NULL AND TRIM("Client_id") != \'\'') 
                           : 'WHERE LOWER("Type") = \'income\' AND "Client_id" IS NOT NULL AND TRIM("Client_id") != \'\'';
        
        $sqlRev = "
            SELECT 
                \"Client_id\" AS client_id,
                SUM(\"Amount\") AS total_revenue,
                COUNT(*) AS transaction_count,
                AVG(\"Amount\") AS avg_amount
            FROM \"Finances_2026\"
            {$revWhere}
            GROUP BY \"Client_id\"
            ORDER BY total_revenue DESC
            LIMIT {$limit}
        ";

        $stmtRev = $pdo->prepare($sqlRev);
        $stmtRev->execute($params);
        $byRevenue = $stmtRev->fetchAll(PDO::FETCH_ASSOC);

        // Transaction Volume Top
        $txWhere = $where ? ($where . ' AND "Client_id" IS NOT NULL AND TRIM("Client_id") != \'\'') 
                          : 'WHERE "Client_id" IS NOT NULL AND TRIM("Client_id") != \'\'';
        
        $sqlTx = "
            SELECT 
                \"Client_id\" AS client_id,
                COUNT(*) AS transaction_count,
                SUM(CASE WHEN LOWER(\"Type\") = 'income' THEN \"Amount\" ELSE 0 END) AS total_income,
                SUM(CASE WHEN LOWER(\"Type\") = 'expense' THEN \"Amount\" ELSE 0 END) AS total_expense
            FROM \"Finances_2026\"
            {$txWhere}
            GROUP BY \"Client_id\"
            ORDER BY transaction_count DESC
            LIMIT {$limit}
        ";

        $stmtTx = $pdo->prepare($sqlTx);
        $stmtTx->execute($params);
        $byCount = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

        return [
            'by_revenue' => array_map(function($r) {
                return [
                    'client_id' => $r['client_id'],
                    'total_revenue' => (float)$r['total_revenue'],
                    'transaction_count' => (int)$r['transaction_count'],
                    'avg_amount' => round((float)$r['avg_amount'], 2)
                ];
            }, $byRevenue),
            'by_transactions' => array_map(function($r) {
                return [
                    'client_id' => $r['client_id'],
                    'transaction_count' => (int)$r['transaction_count'],
                    'total_income' => (float)$r['total_income'],
                    'total_expense' => (float)$r['total_expense']
                ];
            }, $byCount)
        ];
    }

    /**
     * Get records with missing or unlinked client IDs.
     */
    public static function getUnmatchedClients(array $filters = []): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $unmatchedClause = $where 
            ? ($where . ' AND ("Client_id" IS NULL OR TRIM("Client_id") = \'\')')
            : 'WHERE ("Client_id" IS NULL OR TRIM("Client_id") = \'\')';

        $sqlStats = "
            SELECT 
                COUNT(*) AS total_unmatched,
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'income' THEN \"Amount\" ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN LOWER(\"Type\") = 'expense' THEN \"Amount\" ELSE 0 END), 0) AS total_expense
            FROM \"Finances_2026\"
            {$unmatchedClause}
        ";

        $stmtStats = $pdo->prepare($sqlStats);
        $stmtStats->execute($params);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

        $sqlSample = "
            SELECT id, \"Date\", \"Type\", \"Amount\", \"Description\", \"VLESS_id\"
            FROM \"Finances_2026\"
            {$unmatchedClause}
            ORDER BY \"Date\" DESC
            LIMIT 20
        ";

        $stmtSample = $pdo->prepare($sqlSample);
        $stmtSample->execute($params);
        $sample = $stmtSample->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_unmatched' => (int)($stats['total_unmatched'] ?? 0),
            'total_income' => (float)($stats['total_income'] ?? 0),
            'total_expense' => (float)($stats['total_expense'] ?? 0),
            'samples' => $sample
        ];
    }

    /**
     * Get Data Quality indicators and integrity diagnostics.
     */
    public static function getDataQualityMetrics(): array {
        $pdo = ExtDB::conn();

        // 1. Missing values
        $sqlMissing = "
            SELECT 
                COUNT(*) AS total_records,
                COUNT(CASE WHEN \"Date\" IS NULL OR TRIM(\"Date\"::text) = '' THEN 1 END) AS missing_date,
                COUNT(CASE WHEN \"Amount\" IS NULL THEN 1 END) AS missing_amount,
                COUNT(CASE WHEN \"Description\" IS NULL OR TRIM(\"Description\") = '' THEN 1 END) AS missing_description,
                COUNT(CASE WHEN \"Client_id\" IS NULL OR TRIM(\"Client_id\") = '' THEN 1 END) AS missing_client_id,
                COUNT(CASE WHEN \"VLESS_id\" IS NULL OR TRIM(\"VLESS_id\") = '' THEN 1 END) AS missing_vless_id
            FROM \"Finances_2026\"
        ";
        $missingRow = $pdo->query($sqlMissing)->fetch(PDO::FETCH_ASSOC);
        $total = (int)($missingRow['total_records'] ?? 0);

        // 2. Exact duplicates check (matching Date, Type, Amount, Description, Client_id)
        $sqlDuplicates = "
            SELECT COUNT(*) FROM (
                SELECT \"Date\", \"Type\", \"Amount\", \"Description\", \"Client_id\", COUNT(*) 
                FROM \"Finances_2026\"
                GROUP BY \"Date\", \"Type\", \"Amount\", \"Description\", \"Client_id\"
                HAVING COUNT(*) > 1
            ) dupes
        ";
        $duplicateGroupsCount = (int)$pdo->query($sqlDuplicates)->fetchColumn();

        // 3. Outlier / High-Value Anomaly Detection
        $sqlOutliers = "
            SELECT id, \"Date\", \"Type\", \"Amount\", \"Description\", \"Client_id\"
            FROM \"Finances_2026\"
            ORDER BY \"Amount\" DESC
            LIMIT 5
        ";
        $outliers = $pdo->query($sqlOutliers)->fetchAll(PDO::FETCH_ASSOC);

        // Completeness score
        $totalFieldsCheck = $total * 4; // Date, Amount, Description, Client_id
        $missingFieldsSum = (int)$missingRow['missing_date'] + (int)$missingRow['missing_amount'] + (int)$missingRow['missing_description'] + (int)$missingRow['missing_client_id'];
        $completenessScore = $totalFieldsCheck > 0 ? round((( $totalFieldsCheck - $missingFieldsSum ) / $totalFieldsCheck) * 100, 1) : 100;

        return [
            'total_records' => $total,
            'missing_date' => (int)$missingRow['missing_date'],
            'missing_amount' => (int)$missingRow['missing_amount'],
            'missing_description' => (int)$missingRow['missing_description'],
            'missing_client_id' => (int)$missingRow['missing_client_id'],
            'missing_vless_id' => (int)$missingRow['missing_vless_id'],
            'duplicate_groups' => $duplicateGroupsCount,
            'completeness_score' => $completenessScore,
            'top_outliers' => $outliers
        ];
    }

    /**
     * Get filtered, paginated transaction records.
     */
    public static function getTransactions(array $filters = []): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $page = max(1, (int)($filters['page'] ?? 1));
        $limit = min(100, max(5, (int)($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sortBy = 'Date';
        $allowedSort = ['Date' => '"Date"', 'Amount' => '"Amount"', 'id' => 'id', 'Client_id' => '"Client_id"'];
        if (!empty($filters['sortBy']) && isset($allowedSort[$filters['sortBy']])) {
            $sortBy = $allowedSort[$filters['sortBy']];
        }

        $sortDir = (strtoupper($filters['sortDir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

        // Count query
        $countSql = "SELECT COUNT(*) FROM \"Finances_2026\" {$where}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        // Data query
        $sql = "
            SELECT 
                id, 
                \"Date\", 
                \"Type\", 
                \"Amount\", 
                \"Description\", 
                \"Client_id\", 
                \"VLESS_id\", 
                created_at, 
                updated_at, 
                created_by, 
                updated_by, 
                nc_order
            FROM \"Finances_2026\"
            {$where}
            ORDER BY {$sortBy} {$sortDir}, id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalPages = (int)ceil($totalCount / $limit);

        return [
            'items' => array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'date' => $r['Date'],
                    'type' => strtolower($r['Type'] ?? 'income'),
                    'amount' => (float)$r['Amount'],
                    'description' => $r['Description'] ?? '',
                    'client_id' => $r['Client_id'] ?? '',
                    'vless_id' => $r['VLESS_id'] ?? '',
                    'created_at' => $r['created_at'] ?? '',
                    'updated_at' => $r['updated_at'] ?? '',
                    'created_by' => $r['created_by'] ?? '',
                    'updated_by' => $r['updated_by'] ?? '',
                    'nc_order' => $r['nc_order'] ?? ''
                ];
            }, $items),
            'pagination' => [
                'total' => $totalCount,
                'page' => $page,
                'limit' => $limit,
                'pages' => $totalPages
            ]
        ];
    }

    /**
     * Export all matching transactions as CSV data array.
     */
    public static function getExportData(array $filters = []): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $sql = "
            SELECT id, \"Date\", \"Type\", \"Amount\", \"Description\", \"Client_id\", \"VLESS_id\", created_at, updated_at
            FROM \"Finances_2026\"
            {$where}
            ORDER BY \"Date\" DESC, id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
