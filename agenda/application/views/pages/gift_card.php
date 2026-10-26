<?php
/**
 * Blondor - Página de la gift card: pagada (con su código), pendiente, rechazada o un problema.
 *
 * @var array|null $gift_card
 * @var string|null $problem
 * @var string $site_url
 */

extend('layouts/booking_mobile_layout');

$gift_card = vars('gift_card');
$problem = vars('problem');
$site = vars('site_url');
$money = fn($value) => '$' . number_format((int) $value, 0, ',', '.');
$whatsapp = 'https://wa.me/56966756614?text=' . rawurlencode('Hola Blondor, tengo una consulta sobre una gift card');
$status = $gift_card['status'] ?? null;
?>

<?php section('styles'); ?>
<meta name="robots" content="noindex">
<link rel="stylesheet" type="text/css" href="<?= asset_url('assets/css/pages/gift_card.css') ?>">
<?php end_section('styles'); ?>

<?php section('content'); ?>

<div class="bk" data-step="done">
    <header class="bk-top">
        <div class="bk-top__row">
            <a class="bk-brand" href="<?= e($site) ?>">
                <img src="<?= asset_url('assets/img/blondor-mark.png') ?>" alt="">
                <span>BLONDOR</span>
            </a>
        </div>
        <h1 class="bk-top__title">
            <?= in_array($status, ['active', 'used'], true) ? 'Tu gift card' : 'Gift card' ?>
        </h1>
    </header>

    <main class="bk-done">
        <?php if ($problem): ?>
            <h2>Algo no salió bien</h2>
            <p class="gc-text"><?= e($problem) ?></p>
            <div class="bk-done__actions">
                <a class="bk-primary" href="<?= e($site) ?>/#giftcards">Volver a intentarlo</a>
                <a class="bk-secondary" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
            </div>

        <?php elseif ($status === 'failed'): ?>
            <h2>El pago no se completó</h2>
            <p class="gc-text">No se hizo ningún cargo a tu tarjeta. Puedes intentarlo de nuevo.</p>
            <div class="bk-done__actions">
                <a class="bk-primary" href="<?= e($site) ?>/#giftcards">Intentar de nuevo</a>
                <a class="bk-secondary" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
            </div>

        <?php elseif ($status === 'pending'): ?>
            <h2>Estamos confirmando tu pago</h2>
            <p class="gc-text">Esto toma solo unos segundos. Si ya pagaste y esta página no cambia, escríbenos.</p>
            <div class="bk-done__actions">
                <a class="bk-primary" href="">Actualizar</a>
                <a class="bk-secondary" href="<?= e($whatsapp) ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
            </div>

        <?php else: ?>
            <div class="gc-card">
                <div class="gc-card__top">
                    <img src="<?= asset_url('assets/img/blondor-mark.png') ?>" alt="">
                    <span>GIFT CARD</span>
                </div>
                <p class="gc-card__amount"><?= e($money($gift_card['amount'])) ?></p>
                <?php if ($gift_card['recipient_name'] || $gift_card['buyer_name']): ?>
                    <p class="gc-card__people">
                        <?php if ($gift_card['recipient_name']): ?>Para <strong><?= e($gift_card['recipient_name']) ?></strong><?php endif; ?>
                        <?php if ($gift_card['buyer_name']): ?><?= $gift_card['recipient_name'] ? ' · ' : '' ?>De <strong><?= e($gift_card['buyer_name']) ?></strong><?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($gift_card['message']): ?>
                    <p class="gc-card__message">«<?= e($gift_card['message']) ?>»</p>
                <?php endif; ?>
                <div class="gc-card__code">
                    <span>Código</span>
                    <strong><?= e($gift_card['code']) ?></strong>
                </div>
            </div>

            <?php if ($status === 'used'): ?>
                <p class="gc-text"><strong>Esta gift card ya se usó completa.</strong></p>
            <?php elseif ($gift_card['balance'] < $gift_card['amount']): ?>
                <p class="gc-text">Saldo disponible: <strong><?= e($money($gift_card['balance'])) ?></strong></p>
            <?php endif; ?>

            <ul class="gc-steps">
                <li>Reserva tu hora y muestra este código en el salón.</li>
                <li>Sirve para cualquier servicio. Si cuesta menos, te queda saldo para la próxima visita.</li>
                <li>No vence.</li>
            </ul>

            <?php if ($gift_card['buyer_email'] && $gift_card['channel'] === 'online'): ?>
                <p class="bk-done__note">Te la enviamos a <strong><?= e($gift_card['buyer_email']) ?></strong><?= $gift_card['recipient_email'] ? ' y a <strong>' . e($gift_card['recipient_email']) . '</strong>' : '' ?>.</p>
            <?php endif; ?>

            <div class="bk-done__actions">
                <a class="bk-primary" href="<?= site_url('booking') ?>">Reservar hora</a>
                <a class="bk-secondary" href="<?= e($site) ?>">Ir a blondor.cl</a>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php end_section('content'); ?>
