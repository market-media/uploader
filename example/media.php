<?php
require __DIR__ . '/common.php';
$result = handleExample('media');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Exemplo de upload</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="form">
    <?= $result ?>
    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= escape($_SESSION['csrf']) ?>">
        <input type="text" name="name" placeholder="Nome de exibição" maxlength="255" required>
        <input type="file" name="file" required>
        <button>Enviar arquivo</button>
    </form>
</div>
</body>
</html>