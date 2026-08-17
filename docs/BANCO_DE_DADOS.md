# Banco de Dados

Visão geral das tabelas. A estrutura completa está em [`database/schema.sql`](../database/schema.sql), e o histórico de alterações em [`database/migrations/`](../database/migrations/).

Collation `utf8mb4_unicode_ci` em todas as tabelas — insensível a acento e caixa, o que faz `TRIM(campo) = 'NAO'` casar com `não`, `NÃO` e `Nao`. Numa base preenchida por pessoas diferentes ao longo de anos, isso deixa de ser detalhe.


## Patrimônio

| Tabela | Conteúdo |
|---|---|
| `cadastro` | Registro de todos os bens. 56 colunas: identificação, localização, classificação contábil, movimentação, inspeção, baixa e dados fiscais. |
| `nota` | Notas fiscais vinculadas aos bens, com PDF em BLOB. |
| `historico` | Uma linha por movimentação realizada, com origem, destino, usuário e observação. |
| `relacao` | Relação contábil usada na conciliação — descrição, grupo, classe e subgrupo. |
| `descricoes` | Descrições padronizadas, para autopreenchimento no cadastro. |
| `pre_descarte` | Itens marcados para descarte, aguardando aprovação. |
| `baixa_definitiva` | Baixas efetivadas, com protocolo, foto e assinaturas. |
| `cronograma` | Cronograma de inventário por unidade e setor. |
| `termos_responsabilidade` | Termos gerados por setor, com o arquivo assinado. |
| `cadastro_destinatarios` | Destinatários das notificações automáticas. |
| `registro_atividades` | Registro de atividades do cronograma. |

## Engenharia Clínica

| Tabela | Conteúdo |
|---|---|
| `chamado_engclin` | Chamados abertos pelos setores. O número do chamado é o protocolo de todo o ciclo. |
| `ordemservico_engclin` | Ordens de serviço, ligadas ao chamado pelo mesmo protocolo. |
| `maodeobra_engclin` | Intervenções dos técnicos: início, fim, ocorrência, serviço e status. |
| `itens_os_engclin` | Materiais aplicados na OS, com origem (estoque ou reaproveitado). |
| `manutencao_externa_engclin` | Envios a fornecedor externo, com controle de datas. |
| `historico_eventos_engclin` | Trilha de eventos da OS — alimentada pelo sistema. |
| `anexos_engclin` | Arquivos anexados às OS, em BLOB. |
| `preventiva_engclin` | Agenda de preventivas: uma linha por equipamento. |
| `preventiva_hist_engclin` | Histórico de preventivas realizadas, adiadas ou removidas. |
| `estoque_engenharia` | Saldo de peças por unidade, com entradas por nota. |
| `movimentacao_estoque_engclin` | Movimento de estoque — derivado dos lançamentos. |
| `engclin_cadastro_pecas` | Catálogo de peças por tipo de equipamento. |
| `engclin_despachos` | Transferências de peças entre unidades. |
| `criticidade_item_engclin` | Grau de criticidade por tipo de equipamento. |
| `retiradadepecas_catalogo` | Peças retiradas de equipamentos baixados. |
| `retiradadepecas_status` | Situação de cada peça retirada. |
| `retiradadepecas_equipamento_tipo` | Tipos de equipamento do catálogo de peças. |
| `documentos_engclin` | Contratos e documentos com valor, em BLOB. |
| `tecnico` | Equipe técnica, com férias e aniversários. |
| `fornecedores` | Empresas prestadoras de serviço. |

## Acesso e monitoramento

| Tabela | Conteúdo |
|---|---|
| `usuarios` | Contas de acesso: usuário, senha (bcrypt), permissão, classe e status. |
| `usuarios_online` | Sessões ativas, com revogação remota. |
| `historico_acessos` | Tentativas de login, com resultado, IP e navegador. |
| `login_tentativas` | Contadores e bloqueios do controle de força bruta. |
| `autorizacao` | Senha de autorização do cadastro legado. |
| `dev_log_erros` | Erros de PHP e de JavaScript, agrupados por impressão digital. |
| `dev_ameacas` | Eventos de segurança classificados por severidade. |
| `dev_invasoes` | Tentativas de acesso a páginas restritas. |
| `dev_backups` | Histórico de execuções do backup. |
| `dev_alteracoes` | Trilha de alterações feitas diretamente no banco pelo painel. |


## Relacionamentos principais

Não há chaves estrangeiras declaradas — a integridade é mantida pela aplicação.
Os vínculos que importam:

```
cadastro.id
   ├──► chamado_engclin.item_id
   ├──► ordemservico_engclin.item_id
   └──► preventiva_engclin.item_id      (único por equipamento)

chamado_engclin.numero_chamado          ← protocolo de todo o ciclo
   ├──► ordemservico_engclin.numero_chamado
   ├──► maodeobra_engclin.numero_chamado
   ├──► itens_os_engclin.numero_chamado
   ├──► manutencao_externa_engclin.numero_chamado
   ├──► anexos_engclin.numero_chamado
   └──► historico_eventos_engclin.numero_chamado

cadastro.tag_antiga  ←→  historico.tag  (vínculo por tag, não por id)
cadastro.tag_antiga  ←→  nota.tag_patrimonio
```

O painel administrativo consulta esses vínculos antes de permitir a exclusão de
um registro, e informa quantos ficariam órfãos.

## Colunas que merecem atenção

**`cadastro.responsavel`** — setor responsável pela manutenção. Define o que
cada módulo enxerga: a engenharia clínica filtra por `ENGENHARIA CLINICA`. Por
isso usa lista fechada com validação no servidor — um valor divergente faz o
equipamento desaparecer do módulo, sem erro nenhum.

**`cadastro.movimentado`** — quando é `SIM`, a localização atual está nas
colunas `*_destino`; caso contrário, nas colunas de origem. Toda tela que
mostra "onde o item está" precisa derivar isso.

**`cadastro.encontrado`** — resultado da inspeção física. Preenchida ao longo
de anos por pessoas diferentes, aparece como `sim`, `SIM`, `Sim`, `não`, `nao`,
`NÃO`. Comparações usam `UPPER(TRIM(...))` contra a lista de grafias.

**Colunas `DATE` com `'0000-00-00'`** — o modo estrito do MySQL rejeita
comparação literal com esse valor. A alternativa usada é `YEAR(coluna) > 0`.

## Tabelas com conteúdo binário

`nota`, `anexos_engclin`, `documentos_engclin`, `tecnico` (foto) e
`retiradadepecas_catalogo` guardam arquivos em BLOB. Isso pesa no backup: o
dump converte binário em hexadecimal, o que dobra o tamanho.

Por esse motivo o gerador de dump agrupa registros **por bytes**, não por
quantidade. Cinquenta linhas de texto são alguns kilobytes; cinquenta linhas de
PDF são centenas de megabytes.

Guardar arquivo em banco é decisão discutível. Simplifica backup e transação,
mas infla o banco e encarece toda consulta que use `SELECT *`. Manter em disco,
com o banco guardando apenas o caminho, seria o desenho preferível — é mudança
estrutural, registrada aqui como dívida técnica conhecida.
