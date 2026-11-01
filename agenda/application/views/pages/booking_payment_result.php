<?php
/**
 * Blondor - Resultado del pago de una reserva.
 *
 * Muestra el estado real del pago (pagado, rechazado, pendiente o en revisión) con el dinero por separado:
 * lo pagado y el saldo que queda.
 *
 * @var array|null $state
 * @var array $summary
 * @var string $manage_url
 * @var string|null $problem
 */

extend('layouts/booking_mobile_layout');

$state = vars('state');
$summary = vars('summary');
$manage_url = vars('manage_url');
$problem = vars('problem');

$status = $state['status'] ?? 'unknown';

$titles = [
    'paid' => '¡Reserva lista!',
    'rejected' => 'El pago no se concretó',
    'expired' => 'Se nos pasó el tiempo',
    'attention' => 'Estamos revisando tu pago',
    'pending' => 'Estamos confirmando tu pago',
    'unknown' => 'No encontramos esta reserva',
];

$messages = [
    'paid' => 'Tu cita está confirmada y te enviamos el detalle al correo.',
    'rejected' => 'No se hizo ningún cargo. Puedes volver a reservar cuando quieras.',
    'expired' => 'No se hizo ningún cargo: el horario se liberó. Vuelve a elegirlo.',
    'attention' => 'Estamos revisando el pago con el banco. Si se cobró algo, lo vamos a confirmar contigo.',
    'pending' => 'Estamos confirmando el pago con Webpay. Esto toma solo un momento.',
    'unknown' => 'No encontramos esta reserva.',
];

$icon = $status === 'paid'
    ? '<path d="M5 12l5 5 9-10"/>'
    : ($status === 'attention' || $status === 'pending'
        ? '<path d="M12 8v5M12 17h.01"/><circle cx="12" cy="12" r="9"/>'
        : '<path d="M6 6l12 12M18 6L6 18"/>');
?>

<?php section('content'); ?>

<div class="bk" data-step="done" data-payment-status="<?= e($status) ?>">
    <header class="bk-top">
        <div class="bk-top__row">
            <a class="bk-brand" href="https://blondor.cl">
                <img src="<?= asset_url('assets/img/blondor-mark.png') ?>" alt="">
                <span>BLONDOR</span>
            </a>
        </div>
        <h1 class="bk-top__title"><?= e($titles[$status] ?? 'Reserva') ?></h1>
    </header>

    <main class="bk-done">
        <div class="bk-done__icon<?= $status === 'paid' ? '' : ' bk-done__icon--warn' ?>" aria-hidden="true">
            <svg viewBox="0 0 24 24"><?= $icon ?></svg>
        </div>

        <h2><?= e($messages[$status] ?? '') ?></h2>

        <?php if ($problem): ?>
            <p class="bk-done__note"><?= e($problem) ?></p>
        <?php endif; ?>

        <?php if ($summary): ?>
            <div class="bk-summary">
                <p class="bk-summary__service">
                    <?= e($summary['service']) ?><?= $summary['hair_length'] ? ' · ' . e($summary['hair_length']) : '' ?>
                </p>
                <p class="bk-summary__when"><?= e($summary['when']) ?></p>
                <ul class="bk-summary__facts">
                    <li>Total: <?= e($summary['total']) ?></li>
                </ul>
            </div>

            <?php if ($status === 'paid' || $status === 'attention'): ?>
                <div class="bk-money">
                    <div class="bk-money__row">
                        <span>Pagado</span>
                        <strong><?= e($summary['paid']) ?></strong>
                    </div>
                    <?php if ($summary['due'] !== null): ?>
                        <div class="bk-money__row">
                            <span>Saldo a pagar en el salón</span>
                            <strong><?= e($summary['due']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="bk-done__actions">
            <?php if ($status === 'paid'): ?>
                <a class="bk-primary" href="https://blondor.cl">Volver a Blondor</a>
                <?php if ($manage_url): ?>
                    <a class="bk-secondary" href="<?= e($manage_url) ?>">Cambiar o cancelar la cita</a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($status === 'rejected' || $status === 'expired' || $status === 'unknown'): ?>
                <a class="bk-primary" href="<?= e(site_url('booking')) ?>">Volver a reservar</a>
            <?php endif; ?>

            <?php if ($status === 'attention'): ?>
                <a class="bk-secondary" href="https://wa.me/56966756614?text=<?= rawurlencode('Hola, tengo una reserva en revisión de pago.') ?>"
                   target="_blank" rel="noopener">Escribir por WhatsApp</a>
            <?php endif; ?>
        </div>

        <?php if ($status === 'paid'): ?>
            <p class="bk-done__note" id="bk-return-notice">Volverás al inicio en <span id="bk-return-seconds">10</span> segundos.</p>
        <?php endif; ?>
    </main>
</div>

<?php end_section('content'); ?>

<?php if ($status === 'paid'): ?>
    <?php section('scripts'); ?>
    <script>
        (() => {
            const seconds = document.getElementById('bk-return-seconds');
            const deadline = Date.now() + 10000;
            const countdown = window.setInterval(() => {
                seconds.textContent = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
            }, 1000);
            const redirect = window.setTimeout(() => {
                window.clearInterval(countdown);
                window.location.replace('https://blondor.cl');
            }, 10000);

            document.querySelectorAll('.bk-done__actions a').forEach((link) => {
                link.addEventListener('click', () => {
                    window.clearInterval(countdown);
                    window.clearTimeout(redirect);
                    document.getElementById('bk-return-notice').hidden = true;
                });
            });
        })();
    </script>
    <?php end_section('scripts'); ?>
<?php endif; ?>

