<?php
/**
 * Tarjetas de producto para un asistente de compras con RAG (versión genérica).
 *
 * La idea: el modelo de lenguaje NO decide qué productos se muestran. Las tarjetas
 * se eligen en código, antes de llamar al modelo, a partir de la búsqueda vectorial:
 *
 *   1. Se descartan los resultados por debajo de un umbral de similitud.
 *   2. Los datos se REFRESCAN desde la base de datos: la metadata guardada en el
 *      vector puede estar vieja (precio, existencias, imagen). La base manda; la
 *      metadata es solo el respaldo si la base no responde.
 *   3. Una tarjeta destacada y hasta cuatro alternativas.
 *
 * El modelo recibe esos mismos productos como contexto y solo redacta la explicación.
 *
 * Ejecutar el ejemplo:  php tarjetas-de-producto.php
 */

declare(strict_types=1);

const UMBRAL_SIMILITUD = 0.20;
const MAX_ALTERNATIVAS = 4;

/**
 * @param array<int, array{id:int, score:float, metadata:array}> $coincidencias  resultados del vector, ya ordenados
 * @param callable(int[]): array<int, array>                       $leerDeLaBase  devuelve filas frescas por id
 * @return array{destacada: ?array, alternativas: array}
 */
function tarjetasDeProducto(array $coincidencias, callable $leerDeLaBase): array
{
    $candidatos = array_values(array_filter($coincidencias, fn ($m) => (float) $m['score'] >= UMBRAL_SIMILITUD));
    if ($candidatos === []) {
        return ['destacada' => null, 'alternativas' => []];
    }

    $frescos = [];
    try {
        $frescos = $leerDeLaBase(array_map(fn ($m) => (int) $m['id'], $candidatos));
    } catch (\Throwable $e) {
        error_log('Tarjetas: la base no respondió, se usa la metadata del vector. ' . $e->getMessage());
    }

    $tarjetas = [];
    foreach ($candidatos as $m) {
        $fila = $frescos[(int) $m['id']] ?? $m['metadata'];   // la base manda
        $tarjetas[] = [
            'id'     => (int) $m['id'],
            'nombre' => (string) $fila['name'],
            'precio' => (float) $fila['price'],
            'stock'  => (int) $fila['stock'],
            'url'    => 'product.html?id=' . (int) $m['id'],
        ];
    }

    return [
        'destacada'    => $tarjetas[0] ?? null,
        'alternativas' => array_slice($tarjetas, 1, MAX_ALTERNATIVAS),
    ];
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    // Resultados de la búsqueda vectorial (metadata congelada al indexar).
    $coincidencias = [
        ['id' => 3, 'score' => 0.71, 'metadata' => ['name' => 'Monitor de signos vitales', 'price' => 18900, 'stock' => 2]],
        ['id' => 8, 'score' => 0.54, 'metadata' => ['name' => 'Oxímetro de pulso',         'price' => 650,   'stock' => 10]],
        ['id' => 5, 'score' => 0.41, 'metadata' => ['name' => 'Baumanómetro digital',      'price' => 1200,  'stock' => 4]],
        ['id' => 9, 'score' => 0.12, 'metadata' => ['name' => 'Silla de ruedas',           'price' => 3900,  'stock' => 6]],
    ];
    // La base dice otra cosa: el monitor cambió de precio y al oxímetro le queda una pieza.
    $base = fn (array $ids) => array_intersect_key([
        3 => ['name' => 'Monitor de signos vitales', 'price' => 17500, 'stock' => 2],
        8 => ['name' => 'Oxímetro de pulso',         'price' => 650,   'stock' => 1],
        5 => ['name' => 'Baumanómetro digital',      'price' => 1200,  'stock' => 4],
    ], array_flip($ids));

    $r = tarjetasDeProducto($coincidencias, $base);
    printf("Destacada:   %s — $%s (precio de la base, no del vector)\n", $r['destacada']['nombre'], number_format($r['destacada']['precio']));
    foreach ($r['alternativas'] as $t) {
        printf("Alternativa: %s — $%s (existencias: %d, de la base)\n", $t['nombre'], number_format($t['precio']), $t['stock']);
    }
    echo "La silla de ruedas (similitud 0.12) queda fuera por el umbral.\n";
}
