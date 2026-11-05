<?php
require __DIR__ . '/includes/admin_helpers.php';
$admin = require_role(ROLE_ADMIN);

/* ---------- Akcja: blokada / odblokowanie konta ---------- */
if (is_post()) {
    csrf_verify();
    if (post('action') === 'toggle') {
        $id = post_int('id');
        if ($id === (int)$admin['id']) {
            flash('error', 'Nie możesz dezaktywować własnego konta.');
        } elseif (!db_val('SELECT 1 FROM users WHERE id = ?', [$id])) {
            flash('error', 'Nie znaleziono użytkownika.');
        } else {
            db_run('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
            flash('success', 'Zmieniono aktywność konta.');
        }
        redirect_back('admin_users.php');
    }
}

/* ---------- Filtry, wyszukiwanie, paginacja ---------- */
$f = ['q' => get_str('q'), 'role' => get_str('role'), 'active' => get_str('active')];
$where = [];
$params = [];
if ($f['q'] !== '') {
    $where[] = "(CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ?)";
    $params[] = like($f['q']);
    $params[] = like($f['q']);
}
if (isset(ROLES[$f['role']])) {
    $where[] = 'role = ?';
    $params[] = $f['role'];
} else {
    $f['role'] = '';
}
if ($f['active'] === '1' || $f['active'] === '0') {
    $where[] = 'is_active = ?';
    $params[] = (int)$f['active'];
} else {
    $f['active'] = '';
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$total = (int)db_val('SELECT COUNT(*) FROM users' . $w, $params);
[$page, $pages, $offset] = paginate($total, 10);
$rows = db_all(
    'SELECT id, first_name, last_name, email, phone, role, is_active, created_at FROM users' . $w .
    ' ORDER BY created_at DESC, id DESC LIMIT 10 OFFSET ' . $offset,
    $params
);

admin_header('Użytkownicy', 'users');
?>
<h1>Użytkownicy</h1>
<form method="get" action="admin_users.php" class="filters">
    <label>Szukaj <input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="imię, nazwisko lub e-mail"></label>
    <label>Rola
        <select name="role">
            <option value="">Wszystkie</option>
            <?php foreach (ROLES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $f['role'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Konto
        <select name="active">
            <option value="">Wszystkie</option>
            <option value="1"<?= $f['active'] === '1' ? ' selected' : '' ?>>Aktywne</option>
            <option value="0"<?= $f['active'] === '0' ? ' selected' : '' ?>>Zablokowane</option>
        </select>
    </label>
    <button class="btn" type="submit">Filtruj</button>
    <a class="btn btn-secondary" href="admin_users.php">Wyczyść</a>
</form>

<div class="card">
    <?php if (!$rows): ?>
        <p class="muted">Brak użytkowników spełniających kryteria.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Użytkownik</th><th>E-mail</th><th>Telefon</th><th>Rola</th><th>Założone</th><th>Konto</th><th>Akcje</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $self = (int)$r['id'] === (int)$admin['id']; ?>
                    <tr>
                        <td><strong><?= e($r['first_name'] . ' ' . $r['last_name']) ?></strong><?= $self ? ' <span class="muted">(Ty)</span>' : '' ?></td>
                        <td><?= e($r['email']) ?></td>
                        <td><?= e($r['phone']) ?></td>
                        <td><?= badge($r['role'], ROLES[$r['role']] ?? $r['role']) ?></td>
                        <td><?= e(substr((string)$r['created_at'], 0, 10)) ?></td>
                        <td><?= $r['is_active'] ? badge('on', 'Aktywne') : badge('off', 'Zablokowane') ?></td>
                        <td class="actions">
                            <?php if (!$self): ?>
                                <form method="post" action="admin_users.php" class="inline-form"
                                      data-confirm="<?= $r['is_active'] ? 'Zablokować' : 'Odblokować' ?> konto <?= e($r['email']) ?>?">
                                    <?= csrf_field() ?><?= qs_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-small <?= $r['is_active'] ? 'btn-danger' : 'btn-secondary' ?>" type="submit"><?= $r['is_active'] ? 'Zablokuj' : 'Odblokuj' ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Razem: <?= $total ?></p>
        <?= pagination_html($page, $pages, 'admin_users.php') ?>
    <?php endif; ?>
</div>
<?php admin_footer();
