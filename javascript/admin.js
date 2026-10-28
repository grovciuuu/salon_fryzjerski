// Potwierdzenia operacji w panelu administratora (wymóg: potwierdzanie np. usunięcia).
// Formularz z atrybutem data-confirm="Pytanie?" wyśle się dopiero po kliknięciu OK.
// Formularz zmiany statusu z data-confirm-status="cancelled" pyta tylko przy wyborze "Anulowana".
document.addEventListener('submit', function (e) {
    var form = e.target;

    var msg = form.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
        return;
    }

    var status = form.getAttribute('data-confirm-status');
    if (status && form.elements.status && form.elements.status.value === status) {
        if (!window.confirm('Na pewno anulować tę rezerwację?')) {
            e.preventDefault();
        }
    }
});
