<?php
/*
 * Mapeamento de fontes extras por UF.
 * A lista base dos 27 estados vem da tabela `estados` no banco de dados.
 * Aqui ficam apenas os vínculos adicionais (estadual/municipal) por UF.
 *
 * 'apply_uf' = false → fonte já é específica do estado, sem filtro de UF necessário
 */
return [
    'MG' => [
        ['cargo' => 'estadual', 'source_key' => 'almg', 'label' => 'Assembleia Legislativa', 'apply_uf' => false],
    ],
    'PB' => [
        ['cargo' => 'estadual',  'source_key' => 'alpb',     'label' => 'Assembleia Legislativa',       'apply_uf' => false],
        ['cargo' => 'municipal', 'source_key' => 'cmjp',     'label' => 'Câmara de João Pessoa',        'apply_uf' => false],
        ['cargo' => 'municipal', 'source_key' => 'campina',  'label' => 'Câmara de Campina Grande',     'apply_uf' => false],
        ['cargo' => 'municipal', 'source_key' => 'bayeux',   'label' => 'Câmara de Bayeux',             'apply_uf' => false],
        ['cargo' => 'municipal', 'source_key' => 'cabedelo', 'label' => 'Câmara de Cabedelo',           'apply_uf' => false],
        ['cargo' => 'municipal', 'source_key' => 'santarita','label' => 'Câmara de Santa Rita',         'apply_uf' => false],
    ],
];
