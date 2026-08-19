<?php
/**
 * Finances Service Class
 * Handles analytics, trends, queries, and data quality metrics for the external Finances_2026 database table.
 * All SQL queries use robust type casts and safe substring extractions to prevent PostgreSQL runtime errors.
 */
class Finances {
    
    /**
     * Check connection to external database.
     */
    public static function isAvailable(): bool {
        return ExtDB::isAvailable();
    }

    private static function normalizeDate(?string $date): ?string {
        if (empty($date)) return null;
        $date = trim($date);
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return $date;
    }

    /**
     * Build standard SQL WHERE clauses based on filter parameters.
     */
    private static function buildWhereClause(array $filters, array &$params): string {
        $where = [];

        if (!empty($filters['startDate'])) {
            $start = self::normalizeDate($filters['startDate']);
            if ($start) {
                $where[] = '"Date"::text >= :startDate';
                $params['startDate'] = $start;
            }
        }

        if (!empty($filters['endDate'])) {
            $end = self::normalizeDate($filters['endDate']);
            if ($end) {
                $where[] = '"Date"::text <= :endDate';
                $params['endDate'] = $end;
            }
        }

        if (!empty($filters['type']) && in_array(strtolower($filters['type']), ['income', 'expense'])) {
            $where[] = 'TRIM(LOWER("Type"::text)) = :type';
            $params['type'] = strtolower($filters['type']);
        }

        if (!empty($filters['client'])) {
            $where[] = '"Client_id"::text ILIKE :client';
            $params['client'] = '%' . trim($filters['client']) . '%';
        }

        if (!empty($filters['description'])) {
            $where[] = '"Description"::text ILIKE :description';
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
                COALESCE(SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END), 0) AS total_expense,
                COUNT(*) AS total_transactions,
                COUNT(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN 1 END) AS income_count,
                COUNT(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN 1 END) AS expense_count,
                COALESCE(AVG(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) END), 0) AS avg_income,
                COALESCE(AVG(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) END), 0) AS avg_expense,
                COALESCE(MAX(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) END), 0) AS max_income,
                COALESCE(MAX(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) END), 0) AS max_expense
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
                COALESCE(SUBSTRING(NULLIF(TRIM(\"Date\"::text), '') FROM 1 FOR 7), 'Unspecified') AS month,
                COALESCE(SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END), 0) AS expense,
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
                'month' => $r['month'] ?: 'Unspecified',
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

        $groupExpr = "COALESCE(SUBSTRING(NULLIF(TRIM(\"Date\"::text), '') FROM 1 FOR 10), 'Unspecified')";

        $sql = "
            SELECT 
                {$groupExpr} AS period,
                COALESCE(SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END), 0) AS income,
                COALESCE(SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END), 0) AS expense
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
                'period' => $r['period'] ?: 'Unspecified',
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
            SELECT \"Description\"::text AS \"Description\", COALESCE(\"Amount\"::numeric, 0) AS \"Amount\"
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
            'Services & Subscriptions' => 0.0,
            'Miscellaneous' => 0.0
        ];

        foreach ($rows as $r) {
            $desc = mb_strtolower($r['Description'] ?? '');
            $amt = (float)($r['Amount'] ?? 0);

            if (str_contains($desc, 'zom') || str_contains($desc, 'vps') || str_contains($desc, 'server') || str_contains($desc, 'сервер') || str_contains($desc, 'zarub') || str_contains($desc, 'host') || str_contains($desc, 'ip') || str_contains($desc, 'трафик') || str_contains($desc, 'hetzner')) {
                $categories['Server & Hosting'] += $amt;
            } elseif (str_contains($desc, 'роутер') || str_contains($desc, 'доставка') || str_contains($desc, 'router') || str_contains($desc, 'оборудование') || str_contains($desc, 'железо')) {
                $categories['Hardware & Routers'] += $amt;
            } elseif (str_contains($desc, 'моб') || str_contains($desc, 'рефералка') || str_contains($desc, 'ref') || str_contains($desc, 'комиссия') || str_contains($desc, 'бонус')) {
                $categories['Mobile & Referral'] += $amt;
            } elseif (str_contains($desc, 'google') || str_contains($desc, 'imp') || str_contains($desc, 'sub') || str_contains($desc, 'подписка') || str_contains($desc, 'перевод') || str_contains($desc, 'сервис') || str_contains($desc, 'pay') || str_contains($desc, 'card')) {
                $categories['Services & Subscriptions'] += $amt;
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

        usort($formatted, fn($a, $b) => $b['amount'] <=> $a['amount']);
        return $formatted;
    }

    /**
     * Get top clients by profit and by transaction count.
     */
    public static function getTopClients(array $filters = [], int $limit = 10): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $clientWhere = $where ? ($where . ' AND "Client_id" IS NOT NULL AND TRIM("Client_id"::text) != \'\'') 
                             : 'WHERE "Client_id" IS NOT NULL AND TRIM("Client_id"::text) != \'\'';
        
        $sqlProfit = "
            SELECT 
                \"Client_id\"::text AS client_id,
                SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END) - 
                SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END) AS net_profit,
                SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END) AS total_income,
                SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END) AS total_expense,
                COUNT(*) AS transaction_count,
                AVG(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) ELSE NULL END) AS avg_amount
            FROM \"Finances_2026\"
            {$clientWhere}
            GROUP BY \"Client_id\"::text
            ORDER BY net_profit DESC
            LIMIT {$limit}
        ";

        $stmtProfit = $pdo->prepare($sqlProfit);
        $stmtProfit->execute($params);
        $byProfit = $stmtProfit->fetchAll(PDO::FETCH_ASSOC);

        $sqlTx = "
            SELECT 
                \"Client_id\"::text AS client_id,
                COUNT(*) AS transaction_count,
                SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'income' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END) AS total_income,
                SUM(CASE WHEN TRIM(LOWER(\"Type\"::text)) = 'expense' THEN COALESCE(\"Amount\"::numeric, 0) ELSE 0 END) AS total_expense
            FROM \"Finances_2026\"
            {$clientWhere}
            GROUP BY \"Client_id\"::text
            ORDER BY transaction_count DESC
            LIMIT {$limit}
        ";

        $stmtTx = $pdo->prepare($sqlTx);
        $stmtTx->execute($params);
        $byCount = $stmtTx->fetchAll(PDO::FETCH_ASSOC);

        $mappedProfit = array_map(function($r) {
            return [
                'client_id' => $r['client_id'],
                'net_profit' => (float)$r['net_profit'],
                'total_income' => (float)$r['total_income'],
                'total_expense' => (float)$r['total_expense'],
                'total_revenue' => (float)$r['net_profit'], // Compatibility fallback
                'transaction_count' => (int)$r['transaction_count'],
                'avg_amount' => round((float)($r['avg_amount'] ?? 0), 2)
            ];
        }, $byProfit);

        return [
            'by_profit' => $mappedProfit,
            'by_revenue' => $mappedProfit,
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
     * Get income payments missing client subscription IDs.
     */
    public static function getUnmatchedClients(array $filters = []): array {
        $pdo = ExtDB::conn();
        $params = [];
        $where = self::buildWhereClause($filters, $params);

        $unmatchedClause = $where 
            ? ($where . ' AND TRIM(LOWER("Type"::text)) = \'income\' AND ("Client_id" IS NULL OR TRIM("Client_id"::text) = \'\')')
            : 'WHERE TRIM(LOWER("Type"::text)) = \'income\' AND ("Client_id" IS NULL OR TRIM("Client_id"::text) = \'\')';

        $sqlStats = "
            SELECT 
                COUNT(*) AS total_unmatched,
                COALESCE(SUM(COALESCE(\"Amount\"::numeric, 0)), 0) AS total_income
            FROM \"Finances_2026\"
            {$unmatchedClause}
        ";

        $stmtStats = $pdo->prepare($sqlStats);
        $stmtStats->execute($params);
        $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

        $sqlSample = "
            SELECT *
            FROM \"Finances_2026\"
            {$unmatchedClause}
            ORDER BY id DESC
            LIMIT 20
        ";

        $stmtSample = $pdo->prepare($sqlSample);
        $stmtSample->execute($params);
        $sample = $stmtSample->fetchAll(PDO::FETCH_ASSOC);

        return [
            'total_unmatched' => (int)($stats['total_unmatched'] ?? 0),
            'total_income' => (float)($stats['total_income'] ?? 0),
            'samples' => array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'Date' => $r['Date'] ?? $r['date'] ?? '',
                    'Type' => $r['Type'] ?? $r['type'] ?? 'income',
                    'Amount' => (float)($r['Amount'] ?? $r['amount'] ?? 0),
                    'Description' => $r['Description'] ?? $r['description'] ?? '',
                    'VLESS_id' => $r['VLESS_id'] ?? $r['vless_id'] ?? ''
                ];
            }, $sample)
        ];
    }

    /**
     * Get Data Quality indicators and integrity diagnostics.
     */
    public static function getDataQualityMetrics(): array {
        $pdo = ExtDB::conn();

        $sqlMissing = "
            SELECT 
                COUNT(*) AS total_records,
                COUNT(CASE WHEN \"Date\" IS NULL OR TRIM(\"Date\"::text) = '' THEN 1 END) AS missing_date,
                COUNT(CASE WHEN \"Amount\" IS NULL THEN 1 END) AS missing_amount,
                COUNT(CASE WHEN \"Description\" IS NULL OR TRIM(\"Description\"::text) = '' THEN 1 END) AS missing_description,
                COUNT(CASE WHEN \"Client_id\" IS NULL OR TRIM(\"Client_id\"::text) = '' THEN 1 END) AS missing_client_id,
                COUNT(CASE WHEN \"VLESS_id\" IS NULL OR TRIM(\"VLESS_id\"::text) = '' THEN 1 END) AS missing_vless_id
            FROM \"Finances_2026\"
        ";
        $missingRow = $pdo->query($sqlMissing)->fetch(PDO::FETCH_ASSOC);
        $total = (int)($missingRow['total_records'] ?? 0);

        $sqlDuplicates = "
            SELECT COUNT(*) FROM (
                SELECT \"Date\"::text, \"Type\"::text, \"Amount\"::numeric, \"Description\"::text, \"Client_id\"::text, COUNT(*) 
                FROM \"Finances_2026\"
                GROUP BY \"Date\"::text, \"Type\"::text, \"Amount\"::numeric, \"Description\"::text, \"Client_id\"::text
                HAVING COUNT(*) > 1
            ) dupes
        ";
        $duplicateGroupsCount = (int)$pdo->query($sqlDuplicates)->fetchColumn();

        $sqlOutliers = "
            SELECT *
            FROM \"Finances_2026\"
            ORDER BY COALESCE(\"Amount\"::numeric, 0) DESC
            LIMIT 5
        ";
        $rawOutliers = $pdo->query($sqlOutliers)->fetchAll(PDO::FETCH_ASSOC);

        $totalFieldsCheck = $total * 4;
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
            'top_outliers' => array_map(function($r) {
                return [
                    'id' => (int)$r['id'],
                    'Date' => $r['Date'] ?? $r['date'] ?? '',
                    'Type' => $r['Type'] ?? $r['type'] ?? 'income',
                    'Amount' => (float)($r['Amount'] ?? $r['amount'] ?? 0),
                    'Description' => $r['Description'] ?? $r['description'] ?? '',
                    'Client_id' => $r['Client_id'] ?? $r['client_id'] ?? ''
                ];
            }, $rawOutliers)
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

        $sortBy = '"Date"::text';
        $allowedSort = [
            'Date' => '"Date"::text', 
            'Amount' => 'COALESCE("Amount"::numeric, 0)', 
            'id' => 'id', 
            'Client_id' => '"Client_id"::text'
        ];
        if (!empty($filters['sortBy']) && isset($allowedSort[$filters['sortBy']])) {
            $sortBy = $allowedSort[$filters['sortBy']];
        }

        $sortDir = (strtoupper($filters['sortDir'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

        $countSql = "SELECT COUNT(*) FROM \"Finances_2026\" {$where}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = (int)$countStmt->fetchColumn();

        $sql = "
            SELECT *
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
                    'date' => $r['Date'] ?? $r['date'] ?? '',
                    'type' => strtolower($r['Type'] ?? $r['type'] ?? 'income'),
                    'amount' => (float)($r['Amount'] ?? $r['amount'] ?? 0),
                    'description' => $r['Description'] ?? $r['description'] ?? '',
                    'client_id' => $r['Client_id'] ?? $r['client_id'] ?? '',
                    'vless_id' => $r['VLESS_id'] ?? $r['vless_id'] ?? '',
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
            SELECT *
            FROM \"Finances_2026\"
            {$where}
            ORDER BY \"Date\"::text DESC, id DESC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a single transaction by ID from Finances_2026.
     */
    public static function getTransactionById(int $id): ?array {
        if (!self::isAvailable()) return null;
        $pdo = ExtDB::conn();
        $stmt = $pdo->prepare("SELECT * FROM \"Finances_2026\" WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Resolve and validate client code against external Clients table.
     * Supports exact match, # prefix fallback, and case-insensitive matching.
     */
    public static function resolveClientCode(string $code): ?string {
        $code = trim($code);
        if ($code === '') return null;
        if (!ExtDB::isAvailable()) return $code;

        try {
            $pdo = ExtDB::conn();
            $table = Config::get('EXT_PG_CLIENTS_TABLE', 'Clients');

            // 1. Exact match
            $stmt = $pdo->prepare("SELECT \"Code\" FROM \"{$table}\" WHERE \"Code\" = ? LIMIT 1");
            $stmt->execute([$code]);
            $found = $stmt->fetchColumn();
            if ($found !== false) return (string)$found;

            // 2. Hash / non-hash match (#0023 vs 0023)
            $altCode = str_starts_with($code, '#') ? ltrim($code, '#') : '#' . $code;
            $stmt->execute([$altCode]);
            $found = $stmt->fetchColumn();
            if ($found !== false) return (string)$found;

            // 3. Case-insensitive ILIKE match
            $stmt = $pdo->prepare("SELECT \"Code\" FROM \"{$table}\" WHERE \"Code\" ILIKE ? LIMIT 1");
            $stmt->execute([$code]);
            $found = $stmt->fetchColumn();
            if ($found !== false) return (string)$found;
        } catch (Throwable $e) {}

        return null;
    }

    /**
     * Add a new transaction record to Finances_2026.
     */
    public static function addTransaction(array $data): bool {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        $pdo = ExtDB::conn();

        try {
            $pdo->exec("SELECT setval('\"Finances_2026_id_seq\"', COALESCE((SELECT MAX(id) FROM \"Finances_2026\"), 1));");
        } catch (Throwable $e) {}

        $rawDate = trim((string)($data['date'] ?? $data['Date'] ?? date('Y-m-d')));
        $date = self::normalizeDate($rawDate) ?: date('Y-m-d');
        $type = trim($data['type'] ?? $data['Type'] ?? 'Income');
        $amount = (float)($data['amount'] ?? $data['Amount'] ?? 0);
        $description = trim($data['description'] ?? $data['Description'] ?? '');
        $rawClientId = trim($data['client_id'] ?? $data['Client_id'] ?? '');

        $clientId = null;
        if ($rawClientId !== '') {
            $resolved = self::resolveClientCode($rawClientId);
            if ($resolved === null) {
                throw new Exception("Client code '{$rawClientId}' was not found in the Clients database. Please select a valid code from the dropdown or leave it blank.");
            }
            $clientId = $resolved;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO \"Finances_2026\" (\"Date\", \"Type\", \"Amount\", \"Description\", \"Client_id\")
                VALUES (?, ?, ?, ?, ?)
            ");
            $res = $stmt->execute([$date, $type, $amount, $description, $clientId]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23503' || str_contains($e->getMessage(), '23503')) {
                throw new Exception("Client code '{$rawClientId}' violates foreign key constraint (not present in Clients table).");
            }
            throw $e;
        }

        // Check if subscription extension was requested for this client
        $extendMonths = (int)($data['extend_sub_months'] ?? $data['extend_months'] ?? $data['extend_sub'] ?? 0);
        if ($extendMonths > 0 && $clientId !== null) {
            try {
                ExtDB::extendClientSubscription($clientId, $extendMonths);
            } catch (Throwable $e) {
                // Log/proceed, transaction was already created
            }
        }

        try { ExtDB::sync(); } catch (Throwable $e) {}

        return $res;
    }

    /**
     * Update an existing transaction record in Finances_2026.
     */
    public static function updateTransaction(int $id, array $data): bool {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        $pdo = ExtDB::conn();

        $rawDate = trim((string)($data['date'] ?? $data['Date'] ?? date('Y-m-d')));
        $date = self::normalizeDate($rawDate) ?: date('Y-m-d');
        $type = trim($data['type'] ?? $data['Type'] ?? 'Income');
        $amount = (float)($data['amount'] ?? $data['Amount'] ?? 0);
        $description = trim($data['description'] ?? $data['Description'] ?? '');
        $rawClientId = trim($data['client_id'] ?? $data['Client_id'] ?? '');

        $clientId = null;
        if ($rawClientId !== '') {
            $resolved = self::resolveClientCode($rawClientId);
            if ($resolved === null) {
                throw new Exception("Client code '{$rawClientId}' was not found in the Clients database. Please select a valid code from the dropdown or leave it blank.");
            }
            $clientId = $resolved;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE \"Finances_2026\"
                SET \"Date\" = ?, \"Type\" = ?, \"Amount\" = ?, \"Description\" = ?, \"Client_id\" = ?
                WHERE id = ?
            ");
            $res = $stmt->execute([$date, $type, $amount, $description, $clientId, $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23503' || str_contains($e->getMessage(), '23503')) {
                throw new Exception("Client code '{$rawClientId}' violates foreign key constraint (not present in Clients table).");
            }
            throw $e;
        }

        try { ExtDB::sync(); } catch (Throwable $e) {}

        return $res;
    }

    /**
     * Delete a transaction record from Finances_2026.
     */
    public static function deleteTransaction(int $id): bool {
        if (!self::isAvailable()) throw new Exception("PostgreSQL database is unreachable.");
        $pdo = ExtDB::conn();
        $stmt = $pdo->prepare("DELETE FROM \"Finances_2026\" WHERE id = ?");
        $res = $stmt->execute([$id]);

        try { ExtDB::sync(); } catch (Throwable $e) {}

        return $res;
    }

    /**
     * Get all transactions linked to a specific client code from Finances_2026.
     */
    public static function getClientTransactions(string $clientCode): array {
        if (!self::isAvailable()) return [];
        $clientCode = trim($clientCode);
        if ($clientCode === '') return [];

        $pdo = ExtDB::conn();
        $stmt = $pdo->prepare("
            SELECT *
            FROM \"Finances_2026\"
            WHERE \"Client_id\"::text ILIKE ? OR \"Client_id\"::text ILIKE ?
            ORDER BY \"Date\"::text DESC, id DESC
        ");
        $rawCode = ltrim($clientCode, '#');
        $hashCode = '#' . $rawCode;
        $stmt->execute([$rawCode, $hashCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(function($r) {
            return [
                'id' => (int)$r['id'],
                'date' => $r['Date'] ?? $r['date'] ?? '',
                'type' => strtolower($r['Type'] ?? $r['type'] ?? 'income'),
                'amount' => (float)($r['Amount'] ?? $r['amount'] ?? 0),
                'description' => $r['Description'] ?? $r['description'] ?? '',
                'client_id' => $r['Client_id'] ?? $r['client_id'] ?? '',
                'vless_id' => $r['VLESS_id'] ?? $r['vless_id'] ?? ''
            ];
        }, $rows);
    }
}
