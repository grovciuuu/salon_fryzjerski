<?php
require __DIR__ . '/includes/admin_helpers.php';
require_role(ROLE_ADMIN);

/* ---------- Akcja: zmiana statusu (w tym anulowanie) ---------- */
if (is_post()) {
    csrf_verify();
    if (post('action') === 'status') {
        $id = post_int('id');
        $status = post('status');
        if (!isset(STATUSES[$status])) {                                   // tylko dozwolone statusy
            flash('error', 'Niepoprawny status.');
        } elseif (!db_val('SELECT 1 FROM reservations WHERE id = ?', [$id])) {
            flash('error', 'Nie znaleziono rezerwacji.');
        } else {
            db_run('UPDATE reservations SET status = ? WHERE id = ?', [$status, $id]);   // rezerwacji nie kasujemy – zmieniamy status
            flash('success', 'Status rezerwacji został zmieniony na: ' . STATUSES[$status] . '.');
        }
        redirect_back('admin_reservations.php');
    }
}

/* ================= SZCZEGÓŁY (?id=N) ================= */
if (get_int('id') > 0) {
    $d = db_one('SELECT ' . RESERVATION_COLS . RESERVATION_FROM . ' WHERE r.id = ?', [get_int('id')]);
    if (!$d) {
        flash('error', 'Nie znaleziono rezerwacji.');
        redirect('admin_reservations.php');
    }
    admin_header('Rezerwacja #' . $d['id'], 'reservations');
    ?>
    <div class="toolbar"><h1>Rezerwacja #<?= (int)$d['id'] ?></h1>
        <a class="btn btn-secondary" href="admin_reservations.php">&laquo; Wróć do listy</a></div>
    <div class="card">
        <dl class="detail">
            <dt>Status</dt><dd><?= status_badge($d['status']) ?> &nbsp; <?= status_form($d) ?></dd>
            <dt>Termin</dt><dd><?= e($d['reservation_date']) ?>, <?= e(hm($d['start_time'])) ?>–<?= e(hm($d['end_time'])) ?></dd>
            <dt>Usługa</dt><dd><?= e($d['service']) ?> (<?= (int)$d['duration'] ?> min)</dd>
            <dt>Cena</dt><dd><?= e(money($d['price'])) ?></dd>
            <dt>Fryzjer</dt><dd><?= e($d['e_first'] . ' ' . $d['e_last']) ?></dd>
            <dt>Klient</dt><dd><?= e($d['c_first'] . ' ' . $d['c_last']) ?></dd>
            <dt>E-mail klienta</dt><dd><?= e($d['c_email']) ?></dd>
            <dt>Telefon klienta</dt><dd><?= e($d['c_phone']) ?></dd>
            <dt>Komentarz klienta</dt><dd><?= $d['comment'] !== null && $d['comment'] !== '' ? e($d['comment']) : '<span class="muted">brak</span>' ?></dd>
            <dt>Utworzona</dt><dd><?= e($d['created_at']) ?></dd>
        </dl>
    </div>
    <?php
    admin_footer();
    exit;
}

/* ================= LISTA: filtry, wyszukiwanie, paginacja ================= */
$f = ['date' => get_str('date'), 'status' => get_str('status'), 'employee' => get_int('employee'), 'q' => get_str('q')];
$where = [];
$params = [];

if (valid_date($f['date'])) {
    $where[] = 'r.reservation_date = ?';
    $params[] = $f['date'];
} else {
    $f['date'] = '';
}
if (isset(STATUSES[$f['status']])) {
    $where[] = 'r.status = ?';
    $params[] = $f['status'];
} else {
    $f['status'] = '';
}
if ($f['employee'] > 0) {
    $where[] = 'r.employee_id = ?';
    $params[] = $f['employee'];
}
if ($f['q'] !== '') {
    $where[] = "(CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR c.email LIKE ? OR s.name LIKE ?)";
    $like = like($f['q']);
    array_push($params, $like, $like, $like);
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$total = (int)db_val('SELECT COUNT(*)' . RESERVATION_FROM . $w, $params);
[$page, $pages, $offset] = paginate($total, 15);
$rows = db_all(
    'SELECT ' . RESERVATION_COLS . RESERVATION_FROM . $w .
    ' ORDER BY r.reservation_date DESC, r.start_time DESC LIMIT 15 OFFSET ' . $offset,
    $params
);
$employees = db_all("SELECT e.id, CONCAT(u.first_name, ' ', u.last_name) AS name
                       FROM employees e JOIN users u ON u.id = e.user_id ORDER BY u.last_name, u.first_name");

admin_header('Rezerwacje', 'reservations');
?>
<h1>Rezerwacje</h1>
<form method="get" action="admin_reservations.php" class="filters">
    <label>Data <input type="date" name="date" value="<?= e($f['date']) ?>"></label>
    <label>Status
        <select name="status">
            <option value="">Wszystkie</option>
            <?php foreach (STATUSES as $key => $label): ?>
                <option value="<?= e($key) ?>"<?= $f['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Fryzjer
        <select name="employee">
            <option value="0">Wszyscy</option>
            <?php foreach ($employees as $emp): ?>
                <option value="<?= (int)$emp['id'] ?>"<?= (int)$emp['id'] === $f['employee'] ? ' selected' : '' ?>><?= e($emp['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Szukaj <input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="klient lub usługa"></label>
    <button class="btn" type="submit">Filtruj</button>
    <a class="btn btn-secondary" href="admin_reservations.php">Wyczyść</a>
</form>

<div class="card">
    <?php if (!$rows): ?>
        <p class="muted">Brak rezerwacji spełniających kryteria.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>#</th><th>Termin</th><th>Klient</th><th>Usługa</th><th>Fryzjer</th><th>Cena</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= (int)$r['id'] ?></td>
                        <td><?= e($r['reservation_date']) ?><br><span class="muted"><?= e(hm($r['start_time'])) ?>–<?= e(hm($r['end_time'])) ?></span></td>
                        <td><?= e($r['c_first'] . ' ' . $r['c_last']) ?><br><span class="muted"><?= e($r['c_email']) ?></span></td>
                        <td><?= e($r['service']) ?></td>
                        <td><?= e($r['e_first'] . ' ' . $r['e_last']) ?></td>
                        <td><?= e(money($r['price'])) ?></td>
                        <td><?= status_badge($r['status']) ?><br><?= status_form($r) ?></td>
                        <td class="actions"><a href="admin_reservations.php?id=<?= (int)$r['id'] ?>">Szczegóły</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="muted">Razem: <?= $total ?></p>
        <?= pagination_html($page, $pages, 'admin_reservations.php') ?>
    <?php endif; ?>
</div>
<?php admin_footer();
