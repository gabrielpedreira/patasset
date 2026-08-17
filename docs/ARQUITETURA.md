# Arquitetura

## Contexto que explica as escolhas

O sistema roda em **hospedagem compartilhada**: sem acesso a terminal, sem Composer no servidor, sem processo de build, sem contêiner. Atualização é envio de arquivo por FTP. Quem mantém é uma pessoa.

Isso descarta boa parte das soluções que seriam naturais em outro contexto. Não há framework, não há gerenciador de dependências, não há camada de ORM. O que existe é PHP direto, organizado por convenção.

Não é uma escolha estética: um framework num ambiente sem terminal e sem build vira custo sem contrapartida.

---

## O princípio que organiza o código

**O que é duplicado diverge.**

Não é teoria. Três casos que aconteceram neste sistema:

**O menu de navegação** estava copiado em onze arquivos. Quando um item mudava, alguns ficavam para trás — e ficaram, por meses, com telas mostrando opções diferentes. Virou `eng_clin_menu.php`.

**O bloco de movimentação patrimonial** existia em três lugares. Um deles tinha um `bind_param` com seis marcadores e cinco parâmetros: falhava em silêncio, sem gravar o histórico. Virou `eng_clin_mover_item.php`.

**O encerramento de ordem de serviço** existia em duas telas, com regras diferentes sobre quando devolver o equipamento. Virou uma função só.

O padrão é sempre o mesmo: a duplicação não causa problema no dia em que é criada. Causa meses depois, quando alguém corrige um lado e não sabe do outro.

---

## Um segundo princípio: capturar antes de mutar

Vários fluxos precisam do estado **anterior** a uma operação, e o descobrem tarde.

Na ordem de serviço, o equipamento é movido para a engenharia quando o atendimento começa. Ao encerrar, precisa voltar para onde estava — mas a movimentação **sobrescreveu exatamente os campos** que dizem onde ele estava. A localização de retorno tem que ser congelada no início.

O mesmo raciocínio aparece na quantidade inicial de peças em estoque, e no documento de encerramento da OS, que é gravado como retrato no momento do fechamento em vez de ser remontado depois — porque remontar depois produz um documento diferente do que foi assinado.

---

## Organização dos arquivos

Não há framework, então não há `app/`, `routes/` ou `controllers/`. A organização é por **prefixo de nome**, e funciona porque o número de arquivos é conhecido.

| Prefixo | Papel |
|---|---|
| `eng_clin_*` | Módulo de engenharia clínica |
| `dev_*` | Painel administrativo e monitoramento |
| `backup_*` | Sistema de backup |
| `rotina_*` | Inspeção patrimonial e termos |
| `baixa_*` | Baixa e descarte |
| `nao_localizados_*` | Auditoria de itens não localizados |
| `buscar_*` | Endpoints de consulta (AJAX) |
| *(sem prefixo)* | Módulo patrimonial principal |

Arquivos terminados em `_dados.php`, `_action.php`, `_salvar.php` ou `_lista.php` são endpoints que respondem JSON, não telas.

---

## Módulos compartilhados

```
config_seguro.php        Localiza as credenciais fora da pasta pública
conexao.php             ─┐ Conexão + modo manutenção + sessão + CSRF
seguranca_sessao.php     │ Cookie endurecido, expiração, token
login_seguranca.php      │ Controle de tentativas de login
dev_seguranca.php        │ Registro de erros e ameaças
dev_captura.php          │ Captura de erros PHP (auto_prepend)
dev_captura_js.php      ─┘ Captura de erros JS + injeção do token (auto_append)

backup_dump.php          Geração do dump (cron e painel usam o mesmo)
backup_drive_oauth.php   Envio ao Google Drive
backup_notify.php        Relatório por e-mail

eng_clin_menu.php        Navegação e CSS compartilhado do módulo clínico
eng_clin_mover_item.php  Movimentação com histórico e notificação
indicadores_localizacao.php  Indicadores usados por duas telas diferentes
```

### Onde a verificação de segurança acontece

`conexao.php` é incluído por praticamente toda página, e sempre **depois** do `session_start()` dela. É o único ponto do sistema com essas duas propriedades ao mesmo tempo — por isso a verificação de sessão e de token vive ali.

