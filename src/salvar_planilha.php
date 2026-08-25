<?php
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
// Erros não são exibidos ao usuário em produção: a mensagem do PHP revela
// caminho do servidor, nome de tabela e trecho de SQL. Continuam sendo
// registrados e ficam visíveis no painel DEV → Erros (ver dev_captura.php).
ini_set('display_errors', 0);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Só o nome do arquivo, nunca o caminho completo: $e['file'] traz
        // /home/usuario/public_html/... e desligar display_errors não adianta
        // se a própria resposta JSON entrega a estrutura do servidor.
        // O detalhe completo fica no painel DEV → Erros.
        echo json_encode([
            'sucesso'    => false,
            'erro_fatal' => $e['message'],
            'arquivo'    => basename((string)$e['file']),
            'linha'      => $e['line']
        ]);
    }
});

session_start();

if (!isset($_SESSION['usuario_logado'])) {
    echo json_encode(['sucesso'=>false,'mensagem'=>'Sessão expirada']);
    exit;
}

require_once __DIR__ . '/conexao.php';

/*
 * Editar a planilha exige nível A ou B e classe dona do patrimônio. A tela
 * já desabilita o botão Salvar para o nível C — mas isso é só no navegador.
 * Sem esta linha, um usuário C (visualizador) editava registros chamando
 * este endpoint direto, e um usuário de outra classe (ex.: ENGENHARIA
 * CLINICA) alterava dados de patrimônio que a tela nunca lhe ofereceria.
 * Mesma regra de planilha.php. Ver seg_exigir_permissao().
 */
seg_exigir_permissao($conn, ['A', 'B'], ['DEV', 'PATRIMONIO']);

$raw = file_get_contents("php://input");

if (!$raw) {
    echo json_encode(['sucesso'=>false,'mensagem'=>'Body vazio']);
    exit;
}

$payload = json_decode($raw, true);

if (!$payload || !isset($payload['dados'])) {
    echo json_encode(['sucesso'=>false,'mensagem'=>'JSON inválido']);
    exit;
}

$dados = $payload['dados'];

if (!is_array($dados) || count($dados) === 0) {
    echo json_encode(['sucesso'=>false,'mensagem'=>'Lista vazia']);
    exit;
}

/*
 * Campos na mesma ordem que $colunas em planilha.php (índice 0 = id, pulado).
 * Cada entrada: [ 'campo' => nome_no_banco, 'date' => true/false ]
 * Campos date recebem NULL quando vazios ou inválidos.
 */
$campos = [
    ['campo'=>'descricao',              'date'=>false],  // 1
    ['campo'=>'descricao_detalhada',    'date'=>false],  // 2
    ['campo'=>'marca',                  'date'=>false],  // 3
    ['campo'=>'modelo',                 'date'=>false],  // 4
    ['campo'=>'serie',                  'date'=>false],  // 5
    ['campo'=>'propriedade',            'date'=>false],  // 6
    ['campo'=>'tag_antiga',             'date'=>false],  // 7
    ['campo'=>'tag_trocada',            'date'=>false],  // 8
    ['campo'=>'empresa',                'date'=>false],  // 9
    ['campo'=>'tag_alugado',            'date'=>false],  // 10
    ['campo'=>'observacao',             'date'=>false],  // 11
    ['campo'=>'unidade',                'date'=>false],  // 12
    ['campo'=>'setor',                  'date'=>false],  // 13
    ['campo'=>'pavimento',              'date'=>false],  // 14
    ['campo'=>'area',                   'date'=>false],  // 15
    ['campo'=>'usuario_cadastro',       'date'=>false],  // 16
    // A ordem TEM de espelhar $colunas de planilha.php — o JS envia as células
    // na ordem da tela. Estavam invertidos aqui, e o que era digitado em
    // CLASSE ia parar em GRUPO e vice-versa.
    ['campo'=>'classe',                 'date'=>false],  // 17
    ['campo'=>'grupo',                  'date'=>false],  // 18
    ['campo'=>'subgrupo',               'date'=>false],  // 19
    ['campo'=>'responsavel',            'date'=>false],  // 20 — setor responsável pela manutenção
    ['campo'=>'periodo',                'date'=>true ],  // 21
    ['campo'=>'status',                 'date'=>false],  // 22
    ['campo'=>'movimentado_definitivo', 'date'=>false],  // 23
    ['campo'=>'movimentado',            'date'=>false],  // 24
    ['campo'=>'data_movimentacao',      'date'=>true ],  // 25
    ['campo'=>'folha',                  'date'=>false],  // 26
    ['campo'=>'unidade_destino',        'date'=>false],  // 27
    ['campo'=>'setor_destino',          'date'=>false],  // 28
    ['campo'=>'area_destino',           'date'=>false],  // 29
    ['campo'=>'obs_movimentacao',       'date'=>false],  // 30
    ['campo'=>'usuario_movimentacao',   'date'=>false],  // 31
    ['campo'=>'data_inspecao',          'date'=>true ],  // 32
    ['campo'=>'usuario_inspecao',       'date'=>false],  // 33
    ['campo'=>'encontrado',             'date'=>false],  // 34
    ['campo'=>'estado',                 'date'=>false],  // 35
    ['campo'=>'obs3',                   'date'=>false],  // 36
    ['campo'=>'n_conformidade',         'date'=>false],  // 37
    ['campo'=>'status2',                'date'=>false],  // 38
    ['campo'=>'o_servico',              'date'=>false],  // 39
    ['campo'=>'data_baixa',             'date'=>true ],  // 40
    ['campo'=>'centro_custo_unidade',   'date'=>false],  // 41
    ['campo'=>'centro_custo_setor',     'date'=>false],  // 42
    ['campo'=>'unidade_atribuida',      'date'=>false],  // 43
    ['campo'=>'setor_atribuido',        'date'=>false],  // 44
    ['campo'=>'conciliado',             'date'=>false],  // 45
    ['campo'=>'usuario_conciliacao',    'date'=>false],  // 46
    ['campo'=>'nota_fiscal',            'date'=>false],  // 47
    ['campo'=>'fornecedor_nome',        'date'=>false],  // 48
    ['campo'=>'fornecedor_cnpj',        'date'=>false],  // 49
    ['campo'=>'data_aquisicao',         'date'=>true ],  // 50
    ['campo'=>'valor_nota',             'date'=>false],  // 51
    ['campo'=>'valor_item',             'date'=>false],  // 52
    ['campo'=>'data_inicio_depreciacao','date'=>true ],  // 53
    ['campo'=>'depreciacao_acumulada',  'date'=>false],  // 54
    ['campo'=>'saldo_remanecente',      'date'=>false],  // 55
    ['campo'=>'contrato_arrendamento',  'date'=>false],  // 56
];

