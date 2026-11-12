<?php
require __DIR__ . '/includes/app.php';

$catId = isset($_GET['category']) ? (int)$_GET['category'] : 0;

$categories = db()->query('SELECT id, name, description FROM service_categories ORDER BY name')->fetchAll();

if ($catId > 0) {
    $st = db()->prepare(
        'SELECT s.*, c.name AS category_name
           FROM services s
           JOIN service_categories c ON c.id = s.category_id
          WHERE s.is_active = 1 AND s.category_id = ?
          ORDER BY s.name'
    );
    $st->execute([$catId]);
} else {
    $st = db()->query(
        'SELECT s.*, c.name AS category_name
           FROM services s
           JOIN service_categories c ON c.id = s.category_id
          WHERE s.is_active = 1
          ORDER BY c.name, s.name'
    );
}
$services = $st->fetchAll();
$user = current_user();

page_header('Oferta usług');
?>
<h1>Oferta usług</h1>

<form method="get" action="services.php" class="filters">
    <label>Kategoria
        <select name="category" onchange="this.form.submit()">
            <option value="0">Wszystkie kategorie</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $catId ? ' selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($catId > 0): ?>
        <a class="btn btn-secondary" href="services.php">Pokaż wszystkie</a>
    <?php endif; ?>
</form>

<?php if (!$services): ?>
    <div class="card"><p class="muted">Brak aktywnych usług w wybranej kategorii.</p></div>
<?php else: ?>
    <div class="services-grid">
        <?php foreach ($services as $s): ?>
            <div class="service-item">
                <div>
                    <span class="muted" style="font-size:.85rem;"><?= e($s['category_name']) ?></span>
                    <h3><?= e($s['name']) ?></h3>
                    <?php if (!empty($s['description'])): ?>
                        <p><?= e($s['description']) ?></p>
                    <?php endif; ?>
                </div>
                <div>
                    <p class="service-meta">
                        <strong>Czas trwania:</strong> <?= (int)$s['duration'] ?> min<br>
                        <strong>Cena:</strong> <?= number_format((float)$s['price'], 2, ',', ' ') ?> zł
                    </p>
                    <?php if ($user && $user['role'] === ROLE_CLIENT): ?>
                        <a class="btn" href="booking.php?service_id=<?= (int)$s['id'] ?>">Zarezerwuj termin</a>
                    <?php elseif (!$user): ?>
                        <a class="btn btn-secondary" href="login.php">Zaloguj się, aby zarezerwować</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php page_footer();
