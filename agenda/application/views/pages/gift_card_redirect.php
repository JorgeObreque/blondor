<?php
/**
 * Blondor - Envía a la compradora a Webpay (POST con token_ws, como pide Transbank).
 *
 * @var string $redirect_url
 * @var string $token
 */
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Ir a Webpay | Blondor</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #0f5289;
            color: #fff;
            font-family: Manrope, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            text-align: center;
        }

        button {
            margin-top: 16px;
            height: 48px;
            padding: 0 22px;
            border: 0;
            border-radius: 24px;
            background: #fff;
            color: #0f5289;
            font: 800 16px/1 inherit;
            cursor: pointer;
        }
    </style>
</head>
<body>
<form id="webpay" method="post" action="<?= e(vars('redirect_url')) ?>">
    <input type="hidden" name="token_ws" value="<?= e(vars('token')) ?>">
    <p>Te estamos llevando a Webpay para pagar…</p>
    <button type="submit">Ir a pagar</button>
</form>
<script>document.getElementById('webpay').submit();</script>
</body>
</html>
