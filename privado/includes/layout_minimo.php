<?php
/** layout_minimo.php — Pantalla sin menú (cambio obligatorio de contraseña). Recibe $titulo y $contenido. */
?><!doctype html>
<html lang="es-AR" data-theme="dark">
<head>
<?= ui_head($titulo . ' — Moscode', true, false) ?>
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
</head>
<body class="login-pagina">
<?= $contenido ?>
</body>
</html>
