<?php
/* CLI fixtures must represent a real active database principal. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function test_admin_session(): void
{
    if (!in_array((string) db_value('SELECT DATABASE()'), ['suppex_test', 'suppex_fresh'], true)) {
        throw new RuntimeException('Admin fixture requires an isolated test database.');
    }
    auth_start_session();
    $id = (int) ($_SESSION['admin_id'] ?? 1);
    $role = (string) ($_SESSION['admin_role'] ?? 'support');
    if (!in_array($role, ['support', 'manager'], true)) { throw new RuntimeException('Invalid fixture role'); }
    $row = db_one('SELECT password_hash FROM admins WHERE id = ?', [$id]);
    if ($row === null) {
        $hash = password_hash('disposable-test-fixture', PASSWORD_DEFAULT);
        db_query('INSERT INTO admins(id, username, password_hash, name, role, is_active) VALUES(?,?,?,?,?,1)',
            [$id, 'fixture-' . $id, $hash, 'tester', $role]);
    } else {
        $hash = $row['password_hash'];
        db_query('UPDATE admins SET role = ?, is_active = 1 WHERE id = ?', [$role, $id]);
    }
    $_SESSION['admin_auth'] = hash('sha256', $hash);
}