Os endpoints que não usam banco e portanto não incluem `conexao.php` chamam `seg_guardar()` explicitamente. São poucos e estão documentados no próprio código.

---

## Carregamento automático de proteções

A captura de erros e a injeção do token anti-CSRF não são incluídas arquivo por arquivo. São carregadas por duas diretivas do PHP:

```ini
auto_prepend_file = .../dev_captura.php     ; antes de cada página
auto_append_file  = .../dev_captura_js.php  ; depois de cada página
```

A alternativa seria um `require` em cada um dos ~110 arquivos. O problema não é o trabalho inicial: é que o primeiro arquivo novo criado depois disso nasceria sem proteção, e ninguém lembraria de adicionar.

**Consequências que precisaram ser tratadas:**

O arquivo do `auto_append` é emitido **no fim** do documento. Scripts executam na ordem em que aparecem, então telas que disparam requisições durante o carregamento rodariam antes de o token existir. A solução foi entregar o token também num cookie legível por JavaScript, disponível desde o primeiro byte da resposta.

O `auto_append` não pode injetar `<script>` em respostas JSON ou em downloads — corromperia o conteúdo. O arquivo inspeciona os cabeçalhos já enviados e desiste quando o tipo não é HTML.

E o `auto_append` **não roda** quando o script termina com `exit()`, o que na prática protege os endpoints AJAX automaticamente.

---

## Padrões de código

### Acesso ao banco

Prepared statement sempre, com verificação explícita do retorno:

```php
$st = $conn->prepare("SELECT ... WHERE id = ?");
if ($st) {
    $st->bind_param('i', $id);
    $st->execute();
    // ...
    $st->close();
}
```

A verificação `if ($st)` não é zelo excessivo. No PHP 8.1+ o `mysqli` passou a lançar exceções por padrão; o código assume o comportamento anterior, em que `prepare()` retorna `false`. Daí o `mysqli_report(MYSQLI_REPORT_OFF)` nos pontos de entrada.

### Post-Redirect-Get

Formulários que alteram dados redirecionam depois de gravar. Sem isso, o botão "atualizar" do navegador reenvia o formulário, e voltar na navegação produz `ERR_CACHE_MISS`.

### Saída no DOM

Dado que vem do banco passa por `esc()` antes de entrar em `innerHTML` — **inclusive dentro de atributos**, caso frequentemente esquecido: um valor com aspas fecha o atributo e permite injetar um manipulador de evento.

### Falha silenciosa no monitoramento

Todo o código de registro de erros e eventos é envolvido em `try/catch` que não propaga. Um sistema de monitoramento que derruba a aplicação é pior do que não ter monitoramento.

---

## Fluxo de uma ordem de serviço

```
Chamado aberto (formulário público, sem login)
        │  protocolo CH-000000
        ▼
Abertura da OS ─── move o equipamento para a engenharia
        │          (localização de retorno é congelada aqui)
        ▼
Atendimento ─── mão de obra (vários técnicos) + materiais
        │       ├── baixa no estoque da unidade do chamado
        │       └── ou peça reaproveitada
        │
        ├──► Manutenção externa (opcional)
        │
        ▼
Encerramento ─── devolve o equipamento à localização congelada
        │        gera documento (retrato, não remontagem)
        │        agenda a preventiva, se houver periodicidade
        ▼
Histórico completo por protocolo
```

O protocolo do chamado acompanha todo o ciclo. Antes havia numeração separada para chamado e para OS, e ninguém conseguia relacionar os dois sem consultar o banco.

---

## Decisões que foram revistas

Vale registrar, porque explicam trechos do código que parecem estranhos fora de contexto:

**Conta de serviço do Google → OAuth do usuário.** Conta de serviço não tem cota de armazenamento; todo upload era recusado. O código da conta de serviço permanece como caminho de reserva.

**Escopo `drive` → `drive.file`.** O escopo amplo exigiria verificação do Google, e enquanto o app ficasse em modo teste o token expiraria a cada 7 dias.

**CSRF direto → CSRF em observação primeiro.** Registrar sem bloquear revelou que a tela administrativa era a única sem token. Ativar de imediato teria derrubado justamente ela.

**Contagem de linhas → contagem de bytes no dump.** Agrupar 50 registros por `INSERT` é razoável numa tabela de texto e desastroso numa de anexos, onde 50 registros são centenas de megabytes.
