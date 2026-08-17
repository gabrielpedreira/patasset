# Instalação

## Requisitos

| Item | Versão mínima |
|---|---|
| PHP | 8.1 (testado em 8.3) |
| MySQL | 8.0 — ou MariaDB 10.4 |
| Apache | com suporte a `.htaccess` |

Extensões do PHP: `mysqli`, `openssl`, `curl`, `fileinfo`, `gd`, `zlib`, `mbstring`.

O `fileinfo` é obrigatório: é ele que identifica o tipo real dos arquivos enviados. Sem ele, a validação de upload cai para um método menos confiável.

---

## 1. Banco de dados

Crie o banco com collation `utf8mb4_unicode_ci`:

```sql
CREATE DATABASE sistema_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

A collation importa mais do que parece. `utf8mb4_unicode_ci` é insensível a acento e a caixa, o que faz comparações como `TRIM(campo) = 'NAO'` casarem com `não`, `NÃO` e `Nao` — situação real numa base preenchida por pessoas diferentes ao longo de anos.

Se uma tabela ficar com collation diferente das outras, junções entre elas falham com erro `#1267`.

Importe a estrutura:

```bash
mysql -u usuario_banco -p sistema_db < database/schema.sql
```

---

## 2. Código

```bash
cp -r src/* /home/usuario/public_html/
```

---

## 3. Credenciais, fora da pasta pública

```bash
mkdir -p /home/usuario/config_sistema
cp config/segredos.exemplo.php /home/usuario/config_sistema/segredos.php
chmod 750 /home/usuario/config_sistema
chmod 600 /home/usuario/config_sistema/segredos.php
```

Edite `segredos.php` e preencha os valores.

A pasta fica **no mesmo nível** de `public_html`, não dentro dela. O `config_seguro.php` a localiza por caminho relativo (`dirname(__DIR__)`), o que faz a configuração continuar funcionando se a hospedagem mudar o diretório base.

**Se o site exibir "Configuração do servidor indisponível"**, o arquivo não foi encontrado ou não pode ser lido. Em hospedagem onde o PHP roda como outro usuário, a permissão 600 impede a leitura — use 644. O arquivo está fora da pasta pública de qualquer forma; a permissão restrita é camada extra contra vizinhos no mesmo servidor, não a proteção principal.

---

## 4. Pastas de trabalho

```bash
mkdir -p /home/usuario/backups_sistema && chmod 750 /home/usuario/backups_sistema
mkdir -p /home/usuario/public_html/tmp /home/usuario/public_html/logs
mkdir -p /home/usuario/public_html/uploads/termos
```

Confirme que `uploads/.htaccess` foi copiado. Ele nega acesso HTTP e desativa a execução de PHP na pasta — sem isso, um arquivo enviado por qualquer usuário poderia ser executado no servidor.

**Verificação:**

```
https://seudominio.com.br/uploads/termos/naoexiste.pdf
```

Deve retornar **403**. Se retornar **404**, o servidor está ignorando o `.htaccess` e os arquivos precisam ir para fora da pasta pública.

---

## 5. Captura automática de erros

```bash
cp config/user.ini.exemplo /home/usuario/public_html/.user.ini
```

Ajuste os caminhos dentro do arquivo. O PHP guarda o `.user.ini` em cache por até **5 minutos** — aguarde antes de concluir que não funcionou.

Confirme no painel administrativo, em **Auditoria**, o item "Captura de erros ativa".

Se o servidor usar `mod_php`, o `.user.ini` é ignorado; a alternativa está em [INFRAESTRUTURA.md](INFRAESTRUTURA.md).

---

## 6. Primeiro usuário

Como o cadastro público foi removido, o primeiro usuário entra pelo banco:

```sql
INSERT INTO usuarios (usuario, senha, permicao, classe_usuario, status)
VALUES ('admin', '$2y$10$COLE_AQUI_O_HASH', 'A', 'DEV', 'ATIVO');
```

Gere o hash com:

```bash
php -r "echo password_hash('SuaSenhaForte123', PASSWORD_DEFAULT), PHP_EOL;"
```