/**
 * Setores válidos para a coluna `responsavel`.
 * Precisa bater com COLUNAS_COMBOBOX['responsavel'] em planilha.php.
 * A validação existe aqui também porque o combobox da tela não cobre colar
 * células nem o preenchimento por arrasto — e um valor divergente faz o item
 * sumir do sistema que filtra por esse campo.
 */
const RESPONSAVEIS_VALIDOS = [
    'ENGENHARIA CLINICA',
    'HOTELARIA',
    'NUTRIÇÃO',
    'MANUTENÇÃO',
    'MANUTENÇÃO ELETRONICA',
    'MANUTENÇÃO REFRIGERAÇÃO',
    'INFORMÁTICA',
    'SEGURANÇA',
];

/** Aceita variações de acento/caixa e devolve o valor canônico, ou false. */
function responsavel_canonico(string $v) {
    $v = trim($v);
    if ($v === '') return null;

    $simplificar = function (string $s): string {
        $s = mb_strtoupper($s, 'UTF-8');
        $de = ['Á','À','Â','Ã','Ä','É','Ê','Í','Ó','Ô','Õ','Ö','Ú','Ü','Ç'];
        $pa = ['A','A','A','A','A','E','E','I','O','O','O','O','U','U','C'];
        return preg_replace('/\s+/', ' ', trim(str_replace($de, $pa, $s)));
    };

    $alvo = $simplificar($v);
    foreach (RESPONSAVEIS_VALIDOS as $ok) {
        if ($simplificar($ok) === $alvo) return $ok;
    }
    return false;
}

/*
 * Transação: sem ela, um lote de 400 linhas que falhasse na linha 300 deixava
 * 299 gravadas e avisava ao usuário que nada tinha sido salvo. Ele reeditava
 * tudo sem saber que metade já estava no banco. Agora ou grava o lote inteiro
 * ou não grava nada.
 */
$conn->begin_transaction();
$gravadas = 0;

foreach ($dados as $linha) {

    if (!isset($linha[0]) || !$linha[0]) continue;
    $id = (int)$linha[0];

    $sets  = [];
    $vals  = [];
    $types = '';

    foreach ($campos as $i => $def) {

        $valor = $linha[$i + 1] ?? null;

        // Campos date: string vazia ou data inválida → NULL
        if ($def['date']) {
            if (empty($valor) || $valor === '0000-00-00' || $valor === '00-00-0000') {
                $valor = null;
            }
        } else {
            // Campos varchar: string vazia → NULL
            if ($valor === '') {
                $valor = null;
            }
        }

        // Responsável: lista fechada de setores
        if ($def['campo'] === 'responsavel' && $valor !== null) {
            $canon = responsavel_canonico((string)$valor);
            if ($canon === false) {
                $conn->rollback();
                echo json_encode([
                    'sucesso'  => false,
                    'mensagem' => "Responsável inválido no ID $id: \"$valor\". "
                                . "Nada foi salvo. Valores aceitos: "
                                . implode(', ', RESPONSAVEIS_VALIDOS) . ".",
                    'id'       => $id,
                ]);
                exit;
            }
            $valor = $canon;
        }

        $sets[]  = "`{$def['campo']}` = ?";
        $vals[]  = $valor;
        $types  .= 's';
    }

    $sql    = "UPDATE cadastro SET " . implode(', ', $sets) . " WHERE id = ?";
    $types .= 'i';
    $vals[] = $id;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $conn->rollback();
        echo json_encode(['sucesso'=>false,'mensagem'=>'Não foi possível preparar a gravação. Nada foi salvo.']);
        exit;
    }

    $stmt->bind_param($types, ...$vals);

    if (!$stmt->execute()) {
        $conn->rollback();
        $stmt->close();
        echo json_encode(['sucesso'=>false,'mensagem'=>"Falha ao gravar o item $id. Nada foi salvo.", 'id'=>$id]);
        exit;
    }

    $stmt->close();
    $gravadas++;
}

if (!$conn->commit()) {
    $conn->rollback();
    echo json_encode(['sucesso'=>false,'mensagem'=>'Falha ao confirmar a gravação. Nada foi salvo.']);
    exit;
}

$conn->close();

echo json_encode([
    'sucesso'  => true,
    'mensagem' => $gravadas === 1 ? '1 item salvo com sucesso'
                                  : "$gravadas itens salvos com sucesso",
    'gravadas' => $gravadas
]);
exit;