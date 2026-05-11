# Uploader @MarketMedia

Uploader é um conjunto de pequenas classes para envio de imagens, arquivos e midias recebidos por um formulário de sua
aplicação. O Uploader trata, valida e envia os arquivos a seu servidor. A classe de imagem ainda consegue tratar
tamanhos com a biblioteca gd.

### Destaques

- Upload simples de imagens (Simples envio de imagens)
- Upload simples de arquivo (Simples envio de arquivos)
- Upload simples de mídia (Simples envio de midias)
- Gerenciando diretórios com esquemas de data (Gestão de diretórios com esquema de dados)
- Validação de imagens, arquivos e mídias por tipos MIME (Valida de imagens, arquivos e mídias por tipos MIME)
- Composer ready e compatível com PSR-2 (Pronto para o compositor e compatível com PSR-2)

## Instalação

Uploader está disponível via Composer:

```bash
"market-media/uploader": "2.0.*"
```

ou rode

```bash
composer require market-media/uploader
```

## Documentação

Para mais detalhes sobre como usar o upload, veja uma pasta de exemplo no diretório do componente. Nela terá um exemplo
de uso para cada classe. MarketMedia Uploader funciona assim:

#### Upload Image

```php
<?php

$image = new MarketMedia\Uploader\Image("uploads", "images", 600);

if ($_FILES) {
    try {
        $upload = $image->upload($_FILES['image'], $_POST['name']);
        echo "<img src='{$upload}' width='100%'>";
    } catch (Exception $e) {
        echo "<p>(!) {$e->getMessage()}</p>";
    }
}
```

#### Upload File

```php
<?php

$file = new MarketMedia\Uploader\File("uploads", "files");

if ($_FILES) {
    try {
        $upload = $file->upload($_FILES['file'], $_POST['name']);
        echo "<p><a href='{$upload}' target='_blank'>@MarketMedia</a></p>";
    } catch (Exception $e) {
        echo "<p>(!) {$e->getMessage()}</p>";
    }
}
```

#### Upload Media

```php
<?php

$media = new MarketMedia\Uploader\Media("uploads", "medias");

if ($_FILES) {
    try {
        $upload = $media->upload($_FILES['file'], $_POST['name']);
        echo "<p><a href='{$upload}' target='_blank'>@MarketMedia</a></p>";
    } catch (Exception $e) {
        echo "<p>(!) {$e->getMessage()}</p>";
    }
}
```

#### Upload by Filetype (Send)

```php
<?php

$postscript = new MarketMedia\Uploader\Send("uploads", "postscript", [
    "application/postscript"
], ["ai"]);

if ($_FILES) {
    try {
        $upload = $postscript->upload($_FILES['file'], $_POST['name']);
        echo "<p><a href='{$upload}' target='_blank'>@MarketMedia</a></p>";
    } catch (Exception $e) {
        echo "<p>(!) {$e->getMessage()}</p>";
    }
}
```

#### Upload Multiple

```php

$image = new MarketMedia\Uploader\Image("uploads", "images");

try {
    foreach ($image->multiple("file", $_FILES) as $file) {
        $image->upload($file, "image-" . $file["name"], 1200);
    }
    echo "Success!";
} catch (Exception $e) {
    echo "<p>(!) {$e->getMessage()}</p>";
}
```

## License

The MIT License (MIT). Please see [License File](https://github.com/robsonvleite/uploader/blob/master/LICENSE) for more
information.
