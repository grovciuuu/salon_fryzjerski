<?php
require __DIR__ . '/includes/app.php';
$user = require_role(ROLE_CLIENT);

/**
 * Funkcja pomocnicza: wylicza dostępne godziny startu dla danego pracownika, usługi i daty.
 * Uwzględnia godziny pracy z employee_availability, czas trwania usługi oraz istniejące nieanulowane rezerwacje.
 */
function get_available_slots(int $employeeId, string $date, int $durationMinutes): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }
    $today = date('Y-m-d');
    if ($date < $today) {
        return [];
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        return [];
    }
    $dayOfWeek = (int)$dt->format('N'); // 1 (Pon) - 7 (Nie)

    // Pobierz godziny pracy pracownika w tym dniu tygodnia
    $st = db()->prepare('SELECT start_time, end_time FROM employee_availability WHERE employee_id = ? AND day_of_week = ?');
    $st->execute([$employeeId, $dayOfWeek]);
    $availRows = $st->fetchAll();
    if (!$availRows) {
        return [];
    }

    // Pobierz istniejące aktywne rezerwacje pracownika w tym dniu
    $resSt = db()->prepare(
        "SELECT start_time, end_time
           FROM reservations
          WHERE employee_id = ? AND reservation_date = ? AND status <> 'cancelled'"
    );
    $resSt->execute([$employeeId, $date]);
    $busy = $resSt->fetchAll();

    $slots = [];
    $stepSeconds = 15 * 60; // skok co 15 minut
    $durSeconds  = $durationMinutes * 60;
    $nowTs       = time();

    foreach ($availRows as $a) {
        $workStart = strtotime($date . ' ' . $a['start_time']);
        $workEnd   = strtotime($date . ' ' . $a['end_time']);

        for ($t = $workStart; ($t + $durSeconds) <= $workEnd; $t += $stepSeconds) {
            // Nie pozwalamy rezerwować w przeszłości (dla dzisiejszego dnia)
            if ($date === $today && $t <= $nowTs) {
                continue;
            }

            $candStart = date('H:i:s', $t);
            $candEnd   = date('H:i:s', $t + $durSeconds);

            // Reguła nakładania przedziałów (sekcja 7.1 PDF):
            // Dwie wizyty kolidują, gdy: candStart < existingEnd AND candEnd > existingStart
            $conflict = false;
            foreach ($busy as $b) {
                if ($candStart < $b['end_time'] && $candEnd > $b['start_time']) {
                    $conflict = true;
                    break;
                }
            }

            if (!$conflict) {
                $slots[] = substr($candStart, 0, 5);
            }
        }
    }

    $slots = array_values(array_unique($slots));
    sort($slots);
    return $slots;
}

// Parametry kroków procesu: usługa -> pracownik -> data -> godzina -> potwierdzenie
$serviceId  = isset($_REQUEST['service_id']) ? (int)$_REQUEST['service_id'] : 0;
$employeeId = isset($_REQUEST['employee_id']) ? (int)$_REQUEST['employee_id'] : 0;
$date       = isset($_REQUEST['date']) && is_string($_REQUEST['date']) ? trim($_REQUEST['date']) : '';
$time       = isset($_REQUEST['time']) && is_string($_REQUEST['time']) ? trim($_REQUEST['time']) : '';
$confirm    = isset($_GET['confirm']) && $_GET['confirm'] === '1';

// Krok 1: Weryfikacja wybranej usługi
$service = null;
if ($serviceId > 0) {
    $st = db()->prepare('SELECT s.*, c.name AS category_name FROM services s JOIN service_categories c ON c.id = s.category_id WHERE s.id = ? AND s.is_active = 1');
    $st->execute([$serviceId]);
    $service = $st->fetch() ?: null;
    if (!$service) {
        flash('error', 'Wybrana usługa nie jest dostępna.');
        redirect('services.php');
    }
}

// Krok 2: Weryfikacja pracownika przypisanego do tej usługi (relacja N:M)
$employee = null;
$availableEmployees = [];
if ($service) {
    $empSt = db()->prepare(
        'SELECT e.id, e.bio, u.first_name, u.last_name
           FROM employees e
           JOIN users u ON u.id = e.user_id
           JOIN employee_services es ON es.employee_id = e.id
          WHERE es.service_id = ? AND e.is_active = 1 AND u.is_active = 1
          ORDER BY u.last_name, u.first_name'
    );
    $empSt->execute([$serviceId]);
    $availableEmployees = $empSt->fetchAll();

    if ($employeeId > 0) {
        foreach ($availableEmployees as $ae) {
            if ((int)$ae['id'] === $employeeId) {
                $employee = $ae;
                break;
            }
        }
        if (!$employee) {
            flash('error', 'Wybrany pracownik nie wykonuje tej usługi.');
            redirect('booking.php?service_id=' . $serviceId);
        }
    }
}

