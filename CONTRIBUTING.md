# Como contribuir

## Ambiente

Requisitos: PHP 8.1+, MySQL 8+ ou MariaDB 10.4+, Apache. Não há gerenciador de
dependências no servidor de produção — o PHPMailer é versionado em `vendor/`.

Siga o passo a passo de [docs/INSTALACAO.md](docs/INSTALACAO.md).

## Padrões de código

**Idioma.** Nomes de variáveis, funções e comentários em português. O sistema é
mantido por equipe brasileira e o vocabulário do domínio é em português
(patrimônio, chamado, ordem de serviço, baixa) — traduzir metade cria atrito
mais do que resolve.

**Banco de dados.** Prepared statement sempre, com verificação do retorno:

```php
$st = $conn->prepare("SELECT ... WHERE id = ?");
if ($st) { $st->bind_param('i', $id); $st->execute(); /* ... */ $st->close(); }
```

Nunca interpole entrada do usuário em SQL. Onde a parametrização não se aplica
(`ORDER BY`, `LIMIT`), use lista branca ou conversão para inteiro com limites.

**Saída no DOM.** Todo dado vindo do banco passa por `esc()` antes de entrar em
`innerHTML` — inclusive dentro de atributos.

**Formulários.** Redirecione depois de gravar (Post-Redirect-Get). Sem isso, o
botão "atualizar" reenvia o formulário.

**Comentários.** Comente o *porquê*, não o *o quê*. `// incrementa o contador`
não ajuda ninguém; `// conta bytes em vez de linhas porque tabelas com anexos
estouram a memória` evita que alguém "simplifique" de volta.

## Antes de abrir um pull request

- [ ] Testado num ambiente que não seja produção
- [ ] Nenhuma credencial, caminho de servidor ou dado real no diff
- [ ] Prepared statements em qualquer consulta nova
- [ ] `esc()` em qualquer dado novo que vá para a tela
- [ ] Alteração de estrutura acompanhada de arquivo em `database/migrations/`
- [ ] `CHANGELOG.md` atualizado

## Estrutura de commits

```
tipo: resumo curto no imperativo

Contexto do problema e por que esta solução.
```

Tipos: `feat`, `fix`, `sec` (segurança), `docs`, `refactor`, `perf`, `chore`.

## Reportar bugs

Descreva o comportamento esperado, o observado e os passos para reproduzir.
Se houver erro em tela, o painel administrativo registra erros de PHP e de
JavaScript com arquivo, linha e pilha de chamadas — anexar isso ajuda muito.
