<?php
require __DIR__ . '/../php/app.php';
page_header('Strona główna');
?>
<section class="hero card">
    <h1>Aura Fryzur – salon fryzjerski</h1>
    <p>Zarezerwuj wizytę online: załóż konto, wybierz usługę i fryzjera, a my zadbamy o resztę.</p>
    <?php if (current_user()): ?>
        <a class="btn" href="<?= e(role_home(current_user()['role'])) ?>">Przejdź do panelu</a>
    <?php else: ?>
        <a class="btn" href="register.php">Załóż konto</a>
        <a class="btn btn-secondary" href="login.php">Zaloguj się</a>
    <?php endif; ?>
</section>
<?php page_footer();
