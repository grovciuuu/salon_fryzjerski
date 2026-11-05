<?php
require __DIR__ . '/includes/admin_helpers.php';
require_role(ROLE_ADMIN);

$categories = db_all('SELECT id, name FROM service_categories ORDER BY name');
$errors = [];
$form   = null;                               // null = pokazujemy listę, tablica = formularz usługi

/* ---------- Tryb formularza: ?new=1 (dodawanie) albo ?edit=ID (edycja) ---------- */
if (isset($_GET['new'])) {
    $form = ['id' => 0, 'category_id' => 0, 'name' => '', 'description' => '', 'duration' => '60', 'price' => '', 'is_active' => 1];
} elseif (get_int('edit') > 0) {
    $row = db_one('SELECT * FROM services WHERE id = ?', [get_int('edit')]);
    if (!$row) {
        flash('error', 'Nie znaleziono usługi.');
        redirect('admin_services.php');
    }
    $form = [
        'id' => (int)$row['id'], 'category_id' => (int)$row['category_id'], 'name' => $row['name'],
        'description' => (string)$row['description'], 'duration' => (string)$row['duration'],
        'price' => number_format((float)$row['price'], 2, '.', ''), 'is_active' => (int)$row['is_active'],
    ];
}

/* ---------- Akcje POST: zapis, zmiana aktywności, usuwanie ---------- */
if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id     = post_int('id');

    if ($action === 'save') {
        $form = [
            'id' => $id, 'category_id' => post_int('category_id'), 'name' => post('name'),
            'description' => post('description'), 'duration' => post('duration'),
            'price' => str_replace(',', '.', post('price')), 'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        if (!db_val('SELECT 1 FROM service_categories WHERE id = ?', [$form['category_id']])) {
            $errors['category_id'] = 'Wybierz kategorię.';
        }
        $len = mb_strlen($form['name']);
        if ($form['name'] === '') {
            $errors['name'] = 'Nazwa jest wymagana.';
        } elseif ($len < 3 || $len > 150) {
            $errors['name'] = 'Nazwa musi mieć od 3 do 150 znaków.';
        }
        if (mb_strlen($form['description']) > 2000) {
            $errors['description'] = 'Opis może mieć maksymalnie 2000 znaków.';
        }
        if (!ctype_digit($form['duration']) || (int)$form['duration'] < 5 || (int)$form['duration'] > 600) {
            $errors['duration'] = 'Czas trwania: liczba całkowita minut od 5 do 600.';
        }
        if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $form['price'])) {
            $errors['price'] = 'Cena: liczba od 0 do 9999,99 (maks. 2 miejsca po przecinku).';
        }
        if (!$errors && $id > 0 && !db_val('SELECT 1 FROM services WHERE id = ?', [$id])) {
            flash('error', 'Nie znaleziono usługi.');
            redirect('admin_services.php');
        }

        if (!$errors) {
            $desc = $form['description'] !== '' ? $form['description'] : null;
            if ($id > 0) {
                db_run(
                    'UPDATE services SET category_id = ?, name = ?, description = ?, duration = ?, price = ?, is_active = ? WHERE id = ?',
                    [$form['category_id'], $form['name'], $desc, (int)$form['duration'], $form['price'], $form['is_active'], $id]
                );
                flash('success', 'Usługa została zapisana.');
            } else {
                db_run(
                    'INSERT INTO services (category_id, name, description, duration, price, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                    [$form['category_id'], $form['name'], $desc, (int)$form['duration'], $form['price'], $form['is_active']]
                );
                flash('success', 'Usługa została dodana.');
            }
            redirect('admin_services.php');
        }
    }

    if ($action === 'toggle') {
        db_run('UPDATE services SET is_active = 1 - is_active WHERE id = ?', [$id]);
        flash('success', 'Zmieniono aktywność usługi.');
        redirect_back('admin_services.php');
    }

    if ($action === 'delete') {
        // Usługa z rezerwacjami nie może zostać usunięta (zachowujemy historię) – można ją dezaktywować
        $n = (int)db_val('SELECT COUNT(*) FROM reservations WHERE service_id = ?', [$id]);
        if ($n > 0) {
            flash('error', "Nie można usunąć usługi – ma rezerwacje ($n). Zamiast tego ją dezaktywuj.");
        } elseif (db_run('DELETE FROM services WHERE id = ?', [$id]) > 0) {
            flash('success', 'Usługa została usunięta.');
        } else {
            flash('error', 'Nie znaleziono usługi.');
        }
        redirect_back('admin_services.php');
    }
}

/* ---------- Lista: filtry, wyszukiwanie, paginacja ---------- */
$rows = [];
$pages = 1;
$page = 1;
$f = ['q' => get_str('q'), 'category' => get_int('category'), 'active' => get_str('active')];

