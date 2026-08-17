# Infraestrutura

Tudo o que existe no servidor **além dos arquivos do código**. É a informação que não fica versionada e que, sem registro, se perde quando alguém precisa reinstalar ou migrar.

---

## Estrutura de diretórios

Três pastas, e a posição de cada uma é intencional:

```
/home/usuario/
├── public_html/              ← a única pasta alcançável pela web
│   ├── (código da aplicação)
│   ├── .user.ini             diretivas do PHP
│   ├── .htaccess             regras do Apache
│   ├── tmp/                  temporários do backup
│   ├── logs/                 log do backup
│   └── uploads/              arquivos enviados (acesso HTTP negado)
│       └── termos/
├── config_sistema/           ← FORA da web: credenciais
│   └── segredos.php          permissão 600
└── backups_sistema/          ← FORA da web: cópias do banco
    └── backup_*.sql.gz       rotação automática
```

**Por que `config_sistema` e `backups_sistema` ficam fora de `public_html`**

Enquanto o Apache processa PHP, o conteúdo dos arquivos é invisível para quem acessa pela web. Mas basta uma falha de configuração — migração de servidor, handler mal configurado, uma diretiva quebrada — para o Apache passar a servir o código-fonte como texto puro. A senha do banco viraria pública.

O mesmo raciocínio vale para os dumps: um `.sql` dentro da pasta pública é o banco inteiro disponível para quem adivinhar o nome do arquivo.

Fora da pasta pública não existe URL que alcance esses arquivos, aconteça o que acontecer com o PHP. E o PHP continua lendo normalmente: a restrição é do servidor web, não da linguagem.

---

## Permissões

| Caminho | Permissão | Motivo |
|---|---|---|
| `config_sistema/` | 750 | Outros usuários do servidor compartilhado não listam o conteúdo |
| `config_sistema/segredos.php` | 600 | Somente o dono lê |
| `backups_sistema/` | 750 | Mesmo motivo |
| `uploads/` | 755 | O PHP precisa gravar |
| `uploads/*` (arquivos) | 640 | Gravados assim pelo próprio sistema |

Em hospedagem compartilhada onde o PHP roda como outro usuário, a permissão 600 pode impedir a leitura. Nesse caso, 644 é aceitável: o arquivo está fora da pasta pública, e a permissão restrita é apenas uma camada extra contra vizinhos no mesmo servidor, não a proteção principal.

---

## Diretivas do PHP — `.user.ini`

Arquivo em `public_html/.user.ini`:

```ini
auto_prepend_file = /home/usuario/public_html/dev_captura.php
auto_append_file  = /home/usuario/public_html/dev_captura_js.php
```

São elas que ligam a captura de erros e a proteção anti-CSRF. Sem elas, seria preciso um `require` em cada um dos ~110 arquivos — e o próximo arquivo criado nasceria sem proteção.

**O PHP guarda este arquivo em cache por até 5 minutos.** Depois de salvar, aguarde antes de concluir que não funcionou.

Em servidores que usam `mod_php` em vez de FPM/CGI, o `.user.ini` é ignorado. A alternativa é no `.htaccess`:

```apache
php_value auto_prepend_file /home/usuario/public_html/dev_captura.php
php_value auto_append_file  /home/usuario/public_html/dev_captura_js.php
```

Se o site retornar erro 500 depois disso, o servidor não aceita `php_value` no `.htaccess`. Remover as linhas restaura o funcionamento imediatamente.

Para confirmar que está ativo, o painel administrativo tem um item de auditoria chamado "Captura de erros ativa".

---

## Bloqueio da pasta de uploads

Arquivo `public_html/uploads/.htaccess`:

```apache
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>

RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar .cgi .pl .py .sh
AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .php8 .phar

Options -ExecCGI -Indexes
```

Três barreiras independentes: nega acesso HTTP, remove o processador de PHP e desliga a execução de CGI. A redundância existe porque a primeira regra sempre pode falhar por configuração do servidor.

Os arquivos continuam acessíveis pela aplicação — o PHP lê do disco, o que não passa pela restrição HTTP, e entrega ao usuário depois de conferir a sessão.

**Para verificar se está funcionando**, peça um arquivo que não existe:

```
https://seudominio.com.br/uploads/termos/naoexiste.pdf
```

- **403** → o bloqueio está ativo
- **404** → o servidor está ignorando o `.htaccess`

---

## Tarefa agendada (cron)

```cron
0 2 * * 1 /usr/local/bin/php /home/usuario/public_html/backup_run.php
```

Segunda-feira, 02:00. Três pontos de atenção:

**O caminho do PHP precisa ser absoluto.** O cron roda com um `PATH` mínimo, que normalmente não inclui o interpretador. Um comando começando por `php` falha na hora, sem executar nada e sem deixar rastro. Foi exatamente isso que manteve um backup "configurado" sem nunca ter rodado.

Para descobrir o caminho no seu servidor: `which php`, ou no painel de hospedagem, na seção de versão do PHP. Valores comuns: `/usr/local/bin/php`, `/usr/bin/php`, `/opt/cpanel/ea-php83/root/usr/bin/php`.

