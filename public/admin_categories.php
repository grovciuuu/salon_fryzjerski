<?php
require __DIR__ . '/includes/admin_helpers.php';
require_role(ROLE_ADMIN);

$errors = [];
$form   = ['id' => 0, 'name' => '', 'description' => ''];

/* ---------- Tryb edycji (?edit=ID): wczytaj kategorię do formularza ---------- */
if (get_int('edit') > 0) {
    $row = db_one('SELECT id, name, description FROM service_categories WHERE id = ?', [get_int('edit')]);
    if (!$row) {
        flash('error', 'Nie znaleziono kategorii.');
        redirect('admin_categories.php');
    }
    $form = ['id' => (int)$row['id'], 'name' => $row['name'], 'description' => (string)$row['description']];
}

/* ---------- Akcje POST: zapis i usuwanie ---------- */
if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id     = post_int('id');

    if ($action === 'save') {
        $form = ['id' => $id, 'name' => post('name'), 'description' => post('description')];

        $len = mb_strlen($form['name']);
        if ($form['name'] === '') {
            $errors['name'] = 'Nazwa jest wymagana.';
        } elseif ($len < 2 || $len > 100) {
            $errors['name'] = 'Nazwa musi mieć od 2 do 100 znaków.';
        } elseif (db_val('SELECT 1 FROM service_categories WHERE name = ? AND id <> ?', [$form['name'], $id])) {
            $errors['name'] = 'Kategoria o takiej nazwie już istnieje.';
        }
        if (mb_strlen($form['description']) > 1000) {
            $errors['description'] = 'Opis może mieć maksymalnie 1000 znaków.';
        }
        if (!$errors && $id > 0 && !db_val('SELECT 1 FROM service_categories WHERE id = ?', [$id])) {
            flash('error', 'Nie znaleziono kategorii.');
            redirect('admin_categories.php');
        }

        if (!$errors) {
            $desc = $form['description'] !== '' ? $form['description'] : null;
            try {
                if ($id > 0) {
                    db_run('UPDATE service_categories SET name = ?, description = ? WHERE id = ?', [$form['name'], $desc, $id]);
                    flash('success', 'Kategoria została zapisana.');
                } else {
                    db_run('INSERT INTO service_categories (name, description) VALUES (?, ?)', [$form['name'], $desc]);
                    flash('success', 'Kategoria została dodana.');
                }
                redirect('admin_categories.php');
            } catch (PDOException $ex) {
                if ($ex->getCode() !== '23000') {        // 23000 = naruszenie UNIQUE (nazwa)
                    throw $ex;
                }
                $errors['name'] = 'Kategoria o takiej nazwie już istnieje.';
            }
        }
    }

    if ($action === 'delete') {
        // Bezpieczna obsługa zależności: kategorii z usługami nie usuwamy
        $n = (int)db_val('SELECT COUNT(*) FROM services WHERE category_id = ?', [$id]);
        if ($n > 0) {
            flash('error', "Nie można usunąć kategorii – ma przypisane usługi ($n). Najpierw przenieś je do innej kategorii lub usuń.");
        } elseif (db_run('DELETE FROM service_categories WHERE id = ?', [$id]) > 0) {
            flash('success', 'Kategoria została usunięta.');
        } else {
            flash('error', 'Nie znaleziono kategorii.');
        }
        redirect('admin_categories.php');
    }
}

$rows = db_all(
    'SELECT c.id, c.name, c.description, COUNT(s.id) AS n
       FROM service_categories c LEFT JOIN services s ON s.category_id = c.id
      GROUP BY c.id, c.name, c.description ORDER BY c.name'
);

admin_header('Kategorie usług', 'categories');
?>
<h1>Kategorie usług</h1>
<div class="grid-2 wide-right">
    <div class="card">
        <h2><?= $form['id'] > 0 ? 'Edytuj kategorię' : 'Dodaj kategorię' ?></h2>
        <form method="post" action="admin_categories.php" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
            <label>Nazwa
                <input type="text" name="name" value="<?= e($form['name']) ?>" maxlength="100" required>
                <?= field_error($errors, 'name') ?>
            </label>
            <label>Opis (opcjonalnie)
                <textarea name="description" rows="4" maxlength="1000"><?= e($form['description']) ?></textarea>
                <?= field_error($errors, 'description') ?>
            </label>
            <button class="btn" type="submit">Zapisz</button>
            <?php if ($form['id'] > 0): ?>
                <a class="btn btn-secondary" href="admin_categories.php">Anuluj</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2>Lista kategorii</h2>
        <?php if (!$rows): ?>
            <p class="muted">Brak kategorii. Dodaj pierwszą w formularzu obok.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Nazwa</th><th>Opis</th><th>Usługi</th><th>Akcje</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><strong><?= e($r['name']) ?></strong></td>
                            <td><?= e(mb_strimwidth((string)$r['description'], 0, 60, '…')) ?></td>
                            <td><?= (int)$r['n'] ?></td>
                            <td class="actions">
                                <a class="btn btn-small btn-secondary" href="admin_categories.php?edit=<?= (int)$r['id'] ?>">Edytuj</a>
                                <form method="post" action="admin_categories.php" class="inline-form"
                                      data-confirm="Usunąć kategorię „<?= e($r['name']) ?>”?">
                                    <?= csrf_field() ?>
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
        <?php endif; ?>
    </div>
</div>
<?php admin_footer();