if ($form === null) {
    $where = [];
    $params = [];
    if ($f['q'] !== '') {
        $where[] = 's.name LIKE ?';
        $params[] = like($f['q']);
    }
    if ($f['category'] > 0) {
        $where[] = 's.category_id = ?';
        $params[] = $f['category'];
    }
    if ($f['active'] === '1' || $f['active'] === '0') {
        $where[] = 's.is_active = ?';
        $params[] = (int)$f['active'];
    } else {
        $f['active'] = '';
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $total = (int)db_val('SELECT COUNT(*) FROM services s' . $w, $params);
    [$page, $pages, $offset] = paginate($total, 10);

    $rows = db_all(
        'SELECT s.id, s.name, s.duration, s.price, s.is_active, c.name AS category,
                (SELECT COUNT(*) FROM employee_services es WHERE es.service_id = s.id) AS emp_count,
                (SELECT COUNT(*) FROM reservations r WHERE r.service_id = s.id) AS res_count
           FROM services s JOIN service_categories c ON c.id = s.category_id' . $w .
        ' ORDER BY c.name, s.name LIMIT 10 OFFSET ' . $offset,
        $params
    );
}

admin_header('Usługi', 'services');

/* ================= FORMULARZ DODAWANIA / EDYCJI ================= */
if ($form !== null): ?>
<div class="toolbar"><h1><?= $form['id'] > 0 ? 'Edytuj usługę' : 'Dodaj usługę' ?></h1>
    <a class="btn btn-secondary" href="admin_services.php">&laquo; Wróć do listy</a></div>
<div class="card card-narrow">
    <form method="post" action="admin_services.php" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
        <label>Kategoria
            <select name="category_id" required>
                <option value="0">– wybierz –</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)$form['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'category_id') ?>
        </label>
        <label>Nazwa
            <input type="text" name="name" value="<?= e($form['name']) ?>" maxlength="150" required>
            <?= field_error($errors, 'name') ?>
        </label>
        <label>Opis (opcjonalnie)
            <textarea name="description" rows="4" maxlength="2000"><?= e($form['description']) ?></textarea>
            <?= field_error($errors, 'description') ?>
        </label>
        <label>Czas trwania (minuty)
            <input type="number" name="duration" value="<?= e($form['duration']) ?>" min="5" max="600" step="5" required>
            <?= field_error($errors, 'duration') ?>
        </label>
        <label>Cena (zł)
            <input type="text" name="price" value="<?= e($form['price']) ?>" inputmode="decimal" placeholder="np. 90,00" required>
            <?= field_error($errors, 'price') ?>
        </label>
        <label class="check"><input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>> Usługa aktywna (widoczna dla klientów)</label>
        <button class="btn" type="submit">Zapisz</button>
        <a class="btn btn-secondary" href="admin_services.php">Anuluj</a>
    </form>
</div>

<?php else: /* ================= LISTA ================= */ ?>
<div class="toolbar"><h1>Usługi</h1>
    <a class="btn" href="admin_services.php?new=1">+ Dodaj usługę</a></div>

<form method="get" action="admin_services.php" class="filters">
    <label>Szukaj <input type="text" name="q" value="<?= e($f['q']) ?>" placeholder="nazwa usługi"></label>
    <label>Kategoria
        <select name="category">
            <option value="0">Wszystkie</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $f['category'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Aktywność
        <select name="active">
            <option value="">Wszystkie</option>
            <option value="1"<?= $f['active'] === '1' ? ' selected' : '' ?>>Aktywne</option>
            <option value="0"<?= $f['active'] === '0' ? ' selected' : '' ?>>Nieaktywne</option>
        </select>
    </label>
    <button class="btn" type="submit">Filtruj</button>
    <a class="btn btn-secondary" href="admin_services.php">Wyczyść</a>
</form>

<div class="card">
    <?php if (!$rows): ?>
        <p class="muted">Brak usług spełniających kryteria.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Usługa</th><th>Kategoria</th><th>Czas</th><th>Cena</th><th>Fryzjerzy</th><th>Rezerwacje</th><th>Status</th><th>Akcje</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?= e($r['name']) ?></strong></td>
                        <td><?= e($r['category']) ?></td>
                        <td><?= (int)$r['duration'] ?> min</td>
                        <td><?= e(money($r['price'])) ?></td>
                        <td><?= (int)$r['emp_count'] ?></td>
                        <td><?= (int)$r['res_count'] ?></td>
                        <td><?= $r['is_active'] ? badge('on', 'Aktywna') : badge('off', 'Nieaktywna') ?></td>
                        <td class="actions">
                            <a class="btn btn-small btn-secondary" href="admin_services.php?edit=<?= (int)$r['id'] ?>">Edytuj</a>
                            <form method="post" action="admin_services.php" class="inline-form">
                                <?= csrf_field() ?><?= qs_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-small btn-secondary" type="submit"><?= $r['is_active'] ? 'Dezaktywuj' : 'Aktywuj' ?></button>
                            </form>
                            <form method="post" action="admin_services.php" class="inline-form"
                                  data-confirm="Usunąć usługę „<?= e($r['name']) ?>”?">
                                <?= csrf_field() ?><?= qs_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-small btn-danger" type="submit">Usuń</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= pagination_html($page, $pages, 'admin_services.php') ?>
    <?php endif; ?>
</div>
<?php endif;
admin_footer();
