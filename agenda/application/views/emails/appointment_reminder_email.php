<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recordatorio · Blondor</title></head>
<body style="margin:0;padding:24px 12px;background:#f3f6f8;font-family:Arial,Helvetica,sans-serif;color:#243746">
<div style="max-width:600px;margin:0 auto;background:white;border-radius:14px;overflow:hidden">
    <div style="padding:22px;background:#015288;color:white;font-size:24px;font-weight:bold">BLONDOR</div>
    <div style="padding:24px">
        <h1 style="font-size:22px;margin:0 0 16px"><?= e($heading) ?></h1>
        <p><?= $is_staff ? 'Esta cita se acerca. Puedes revisar sus detalles en tu agenda.' : 'Hola ' . e($customer) . ', te recordamos tu próxima cita. ¡Te esperamos!' ?></p>
        <table style="width:100%;border-collapse:collapse;font-size:16px">
            <tr><th style="padding:8px 0;text-align:left">Fecha</th><td><?= e($date) ?></td></tr>
            <tr><th style="padding:8px 0;text-align:left">Hora</th><td><?= e($time) ?> · <?= e($timezone) ?></td></tr>
            <tr><th style="padding:8px 0;text-align:left">Servicio</th><td><?= e($service) ?><?= $hair_length ? ' · ' . e($hair_length) : '' ?></td></tr>
            <tr><th style="padding:8px 0;text-align:left">Profesional</th><td><?= e($provider) ?></td></tr>
            <?php if ($is_staff): ?>
                <tr><th style="padding:8px 0;text-align:left">Clienta</th><td><?= e($customer) ?></td></tr>
                <?php if ($phone): ?><tr><th style="padding:8px 0;text-align:left">Teléfono</th><td><?= e($phone) ?></td></tr><?php endif; ?>
            <?php endif; ?>
        </table>
        <p style="margin:24px 0">Av. Apoquindo 4900, local 53, Las Condes.</p>
        <p><a style="display:inline-block;background:#015288;color:white;padding:14px 18px;border-radius:8px;text-decoration:none" href="<?= e($link) ?>"><?= $is_staff ? 'Ver cita en la agenda' : 'Ver o cambiar mi cita' ?></a></p>
    </div>
</div>
</body>
</html>
