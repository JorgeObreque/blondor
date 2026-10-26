<?php
/**
 * Blondor - Correo con la gift card (a quien compra y a quien la recibe).
 *
 * @var array $gift_card
 * @var bool $for_buyer
 * @var string $view_url
 * @var string $booking_url
 */

$money = '$' . number_format((int) $gift_card['amount'], 0, ',', '.');
$from = $gift_card['buyer_name'] ?: 'Alguien especial';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gift card Blondor</title>
</head>
<body style="margin:0; padding:0; background:#f2f6fa; font-family:Arial, Helvetica, sans-serif; color:#12263a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f6fa;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;">
                <tr>
                    <td style="padding:0 4px 16px; font-size:16px; line-height:1.5;">
                        <?php if ($for_buyer): ?>
                            Hola <?= e($gift_card['buyer_name']) ?>, gracias por tu compra. Esta es tu gift card Blondor.
                            <?php if ($gift_card['recipient_email']): ?>
                                También se la enviamos a <?= e($gift_card['recipient_name'] ?: $gift_card['recipient_email']) ?>.
                            <?php endif; ?>
                        <?php else: ?>
                            Hola <?= e($gift_card['recipient_name'] ?: '') ?>, <?= e($from) ?> te regaló una gift card para
                            Blondor.
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="background:#015288; border-radius:18px; padding:24px; color:#ffffff;">
                        <div style="font-size:12px; font-weight:bold; letter-spacing:3px; color:#c9dcee;">BLONDOR · GIFT CARD</div>
                        <div style="font-family:Georgia, serif; font-size:44px; line-height:1.1; margin:12px 0 8px;"><?= e($money) ?></div>
                        <?php if ($gift_card['recipient_name']): ?>
                            <div style="font-size:15px; color:#dde8f2;">Para <strong style="color:#ffffff;"><?= e($gift_card['recipient_name']) ?></strong> · De <strong style="color:#ffffff;"><?= e($from) ?></strong></div>
                        <?php endif; ?>
                        <?php if ($gift_card['message']): ?>
                            <div style="font-size:15px; font-style:italic; color:#fffce9; margin-top:10px;">«<?= e($gift_card['message']) ?>»</div>
                        <?php endif; ?>
                        <div style="background:#fffce9; color:#003366; border-radius:12px; padding:12px 14px; margin-top:16px;">
                            <div style="font-size:11px; font-weight:bold; letter-spacing:2px;">CÓDIGO</div>
                            <div style="font-size:24px; font-weight:bold; letter-spacing:2px;"><?= e($gift_card['code']) ?></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 4px 8px; font-size:15px; line-height:1.6; color:#3c4d5f;">
                        <strong style="color:#12263a;">Cómo usarla</strong><br>
                        1. Reserva tu hora y muestra este código en el salón.<br>
                        2. Sirve para cualquier servicio. Si cuesta menos, te queda saldo para la próxima visita.<br>
                        3. No vence.
                    </td>
                </tr>
                <tr>
                    <td style="padding:12px 4px;">
                        <a href="<?= e($booking_url) ?>" style="display:inline-block; background:#015288; color:#ffffff; text-decoration:none; font-weight:bold; padding:14px 22px; border-radius:24px;">Reservar hora</a>
                        &nbsp;
                        <a href="<?= e($view_url) ?>" style="display:inline-block; color:#015288; text-decoration:none; font-weight:bold; padding:14px 8px;">Ver gift card</a>
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 4px 0; font-size:13px; color:#5c6b7a;">
                        Blondor · Av. Apoquindo 4900, local 53, Las Condes · WhatsApp +56 9 6675 6614
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
