# Uploader @MarketMedia

Biblioteca de upload HTTP para imagens, arquivos e mídias.

## Requisitos e instalação

PHP ^8.5 e extensões gd, fileinfo, zip, mbstring e exif.
O suporte GD a JPEG, PNG, GIF e WebP deve estar habilitado.

~~~bash
composer require market-media/uploader
~~~

## Segurança e mudanças de compatibilidade

- upload() aceita somente arquivos de um upload HTTP válido, com error igual a UPLOAD_ERR_OK. Arrays apontando para arquivos locais são rejeitados, inclusive no CLI.
- O tamanho é medido no arquivo temporário. O campo type do cliente não é usado como autorização: o MIME é detectado por Fileinfo e comparado com um mapa por extensão.
- Documentos DOCX/XLSX/ODS/ODT também têm seu contêiner ZIP inspecionado, sem extração. Isso não equivale à análise completa de documentos, detecção de macros ou antivírus. DOC/XLS antigos compartilham assinaturas de contêiner e não são distinguidos por um parser completo.
- Todo nome salvo tem um identificador aleatório de 128 bits. O argumento $name é mantido por compatibilidade de chamada, mas não compõe o nome físico. Guarde o nome de exibição separadamente, com limite de tamanho.
- O destino é criado exclusivamente, sem sobrescrever arquivos existentes. Falhas removem o arquivo parcial e lançam exceção. Diretórios novos usam 0700 e arquivos 0600; no Windows, configure também as ACLs.
- O caminho retornado é absoluto e interno. Não o exponha em respostas públicas nem o use como URL. Associe um identificador público ao arquivo e autorize cada download.
- fileTypeDir deve ser relativo à raiz confiável: são rejeitados caminhos absolutos, segmentos .., wrappers e links simbólicos nos subdiretórios. A raiz vem da configuração do servidor, nunca de parâmetros HTTP. Seus diretórios ancestrais devem ser controlados pela aplicação.
- isAllowed() e isExtension() agora são métodos de instância, inclusive em Send. Troque File::isAllowed() por $file->isAllowed(). Configurações de Send não afetam outras instâncias.
- As imagens são inspecionadas antes da decodificação e reencodificadas. GIFs são convertidos em imagens estáticas com o primeiro quadro; a animação não é preservada.
- Não há uso de shell ou execução de comandos. Removidas as chamadas a imagedestroy(), depreciada no PHP 8.5.

O armazenamento deve ficar **fora da raiz pública**, com permissões restritas e sem execução de scripts. A biblioteca não detecta automaticamente o document root da aplicação. Não permita que outros usuários do sistema modifiquem os diretórios de armazenamento.

Autenticação, autorização, CSRF, limites de frequência, cotas acumuladas e política de retenção pertencem à aplicação. MIME e extensão não garantem ausência de conteúdo malicioso: documentos e arquivos compactados podem precisar de antivírus ou reconstrução segura. Downloads devem usar autorização, Content-Disposition: attachment e X-Content-Type-Options: nosniff; conteúdo público deve ser servido em origem isolada.

## Uso

As chamadas abaixo pertencem a um controlador que já verificou autenticação, autorização e CSRF. Configure um diretório privado no servidor e mantenha display_errors=Off em produção.

~~~php
use MarketMedia\Uploader\Image;
use MarketMedia\Uploader\File;
use MarketMedia\Uploader\Media;
use MarketMedia\Uploader\Send;

$root = '/srv/app-private/uploads';

$image = new Image($root, 'images'); // Com subdiretórios de ano/mês.
$file = new File($root, 'documents', false);
$media = new Media($root, 'media');
$custom = new Send($root, 'postscript', ['application/postscript'], ['ai']);

try {
    $storedPath = $image->upload($_FILES['image'], 'Nome de exibição', 1200);
    // Guarde a associação entre o arquivo, seu proprietário e um identificador público.
    // Retorne esse identificador; não exponha $storedPath.
} catch (\Throwable $error) {
    error_log($error->getMessage());
    // Envie uma mensagem genérica e um status adequado pela resposta da aplicação.
}
~~~