Depois de entrar, crie os demais usuários pelo painel administrativo, que valida formato e força de senha.

Classes disponíveis: `DEV` (acesso total), `PATRIMONIO`, `ENGENHARIA CLINICA`.
Níveis: `A` (administrador), `B`, `C` (operador).

---

## 7. E-mail

No `segredos.php`, preencha os dados de SMTP.

Com Gmail, use **senha de app** — não a senha da conta. Gerada em Conta Google → Segurança → Senhas de app, e exige verificação em duas etapas ativa.

Teste movimentando um item: deve chegar a notificação.

---

## 8. Backup

### 8.1 Cópia local

Já funciona com as pastas do passo 4. Confirme os parâmetros no `backup_config.php`:

```php
define('BACKUP_LOCAL_ATIVO', true);
define('BACKUP_LOCAL_DIR',   dirname(__DIR__) . '/backups_sistema');
define('BACKUP_MANTER',      30);   // cópias mantidas em cada destino
define('BACKUP_COMPACTAR',   true);
```

### 8.2 Google Drive

Acesse `backup_oauth.php` como usuário `DEV` e siga os cinco passos da tela. O detalhamento, incluindo as armadilhas conhecidas, está em [INFRAESTRUTURA.md](INFRAESTRUTURA.md) — em resumo:

- Publique o app na tela de permissão OAuth. Em modo "Teste", o Google expira o acesso a cada 7 dias.
- O URI de redirecionamento precisa bater caractere por caractere, com `https` e `www`.
- Se o retorno der "Not Acceptable", é o firewall do servidor; a tela aceita colar o código manualmente.

### 8.3 Tarefa agendada

```cron
0 2 * * 1 /usr/local/bin/php /home/usuario/public_html/backup_run.php
```

**O caminho do PHP precisa ser absoluto.** O cron roda com `PATH` mínimo; um comando começando por `php` falha sem executar nada e sem deixar rastro.

Descubra o caminho com `which php` ou no painel de hospedagem. Comuns: `/usr/local/bin/php`, `/usr/bin/php`, `/opt/cpanel/ea-php83/root/usr/bin/php`.

O horário é do **servidor**, que pode estar em outro fuso. Para testar, use `*/5 * * * *`, confirme, e volte ao horário definitivo.

---

## 9. Verificação final

Percorra esta lista antes de liberar para uso:

- [ ] Login funciona
- [ ] Painel administrativo abre e a Auditoria não acusa erro grave
- [ ] "Captura de erros ativa" em verde
- [ ] Cadastro e edição na planilha salvam
- [ ] Movimentação gera histórico e envia e-mail
- [ ] Upload de PDF funciona; upload de `.php` renomeado para `.pdf` é **recusado**
- [ ] `uploads/` retorna 403 no acesso direto
- [ ] Backup manual pelo painel baixa o arquivo
- [ ] "Executar backup automático" grava nos dois destinos
- [ ] Relatório de backup chega por e-mail

O teste do arquivo renomeado é o mais importante da lista: é o que confirma que a validação lê o conteúdo, não a extensão.

---

## Problemas comuns

| Sintoma | Causa provável |
|---|---|
| "Configuração do servidor indisponível" | Caminho ou permissão do `segredos.php` |
| Erro 500 em todas as páginas | Caminho errado no `.user.ini` — remova as linhas |
| "Captura de erros ativa" em vermelho | `.user.ini` não aplicado; aguarde 5 min ou use `.htaccess` |
| Backup não roda no horário | Caminho do PHP no cron, ou fuso do servidor |
| `storageQuotaExceeded` no Drive | Conta de serviço em vez de OAuth |
| `redirect_uri_mismatch` | URI divergente — confira `www` e `https` |
| "Not Acceptable" no retorno do OAuth | ModSecurity; use a colagem manual do código |
| Erro `#1267` em consultas | Collation diferente entre tabelas |
| `#1060 Duplicate column` numa migração | Coluna já existe; remova a linha e siga |