**O horário é do servidor, não o seu.** Hospedagens costumam ficar em outro fuso. Para testar sem esperar, use `*/5 * * * *` (a cada 5 minutos), confirme, e volte ao horário definitivo — não esqueça de voltar.

**`MAILTO=""`** evita o e-mail bruto do cron. O sistema já envia um relatório legível por conta própria.

---

## Google Drive — backup remoto

O backup usa **OAuth 2.0 da conta do usuário**, não conta de serviço.

### Por que não conta de serviço

Conta de serviço é uma identidade sem pessoa por trás, e **sem cota de armazenamento**. Qualquer upload dela é recusado com `storageQuotaExceeded`, porque o arquivo ficaria sob a propriedade dela. A saída oficial do Google é Drive Compartilhado, que existe apenas no Workspace — em conta pessoal, não há alternativa.

Com OAuth, o arquivo pertence ao usuário e ocupa a cota dele.

### Por que o escopo `drive.file`

`drive.file` é um escopo **não sensível**, o que permite publicar o app sem passar pela verificação do Google — processo de semanas, com política de privacidade e revisão manual.

Publicar importa por um motivo específico: enquanto o app fica em modo "Teste", **o Google expira o refresh token a cada 7 dias**. O backup pararia de enviar toda semana, em silêncio.

O preço é que `drive.file` só enxerga arquivos criados pelo próprio app. A pasta de destino é criada pelo sistema, e o ID fica guardado — não é possível usar uma pasta pré-existente.

### Configuração

1. **Google Cloud Console** → novo projeto
2. **APIs e serviços → Biblioteca** → ativar **Google Drive API**
3. **Tela de permissão OAuth** → tipo **Externo** → preencher nome e e-mails → **PUBLICAR APP**
4. **Credenciais → Criar credenciais → ID do cliente OAuth** → **Aplicativo da Web**
5. Em **URIs de redirecionamento autorizados**, informar exatamente:
   ```
   https://www.seudominio.com.br/backup_oauth.php
   ```
   Precisa bater caractere por caractere, inclusive o `www`. Divergência resulta em `redirect_uri_mismatch`.
6. Acessar `backup_oauth.php` no sistema, informar o ID e a chave secreta, e autorizar

O arquivo com o token fica em `backups_sistema/oauth_sistema.json`, fora da pasta pública.

### Firewall pode barrar o retorno

Alguns servidores rodam **ModSecurity**, que bloqueia o retorno do Google com **"Not Acceptable"**. O motivo é o parâmetro `iss=https://accounts.google.com` na URL: um endereço dentro da query string dispara a regra de inclusão remota.

A autorização funciona — só a página de retorno é barrada. Como o código fica visível na barra de endereços, a tela de autorização aceita colá-lo manualmente. É uma operação única.

---

## E-mail (SMTP)

Envio pelo Gmail com **senha de app** — não a senha da conta. Gerada em Conta Google → Segurança → Senhas de app, e exige verificação em duas etapas ativa.

Dois destinatários distintos, por razões diferentes:

| Aviso | Vai para | Por quê |
|---|---|---|
| Falha ou êxito de backup | Desenvolvedor | Problema técnico |
| Item movimentado | Equipe de patrimônio | Informação operacional |
| Termo gerado | Equipe de patrimônio | Informação operacional |

Misturar os dois numa constante só significa que trocar o e-mail do desenvolvedor desvia as notificações da equipe.

---

## Banco de dados

MySQL 8 / MariaDB. Collation `utf8mb4_unicode_ci` em todas as tabelas.

Dois comportamentos do MySQL moderno que afetam este código:

**`ONLY_FULL_GROUP_BY`** (padrão desde o MySQL 5.7) rejeita `SELECT` com colunas não agregadas fora do `GROUP BY`.

**Modo estrito** rejeita comparação literal com `'0000-00-00'` em colunas `DATE`. A alternativa usada é `YEAR(coluna) > 0`.

E, no PHP 8.1+, o `mysqli` passou a lançar exceções por padrão. O código assume o comportamento antigo, em que `prepare()` retorna `false`, por isso há `mysqli_report(MYSQLI_REPORT_OFF)` nos pontos de entrada.

---

## Checklist de migração

Ao mover para outro servidor:

- [ ] Criar as três pastas com as permissões corretas
- [ ] Ajustar os caminhos em `.user.ini` e no `.htaccess` de `uploads/`
- [ ] Preencher `segredos.php` com as credenciais do novo ambiente
- [ ] Importar o banco e conferir a collation
- [ ] Recriar a tarefa agendada com o caminho absoluto do PHP no novo servidor
- [ ] Adicionar o novo domínio nos URIs de redirecionamento do OAuth
- [ ] Reautorizar o Google Drive
- [ ] Conferir o item "Captura de erros ativa" na auditoria
- [ ] Testar: login, upload de arquivo, envio de e-mail e backup manual
- [ ] Confirmar que `uploads/` retorna 403
