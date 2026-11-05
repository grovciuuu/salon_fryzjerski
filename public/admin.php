<?php
require __DIR__ . '/includes/admin_helpers.php';
require_role(ROLE_ADMIN);                 // tylko administrator – sprawdzane po stronie serwera

$today = date('Y-m-d');

/* Liczby do kafelków – wszystko prosto z bazy */
$clients  = (int)db_val("SELECT COUNT(*) FROM users WHERE role = 'client'");
$empAll   = (int)db_val('SELECT COUNT(*) FROM employees');
$empAct   = (int)db_val('SELECT COUNT(*) FROM employees WHERE is_active = 1');
$srvAll   = (int)db_val('SELECT COUNT(*) FROM services');
$srvAct   = (int)db_val('SELECT COUNT(*) FROM services WHERE is_active = 1');
$resAll   = (int)db_val('SELECT COUNT(*) FROM reservations');
$resToday = (int)db_val("SELECT COUNT(*) FROM reservations WHERE reservation_date = ? AND status <> 'cancelled'", [$today]);

$byStatus = array_fill_keys(array_keys(STATUSES), 0);
foreach (db_all('SELECT status, COUNT(*) AS n FROM reservations GROUP BY status') as $row) {
    $byStatus[$row['status']] = (int)$row['n'];
}

/* Dzisiejsze wizyty (bez anulowanych) */
$todayRows = db_all(
    'SELECT ' . RESERVATION_COLS . RESERVATION_FROM .
    " WHERE r.reservation_date = ? AND r.status <> 'cancelled' ORDER BY r.start_time",
    [$today]
);

admin_header('Panel administratora', 'dashboard');
?>
<h1>Dashboard</h1>

<div class="stats">
    <a class="stat" href="admin_users.php?role=client"><strong><?= $clients ?></strong><span>Klienci</span></a>
    <a class="stat" href="admin_employees.php"><strong><?= $empAct ?>/<?= $empAll ?></strong><span>Pracownicy (aktywni / wszyscy)</span></a>
    <a class="stat" href="admin_services.php"><strong><?= $srvAct ?>/<?= $srvAll ?></strong><span>Usługi (aktywne / wszystkie)</span></a>
    <a class="stat" href="admin_reservations.php"><strong><?= $resAll ?></strong><span>Wszystkie rezerwacje</span></a>
    <a class="stat" href="admin_reservations.php?date=<?= e($today) ?>"><strong><?= $resToday ?></strong><span>Rezerwacje na dziś</span></a>
</div>

<div class="card">
    <h2>Rezerwacje według statusu</h2>
    <div class="stats stats-small">
        <?php foreach (STATUSES as $key => $label): ?>
            <a class="stat" href="admin_reservations.php?status=<?= e($key) ?>">
                <strong><?= $byStatus[$key] ?></strong><span><?= e($label) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h2>Dzisiejsze wizyty (<?= e(date('d.m.Y')) ?>)</h2>
    <?php if (!$todayRows): ?>
        <p class="muted">Brak wizyt zaplanowanych na dziś.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Godzina</th><th>Klient</th><th>Usługa</th><th>Fryzjer</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($todayRows as $r): ?>
                    <tr>
                        <td><?= e(hm($r['start_time'])) ?>–<?= e(hm($r['end_time'])) ?></td>
                        <td><?= e($r['c_first'] . ' ' . $r['c_last']) ?></td>
                        <td><?= e($r['service']) ?></td>
                        <td><?= e($r['e_first'] . ' ' . $r['e_last']) ?></td>
                        <td><?= status_badge($r['status']) ?></td>
                        <td class="actions"><a href="admin_reservations.php?id=<?= (int)$r['id'] ?>">Szczegóły</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php admin_footer();
