<?php
/**
 * Blondor - Reserva confirmada (móvil primero).
 *
 * @var array $summary
 */

extend('layouts/booking_mobile_layout');

$summary = vars('summary');
$start = new DateTime($summary['start']);
$days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$when = ucfirst($days[(int) $start->format('w')]) . ' ' . $start->format('j') . ' de ' . $months[(int) $start->format('n') - 1];
$time = setting('time_format') === 'regular'
    ? $start->format('g:i') . ($start->format('H') < 12 ? ' a. m.' : ' p. m.')
    : $start->format('H:i');
$duration = $summary['duration'] < 60
    ? $summary['duration'] . ' min'
    : floor($summary['duration'] / 60) . ' h' . ($summary['duration'] % 60 ? ' ' . $summary['duration'] % 60 . ' min' : '');
$price = $summary['price'] > 0
    ? ($summary['price_type'] === 'from' ? 'desde ' : '') . '$' . number_format($summary['price'], 0, ',', '.')
    : '';
$address = 'Av. Apoquindo 4900, local 53, Las Condes';

// "MECHAS CON PAPEL" → "Mechas con papel", igual que en la reserva.
$service_name = trim(preg_replace('/\s+/', ' ', $summary['service']));
$service_name = $service_name === mb_strtoupper($service_name) ? mb_strtolower($service_name) : $service_name;
$service_name = mb_strtoupper(mb_substr($service_name, 0, 1)) . mb_substr($service_name, 1);
?>

<?php section('content'); ?>

<div class="bk" data-step="done">
    <header class="bk-top">
        <div class="bk-top__row">
            <a class="bk-brand" href="https://blondor.cl">
                <img src="<?= asset_url('assets/img/blondor-mark.png') ?>" alt="">
                <span>BLONDOR</span>
            </a>
        </div>
        <h1 class="bk-top__title">Reserva lista</h1>
    </header>

    <main class="bk-done">
        <div class="bk-done__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M5 12l5 5 9-10"/></svg>
        </div>

        <h2>¡Te esperamos!</h2>

        <div class="bk-summary">
            <p class="bk-summary__service"><?= e($service_name) ?></p>
            <p class="bk-summary__when"><?= e($when) ?>, <?= e($time) ?></p>
            <ul class="bk-summary__facts">
                <li><?= e($duration) ?></li>
                <?php if ($price): ?><li><?= e($price) ?></li><?php endif; ?>
            </ul>
            <p class="bk-summary__where"><?= e($address) ?></p>
        </div>

        <?php if (!empty($summary['email'])): ?>
            <p class="bk-done__note">Te enviamos la confirmación a <strong><?= e($summary['email']) ?></strong>. Si no la ves, revisa la carpeta de spam.</p>
        <?php endif; ?>

        <div class="bk-done__actions">
            <a class="bk-primary" href="<?= e(vars('add_to_google_url')) ?>" target="_blank" rel="noopener">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16M12 13v4M10 15h4"/></svg>
                Agregar a mi calendario
            </a>
            <a class="bk-secondary" href="https://maps.google.com/?q=<?= rawurlencode($address) ?>" target="_blank" rel="noopener">
                Cómo llegar
            </a>
            <a class="bk-secondary" href="<?= e($summary['manage_url']) ?>">
                Cambiar o cancelar la cita
            </a>
        </div>
    </main>
</div>

<?php end_section('content'); ?>