// Krok 3 & 4: Dostępne godziny dla wybranego pracownika i daty
$slots = [];
if ($service && $employee && $date !== '') {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
        flash('error', 'Wybierz poprawną datę (nie można rezerwować w przeszłości).');
        redirect('booking.php?service_id=' . $serviceId . '&employee_id=' . $employeeId);
    }
    $slots = get_available_slots($employeeId, $date, (int)$service['duration']);
}

// Zapis rezerwacji (POST - ostateczne potwierdzenie i ponowne sprawdzenie konfliktu po stronie serwera!)
if (is_post() && post('action') === 'book') {
    csrf_verify();
    $comment = post('comment');
    if (mb_strlen($comment) > 500) {
        $comment = mb_substr($comment, 0, 500);
    }

    if (!$service || !$employee || $date === '' || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        flash('error', 'Niekompletne dane rezerwacji. Rozpocznij wybór ponownie.');
        redirect('services.php');
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Ponowne sprawdzenie dostępności bezpośrednio przed zapisem (ochrona przed manipulacją żądania i równoczesną rezerwacją)
        $currentSlots = get_available_slots($employeeId, $date, (int)$service['duration']);
        if (!in_array($time, $currentSlots, true)) {
            $pdo->rollBack();
            flash('error', 'Wybrany termin jest już zajęty lub wykracza poza godziny pracy pracownika. Wybierz inną godzinę.');
            redirect('booking.php?service_id=' . $serviceId . '&employee_id=' . $employeeId . '&date=' . urlencode($date));
        }

        $startTime = $time . ':00';
        $endTs     = strtotime($date . ' ' . $startTime) + ((int)$service['duration'] * 60);
        $endTime   = date('H:i:s', $endTs);

        // Dodatkowy twardy check SQL na nakładanie się przedziałów
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM reservations
              WHERE employee_id = ?
                AND reservation_date = ?
                AND status <> 'cancelled'
                AND (? < end_time AND ? > start_time)"
        );
        $chk->execute([$employeeId, $date, $startTime, $endTime]);
        if ((int)$chk->fetchColumn() > 0) {
            $pdo->rollBack();
            flash('error', 'Wybrany termin koliduje z inną rezerwacją.');
            redirect('booking.php?service_id=' . $serviceId . '&employee_id=' . $employeeId . '&date=' . urlencode($date));
        }

        $ins = $pdo->prepare(
            "INSERT INTO reservations (user_id, employee_id, service_id, reservation_date, start_time, end_time, status, comment)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)"
        );
        $ins->execute([
            (int)$user['id'],
            $employeeId,
            $serviceId,
            $date,
            $startTime,
            $endTime,
            $comment !== '' ? $comment : null
        ]);

        $pdo->commit();
        flash('success', 'Rezerwacja została potwierdzona i zapisana!');
        redirect('client.php');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
}

page_header('Rezerwacja wizyty');
?>
<h1>Rezerwacja wizyty</h1>

<div class="steps-bar">
    <span class="step-pill <?= $service ? 'done' : 'active' ?>">1. Usługa</span>
    <span class="step-pill <?= $employee ? 'done' : ($service ? 'active' : '') ?>">2. Pracownik</span>
    <span class="step-pill <?= ($date !== '' && $slots) ? 'done' : ($employee ? 'active' : '') ?>">3. Data</span>
    <span class="step-pill <?= ($time !== '' && $confirm) ? 'done' : (($date !== '') ? 'active' : '') ?>">4. Godzina</span>
    <span class="step-pill <?= ($confirm && $time !== '') ? 'active' : '' ?>">5. Potwierdzenie</span>
</div>

<?php if (!$service): ?>
    <!-- KROK 1: WYBÓR USŁUGI -->
    <div class="card">
        <h2>Krok 1: Wybierz usługę</h2>
        <p class="muted">Wybierz usługę z naszej oferty, aby przejść dalej.</p>
        <a class="btn" href="services.php">Przejdź do oferty usług</a>
    </div>

