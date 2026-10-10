<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once '../conexion/conexion.php';

// 1. Revisar que nos mandaron el ID
if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta el ID del contrato']);
    exit;
}

$id = intval($_GET['id']);

// 2. Buscar los datos generales del contrato (los valores ACTUALES)
$sql = "SELECT
            dc.id_contrato,
            c.nombre          AS cliente,
            ts.nombre_servicio AS servicio,
            e.fecha           AS fecha_entrega,
            e.unidades        AS unidades
        FROM detalle_contrato dc
        JOIN cliente c        ON c.id_cliente = dc.cliente_id
        JOIN tipo_servicio ts ON ts.id_servicios = dc.servicio_id
        LEFT JOIN entrega e   ON e.detalle_contrato_id = dc.id_contrato
        WHERE dc.id_contrato = :id";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $id]);
$contrato = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Si no existe, avisar
if (!$contrato) {
    http_response_code(404);
    echo json_encode(['error' => 'Contrato no encontrado']);
    exit;
}

// 4. Buscar los modelos de ese contrato (puede haber varios, o ninguno)
$sql2 = "SELECT
            m.id_modelo,
            m.nombre    AS modelo,
            m.tipo,
            m.categoria,
            m.nivel,
            cm.cantidad
         FROM contrato_modelo cm
         JOIN modelo m ON m.id_modelo = cm.id_modelo
         WHERE cm.id_contrato = :id
         ORDER BY m.nivel, m.categoria, m.tipo, m.nombre";

$stmt2 = $pdo->prepare($sql2);
$stmt2->execute(['id' => $id]);
$productos = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// 5. Convertir números (MySQL los entrega como texto)
foreach ($productos as &$p) {
    $p['id_modelo'] = (int)$p['id_modelo'];
    $p['cantidad']  = (int)$p['cantidad'];
}
unset($p);

// 6. Armar la respuesta final
echo json_encode([
    'id_contrato'   => (int)$contrato['id_contrato'],
    'cliente'       => $contrato['cliente'],
    'promocion'     => $contrato['servicio'],
    'fecha_entrega' => $contrato['fecha_entrega'],
    'unidades'      => $contrato['unidades'] !== null ? (int)$contrato['unidades'] : null,
    'productos'     => $productos
], JSON_UNESCAPED_UNICODE);