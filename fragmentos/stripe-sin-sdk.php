<?php
/**
 * Stripe Checkout sin SDK (versión genérica).
 *
 * En hosting compartido sin Composer no se puede instalar el SDK oficial, así que se
 * habla con la API REST por cURL. Dos piezas:
 *
 *   1. La sesión de pago. Stripe recibe `application/x-www-form-urlencoded` con arrays
 *      anidados en notación de corchetes (`line_items[0][price_data][currency]`), que
 *      es exactamente lo que produce http_build_query() a partir de un array de PHP.
 *      Los importes van en centavos y se calculan en el servidor, nunca desde el cliente.
 *
 *   2. La verificación del webhook. Stripe manda `Stripe-Signature: t=<ts>,v1=<firma>`.
 *      La firma es HMAC-SHA256 de "<ts>.<cuerpo crudo>" con el secreto del endpoint.
 *      Se compara en tiempo constante y se rechazan timestamps viejos (repetición).
 *      El webhook es la ÚNICA fuente de verdad del pago: la página de retorno se puede
 *      cerrar o falsear.
 *
 * Ejecutar las comprobaciones:  php stripe-sin-sdk.php
 */

declare(strict_types=1);

function centavos(float $pesos): int
{
    return (int) round($pesos * 100);
}

/** Parámetros de una sesión de Stripe Checkout a partir de un pedido ya validado. */
function parametrosDeCheckout(string $numeroDePedido, array $lineas, string $urlTienda, int $minutos = 60): array
{
    $items = [];
    foreach ($lineas as $l) {
        $items[] = [
            'quantity'   => $l['cantidad'],
            'price_data' => [
                'currency'     => 'mxn',
                'unit_amount'  => centavos($l['precio']),     // precio de la BASE, no del navegador
                'product_data' => ['name' => $l['nombre']],
            ],
        ];
    }
    return [
        'mode'                => 'payment',
        'line_items'          => $items,
        'client_reference_id' => $numeroDePedido,
        'metadata'            => ['pedido' => $numeroDePedido],
        'expires_at'          => time() + $minutos * 60,      // al caducar, el webhook devuelve el stock
        'success_url'         => $urlTienda . '/pago-exitoso.html?pedido=' . rawurlencode($numeroDePedido) . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'          => $urlTienda . '/pago-cancelado.html?pedido=' . rawurlencode($numeroDePedido),
    ];
}

/** POST a la API de Stripe. Nunca lanza: devuelve el cuerpo decodificado o un error genérico. */
function solicitudAStripe(string $ruta, array $parametros, string $llaveSecreta): array
{
    try {
        $ch = curl_init('https://api.stripe.com/v1/' . ltrim($ruta, '/'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $llaveSecreta . ':',
            CURLOPT_POSTFIELDS     => http_build_query($parametros),
            CURLOPT_TIMEOUT        => 20,
        ]);
        $cuerpo = curl_exec($ch);
        $http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($cuerpo === false || $http >= 400) {
            throw new RuntimeException("Stripe respondió HTTP {$http}");
        }
        return json_decode((string) $cuerpo, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        error_log('Stripe: ' . $e->getMessage());
        return ['error' => 'No se pudo iniciar el pago. Inténtelo de nuevo.'];
    }
}

/** Verifica la cabecera Stripe-Signature contra el cuerpo crudo del webhook. */
function firmaDeStripeValida(string $cuerpoCrudo, string $cabecera, string $secreto, int $toleranciaSeg = 300, ?int $ahora = null): bool
{
    $ts = null;
    $firmas = [];
    foreach (explode(',', $cabecera) as $par) {
        [$clave, $valor] = array_pad(explode('=', trim($par), 2), 2, '');
        if ($clave === 't')  $ts = (int) $valor;
        if ($clave === 'v1') $firmas[] = $valor;
    }
    if ($ts === null || $firmas === [] || $secreto === '') {
        return false;
    }
    if (abs(($ahora ?? time()) - $ts) > $toleranciaSeg) {
        return false;                                         // evento viejo: posible repetición
    }
    $esperada = hash_hmac('sha256', $ts . '.' . $cuerpoCrudo, $secreto);
    foreach ($firmas as $f) {
        if (hash_equals($esperada, $f)) {
            return true;
        }
    }
    return false;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $params = parametrosDeCheckout('PED-20260821-0001', [['nombre' => 'Oxímetro de pulso', 'precio' => 650.50, 'cantidad' => 2]], 'https://tienda.example.com');
    echo "Cuerpo que se manda a Stripe (fragmento):\n  ", urldecode(substr(http_build_query($params), 0, 160)), "…\n\n";

    $secreto = 'whsec_' . bin2hex(random_bytes(16));
    $cuerpo  = '{"type":"checkout.session.completed","data":{"object":{"client_reference_id":"PED-20260821-0001"}}}';
    $ts      = time();
    $firma   = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $cuerpo, $secreto);

    $casos = [
        'firma correcta'                    => firmaDeStripeValida($cuerpo, $firma, $secreto) === true,
        'cuerpo alterado'                   => firmaDeStripeValida(str_replace('0001', '0002', $cuerpo), $firma, $secreto) === false,
        'otro secreto'                      => firmaDeStripeValida($cuerpo, $firma, 'whsec_otro') === false,
        'evento de hace 10 minutos'         => firmaDeStripeValida($cuerpo, $firma, $secreto, 300, $ts + 600) === false,
        'importe en centavos (650.50 → 65050)' => centavos(650.50) === 65050,
    ];
    foreach ($casos as $nombre => $ok) {
        printf("%s  %s\n", $ok ? 'OK   ' : 'FALLA', $nombre);
    }
    exit(in_array(false, $casos, true) ? 1 : 0);
}
