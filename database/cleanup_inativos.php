<?php
/**
 * cleanup_inativos.php
 *
 * Remove parlamentares com ativo=0 e todos os dados vinculados:
 *   parl_materias, parl_normas, parl_filiacoes, parl_comissoes,
 *   parl_relatorias, parl_frentes, parl_perfil_detalhe,
 *   parl_materias_detalhe, parl_materias_tramitacao, parl_emendas
 *
 * Uso:
 *   php database/cleanup_inativos.php              — mostra o que será removido
 *   php database/cleanup_inativos.php --confirm    — executa a limpeza
 *   php database/cleanup_inativos.php cmjp --confirm — só uma fonte
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

define('ROOT', dirname(__DIR__));
define('APP',  ROOT . '/app');
require ROOT . '/config/config.php';
require APP  . '/Core/Database.php';

$pdo     = Database::connect();
$args    = array_slice($argv ?? [], 1);
$confirm = in_array('--confirm', $args);
$fonteArg = current(array_filter($args, fn($a) => $a !== '--confirm')) ?: null;

// Tabelas filhas e coluna de referência (source_key + sapl_id do parlamentar)
$tabelas = [
    'parl_materias'            => 'sapl_id',
    'parl_normas'              => 'sapl_id',
    'parl_filiacoes'           => 'sapl_id',
    'parl_comissoes'           => 'sapl_id',
    'parl_relatorias'          => 'sapl_id',
    'parl_frentes'             => 'sapl_id',
    'parl_perfil_detalhe'      => 'sapl_id',
    'parl_emendas'             => 'parlamentar_id',
];

// Busca inativos por fonte
$sql = "SELECT source_key, sapl_id, nome_parlamentar
        FROM parl_parlamentares
        WHERE ativo = 0"
     . ($fonteArg ? " AND source_key = " . $pdo->quote($fonteArg) : '')
     . " ORDER BY source_key, nome_parlamentar";

$inativos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (!$inativos) {
    echo "Nenhum parlamentar inativo encontrado.\n";
    exit(0);
}

// Agrupa por fonte para exibição
$porFonte = [];
foreach ($inativos as $p) {
    $porFonte[$p['source_key']][] = $p;
}

echo ($confirm ? "[EXECUTANDO LIMPEZA]\n" : "[DRY RUN — use --confirm para executar]\n") . "\n";

foreach ($porFonte as $source => $parls) {
    $ids = array_column($parls, 'sapl_id');
    $in  = implode(',', $ids);

    echo "┌─ {$source}: " . count($parls) . " inativos\n";

    // Conta registros filhos que serão deletados
    foreach ($tabelas as $tabela => $col) {
        $qtd = $pdo->query(
            "SELECT COUNT(*) FROM {$tabela} WHERE source_key = '{$source}' AND {$col} IN ({$in})"
        )->fetchColumn();
        if ($qtd > 0) {
            echo "│  {$tabela}: {$qtd} registros\n";
        }
    }

    if ($confirm) {
        $pdo->beginTransaction();
        try {
            foreach ($tabelas as $tabela => $col) {
                $pdo->exec(
                    "DELETE FROM {$tabela} WHERE source_key = '{$source}' AND {$col} IN ({$in})"
                );
            }

            // Limpa detalhes de matérias órfãs (materia_id não existe mais em parl_materias)
            $pdo->exec(
                "DELETE d FROM parl_materias_detalhe d
                 WHERE d.source_key = '{$source}'
                 AND NOT EXISTS (
                     SELECT 1 FROM parl_materias m
                     WHERE m.source_key = d.source_key AND m.materia_id = d.materia_id
                 )"
            );
            $pdo->exec(
                "DELETE t FROM parl_materias_tramitacao t
                 WHERE t.source_key = '{$source}'
                 AND NOT EXISTS (
                     SELECT 1 FROM parl_materias m
                     WHERE m.source_key = t.source_key AND m.materia_id = t.materia_id
                 )"
            );

            $deleted = $pdo->exec(
                "DELETE FROM parl_parlamentares WHERE source_key = '{$source}' AND ativo = 0"
                . ($fonteArg ? '' : '')
            );
            $pdo->commit();
            echo "│  ✓ {$deleted} parlamentares removidos\n";
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "│  ERRO: " . $e->getMessage() . "\n";
        }
    }

    echo "└─\n\n";
}

if (!$confirm) {
    echo "Rode com --confirm para executar a limpeza.\n";
} else {
    echo "Limpeza concluída.\n";
}
