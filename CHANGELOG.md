# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

## [Não publicado]

### Segurança
- Correção de upload de arquivo sem validação de tipo, que permitia enviar e
  executar código no servidor a partir de qualquer conta autenticada
- Documentos passam a ser servidos por script que confere a sessão; antes eram
  acessíveis por link direto, sem login
- Proteção anti-CSRF com token por sessão, implantada primeiro em modo
  observação e depois ativada
- Cookie de sessão com `HttpOnly`, `Secure` e `SameSite=Lax`; renovação do
  identificador no login
- Encerramento de sessão por inatividade detectada no navegador
- Escape de saída no DOM em 15 telas, incluindo dentro de atributos HTML
- Credenciais movidas para fora da pasta pública
- Bloqueio progressivo de login, com contagem separada por usuário e por IP
- `display_errors` desativado em produção; erros passam a ser registrados no
  painel administrativo

### Adicionado
- Agenda de manutenções preventivas, com sinalização por vencimento, registro
  de execução ou adiamento e histórico por equipamento
- Painel administrativo: registro de erros de PHP e de JavaScript, classificação
  de ameaças, histórico de backups, auditoria de segurança e navegação nas
  tabelas do banco com trilha de alterações
- Indicadores cruzando localização e nota fiscal, com gráfico
- Download de backup por escopo: banco completo, um módulo ou uma tabela
- Backup remoto no Google Drive por OAuth, com relatório por e-mail em toda
  execução

### Corrigido
- Backup automático nunca havia sido executado: a tarefa agendada usava `php`
  sem caminho absoluto, e o cron não encontrava o interpretador
- Backup cobria 8 das 41 tabelas; passou a varrer a estrutura do banco, de modo
  que tabela nova entra automaticamente
- Dump esgotava a memória em tabelas com anexos, por agrupar registros por
  quantidade em vez de por tamanho
- Inversão de duas colunas ao salvar a planilha
- Menu de navegação duplicado em onze arquivos, com divergências entre telas
- Movimentação patrimonial duplicada em três arquivos, um deles com número
  errado de parâmetros, falhando em silêncio

### Alterado
- Cadastro de usuários saiu da tela de login e passou para o painel
  administrativo, com validação de formato e força de senha
- Coluna de setor responsável passou a usar lista fechada, com validação no
  servidor
- Chamado e ordem de serviço passaram a compartilhar um único protocolo