File, Media e Send usam upload(array $file, string $name).
Image usa upload(array $image, string $name, int $width = 2000, ?array $quality = null).
A qualidade padrão é ['jpg' => 75, 'png' => 5, 'webp' => 75]; configurações parciais são aceitas.
JPEG/WebP aceitam qualidade inteira de 0 a 100 e PNG de 0 a 9.
A largura de saída deve ser positiva e não pode ultrapassar o limite configurado. Imagens menores não são ampliadas.

### Limites

Os valores padrão são: 20 MiB por arquivo, 20 arquivos em multiple(), largura e altura de até 8192 pixels e até 12 milhões de pixels por imagem.

~~~php
$image->setLimits(
    maxBytes: 10 * 1024 * 1024,
    maxFiles: 5,
    maxWidth: 4096,
    maxHeight: 4096,
    maxPixels: 8000000
);

foreach ($image->multiple('images', $_FILES) as $upload) {
    $storedPath = $image->upload($upload, 'Imagem', 1200);
}
~~~

Esses limites não substituem upload_max_filesize, post_max_size, max_file_uploads, memory_limit, limites do servidor HTTP ou cotas por usuário. Ajuste o teto de pixels à memória disponível e à concorrência dos workers. maxFiles limita o grupo recebido por multiple(), não o total histórico de uploads.

### Tipos personalizados

Com um único MIME, ele se aplica a todas as extensões configuradas. Quando as duas listas têm o mesmo tamanho, cada extensão corresponde ao MIME da mesma posição. Para aliases ou relações mais complexas, forneça um mapa explícito:

~~~php
$custom = new Send(
    $root,
    'reports',
    ['application/pdf', 'text/csv', 'text/plain'],
    ['pdf', 'csv'],
    false,
    [
        'pdf' => ['application/pdf'],
        'csv' => ['text/csv', 'text/plain'],
    ]
);
~~~

Listas ambíguas sem mapa são rejeitadas. A configuração de Send é confiável: não derive listas permitidas dos parâmetros enviados pelo usuário e não permita formatos executáveis.

## Exemplos no navegador

Os exemplos vêm desabilitados. Para testar localmente:

1. Crie um diretório privado fora da raiz pública.
2. Defina UPLOADER_EXAMPLES_ENABLED=1 e UPLOADER_STORAGE_DIR com o caminho absoluto desse diretório.
3. Sirva a pasta example com PHP 8.5.

Os exemplos usam token CSRF, cookies de sessão com HttpOnly e SameSite=Lax, links de download por identificador e acesso apenas aos arquivos enviados pela própria sessão. Impõem uma cota de demonstração de 20 arquivos e 100 MiB por sessão. A resposta de download é um anexo e não expõe o caminho interno. Formulários e downloads usam Cache-Control: no-store.

São exemplos de desenvolvimento, sem login. A sessão não substitui autenticação e a cota por sessão não impede abuso por novas sessões. Em produção, integre o usuário autenticado, cotas persistentes, limite de frequência e limpeza dos arquivos antigos.

## Testes

~~~bash
composer validate --strict
composer check-platform-reqs --lock
composer test
~~~

Os testes exigem proc_open, sockets locais e as extensões declaradas. Iniciam um servidor PHP temporário em loopback e enviam requisições multipart reais. Verificam formatos de imagem, transparência, arquivos falsos, origem HTTP, limites, colisões, limpeza de gravações parciais, isolamento de políticas e segurança dos exemplos. Avisos e depreciações causam falha.

## Referências

- [OWASP: File Upload Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html)
- [Migração para PHP 8.5](https://www.php.net/manual/en/migration85.deprecated.php)

## Licença

MIT. Consulte o arquivo LICENSE.