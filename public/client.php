<?php
require __DIR__ . '/includes/functions.php';
$user = require_role(ROLE_CLIENT);
$now  = date('Y-m-d H:i:s');

/* ---------- Anulowanie własnej, przyszłej rezerwacji ---------- */
if (is_post()) {
    csrf_verify();
    if (post('action') === 'cancel') {
        // Jedno zapytanie sprawdza wszystko naraz: rezerwacja musi należeć do TEGO klienta (user_id z sesji),
        // być aktywna i dotyczyć przyszłego terminu. Podmiana id w formularzu na cudzą rezerwację nic nie da.
        $changed = db_run(
            "UPDATE reservations SET status = 'cancelled'
              WHERE id = ? AND user_id = ? AND status IN ('pending', 'confirmed')
                AND CONCAT(reservation_date, ' ', start_time) > ?",
            [post_int('id'), $user['id'], $now]
        );
        if ($changed > 0) {
            flash('success', 'Rezerwacja została anulowana.');
        } else {
            flash('error', 'Tej rezerwacji nie można anulować (nie istnieje, już się odbyła lub została zamknięta).');
        }
    }
    redirect('client.php');
}

/* ---------- Tylko rezerwacje zalogowanego klienta ---------- */
$all = db_all(
    'SELECT r.id, r.reservation_date, r.start_time, r.end_time, r.status, r.comment,
            s.name AS service, s.price, eu.first_name AS e_first, eu.last_name AS e_last
       FROM reservations r
       JOIN services s  ON s.id  = r.service_id
       JOIN employees e ON e.id  = r.employee_id
       JOIN users eu    ON eu.id = e.user_id
      WHERE r.user_id = ?
      ORDER BY r.reservation_date, r.start_time',
    [$user['id']]
);

// Podział: przyszłe (aktywne, jeszcze się nie zaczęły) oraz zakończone/anulowane
$upcoming = [];
$history  = [];
foreach ($all as $r) {
    $isFuture = in_array($r['status'], ['pending', 'confirmed'], true)
        && ($r['reservation_date'] . ' ' . $r['start_time']) > $now;
    if ($isFuture) {
        $upcoming[] = $r;
    } else {
        $history[] = $r;
    }
}
$history = array_reverse($history);          // najnowsze na górze

/** Tabela rezerwacji klienta; $canCancel = czy pokazać przycisk anulowania. */
function reservations_table(array $rows, bool $canCancel): void
{
    if (!$rows) {
        echo '<p class="muted">Brak rezerwacji w tej sekcji.</p>';
        return;
    } ?>
    <div class="table-wrap">
        <table class="data">
            <thead><tr><th>Usługa</th><th>Fryzjer</th><th>Data</th><th>Godzina</th><th>Cena</th><th>Status</th><?= $canCancel ? '<th></th>' : '' ?></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><strong><?= e($r['service']) ?></strong></td>
                    <td><?= e($r['e_first'] . ' ' . $r['e_last']) ?></td>
                    <td><?= e(date('d.m.Y', strtotime($r['reservation_date']))) ?></td>
                    <td><?= e(hm($r['start_time'])) ?>–<?= e(hm($r['end_time'])) ?></td>
                    <td><?= e(money($r['price'])) ?></td>
                    <td><?= status_badge($r['status']) ?></td>
                    <?php if ($canCancel): ?>
                        <td class="actions">
                            <form method="post" action="client.php" class="inline-form"
                                  data-confirm="Anulować rezerwację: <?= e($r['service']) ?>, <?= e(date('d.m.Y', strtotime($r['reservation_date']))) ?> <?= e(hm($r['start_time'])) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-small btn-danger" type="submit">Anuluj</button>
                            </form>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

page_header('Panel klienta');
?>
<h1>Panel klienta</h1>

<div class="card">
    <h2>Moje konto</h2>
    <dl class="detail">
        <dt>Imię i nazwisko</dt><dd><?= e($user['first_name'] . ' ' . $user['last_name']) ?></dd>
        <dt>E-mail</dt><dd><?= e($user['email']) ?></dd>
        <dt>Telefon</dt><dd><?= e((string)$user['phone']) ?></dd>
    </dl>
    <p>
        <a class="btn btn-secondary" href="profile.php">Edytuj profil</a>
        <a class="btn" href="booking.php">Zarezerwuj wizytę</a>
    </p>
</div>

<div class="card">
    <h2>Przyszłe rezerwacje</h2>
    <?php reservations_table($upcoming, true); ?>
</div>

<div class="card">
    <h2>Zakończone i anulowane</h2>
    <?php reservations_table($history, false); ?>
</div>
<?php page_footer();
