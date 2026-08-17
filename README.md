# Sistema de Gestão Patrimonial e Engenharia Clínica

Sistema web para controle de patrimônio e manutenção de equipamentos médico-hospitalares, desenvolvido para uso em rede hospitalar com múltiplas unidades.

São dois módulos que compartilham a mesma base de dados: o **controle patrimonial**, que acompanha cada bem desde a aquisição até a baixa, e a **engenharia clínica**, que cuida dos chamados, ordens de serviço e manutenções preventivas dos equipamentos médicos.

> **Sobre este repositório** — é uma versão neutra do sistema, sem credenciais, dados reais, identidade visual do cliente ou endereços de produção. Serve para demonstrar arquitetura, funcionalidades e decisões técnicas.

---

## Índice

- [Funcionalidades](#funcionalidades)
- [Tecnologias](#tecnologias)
- [Arquitetura](#arquitetura)
- [Instalação](#instalação)
- [Segurança](#segurança)
- [Estrutura de pastas](#estrutura-de-pastas)
- [Documentação](#documentação)
- [Licença](#licença)

---

## Funcionalidades

### Controle Patrimonial

**Cadastro e planilha** — Registro de bens com 56 campos, editáveis numa planilha em tela com filtros por coluna, ordenação, preenchimento por arrasto, busca por termo e exportação para Excel. Campos de lista fechada usam combobox, com validação também no servidor: o combobox não cobre colar células nem preenchimento por arrasto.

**Movimentação** — Transferência de bens entre unidades, setores e áreas, com histórico completo, geração de termo e notificação automática por e-mail. A localização atual de um item é derivada de origem ou destino conforme o estado de movimentação.

**Rotina de inspeção** — Conferência física por local, com marcação de itens localizados, estado de conservação e não conformidades. Gera termo de responsabilidade em PDF por setor, com assinatura do coordenador.

**Baixa e descarte** — Fluxo em duas etapas: pré-descarte, com aprovação, e baixa definitiva. Guarda foto, protocolo e assinaturas.

**Conciliação contábil** — Cruzamento entre o cadastro físico e a relação contábil, com anexo de nota fiscal, cálculo de depreciação e indicadores de divergência.

**Relatórios e indicadores** — Painéis com gráficos configuráveis: escolha de fonte de dados, dimensões, métricas e tipo de gráfico, com exportação em PNG e PDF.

### Engenharia Clínica

**Abertura de chamado** — Formulário público, acessível por QR Code, sem exigir login. Busca automática do equipamento por tag ou número de série, com limite de requisições por IP.

**Ordem de serviço** — Protocolo único do chamado até o encerramento. Salvamento por etapas, múltiplos técnicos por OS, registro de mão de obra e materiais, e movimentação patrimonial automática ao iniciar o atendimento, preservando a localização original para devolução.

**Manutenção externa** — Envio de equipamento a fornecedor, com controle de datas e retorno.

**Manutenções preventivas** — Agenda por periodicidade, com sinalização por cores conforme o vencimento, registro de execução ou adiamento, e histórico completo por equipamento.

**Estoque de peças** — Entrada por nota fiscal com múltiplos itens, saldo por unidade, transferências entre unidades e baixa automática no consumo em OS.

**Retirada de peças** — Aproveitamento de componentes de equipamentos baixados, com controle de disponibilidade e situação de cada peça.

### Painel do Desenvolvedor

Área restrita com gestão de usuários e sessões, registro de erros de PHP **e de JavaScript** capturados no navegador do usuário, classificação de possíveis ameaças, histórico de backups, auditoria de segurança automatizada e navegação nas tabelas do banco com trilha de alterações.

---

## Tecnologias

| Camada | Escolha |
|---|---|
| Servidor | PHP 8.3 |
| Banco | MySQL 8 / MariaDB, via MySQLi com prepared statements |
| Frontend | HTML, CSS e JavaScript sem framework |
| Gráficos | Chart.js |
| Planilhas | SheetJS |
| E-mail | PHPMailer via SMTP |
| Backup remoto | Google Drive API v3 com OAuth 2.0 |
| Hospedagem | Apache em hospedagem compartilhada |

**Sem framework, e isso foi deliberado.** O sistema roda em hospedagem compartilhada, sem acesso a terminal, sem Composer no servidor e sem processo de build. Atualização é envio de arquivo por FTP. Um framework ali seria um peso sem contrapartida.

---

## Arquitetura

### Princípio que organiza o código

**O que é duplicado diverge.** Três problemas reais que motivaram consolidações:

O menu de navegação estava copiado em onze arquivos. Quando um item mudava, alguns ficavam para trás — e ficaram. Virou `eng_clin_menu.php`, um arquivo só.

O bloco de movimentação patrimonial existia em três lugares. Um deles tinha um `bind_param` com número errado de parâmetros, e falhava em silêncio. Virou `eng_clin_mover_item.php`.

O encerramento de OS existia em duas telas, com regras diferentes. Virou uma função só.

### Módulos compartilhados

```
config_seguro.php        Localiza as credenciais fora da pasta pública
conexao.php              Conexão, modo manutenção, verificação de sessão e CSRF
seguranca_sessao.php     Cookie endurecido, expiração, token anti-CSRF
login_seguranca.php      Controle de tentativas de login
dev_seguranca.php        Registro de erros e ameaças
dev_captura.php          Captura automática de erros PHP (auto_prepend)
dev_captura_js.php       Captura de erros JS e injeção do token (auto_append)
backup_dump.php          Geração do dump, usada pelo cron e pelo painel
eng_clin_menu.php        Navegação do módulo de engenharia clínica
eng_clin_mover_item.php  Movimentação patrimonial com histórico e e-mail
```

### Uma decisão que vale explicar

A captura de erros e a proteção anti-CSRF são carregadas por `auto_prepend_file` e `auto_append_file`, diretivas do PHP configuradas em `.user.ini`. Elas fazem dois arquivos serem incluídos automaticamente antes e depois de **toda** página.

A alternativa seria adicionar um `require` em cada um dos ~110 arquivos. O problema não é o trabalho: é que o primeiro arquivo novo criado depois disso nasceria sem proteção, e ninguém lembraria. A diretiva pega tudo, inclusive o que ainda não existe.

---

## Instalação

Requisitos: PHP 8.1+, MySQL 8+ ou MariaDB 10.4+, Apache com `mod_rewrite` e suporte a `.htaccess`, e extensões `mysqli`, `openssl`, `curl`, `fileinfo`, `gd`, `zlib`.

```bash
# 1. Copie o código para a pasta pública do site
cp -r src/* /home/usuario/public_html/

# 2. Crie a pasta de configuração FORA da pasta pública
mkdir -p /home/usuario/config_sistema
cp config/segredos.exemplo.php /home/usuario/config_sistema/segredos.php
chmod 600 /home/usuario/config_sistema/segredos.php
# → edite o arquivo e preencha as credenciais

# 3. Crie a pasta de backups, também fora da pasta pública
mkdir -p /home/usuario/backups_sistema
chmod 750 /home/usuario/backups_sistema

# 4. Importe a estrutura do banco
mysql -u usuario_banco -p sistema_db < database/schema.sql

# 5. Ative a captura automática de erros
cp config/user.ini.exemplo /home/usuario/public_html/.user.ini
# → ajuste os caminhos dentro do arquivo

# 6. Agende o backup automático (semanal, segunda 02:00)
# 0 2 * * 1 /usr/local/bin/php /home/usuario/public_html/backup_run.php
```

O passo a passo detalhado, incluindo a configuração do Google Drive, está em **[docs/INSTALACAO.md](docs/INSTALACAO.md)**.

---

## Segurança

O sistema passou por uma revisão de segurança documentada. As medidas implementadas:

**Autenticação** — Senhas com bcrypt. Bloqueio progressivo por tentativas: 5 falhas no mesmo usuário bloqueiam por 15 minutos, depois 30, depois 60. O limite por IP é deliberadamente mais alto (20), porque numa rede corporativa todos saem pelo mesmo IP público e um limite baixo derrubaria o acesso de todos os setores.

**Sessão** — Cookie com `HttpOnly`, `Secure` e `SameSite=Lax`. Renovação do identificador no login, para anular fixação de sessão. Encerramento por inatividade detectada no navegador — necessário porque o *heartbeat* mantém a sessão viva no servidor enquanto a aba está aberta, ainda que não haja ninguém na frente da tela.

**CSRF** — Token por sessão, entregue também num cookie legível por JavaScript (*double submit cookie*). A verificação foi implantada primeiro em modo observação, registrando violações sem bloquear, e só depois ativada — o que revelou que a tela de maior valor era a única sem proteção, por causa de um `session_write_close()` que apagava o token antes de ele ser emitido.

**Injeção de SQL** — Prepared statements em todo o acesso a dados. Onde a parametrização não se aplica, como `ORDER BY`, o valor passa por lista branca.

**XSS** — Escape de saída no ponto de inserção no DOM, incluindo dentro de atributos HTML.

**Upload de arquivos** — Tipo verificado pelo conteúdo com `finfo`, não pela extensão enviada. A extensão final é derivada do tipo detectado, e o nome é gerado pelo sistema. A pasta de upload nega acesso HTTP e tem a execução de PHP desativada. Arquivos são entregues por um script que confere a sessão.

**Credenciais** — Fora da pasta pública, carregadas por caminho absoluto.

**Monitoramento** — Erros de PHP e de JavaScript, tentativas de acesso indevido e eventos de segurança ficam registrados e visíveis no painel, com agrupamento por impressão digital para que um erro repetido não vire milhares de linhas.

**Backup** — Dump completo semanal em dois destinos independentes, com rotação e relatório por e-mail em toda execução — inclusive nas bem-sucedidas, para que a ausência do e-mail seja sinal de que o agendamento parou.

Detalhes e o que permanece em aberto: **[SECURITY.md](SECURITY.md)**.

---

## Estrutura de pastas

```
.
├── src/                    Código da aplicação (vai para public_html)
│   ├── assets/             Imagens e recursos estáticos
│   └── uploads/            Arquivos enviados (bloqueado por .htaccess)
├── config/
│   ├── segredos.exemplo.php    Modelo de configuração
│   └── user.ini.exemplo        Modelo das diretivas do PHP
├── database/
│   ├── schema.sql          Estrutura das tabelas
│   └── migrations/         Alterações incrementais aplicadas
└── docs/
    ├── INSTALACAO.md       Passo a passo completo
    ├── ARQUITETURA.md      Decisões técnicas e organização
    ├── BANCO_DE_DADOS.md   Dicionário de dados
    └── INFRAESTRUTURA.md   O que existe no servidor além dos arquivos
```

---

## Documentação

| Documento | Conteúdo |
|---|---|
| [docs/INSTALACAO.md](docs/INSTALACAO.md) | Instalação completa, do banco ao Google Drive |
| [docs/ARQUITETURA.md](docs/ARQUITETURA.md) | Organização do código e decisões de projeto |
| [docs/BANCO_DE_DADOS.md](docs/BANCO_DE_DADOS.md) | Tabelas, relacionamentos e dicionário de dados |
| [docs/INFRAESTRUTURA.md](docs/INFRAESTRUTURA.md) | Configuração de servidor que não está no código |
| [SECURITY.md](SECURITY.md) | Medidas de segurança e itens em aberto |
| [CHANGELOG.md](CHANGELOG.md) | Histórico de versões |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Padrões de código e como contribuir |

---

## Licença

Distribuído sob a licença MIT. Ver [LICENSE](LICENSE).
