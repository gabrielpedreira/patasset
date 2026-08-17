# Política de Segurança

## Reportar uma vulnerabilidade

Abra uma *issue* descrevendo o problema, ou entre em contato pelo e-mail do perfil. Se a falha for explorável, prefira o contato direto antes da divulgação pública.

---

## Medidas implementadas

### Autenticação

Senhas armazenadas com **bcrypt** (`password_hash`). A verificação aceita também senhas em texto puro, para compatibilidade com base legada, e existe uma rotina no painel administrativo que converte as pendentes.

**Bloqueio progressivo por tentativas**, com contagem separada por usuário e por endereço IP:

| Chave | Limite | Bloqueio |
|---|---|---|
| Usuário | 5 falhas | 15 min → 30 min → 60 min |
| IP | 20 falhas | 15 min → 30 min → 60 min |

O limite por IP é deliberadamente muito mais alto. Numa rede corporativa, todos os usuários saem pelo mesmo IP público — um limite baixo por IP transformaria o esquecimento de senha de uma pessoa em indisponibilidade para todos os setores.

Complementos: atraso crescente na resposta a cada falha, campo-armadilha invisível no formulário, rejeição de requisições que não sejam POST, e mensagem genérica que não revela se o usuário existe. Quando o usuário tentado **não existe**, o evento é classificado com severidade maior — ninguém erra a senha de uma conta que não existe, isso indica varredura de nomes.

### Sessão

- `HttpOnly` — JavaScript não lê o cookie de sessão
- `Secure` — o cookie só trafega por HTTPS
- `SameSite=Lax` — o navegador não envia o cookie em POST originado de outro site
- `session_regenerate_id()` no login, contra fixação de sessão
- Encerramento por inatividade (30 min) e teto absoluto de sessão (12 h)
- Revogação remota de sessão pelo painel administrativo

A detecção de inatividade é feita **no navegador**, monitorando teclado, mouse e toque. Fazer isso no servidor não funcionaria: o *heartbeat* que valida a sessão dispara a cada 30 segundos enquanto a aba está aberta, então uma estação abandonada parece ativa. Só o periférico sabe se há alguém ali.

### CSRF

Token de 32 bytes por sessão, exigido em toda requisição POST. Entregue por três caminhos: campo escondido nos formulários, cabeçalho `X-CSRF-Token` nas requisições AJAX, e um cookie legível por JavaScript (*double submit cookie*).

O cookie parece contraintuitivo, já que o navegador o envia automaticamente. A proteção está na comparação: o servidor confronta o valor do cookie com o recebido no cabeçalho ou no corpo. Um site atacante não consegue **ler** o cookie (política de mesma origem) nem **definir cabeçalhos** numa requisição disparada de fora — sem poder copiar o valor de um lugar para o outro, não passa.

O token é anexado automaticamente pela interceptação de `fetch`, `XMLHttpRequest` e do envio de formulários, em código carregado por `auto_append_file`. Assim, tela nova nasce protegida.

**A implantação foi feita em duas etapas**, e isso importa: primeiro em modo observação, registrando violações sem bloquear. Foi o que revelou que a tela administrativa — a de maior valor para um atacante — era a única sem token, porque um `session_write_close()` no início do arquivo apagava a sessão antes de o token ser emitido. Ativar o bloqueio direto teria derrubado justamente essa tela, e a conclusão apressada seria "a proteção quebrou o sistema".

### Injeção de SQL

Prepared statements em todo o acesso a dados. Onde a parametrização não se aplica — nomes de coluna em `ORDER BY`, valores de `LIMIT` — o dado passa por lista branca ou conversão para inteiro com limites.

### XSS

Escape no ponto de inserção no DOM, inclusive **dentro de atributos HTML** — caso frequentemente esquecido: um valor com aspas fecha o atributo e permite injetar um manipulador de evento.

O vetor real neste tipo de sistema não é o atacante externo, e sim o dado legítimo: uma descrição de equipamento colada de outro sistema, contendo marcação, que executa quando outra pessoa abre o relatório.

### Upload de arquivos

- Tipo verificado pelo **conteúdo** (`finfo`), nunca pela extensão enviada
- Extensão final derivada do tipo detectado
- Nome gerado com `random_bytes`
- Limite de tamanho
- Pasta de upload com `Require all denied` e execução de PHP desativada
- Entrega dos arquivos por script que confere a sessão
- Tentativa de envio de tipo não permitido é registrada como evento de segurança

Nome de arquivo é dado do usuário; conteúdo é fato. A versão anterior confiava no nome, o que permitia enviar um `.php` e executá-lo — controle total do servidor a partir de qualquer conta.

### Credenciais

Mantidas em arquivo **fora da pasta pública**, carregado por caminho absoluto. Enquanto o PHP funciona, um arquivo dentro da pasta pública é invisível; se o processamento falhar por configuração, o servidor entrega o código-fonte como texto puro. Fora da pasta pública, nenhuma URL alcança o arquivo.

### Monitoramento

Registro automático, sem necessidade de incluir código em cada página:

- Erros de PHP (avisos, exceções e erros fatais)
- Erros de JavaScript no navegador do usuário, com pilha de chamadas
- Tentativas de acesso a páginas restritas
- Bloqueios de login e eventos classificados por severidade
- Trilha de alterações feitas diretamente no banco pelo painel

Erros idênticos são agrupados por impressão digital, com contador. O mesmo aviso repetindo mil vezes vira uma linha com "1000x", em vez de mil linhas.

### Backup

Dump completo semanal, em dois destinos independentes, com rotação e verificação. Relatório por e-mail em **toda** execução, inclusive nas bem-sucedidas — e-mail que só chega em falha não distingue "está tudo bem" de "o agendamento parou e ninguém percebeu".

---

## Itens em aberto

Transparência sobre o que não está resolvido:

| Item | Situação |
|---|---|
| Autenticação em duas etapas para usuários | Não implementada |
| Validação de tipo em três pontos de upload | Gravam como BLOB, sem execução possível, mas sem verificação de tipo |
| Três endpoints sem verificação de sessão | Não expõem dado sensível, mas são inconsistências |
| Política de expiração de senha | Não implementada |
| Teste de invasão | Nunca realizado |
| Revisão de conformidade com a LGPD | Não realizada |

---

## Notas sobre a revisão

A avaliação de segurança foi feita por **leitura de código**, não por teste de invasão. Falhas de lógica de autorização — acessar dados de outra unidade manipulando um parâmetro, por exemplo — só aparecem em teste dinâmico e não foram verificadas.

O sistema roda em hospedagem compartilhada, o que impõe limites fora do controle da aplicação: vizinhos no mesmo servidor, ausência de acesso administrativo e pouco controle sobre a configuração do PHP a longo prazo.
