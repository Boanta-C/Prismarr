<?php

namespace App\Controller\Admin;

use App\Entity\Admin\AuditLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/database', name: 'admin_db_')]
class DatabaseController extends AbstractController
{
    // Mots-clés DDL absolument interdits (même dans une sous-requête)
    private const DDL_PATTERN = '/\b(DROP|TRUNCATE|ALTER|CREATE\s+TABLE|CREATE\s+DATABASE|RENAME|GRANT|REVOKE|LOAD\s+DATA|LOAD\s+INFILE|INTO\s+OUTFILE)\b/i';

    // Colonnes sensibles à masquer en lecture (jamais affichées en clair)
    private const SENSITIVE_COLUMNS = ['password', 'passwd', 'secret', 'token', 'api_key', 'private_key'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection             $db,
    ) {}

    // ── Liste des tables ──────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tables = [];
        foreach ($this->getAllowedTables() as $name) {
            $count = (int) $this->db->fetchOne("SELECT COUNT(*) FROM `{$name}`");
            $tables[$name] = ['name' => $name, 'rows' => $count];
        }

        $recentLogs = $this->em->getRepository(AuditLog::class)->findRecent(20);

        return $this->render('admin/database/index.html.twig', [
            'tables'      => $tables,
            'recent_logs' => $recentLogs,
        ]);
    }

    // ── Parcourir une table ───────────────────────────────────────────────────

    #[Route('/{table}', name: 'browse', methods: ['GET'], requirements: ['table' => '[a-zA-Z0-9_]+'])]
    public function browse(string $table, Request $request): Response
    {
        $this->assertTableAllowed($table);

        $columns    = $this->getColumns($table);
        $pkColumns  = $this->getPkColumns($table);
        $page       = max(1, (int) $request->query->get('page', 1));
        $perPage    = 25;
        $offset     = ($page - 1) * $perPage;
        $filters    = array_filter((array) $request->query->all('filter'));

        // Tri
        $sortCol = $request->query->get('sort', '');
        $sortDir = strtoupper($request->query->get('dir', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        if (!in_array($sortCol, $columns, true)) {
            $sortCol = '';
        }
        $orderSql = $sortCol ? "ORDER BY `{$sortCol}` {$sortDir}" : '';

        // Construction de la requête avec filtres
        $where  = [];
        $params = [];
        $types  = [];

        foreach ($filters as $col => $val) {
            if (!in_array($col, $columns, true) || $val === '') {
                continue;
            }
            $where[]  = "`{$col}` LIKE ?";
            $params[] = "%{$val}%";
            $types[]  = ParameterType::STRING;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total    = (int) $this->db->fetchOne("SELECT COUNT(*) FROM `{$table}` {$whereSql}", $params, $types);
        $rows     = $this->db->fetchAllAssociative(
            "SELECT * FROM `{$table}` {$whereSql} {$orderSql} LIMIT {$perPage} OFFSET {$offset}",
            $params,
            $types,
        );

        // Masquer les colonnes sensibles
        $rows = $this->maskSensitiveColumns($rows);

        return $this->render('admin/database/browse.html.twig', [
            'table'         => $table,
            'columns'       => $columns,
            'pk_columns'    => $pkColumns,
            'rows'          => $rows,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $perPage,
            'pages'         => (int) ceil($total / $perPage),
            'filters'       => $filters,
            'has_simple_pk' => count($pkColumns) === 1,
            'sort_col'      => $sortCol,
            'sort_dir'      => $sortDir,
        ]);
    }

    // ── Formulaire édition ligne ──────────────────────────────────────────────

    #[Route('/{table}/{id}/edit', name: 'edit_row', methods: ['GET', 'POST'], requirements: ['table' => '[a-zA-Z0-9_]+'])]
    public function editRow(string $table, string $id, Request $request): Response
    {
        $this->assertTableAllowed($table);
        $columns   = $this->getColumns($table);
        $pkColumns = $this->getPkColumns($table);

        if (count($pkColumns) !== 1) {
            $this->addFlash('warning', 'Édition non disponible pour les tables avec clé primaire composite.');
            return $this->redirectToRoute('admin_db_browse', ['table' => $table]);
        }

        $pkCol = $pkColumns[0];
        $row   = $this->db->fetchAssociative("SELECT * FROM `{$table}` WHERE `{$pkCol}` = ?", [$id]);

        if (!$row) {
            throw $this->createNotFoundException("Ligne introuvable : {$table}#{$id}");
        }

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid("edit_{$table}_{$id}", $token)) {
                throw $this->createAccessDeniedException('Token CSRF invalide.');
            }

            $newValues = [];
            foreach ($columns as $col) {
                if ($col === $pkCol) {
                    continue; // PK non modifiable
                }
                $val = $request->request->get($col);
                $newValues[$col] = ($val === '' || $val === null) ? null : $val;
            }

            $setClauses = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($newValues)));
            $sql        = "UPDATE `{$table}` SET {$setClauses} WHERE `{$pkCol}` = ?";
            $params     = array_values($newValues);
            $params[]   = $id;

            $this->db->executeStatement($sql, $params);
            $this->logAudit('update', $table, $id, $row, $newValues, $sql, $request);

            $this->addFlash('success', "Ligne #{$id} mise à jour.");
            return $this->redirectToRoute('admin_db_browse', array_merge(['table' => $table], $this->parseBack($request->request->get('_back', ''))));
        }

        return $this->render('admin/database/edit_row.html.twig', [
            'table'     => $table,
            'row'       => $row,
            'columns'   => $columns,
            'pk_col'    => $pkCol,
            'pk_val'    => $id,
        ]);
    }

    // ── Formulaire insertion ligne ────────────────────────────────────────────

    #[Route('/{table}/~insert', name: 'insert_row', methods: ['GET', 'POST'], requirements: ['table' => '[a-zA-Z0-9_]+'])]
    public function insertRow(string $table, Request $request): Response
    {
        $this->assertTableAllowed($table);

        $allCols    = $this->db->fetchAllAssociative("DESCRIBE `{$table}`");
        $pkColumns  = array_column(array_filter($allCols, fn($r) => $r['Key'] === 'PRI'), 'Field');
        $insertable = array_filter($allCols, fn($r) => $r['Extra'] !== 'auto_increment');
        $columns    = array_column(array_values($insertable), 'Field');
        $meta       = array_column($allCols, null, 'Field');

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid("insert_{$table}", $token)) {
                throw $this->createAccessDeniedException('Token CSRF invalide.');
            }

            $values = [];
            foreach ($columns as $col) {
                $val = $request->request->get($col);
                $values[$col] = ($val === '') ? null : $val;
            }

            $colList = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($values)));
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";

            $this->db->executeStatement($sql, array_values($values));
            $newId = (string) $this->db->lastInsertId();
            $this->logAudit('insert', $table, $newId, null, $values, $sql, $request);

            $this->addFlash('success', "Ligne insérée avec succès.");
            return $this->redirectToRoute('admin_db_browse', ['table' => $table]);
        }

        return $this->render('admin/database/insert_row.html.twig', [
            'table'   => $table,
            'columns' => $columns,
            'meta'    => $meta,
        ]);
    }

    // ── Suppression ligne ─────────────────────────────────────────────────────

    #[Route('/{table}/{id}/delete', name: 'delete_row', methods: ['POST'], requirements: ['table' => '[a-zA-Z0-9_]+'])]
    public function deleteRow(string $table, string $id, Request $request): Response
    {
        $this->assertTableAllowed($table);
        $pkColumns = $this->getPkColumns($table);

        if (count($pkColumns) !== 1) {
            $this->addFlash('warning', 'Suppression non disponible pour les tables avec clé primaire composite.');
            return $this->redirectToRoute('admin_db_browse', ['table' => $table]);
        }

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid("delete_{$table}_{$id}", $token)) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $pkCol = $pkColumns[0];
        $row   = $this->db->fetchAssociative("SELECT * FROM `{$table}` WHERE `{$pkCol}` = ?", [$id]);

        if (!$row) {
            throw $this->createNotFoundException("Ligne introuvable.");
        }

        $sql = "DELETE FROM `{$table}` WHERE `{$pkCol}` = ?";
        $this->db->executeStatement($sql, [$id]);
        $this->logAudit('delete', $table, $id, $row, null, $sql, $request);

        $this->addFlash('success', "Ligne #{$id} supprimée.");
        return $this->redirectToRoute('admin_db_browse', array_merge(['table' => $table], $this->parseBack($request->request->get('_back', ''))));
    }

    // ── Query runner ──────────────────────────────────────────────────────────

    #[Route('/~query', name: 'query', methods: ['GET', 'POST'])]
    public function query(Request $request): Response
    {
        $result    = null;
        $error     = null;
        $affected  = null;
        $queryTime = null;
        $sql       = $request->request->get('sql', '');

        // Pré-remplir un template INSERT si demandé depuis la page browse
        if ($sql === '' && $request->query->has('insert')) {
            $insertTable = $request->query->get('insert', '');
            if (in_array($insertTable, $this->getAllowedTables(), true)) {
                $sql = $this->buildInsertTemplate($insertTable);
            }
        }

        if ($request->isMethod('POST') && $sql !== '') {
            $token = $request->request->get('_token');
            if (!$this->isCsrfTokenValid('admin_query', $token)) {
                throw $this->createAccessDeniedException('Token CSRF invalide.');
            }

            // Sécurité : bloquer DDL
            if (preg_match(self::DDL_PATTERN, $sql)) {
                $error = 'Les instructions DDL (DROP, TRUNCATE, ALTER, CREATE TABLE, RENAME, GRANT…) sont interdites.';
            } else {
                try {
                    $start = microtime(true);
                    $isSelect = preg_match('/^\s*SELECT\b/i', $sql);

                    if ($isSelect) {
                        $result = $this->db->fetchAllAssociative($sql);
                        $result = $this->maskSensitiveColumns($result);
                    } else {
                        $affected = $this->db->executeStatement($sql);
                        $this->logAudit('query', null, null, null, null, $sql, $request);
                    }

                    $queryTime = round((microtime(true) - $start) * 1000, 1);
                } catch (DbalException $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->render('admin/database/query.html.twig', [
            'sql'        => $sql,
            'result'     => $result,
            'error'      => $error,
            'affected'   => $affected,
            'query_time' => $queryTime,
        ]);
    }

    // ── Helpers sécurité ─────────────────────────────────────────────────────

    private function parseBack(string $back): array
    {
        $params = [];
        parse_str($back, $params);
        return array_intersect_key($params, array_flip(['filter', 'sort', 'dir', 'page']));
    }

    private function buildInsertTemplate(string $table): string
    {
        $rows      = $this->db->fetchAllAssociative("DESCRIBE `{$table}`");
        $insertable = array_values(array_filter($rows, fn($r) => $r['Extra'] !== 'auto_increment'));
        $cols      = array_column($insertable, 'Field');
        $colList   = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $valList   = implode(', ', array_fill(0, count($cols), "''"));

        return "INSERT INTO `{$table}` ({$colList})\nVALUES ({$valList});";
    }

    private function getAllowedTables(): array
    {
        return $this->db->fetchFirstColumn('SHOW TABLES');
    }

    private function assertTableAllowed(string $table): void
    {
        if (!in_array($table, $this->getAllowedTables(), true)) {
            throw $this->createNotFoundException("Table inconnue : {$table}");
        }
    }

    private function getColumns(string $table): array
    {
        $rows = $this->db->fetchAllAssociative("DESCRIBE `{$table}`");
        return array_column($rows, 'Field');
    }

    private function getPkColumns(string $table): array
    {
        $rows = $this->db->fetchAllAssociative("DESCRIBE `{$table}`");
        return array_column(
            array_filter($rows, fn($r) => $r['Key'] === 'PRI'),
            'Field'
        );
    }

    private function maskSensitiveColumns(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }
        $cols = array_keys($rows[0]);
        $sensitive = array_filter($cols, fn($c) => in_array(strtolower($c), self::SENSITIVE_COLUMNS, true));

        if (empty($sensitive)) {
            return $rows;
        }

        return array_map(function (array $row) use ($sensitive) {
            foreach ($sensitive as $col) {
                if (array_key_exists($col, $row) && $row[$col] !== null) {
                    $row[$col] = '••••••••';
                }
            }
            return $row;
        }, $rows);
    }

    private function logAudit(string $action, ?string $table, ?string $rowId, ?array $old, ?array $new, string $sql, Request $request): void
    {
        try {
            $log = (new AuditLog())
                ->setUserEmail($this->getUser()?->getUserIdentifier() ?? 'unknown')
                ->setAction($action)
                ->setTableName($table)
                ->setRowIdentifier($rowId)
                ->setOldValues($old)
                ->setNewValues($new)
                ->setIpAddress($request->getClientIp())
                ->setSql($sql);

            $this->em->persist($log);
            $this->em->flush();
        } catch (\Throwable) {
            // Audit non critique — ne pas casser l'action principale
        }
    }
}
