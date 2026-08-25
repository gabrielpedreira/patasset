<?php
session_start();
if (!isset($_SESSION['usuario_logado'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Acesso negado.');
}
ini_set('display_errors', 0);
error_reporting(0);

require_once "conexao.php";
require_once __DIR__ . "/vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * Exportar é ler a planilha inteira num arquivo. Vale a mesma regra da tela:
 * A, B ou C, só a classe dona do patrimônio. Antes, qualquer logado — inclusive
 * outra classe — baixava o inventário completo por este endpoint.
 * json=false: em caso de recusa, redireciona (isto devolve um arquivo, não JSON).
 */
seg_exigir_permissao($conn, ['A', 'B', 'C'], ['DEV', 'PATRIMONIO'], false);

/* ===============================
   CONSULTA
================================ */
$sql = "SELECT
            id, periodo, status, movimentado_definitivo, movimentado,
            data_movimentacao, folha, unidade_destino, setor_destino,
            area_destino, obs_movimentacao, usuario_movimentacao,

            grupo, classe, subgrupo,

            unidade, setor, pavimento, area,

            tag_antiga, tag_trocada,

            propriedade, empresa, tag_alugado,
            descricao, descricao_detalhada,
            marca, modelo, serie,
            observacao, usuario_cadastro,

            data_inspecao, usuario_inspecao, encontrado,
            estado, obs3, n_conformidade, status2, o_servico,
            data_baixa,

            centro_custo_unidade, centro_custo_setor,

            unidade_atribuida, setor_atribuido,
            conciliado, usuario_conciliacao,

            nota_fiscal,
            fornecedor_nome, fornecedor_cnpj,

            data_aquisicao,
            valor_nota, valor_item,

            data_inicio_depreciacao,
            depreciacao_acumulada,
            saldo_remanecente,

            contrato_arrendamento
        FROM cadastro
        ORDER BY id ASC";

$result = $conn->query($sql);
if (!$result) {
    die("Erro na consulta SQL: " . $conn->error);
}

/* ===============================
   PLANILHA
================================ */
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

/* ===============================
   CABEÇALHOS
================================ */
$headers = [
    "ID",                       // id
    "PERÍODO",                  // periodo
    "STATUS",                   // status
    "MOVIMENTADO DEFINITIVO",   // movimentado_definitivo
    "MOVIMENTADO",              // movimentado
    "DATA DE MOVIMENTAÇÃO",     // data_movimentacao
    "FOLHA",                    // folha
    "UNIDADE DE DESTINO",       // unidade_destino
    "SETOR DE DESTINO",         // setor_destino
    "ÁREA DE DESTINO",          // area_destino
    "OBS. MOVIMENTAÇÃO",        // obs_movimentacao
    "USUÁRIO QUE MOVIMENTOU",   // usuario_movimentacao

    "GRUPO",                    // grupo
    "CLASSE",                   // classe
    "SUBGRUPO",                 // subgrupo

    "UNIDADE",                  // unidade
    "SETOR",                    // setor
    "PAVIMENTO",                // pavimento
    "ÁREA",                     // area

    "TAG PATRIMÔNIO",           // tag_antiga
    "TAG NOVA COMPRA",          // tag_trocada

    "PROPRIEDADE",              // propriedade
    "EMPRESA",                  // empresa
    "TAG ALUGADO",              // tag_alugado
    "DESCRIÇÃO",                // descricao
    "DESCRIÇÃO DETALHADA",      // descricao_detalhada
    "MARCA",                    // marca
    "MODELO",                   // modelo
    "SÉRIE",                    // serie
    "OBSERVAÇÃO",               // observacao
    "USUÁRIO CADASTRO",         // usuario_cadastro

    "DATA DA INSPEÇÃO",         // data_inspecao
    "USUÁRIO DA INSPEÇÃO",      // usuario_inspecao
    "ROTINA",                   // encontrado
    "ESTADO",                   // estado
    "OBS3",                     // obs3
    "N. CONFORMIDADE",          // n_conformidade
    "STATUS 2",                 // status2
    "O. SERVIÇO",               // o_servico
    "DATA DA BAIXA",            // data_baixa

    "CENTRO DE CUSTO UNIDADE",  // centro_custo_unidade
    "CENTRO DE CUSTO SETOR",    // centro_custo_setor

    "UNIDADE ATRIBUÍDA",        // unidade_atribuida
    "SETOR ATRIBUÍDO",          // setor_atribuido
    "CONCILIADO",               // conciliado
    "USUÁRIO CONCILIAÇÃO",      // usuario_conciliacao

    "NOTA FISCAL",              // nota_fiscal
    "FORNECEDOR",               // fornecedor_nome
    "CNPJ FORNECEDOR",          // fornecedor_cnpj

    "DATA AQUISIÇÃO",           // data_aquisicao
    "VALOR NOTA",               // valor_nota
    "VALOR ITEM",               // valor_item

    "DATA INÍCIO DEPRECIAÇÃO",  // data_inicio_depreciacao
    "DEPRECIAÇÃO ACUMULADA",    // depreciacao_acumulada
    "SALDO REMANESCENTE",       // saldo_remanecente

    "CONTRATO ARRENDAMENTO",    // contrato_arrendamento
];

$sheet->fromArray($headers, null, 'A1');

/* ===============================
   DADOS
================================ */
$rowNum = 2;

while ($row = $result->fetch_assoc()) {
    $sheet->fromArray([
        $row['id'],
        $row['periodo'],
        $row['status'],
        $row['movimentado_definitivo'],
        $row['movimentado'],
        $row['data_movimentacao'],
        $row['folha'],
        $row['unidade_destino'],
        $row['setor_destino'],
        $row['area_destino'],
        $row['obs_movimentacao'],
        $row['usuario_movimentacao'],

        $row['grupo'],
        $row['classe'],
        $row['subgrupo'],

        $row['unidade'],
        $row['setor'],
        $row['pavimento'],
        $row['area'],

        $row['tag_antiga'],
        $row['tag_trocada'],

        $row['propriedade'],
        $row['empresa'],
        $row['tag_alugado'],
        $row['descricao'],
        $row['descricao_detalhada'],     // ← adicionado
        $row['marca'],
        $row['modelo'],
        $row['serie'],
        $row['observacao'],
        $row['usuario_cadastro'],

        $row['data_inspecao'],
        $row['usuario_inspecao'],
        $row['encontrado'],
        $row['estado'],                  // ← adicionado
        $row['obs3'],                    // ← adicionado
        $row['n_conformidade'],          // ← adicionado
        $row['status2'],                 // ← adicionado
        $row['o_servico'],               // ← adicionado
        $row['data_baixa'],

        $row['centro_custo_unidade'],
        $row['centro_custo_setor'],

        $row['unidade_atribuida'],
        $row['setor_atribuido'],
        $row['conciliado'],
        $row['usuario_conciliacao'],     // ← adicionado

        $row['nota_fiscal'],
        $row['fornecedor_nome'],
        $row['fornecedor_cnpj'],

        $row['data_aquisicao'],
        $row['valor_nota'],
        $row['valor_item'],

        $row['data_inicio_depreciacao'],
        $row['depreciacao_acumulada'],
        $row['saldo_remanecente'],

        $row['contrato_arrendamento'],
    ], null, "A{$rowNum}");

    $rowNum++;
}

$conn->close();

/* ===============================
   DOWNLOAD DIRETO
================================ */
$nomeArquivo = "PLANILHA SISTEMA DE PATRIMONIO " . date("d-m-Y H.i") . ".xlsx";

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$nomeArquivo\"");
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;