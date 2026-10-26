<?php
    header('Content-Type: application/json');
    $respuesta = array();
    if ($_SERVER["REQUEST_METHOD"] == "GET") {
        $nombre = htmlspecialchars($_GET['nombre']);
        $correo = htmlspecialchars($_GET['correo']);
        $telefono = htmlspecialchars($_GET['telefono']);
        $mensaje = htmlspecialchars($_GET['mensaje']);
        
        $to = 'peluqueria@blondor.cl';
        $subject = "Nuevo mensaje de $nombre";
        $body = "Nombre: $nombre\nCorreo: $correo\nTeléfono: $telefono\nMensaje:\n$mensaje";
        $headers = "From: peluqueria@blondor.cl\r\n";
        $headers .= "Reply-To: peluqueria@blondor.cl\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";


         // Verificar que todos los campos estén completos
        if (empty($nombre) || empty($correo) || empty($telefono) || empty($mensaje)) {
            $respuesta = [
                'status' => -1,
                'message' => 'Todos los campos son obligatorios.'
            ];
            echo json_encode($respuesta);
            exit;
        }
    
        if (mail($to, $subject, $body, $headers)) {
            $respuesta = [
                'status' => 1,
                'message' => 'Correo Enviado !'
            ];
        } else {
            $respuesta = [
                'status' => -1,
                'message' => 'ERROR'
            ];
        }

        echo json_encode($respuesta);
    }
?>