<?php elseif (!$employee): ?>
    <!-- KROK 2: WYBÓR PRACOWNIKA -->
    <div class="card">
        <h2>Krok 2: Wybierz pracownika</h2>
        <p>Wybrana usługa: <strong><?= e($service['name']) ?></strong> (<?= (int)$service['duration'] ?> min, <?= number_format((float)$service['price'], 2, ',', ' ') ?> zł)
           &nbsp;<a href="services.php">[zmień usługę]</a></p>

        <?php if (!$availableEmployees): ?>
            <div class="alert alert-error">Brak aktywnych pracowników wykonujących tę usługę.</div>
        <?php else: ?>
            <div class="services-grid">
                <?php foreach ($availableEmployees as $emp): ?>
                    <div class="service-item">
                        <div>
                            <h3><?= e($emp['first_name'] . ' ' . $emp['last_name']) ?></h3>
                            <?php if (!empty($emp['bio'])): ?>
                                <p class="muted"><?= e($emp['bio']) ?></p>
                            <?php endif; ?>
                        </div>
                        <a class="btn" href="booking.php?service_id=<?= $serviceId ?>&employee_id=<?= (int)$emp['id'] ?>">Wybierz pracownika</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php elseif (!$confirm || $time === '' || !in_array($time, $slots, true)): ?>
    <!-- KROK 3 i 4: WYBÓR DATY I GODZINY -->
    <div class="card card-narrow">
        <h2>Krok 3 i 4: Wybierz datę i godzinę</h2>
        <p>
            <strong>Usługa:</strong> <?= e($service['name']) ?> (<?= (int)$service['duration'] ?> min)<br>
            <strong>Pracownik:</strong> <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?>
            &nbsp;<a href="booking.php?service_id=<?= $serviceId ?>">[zmień]</a>
        </p>

        <form method="get" action="booking.php">
            <input type="hidden" name="service_id" value="<?= $serviceId ?>">
            <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
            <label>Wybierz datę wizyty
                <input type="date" name="date" value="<?= e($date) ?>" min="<?= date('Y-m-d') ?>" required onchange="this.form.submit()">
            </label>
            <button class="btn btn-secondary" type="submit">Sprawdź dostępne godziny</button>
        </form>

        <?php if ($date !== ''): ?>
            <hr style="margin: 20px 0; border: 0; border-top: 1px solid var(--border);">
            <h3>Dostępne godziny w dniu <?= e($date) ?>:</h3>
            <?php if (!$slots): ?>
                <div class="alert alert-error">Brak wolnych terminów w tym dniu (pracownik nie przyjmuje lub wszystkie terminy są zajęte). Wybierz inną datę.</div>
            <?php else: ?>
                <div class="slots-grid">
                    <?php foreach ($slots as $slot): ?>
                        <a class="slot-btn"
                           href="booking.php?service_id=<?= $serviceId ?>&employee_id=<?= $employeeId ?>&date=<?= e($date) ?>&time=<?= e($slot) ?>&confirm=1">
                            <?= e($slot) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php else: ?>
    <!-- KROK 5: POTWIERDZENIE REZERWACJI -->
    <?php
        $endTs = strtotime($date . ' ' . $time . ':00') + ((int)$service['duration'] * 60);
        $endTimeDisplay = date('H:i', $endTs);
    ?>
    <div class="card card-narrow">
        <h2>Krok 5: Potwierdzenie rezerwacji</h2>
        <p>Sprawdź szczegóły wizyty przed zatwierdzeniem:</p>
        <ul>
            <li><strong>Usługa:</strong> <?= e($service['name']) ?> (<?= e($service['category_name']) ?>)</li>
            <li><strong>Pracownik:</strong> <?= e($employee['first_name'] . ' ' . $employee['last_name']) ?></li>
            <li><strong>Data:</strong> <?= e($date) ?></li>
            <li><strong>Godzina:</strong> <?= e($time) ?> – <?= e($endTimeDisplay) ?> (<?= (int)$service['duration'] ?> min)</li>
            <li><strong>Cena:</strong> <?= number_format((float)$service['price'], 2, ',', ' ') ?> zł</li>
        </ul>

        <form method="post" action="booking.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="book">
            <input type="hidden" name="service_id" value="<?= $serviceId ?>">
            <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <input type="hidden" name="time" value="<?= e($time) ?>">

            <label>Uwagi do rezerwacji (opcjonalnie)
                <textarea name="comment" rows="3" maxlength="500" placeholder="Dodatkowe informacje dla fryzjera..."></textarea>
            </label>

            <button class="btn" type="submit">Potwierdzam i rezerwuję</button>
            <a class="btn btn-secondary" href="booking.php?service_id=<?= $serviceId ?>&employee_id=<?= $employeeId ?>&date=<?= e($date) ?>">Zmień godzinę</a>
        </form>
    </div>
<?php endif; ?>
<?php page_footer();
