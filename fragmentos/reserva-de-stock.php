<?php
/**
 * Reserva de stock y webhook idempotente (versión genérica, sobre SQLite en memoria).
 *
 *   - Reservar: el decremento es CONDICIONAL dentro del propio UPDATE
 *     (`stock = stock - ? WHERE id = ? AND stock >= ?`). Comprobar primero y restar
 *     después deja una ventana en la que dos compras simultáneas venden la misma pieza.
 *   - Pago confirmado: Stripe puede reenviar el mismo evento. Si el pedido ya está
 *     pagado, no se vuelve a sumar la venta.
 *   - Sesión caducada sin pago: el stock reservado vuelve al catálogo.
 *
 * Ejecutar el ejemplo:  php reserva-de-stock.php
 */

declare(strict_types=1);

function crearPedido(PDO $db, string $numero, int $productoId, int $cantidad): bool
{
    $db->beginTransaction();
    try {
        $reserva = $db->prepare('UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?');
        $reserva->execute([$cantidad, $productoId, $cantidad]);
        if ($reserva->rowCount() !== 1) {                     // no alcanzó: nadie más tocó el stock
            $db->rollBack();
            return false;
        }
        $db->prepare("INSERT INTO orders (numero, producto_id, cantidad, pago) VALUES (?, ?, ?, 'pendiente')")
           ->execute([$numero, $productoId, $cantidad]);
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('Pedido: ' . $e->getMessage());
        return false;
    }
}

/** Aplica un evento ya verificado (ver stripe-sin-sdk.php). Devuelve qué hizo. */
function aplicarEventoDePago(PDO $db, string $tipo, string $numero): string
{
    $q = $db->prepare('SELECT producto_id, cantidad, pago FROM orders WHERE numero = ?');
    $q->execute([$numero]);
    $pedido = $q->fetch(PDO::FETCH_ASSOC);
    if (!$pedido) {
        return 'pedido desconocido';
    }

    if ($tipo === 'checkout.session.completed') {
        if ($pedido['pago'] === 'pagado') {
            return 'reintento ignorado';                      // idempotencia
        }
        $db->beginTransaction();
        $db->prepare("UPDATE orders SET pago = 'pagado' WHERE numero = ?")->execute([$numero]);
        $db->prepare('UPDATE products SET ventas = ventas + ? WHERE id = ?')->execute([$pedido['cantidad'], $pedido['producto_id']]);
        $db->commit();
        return 'pagado';
    }

    if ($tipo === 'checkout.session.expired' && $pedido['pago'] === 'pendiente') {
        $db->beginTransaction();
        $db->prepare("UPDATE orders SET pago = 'expirado' WHERE numero = ?")->execute([$numero]);
        $db->prepare('UPDATE products SET stock = stock + ? WHERE id = ?')->execute([$pedido['cantidad'], $pedido['producto_id']]);
        $db->commit();
        return 'expirado, stock devuelto';
    }
    return 'sin cambios';
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, nombre TEXT, stock INTEGER, ventas INTEGER DEFAULT 0)');
    $db->exec("CREATE TABLE orders (numero TEXT PRIMARY KEY, producto_id INTEGER, cantidad INTEGER, pago TEXT)");
    $db->exec("INSERT INTO products (id, nombre, stock) VALUES (1, 'Monitor de signos vitales', 3)");
    $estado = fn () => $db->query('SELECT stock, ventas FROM products WHERE id = 1')->fetch(PDO::FETCH_ASSOC);

    echo 'Pedido A (2 piezas): ', crearPedido($db, 'A', 1, 2) ? 'reservado' : 'sin stock', "\n";
    echo 'Pedido B (2 piezas): ', crearPedido($db, 'B', 1, 2) ? 'reservado' : 'sin stock (quedaba 1)', "\n";
    echo 'Pedido C (1 pieza):  ', crearPedido($db, 'C', 1, 1) ? 'reservado' : 'sin stock', "\n";
    echo 'Evento completed A:  ', aplicarEventoDePago($db, 'checkout.session.completed', 'A'), "\n";
    echo 'Mismo evento otra vez: ', aplicarEventoDePago($db, 'checkout.session.completed', 'A'), "\n";
    echo 'Evento expired C:    ', aplicarEventoDePago($db, 'checkout.session.expired', 'C'), "\n";
    $e = $estado();
    printf("Final: stock %d (3 − 2 vendidas), ventas %d (no 4)\n", $e['stock'], $e['ventas']);
    exit(($e['stock'] === 1 && $e['ventas'] === 2) ? 0 : 1);
}
