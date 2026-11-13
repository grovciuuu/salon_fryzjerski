<?php
require __DIR__ . '/includes/functions.php';
$user = require_role(ROLE_EMPLOYEE);

// Konto pracownika jest powiązane z wierszem w tabeli employees
$emp = db_one('SELECT id FROM employees WHERE user_id = ?', [$user['id']]);
if (!$emp) {
    page_header('Panel pracownika');
    echo '<div class="card"><h1>Panel pracownika</h1><p>Twoje konto nie ma jeszcze profilu pracownika. '
       . 'Skontaktuj się z administratorem.</p></div>';
    page_footer();
    exit;
}
$empId = (int)$emp['id'];
$today = date('Y-m-d');

/* ---------- Zmiana statusu WŁASNEJ wizyty ---------- */
if (is_post()) {
    csrf_verify();
    if (post('action') === 'status') {
        $id = post_int('id');
        $status = post('status');
        if (!isset(STATUSES[$status])) {
            flash('error', 'Niepoprawny status.');
        } elseif (!db_val('SELECT 1 FROM reservations WHERE id = ? AND employee_id = ?', [$id, $empId])) {
            // pracownik nie może ruszać wizyt innych pracowników (employee_id z konta, nie z formularza)
            flash('error', 'Nie znaleziono takiej wizyty wśród Twoich rezerwacji.');
        } else {
            db_run('UPDATE reservations SET status = ? WHERE id = ? AND employee_id = ?', [$status, $id, $empId]);
            flash('success', 'Status wizyty został zmieniony na: ' . STATUSES[$status] . '.');
        }
    }
    redirect('employee.php');
}

/* ---------- Wizyty: dziś / przyszłe / historia (zawsze tylko tego pracownika) ---------- */
$base = 'SELECT r.id, r.reservation_date, r.start_time, r.end_time, r.status, r.comment,
                c.first_name AS c_first, c.last_name AS c_last, c.email AS c_email, c.phone AS c_phone,
                s.name AS service, s.duration, s.price
           FROM reservations r
           JOIN users c    ON c.id = r.user_id
           JOIN services s ON s.id = r.service_id
          WHERE r.employee_id = ? ';

$todayRows  = db_all($base . 'AND r.reservation_date = ? ORDER BY r.start_time', [$empId, $today]);
$futureRows = db_all($base . "AND r.reservation_date > ? AND r.status IN ('pending', 'confirmed')
                              ORDER BY r.reservation_date, r.start_time", [$empId, $today]);
$historyRows = db_all($base . "AND (r.reservation_date < ? OR (r.reservation_date > ? AND r.status IN ('completed', 'cancelled')))
                               ORDER BY r.reservation_date DESC, r.start_time DESC LIMIT 50", [$empId, $today, $today]);

/** Tabela wizyt ze szczegółami (klient, usługa, komentarz) i zmianą statusu. */
function visits_table(array $rows, bool $showDate): void
{
    if (!$rows) {
        echo '<p class="muted">Brak wizyt w tej sekcji.</p>';
        return;
    } ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Termin</th><th>Klient</th><th>Usługa</th><th>Komentarz klienta</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td>
                        <?php if ($showDate): ?><?= e(date('d.m.Y', strtotime($r['reservation_date']))) ?><br><?php endif; ?>
                        <strong><?= e(hm($r['start_time'])) ?>–<?= e(hm($r['end_time'])) ?></strong>
                    </td>
                    <td><?= e($r['c_first'] . ' ' . $r['c_last']) ?><br>
                        <span class="muted"><?= e((string)$r['c_phone']) ?><br><?= e($r['c_email']) ?></span></td>
                    <td><?= e($r['service']) ?><br><span class="muted"><?= (int)$r['duration'] ?> min, <?= e(money($r['price'])) ?></span></td>
                    <td><?= $r['comment'] !== null && $r['comment'] !== '' ? e($r['comment']) : '<span class="muted">brak</span>' ?></td>
                    <td><?= status_badge($r['status']) ?><br><?= status_form($r, 'employee.php') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

page_header('Panel pracownika');
?>
<h1>Panel pracownika</h1>
<p class="muted">Witaj, <strong><?= e($user['first_name'] . ' ' . $user['last_name']) ?></strong>. Poniżej widzisz tylko swoje wizyty.</p>

<div class="card">
    <h2>Dzisiaj (<?= e(date('d.m.Y')) ?>)</h2>
    <?php visits_table($todayRows, false); ?>
</div>

<div class="card">
    <h2>Przyszłe wizyty</h2>
    <?php visits_table($futureRows, true); ?>
</div>

<div class="card">
    <h2>Historia (ostatnie 50)</h2>
    <?php visits_table($historyRows, true); ?>
</div>
<?php page_footer();
