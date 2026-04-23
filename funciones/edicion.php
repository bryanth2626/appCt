<?php

//Gestión Post-Contrato

$host = '127.0.0.1';
$port = '3307';
$db = 'administracion';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$pdo = null;
$error_msg = '';
$success_msg = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    $error_msg = 'Error de conexión: ' . $e->getMessage();
}

// ── Crear tabla historial si no existe (con sesion_id)
if ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS historial_contrato (
            id_historial   INT AUTO_INCREMENT PRIMARY KEY,
            contrato_id    INT NOT NULL,
            sesion_id      CHAR(8) NOT NULL DEFAULT '00000000',
            fecha_hora     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            campo_cambiado VARCHAR(80) NOT NULL,
            valor_anterior TEXT,
            valor_nuevo    TEXT,
            CONSTRAINT fk_hist_contrato
                FOREIGN KEY (contrato_id) REFERENCES detalle_contrato(id_contrato)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
    // Agregar columna si tabla ya existía sin sesion_id
    try {
        $pdo->exec("ALTER TABLE historial_contrato ADD COLUMN sesion_id CHAR(8) NOT NULL DEFAULT '00000000' AFTER contrato_id");
    } catch (PDOException $e) { /* Ya existe, ignorar */
    }
}

// ── Comparar valores correctamente (decimales, fechas, strings)
function valorCambio($a, $b): bool
{
    // NULL y vacío son equivalentes
    $a = ($a === null || $a === false) ? '' : trim((string)$a);
    $b = ($b === null || $b === false) ? '' : trim((string)$b);
    if ($a === $b) return false;
    // Fechas inválidas (0000-00-00, año negativo) = vacío
    $invalida = fn($v) => !$v || str_starts_with($v, '0000') || str_starts_with($v, '-') || (preg_match('/^-?\d+/', $v) && (int)explode('-', ltrim($v,'-'))[0] < 1900);
    if ($invalida($a)) $a = '';
    if ($invalida($b)) $b = '';
    if ($a === $b) return false;
    // Decimales: comparar como float redondeado a 2
    if (is_numeric($a) && is_numeric($b)) {
        return round((float)$a, 2) !== round((float)$b, 2);
    }
    // Horas (HH:MM:SS vs HH:MM): comparar solo HH:MM
    if (preg_match('/^\d{2}:\d{2}/', $a) && preg_match('/^\d{2}:\d{2}/', $b)) {
        return substr($a, 0, 5) !== substr($b, 0, 5);
    }
    return $a !== $b;
}

function formatearValor(string $campo, $valor): string
{
    if ($valor === null || $valor === '') return '—';
    // Montos
    if (in_array($campo, ['Monto total', 'Adelanto', 'Saldo'])) {
        return 'S/ ' . number_format((float)$valor, 2);
    }
    // Fechas — validar que no sean inválidas
    if (str_contains($campo, 'echa')) {
        $v = (string)$valor;
        if (!$v || str_starts_with($v, '0000') || str_starts_with($v, '-')) return 'Por confirmar';
        $ts = @strtotime($v);
        if (!$ts || $ts < 0 || date('Y', $ts) < 1900) return 'Por confirmar';
        return date('d/m/Y', $ts);
    }
    // Horas — mostrar en formato 12h AM/PM
    if (str_contains($campo, 'ora') || str_contains($campo, 'inicio')) {
        $v = (string)$valor;
        if (!$v || $v === '00:00:00') return 'Por confirmar';
        $ts = @strtotime('1970-01-01 ' . $v);
        return $ts ? date('g:i A', $ts) : substr($v, 0, 5);
    }
    return (string)$valor;
}

// ══════════════════════════════════════════════════════
//  PROCESAR EDICIÓN
// ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'editar' && $pdo) {
    $id = (int) $_POST['id_contrato'];
    try {
        $pdo->beginTransaction();

        // ── Leer datos actuales ANTES de modificar
        $stmtAntes = $pdo->prepare("
            SELECT
                dc.proforma, dc.provincia, dc.direccion,
                dc.ho_inicio, dc.fecha_evento, dc.servicio_detalle,
                c.nombre, c.dni, c.email, c.telefono,
                ts.nombre_servicio,
                p.monto_total, p.adelanto, p.saldo, p.fecha_limite,
                fp.nombre_pago,
                e.fecha AS fecha_entrega, e.unidades,
                f.tipo  AS formato
            FROM detalle_contrato dc
            JOIN  cliente       c   ON c.id_cliente    = dc.cliente_id
            JOIN  tipo_servicio ts  ON ts.id_servicios = dc.servicio_id
            LEFT JOIN pago       p  ON p.contrato_id         = dc.id_contrato
            LEFT JOIN forma_pago fp ON fp.id_forma            = p.forma_id
            LEFT JOIN entrega    e  ON e.detalle_contrato_id  = dc.id_contrato
            LEFT JOIN formato    f  ON f.id_formato            = e.formato_id
            WHERE dc.id_contrato = ?
        ");
        $stmtAntes->execute([$id]);
        $old = $stmtAntes->fetch(PDO::FETCH_ASSOC);

        // ── Recoger nuevos valores
        $nombre = trim($_POST['nombre'] ?? '');
        $dni = trim($_POST['dni'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $proforma = (int) $_POST['proforma'];
        $provincia = trim($_POST['provincia'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $ho_inicio = trim($_POST['ho_inicio'] ?? '');
        $fecha_evento = trim($_POST['fecha_evento'] ?? '');
        $servicio_id = (int) $_POST['servicio_id'];
        $monto_total = (float) $_POST['monto_total'];
        $adelanto = (float) $_POST['adelanto'];
        $saldo = round($monto_total - $adelanto, 2);
        $fecha_limite = trim($_POST['fecha_limite'] ?? '');
        $forma_id = (int) $_POST['forma_id'];
        $fecha_entrega = trim($_POST['fecha_entrega'] ?? '');
        $unidades = (int) $_POST['unidades'];
        $formato_id = (int) $_POST['formato_id'];
        $condiciones_sel = array_map('intval', $_POST['condiciones_sel'] ?? []);

        // ── Obtener nombres legibles para campos foráneos
        $nuevoServicio = $pdo->query("SELECT nombre_servicio FROM tipo_servicio WHERE id_servicios=$servicio_id")->fetchColumn();
        $nuevaFormaPago = $pdo->query("SELECT nombre_pago FROM forma_pago WHERE id_forma=$forma_id")->fetchColumn();
        $nuevoFormato = $pdo->query("SELECT tipo FROM formato WHERE id_formato=$formato_id")->fetchColumn();

        // ── Ejecutar actualizaciones
        $stmtCli = $pdo->prepare("SELECT cliente_id FROM detalle_contrato WHERE id_contrato=?");
        $stmtCli->execute([$id]);
        $id_cliente = $stmtCli->fetchColumn();

        $pdo->prepare("UPDATE cliente SET nombre=?,dni=?,email=?,telefono=? WHERE id_cliente=?")
            ->execute([$nombre, $dni, $email, $telefono, $id_cliente]);

        $servicio_detalle_nuevo = trim($_POST['servicio_detalle'] ?? '');

        $pdo->prepare("UPDATE detalle_contrato SET proforma=?,provincia=?,direccion=?,ho_inicio=?,fecha_evento=?,servicio_id=?,servicio_detalle=? WHERE id_contrato=?")
            ->execute([$proforma, $provincia, $direccion, $ho_inicio ?: null, $fecha_evento ?: null, $servicio_id, $servicio_detalle_nuevo ?: null, $id]);

        $pdo->prepare("UPDATE pago SET monto_total=?,adelanto=?,saldo=?,fecha_limite=?,forma_id=? WHERE contrato_id=?")
            ->execute([$monto_total, $adelanto, $saldo, $fecha_limite, $forma_id, $id]);

        $pdo->prepare("UPDATE entrega SET fecha=?,unidades=?,formato_id=? WHERE detalle_contrato_id=?")
            ->execute([$fecha_entrega, $unidades, $formato_id, $id]);

        // ── Actualizar condiciones
        $pdo->prepare("DELETE FROM contrato_condicion WHERE contrato_id=?")->execute([$id]);
        if (!empty($condiciones_sel)) {
            $stmtCond = $pdo->prepare("INSERT INTO contrato_condicion (contrato_id,condicion_id) VALUES(?,?)");
            foreach ($condiciones_sel as $cid) {
                if ($cid > 0)
                    $stmtCond->execute([$id, $cid]);
            }
        }

        // ── Detectar cambios reales y registrar en historial
        $comparar = [
            'Nombre cliente' => [$old['nombre'], $nombre],
            'DNI' => [$old['dni'], $dni],
            'Email' => [$old['email'], $email],
            'Teléfono' => [$old['telefono'], $telefono],
            'Proforma' => [$old['proforma'], $proforma],
            'Provincia' => [$old['provincia'], $provincia],
            'Dirección' => [$old['direccion'], $direccion],
            'Hora inicio' => [$old['ho_inicio'], $ho_inicio],
            'Fecha evento' => [$old['fecha_evento'], $fecha_evento],
            'Evento' => [$old['nombre_servicio'], $nuevoServicio],
            'Servicio detalle' => [$old['servicio_detalle'] ?? '', $servicio_detalle_nuevo],
            'Monto total' => [$old['monto_total'], $monto_total],
            'Adelanto' => [$old['adelanto'], $adelanto],
            'Saldo' => [$old['saldo'], $saldo],
            'Fecha límite pago' => [$old['fecha_limite'], $fecha_limite],
            'Forma de pago' => [$old['nombre_pago'], $nuevaFormaPago],
            'Fecha entrega' => [$old['fecha_entrega'], $fecha_entrega],
            'Unidades' => [$old['unidades'], $unidades],
            'Formato entrega' => [$old['formato'], $nuevoFormato],
        ];

        // Filtrar solo los que realmente cambiaron
        $cambiosReales = [];
        foreach ($comparar as $campo => [$ant, $nvo]) {
            if (valorCambio($ant, $nvo)) {
                $cambiosReales[$campo] = [$ant, $nvo];
            }
        }

        // Solo insertar historial si hubo cambios
        if (!empty($cambiosReales)) {
            // Generar ID de sesión único de 8 chars (agrupa esta edición)
            $sesionId = strtoupper(substr(md5(uniqid()), 0, 8));
            $stmtH = $pdo->prepare("
                INSERT INTO historial_contrato
                    (contrato_id, sesion_id, campo_cambiado, valor_anterior, valor_nuevo)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($cambiosReales as $campo => [$ant, $nvo]) {
                $stmtH->execute([
                    $id,
                    $sesionId,
                    $campo,
                    formatearValor($campo, $ant),
                    formatearValor($campo, $nvo)
                ]);
            }
        }

        $pdo->commit();
        $numCambios = count($cambiosReales);
        $success_msg = 'Contrato N° ' . str_pad($id, 3, '0', STR_PAD_LEFT) . ' actualizado. '
            . ($numCambios > 0 ? "$numCambios campo(s) modificado(s) registrado(s) en el historial." : 'Sin cambios detectados.');

    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_msg = 'Error al guardar: ' . $e->getMessage();
    }
}

// ══════════════════════════════════════════════════════
//  PROCESAR ELIMINACIÓN
// ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && $pdo) {
    $id = (int) $_POST['id_contrato'];
    try {
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM historial_contrato  WHERE contrato_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM contrato_condicion  WHERE contrato_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM entrega             WHERE detalle_contrato_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM pago                WHERE contrato_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM detalle_contrato    WHERE id_contrato=?")->execute([$id]);
        $pdo->commit();
        $success_msg = 'Contrato N° ' . str_pad($id, 3, '0', STR_PAD_LEFT) . ' eliminado correctamente.';
    } catch (PDOException $e) {
        $pdo->rollBack();
        $error_msg = 'Error al eliminar: ' . $e->getMessage();
    }
}

// ── Filtros y consulta principal
$busqueda = trim($_GET['q'] ?? '');
$filtro_mes = trim($_GET['mes'] ?? '');
$hoy = date('Y-m-d');

$contratos = [];
if ($pdo) {
    $sql = "
        SELECT
            dc.id_contrato,
            dc.proforma, dc.provincia, dc.direccion, dc.ho_inicio,
            dc.fecha_evento, dc.fecha_contrato, dc.servicio_detalle,
            c.nombre AS cliente, c.dni, c.email, c.telefono,
            ts.nombre_servicio, ts.id_servicios AS servicio_id,
            p.monto_total, p.adelanto, p.saldo, p.fecha_limite,
            fp.nombre_pago, p.forma_id,
            e.fecha AS fecha_entrega, e.unidades,
            f.tipo AS formato, f.id_formato AS formato_id
        FROM detalle_contrato dc
        JOIN  cliente       c   ON c.id_cliente    = dc.cliente_id
        JOIN  tipo_servicio ts  ON ts.id_servicios = dc.servicio_id
        LEFT JOIN pago       p  ON p.contrato_id         = dc.id_contrato
        LEFT JOIN forma_pago fp ON fp.id_forma            = p.forma_id
        LEFT JOIN entrega    e  ON e.detalle_contrato_id  = dc.id_contrato
        LEFT JOIN formato    f  ON f.id_formato            = e.formato_id
        WHERE 1=1
    ";
    $params = [];
    if ($busqueda !== '') {
        $sql .= " AND (c.nombre LIKE ? OR c.dni LIKE ? OR dc.provincia LIKE ?)";
        $like = '%' . $busqueda . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }
    if ($filtro_mes !== '') {
        $sql .= " AND DATE_FORMAT(dc.fecha_evento,'%Y-%m')=?";
        $params[] = $filtro_mes;
    }
    $sql .= " ORDER BY dc.fecha_contrato DESC, dc.id_contrato DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contratos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contratos as &$ct) {
        // Condiciones
        $s = $pdo->prepare("
            SELECT co.id_condiciones, co.detalle
            FROM contrato_condicion cc
            JOIN condiciones co ON co.id_condiciones = cc.condicion_id
            WHERE cc.contrato_id = ?
            ORDER BY co.id_condiciones
        ");
        $s->execute([$ct['id_contrato']]);
        $ct['condiciones_obj'] = $s->fetchAll(PDO::FETCH_ASSOC);

        // Historial — agrupado por sesion_id
        $h = $pdo->prepare("
            SELECT sesion_id, campo_cambiado, valor_anterior, valor_nuevo,
                   fecha_hora,
                   MIN(fecha_hora) OVER (PARTITION BY sesion_id) AS sesion_inicio
            FROM historial_contrato
            WHERE contrato_id = ?
            ORDER BY sesion_inicio DESC, sesion_id, id_historial
        ");
        $h->execute([$ct['id_contrato']]);
        $filas = $h->fetchAll(PDO::FETCH_ASSOC);

        // Agrupar por sesion_id
        $sesiones = [];
        foreach ($filas as $fila) {
            $sid = $fila['sesion_id'];
            if (!isset($sesiones[$sid])) {
                $sesiones[$sid] = [
                    'sesion_id' => $sid,
                    'fecha_hora' => $fila['fecha_hora'],
                    'cambios' => []
                ];
            }
            $sesiones[$sid]['cambios'][] = [
                'campo' => $fila['campo_cambiado'],
                'anterior' => $fila['valor_anterior'],
                'nuevo' => $fila['valor_nuevo'],
            ];
        }
        $ct['historial_sesiones'] = array_values($sesiones);
    }
    unset($ct);
}

// Catálogos
$servicios = $pdo ? $pdo->query("SELECT id_servicios,nombre_servicio FROM tipo_servicio ORDER BY nombre_servicio")->fetchAll(PDO::FETCH_ASSOC) : [];
$formatos = $pdo ? $pdo->query("SELECT id_formato,tipo FROM formato ORDER BY tipo")->fetchAll(PDO::FETCH_ASSOC) : [];
$formas_pago = $pdo ? $pdo->query("SELECT id_forma,nombre_pago FROM forma_pago")->fetchAll(PDO::FETCH_ASSOC) : [];
$todas_cond = $pdo ? $pdo->query("SELECT id_condiciones,detalle FROM condiciones ORDER BY id_condiciones")->fetchAll(PDO::FETCH_ASSOC) : [];

function calcEstado(array $ct, string $hoy): array
{
    $s = (float) ($ct['saldo'] ?? 0);
    $f = $ct['fecha_limite'] ?? '';
    if ($s <= 0)
        return ['label' => 'Pagado', 'clase' => 'badge-ok'];
    if ($f && $f < $hoy)
        return ['label' => 'No pago', 'clase' => 'badge-pendiente'];
    return ['label' => 'Proceso', 'clase' => 'badge-parcial'];
}

$total_contratos = count($contratos);
$total_monto = array_sum(array_column($contratos, 'monto_total'));
$total_saldo = array_sum(array_column($contratos, 'saldo'));

$CONDICION_FIJA = '* Después de ser entregado el material, el cliente tiene 1 semana para realizar algún reclamo o cambio. Pasando la semana no hay reclamo alguno *';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gestión Post-Contrato — ISHUME</title>
    <link href="../estilos/lista.css" rel="stylesheet" />
    <style>
        /* ── Modal grande ── */
        .modal-g-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
            overflow-y: auto;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
        }

        .modal-g-overlay.visible {
            opacity: 1;
            pointer-events: all;
        }

        .modal-g-box {
            background: #fff;
            width: 100%;
            max-width: 700px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.3);
            margin: auto;
        }

        /* Tabs modal edición */
        .mtabs {
            display: flex;
            border-bottom: 2px solid #e8e8e8;
            background: #fafafa;
        }

        .mtab {
            padding: 10px 18px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            border: none;
            background: transparent;
            color: #999;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            font-family: Arial, sans-serif;
            transition: color 0.15s, border-color 0.15s;
        }

        .mtab:hover {
            color: #f07c00;
        }

        .mtab.active {
            color: #f07c00;
            border-bottom-color: #f07c00;
        }

        .mpanel {
            display: none;
            padding: 18px 22px 22px;
        }

        .mpanel.active {
            display: block;
        }

        /* Campos edición */
        .eg2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px 20px;
        }

        .eg3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px 20px;
        }

        .es2 {
            grid-column: span 2;
        }

        .ec {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .ec label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #888;
        }

        .ec input,
        .ec select {
            border: none;
            border-bottom: 1.5px solid #ccc;
            background: transparent;
            padding: 5px 2px;
            font-size: 13px;
            font-family: Arial, sans-serif;
            color: #111;
            outline: none;
            width: 100%;
            transition: border-color 0.2s;
        }

        .ec input:focus,
        .ec select:focus {
            border-bottom-color: #f07c00;
        }

        .esec-t {
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #f07c00;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 6px;
            margin-bottom: 12px;
        }

        .esec {
            margin-bottom: 16px;
        }

        /* Checkboxes condiciones edición */
        .econd-lista {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 6px;
        }

        .econd-item {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 7px 10px;
            border: 1.5px solid #e8e8e8;
            cursor: pointer;
            transition: border-color 0.15s, background 0.15s;
            font-size: 12px;
            line-height: 1.5;
            color: #333;
        }

        .econd-item:hover {
            border-color: #f07c00;
            background: #fff8f2;
        }

        .econd-item.sel {
            border-color: #f07c00;
            background: #fff3e0;
        }

        .econd-item input[type="checkbox"] {
            accent-color: #f07c00;
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* ── Historial por sesiones ── */
        .hist-sesion {
            margin-bottom: 16px;
            border: 1px solid #e8e8e8;
        }

        .hist-sesion-header {
            background: #111;
            padding: 8px 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .hist-sesion-fecha {
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            letter-spacing: 0.05em;
        }

        .hist-sesion-id {
            font-size: 10px;
            color: #888;
            font-family: monospace;
            letter-spacing: 0.1em;
        }

        .hist-sesion-body {
            padding: 0;
        }

        .hist-fila {
            display: grid;
            grid-template-columns: 140px 1fr 1fr;
            gap: 0;
            border-bottom: 1px solid #f5f5f5;
            padding: 8px 14px;
            font-size: 12px;
        }

        .hist-fila:last-child {
            border-bottom: none;
        }

        .hist-fila:hover {
            background: #fff8f2;
        }

        .hf-campo {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #f07c00;
            align-self: center;
        }

        .hf-ant {
            color: #c0392b;
            font-size: 12px;
            padding-right: 10px;
            position: relative;
        }

        .hf-ant::before {
            content: 'Antes: ';
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #e0a0a0;
            display: block;
            margin-bottom: 2px;
        }

        .hf-nvo {
            color: #1a7a30;
            font-size: 12px;
            font-weight: 600;
            padding-left: 10px;
            border-left: 2px solid #f0f0f0;
        }

        .hf-nvo::before {
            content: 'Después: ';
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #8abeaa;
            display: block;
            margin-bottom: 2px;
        }

        .hist-vacio {
            text-align: center;
            color: #bbb;
            padding: 30px 20px;
            font-size: 13px;
        }

        /* ── Vista documento ── */
        .doc-view-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.65);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            z-index: 3000;
            padding: 20px;
            overflow-y: auto;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
        }

        .doc-view-overlay.visible {
            opacity: 1;
            pointer-events: all;
        }

        .doc-view-barra {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            width: 760px;
            margin-bottom: 10px;
            flex-shrink: 0;
        }

        .doc-view-hoja {
            background: #fff;
            width: 760px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.3);
            flex-shrink: 0;
        }

        /* Estilos internos del documento vista */
        .cv-body {
            padding: 28px 36px 36px;
        }

        .cv-title {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 2px solid #f07c00;
            padding-bottom: 10px;
            margin-bottom: 22px;
        }

        .cv-title h2 {
            font-size: 14px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #111;
        }

        .cv-title span {
            font-size: 12px;
            font-weight: 600;
            color: #555;
        }

        .cv-sec {
            margin-bottom: 18px;
        }

        .cv-sec-t {
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #fff;
            background: #111;
            padding: 5px 10px;
            margin-bottom: 10px;
            display: inline-block;
        }

        .cv-tbl {
            width: 100%;
            border-collapse: collapse;
        }

        .cv-tbl tr {
            border-bottom: 1px solid #f0f0f0;
        }

        .cv-tbl td {
            padding: 6px 8px;
            font-size: 12.5px;
            vertical-align: top;
        }

        .cv-tbl td.l {
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #666;
            width: 170px;
            white-space: nowrap;
        }

        .cv-tbl-pago {
            width: 100%;
            border-collapse: collapse;
        }

        .cv-tbl-pago th {
            background: #f07c00;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 7px 10px;
            text-align: left;
        }

        .cv-tbl-pago td {
            padding: 8px 10px;
            font-size: 12.5px;
            border-bottom: 1px solid #f0f0f0;
        }

        .cv-conds {
            background: #fafafa;
            border: 1px solid #e8e8e8;
            padding: 14px 18px;
            font-size: 12px;
            line-height: 1.8;
            color: #444;
        }

        .cv-conds ol {
            padding-left: 20px;
            margin-bottom: 8px;
        }

        .cv-conds li {
            margin-bottom: 4px;
        }

        .cv-cond-fija {
            color: #c0392b;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            text-align: center;
            padding-top: 10px;
            border-top: 1px dashed #e0a0a0;
            margin-top: 6px;
        }

        .cv-firmas {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 60px;
            margin-top: 70px;
            margin-bottom: 10px;
        }

        .cv-firma {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .cv-linea {
            width: 100%;
            border-bottom: 1.5px solid #111;
        }

        .cv-flabel {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #555;
        }

        .cv-estado {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            padding: 3px 10px;
        }

        /* Botón historial */
        .btn-hist {
            color: #2c3e50 !important;
        }

        .btn-hist:hover {
            border-color: #2c3e50 !important;
            background: #eaf0f6 !important;
            color: #2c3e50 !important;
        }
    </style>
</head>

<body>

    <form id="form-delete" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete" />
        <input type="hidden" name="id_contrato" id="del-id" />
    </form>

    <!-- ══ MODAL EDITAR ══ -->
    <div class="modal-g-overlay" id="modal-editar">
        <div class="modal-g-box">
            <div class="modal-header">
                <h3 id="tit-editar">Editar Contrato</h3>
                <button class="modal-close" onclick="cerrar('modal-editar')">×</button>
            </div>
            <div class="mtabs">
                <button class="mtab active" onclick="mt('mpDatos',this)">Datos</button>
                <button class="mtab" onclick="mt('mpPago',this)">Pago</button>
                <button class="mtab" onclick="mt('mpEntrega',this)">Entrega</button>
                <button class="mtab" onclick="mt('mpCond',this)">Condiciones</button>
            </div>
            <form method="POST" action="" id="form-editar">
                <input type="hidden" name="action" value="editar" />
                <input type="hidden" name="id_contrato" id="e-id" />

                <!-- TAB DATOS -->
                <div class="mpanel active" id="mpDatos">
                    <div class="esec">
                        <div class="esec-t">Cliente</div>
                        <div class="eg2">
                            <div class="ec es2"><label>Nombre completo *</label><input type="text" name="nombre"
                                    id="e-nombre" required /></div>
                            <div class="ec"><label>DNI *</label><input type="text" name="dni" id="e-dni" maxlength="8"
                                    required /></div>
                            <div class="ec"><label>Teléfono</label><input type="text" name="telefono" id="e-telefono"
                                    maxlength="9" /></div>
                            <div class="ec es2"><label>Email</label><input type="email" name="email" id="e-email" />
                            </div>
                        </div>
                    </div>
                    <div class="esec">
                        <div class="esec-t">Evento</div>
                        <div class="eg2">
                            <div class="ec"><label>Fecha del evento</label><input type="date" name="fecha_evento"
                                    id="e-fecha_evento" /></div>
                            <div class="ec"><label>Hora de inicio</label><input type="time" name="ho_inicio"
                                    id="e-ho_inicio" /></div>
                            <div class="ec es2"><label>Dirección *</label><input type="text" name="direccion"
                                    id="e-direccion" required /></div>
                            <div class="ec"><label>Provincia *</label><input type="text" name="provincia"
                                    id="e-provincia" required /></div>
                            <div class="ec"><label>Proforma</label><input type="number" name="proforma" id="e-proforma"
                                    min="1" /></div>
                            <div class="ec es2"><label>Evento *</label>
                                <select name="servicio_id" id="e-servicio_id" required>
                                    <?php foreach ($servicios as $s): ?>
                                        <option value="<?= $s['id_servicios'] ?>">
                                            <?= htmlspecialchars($s['nombre_servicio']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ec es2"><label>Servicio (descripción)</label>
                                <input type="text" name="servicio_detalle" id="e-servicio_detalle"
                                       maxlength="100" placeholder="Descripción del servicio contratado..."/>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB PAGO -->
                <div class="mpanel" id="mpPago">
                    <div class="esec">
                        <div class="esec-t">Estado de Pago</div>
                        <div class="eg2">
                            <div class="ec"><label>Monto Total (S/) *</label><input type="number" name="monto_total"
                                    id="e-monto_total" min="0" step="0.01" required oninput="editSaldo()" /></div>
                            <div class="ec"><label>Adelanto (S/) *</label><input type="number" name="adelanto"
                                    id="e-adelanto" min="0" step="0.01" required oninput="editSaldo()" /></div>
                            <div class="ec"><label>Saldo Pendiente</label><input type="text" id="e-saldo-d"
                                    readonly /><input type="hidden" name="saldo" id="e-saldo-h" /></div>
                            <div class="ec"><label>Fecha límite</label><input type="date" name="fecha_limite"
                                    id="e-fecha_limite" /></div>
                            <div class="ec es2"><label>Forma de pago *</label>
                                <select name="forma_id" id="e-forma_id" required>
                                    <?php foreach ($formas_pago as $fp): ?>
                                        <option value="<?= $fp['id_forma'] ?>"><?= htmlspecialchars($fp['nombre_pago']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <small id="e-saldo-err" style="color:#c00;font-size:10px;display:none;">⚠ El adelanto supera el
                            monto total</small>
                    </div>
                </div>

                <!-- TAB ENTREGA -->
                <div class="mpanel" id="mpEntrega">
                    <div class="esec">
                        <div class="esec-t">Entrega del Material</div>
                        <div class="eg3">
                            <div class="ec"><label>Fecha entrega</label><input type="date" name="fecha_entrega"
                                    id="e-fecha_entrega" /></div>
                            <div class="ec"><label>Formato *</label>
                                <select name="formato_id" id="e-formato_id" required>
                                    <?php foreach ($formatos as $f): ?>
                                        <option value="<?= $f['id_formato'] ?>"><?= htmlspecialchars($f['tipo']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ec"><label>N° unidades *</label><input type="number" name="unidades"
                                    id="e-unidades" min="1" required /></div>
                        </div>
                    </div>
                </div>

                <!-- TAB CONDICIONES -->
                <div class="mpanel" id="mpCond">
                    <div class="esec">
                        <div class="esec-t">Condiciones del Contrato</div>
                        <div class="econd-lista" id="e-cond-lista">
                            <?php foreach ($todas_cond as $tc): ?>
                                <label class="econd-item" id="ec-<?= $tc['id_condiciones'] ?>">
                                    <input type="checkbox" name="condiciones_sel[]" value="<?= $tc['id_condiciones'] ?>"
                                        onchange="this.closest('.econd-item').classList.toggle('sel',this.checked)" />
                                    <span><?= htmlspecialchars($tc['detalle']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="modal-footer-btns" style="padding:12px 22px;border-top:1px solid #f0f0f0;">
                    <button type="button" class="btn btn-secondary btn-sm"
                        onclick="cerrar('modal-editar')">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-sm" onclick="return validarEd()">Guardar
                        Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══ MODAL VER ══ -->
    <div class="doc-view-overlay" id="modal-ver">
        <div style="display:flex;flex-direction:column;align-items:center;">
            <div class="doc-view-barra">
                <a id="btn-pdf-link" href="#" target="_blank" class="btn btn-primary btn-sm">⬇ Imprimir / PDF</a>
                <button class="btn btn-secondary btn-sm"
                    onclick="cerrar('modal-ver');document.getElementById('ver-hoja').innerHTML=''">✕ Cerrar</button>
            </div>
            <div class="doc-view-hoja" id="ver-hoja"></div>
        </div>
    </div>

    <!-- ══ MODAL HISTORIAL ══ -->
    <div class="modal-g-overlay" id="modal-hist">
        <div class="modal-g-box" style="max-width:640px;">
            <div class="modal-header">
                <h3 id="tit-hist">Historial de Cambios</h3>
                <button class="modal-close" onclick="cerrar('modal-hist')">×</button>
            </div>
            <div style="padding:20px 22px 24px;max-height:70vh;overflow-y:auto;" id="hist-body"></div>
        </div>
    </div>

    <div class="modal-g-overlay" id="modal-confirm">
        <div class="modal-g-box" style="max-width:400px;">
            <div class="modal-header">
                <h3>Confirmar Eliminación</h3>
                <button class="modal-close" onclick="cerrar('modal-confirm')">×</button>
            </div>
            <div style="padding:20px; font-size:14px;" id="confirm-text"></div>
            <div class="modal-footer-btns" style="padding:12px 20px;">
                <button class="btn btn-secondary btn-sm" onclick="cerrar('modal-confirm')">Cancelar</button>
                <button class="btn btn-danger btn-sm" onclick="ejecutarDelete()">Eliminar</button>
            </div>
        </div>
    </div>


    <!-- ══ PÁGINA ══ -->
    <div class="pagina">

        <div class="doc-header">
            <div class="doc-header-logo"><img src="../img/logo.png" alt="ISHUME" /></div>
            <div class="doc-header-info">
                <h1>Xiomara Tasayco H.</h1>
                <p>Gerenta General &nbsp;|&nbsp; Gestión Post-Contrato</p>
            </div>
        </div>

        <div class="doc-body">

            <?php if ($success_msg): ?>
                <div class="msg msg-ok"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="msg msg-error"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <div class="doc-title-row">
                <h2>Gestión Post-Contrato</h2>
            </div>

            <div class="resumen-strip">
                <div class="resumen-item">
                    <span class="resumen-valor"><?= $total_contratos ?></span>
                    <span class="resumen-label">Contratos</span>
                </div>
                <div class="resumen-sep"></div>
                <div class="resumen-item">
                    <span class="resumen-valor">S/ <?= number_format($total_monto, 2) ?></span>
                    <span class="resumen-label">Monto Total</span>
                </div>
                <div class="resumen-sep"></div>
                <div class="resumen-item">
                    <span class="resumen-valor saldo-pendiente">S/ <?= number_format($total_saldo, 2) ?></span>
                    <span class="resumen-label">Saldo Pendiente</span>
                </div>
            </div>

            <form method="GET" class="filtros-row">
                <div class="filtro-campo">
                    <input type="text" name="q" placeholder="Buscar por cliente, DNI o provincia…"
                        value="<?= htmlspecialchars($busqueda) ?>" />
                </div>
                <div class="filtro-campo filtro-mes">
                    <input type="month" name="mes" value="<?= htmlspecialchars($filtro_mes) ?>" />
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
                <?php if ($busqueda || $filtro_mes): ?>
                    <a href="edicion.php" class="btn btn-secondary btn-sm">Limpiar</a>
                <?php endif; ?>
            </form>

            <?php if (empty($contratos)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 48 48" fill="none">
                        <rect x="8" y="4" width="32" height="40" rx="2" stroke="#ddd" stroke-width="2" />
                        <line x1="14" y1="16" x2="34" y2="16" stroke="#ddd" stroke-width="2" />
                        <line x1="14" y1="22" x2="34" y2="22" stroke="#ddd" stroke-width="2" />
                        <line x1="14" y1="28" x2="26" y2="28" stroke="#ddd" stroke-width="2" />
                    </svg>
                    <p>No se encontraron contratos.</p>
                </div>
            <?php else: ?>
                <div class="tabla-wrapper">
                    <table class="tabla-contratos">
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Cliente</th>
                                <th>Servicio</th>
                                <th>Fecha Evento</th>
                                <th>Monto</th>
                                <th>Saldo</th>
                                <th>Estado</th>
                                <th class="th-acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contratos as $ct):
                                $estado = calcEstado($ct, $hoy);
                                $condIds = array_column($ct['condiciones_obj'], 'id_condiciones');
                                $nHist = count($ct['historial_sesiones']);
                                ?>
                                <tr>
                                    <td class="td-num"><?= str_pad($ct['id_contrato'], 3, '0', STR_PAD_LEFT) ?></td>
                                    <td>
                                        <div class="cliente-nombre"><?= htmlspecialchars($ct['cliente']) ?></div>
                                        <div class="cliente-dni">DNI <?= htmlspecialchars($ct['dni']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($ct['nombre_servicio']) ?></td>
                                    <td>
                                        <?= date('d/m/Y', strtotime($ct['fecha_evento'])) ?>
                                        <div class="sub-dato"><?= substr($ct['ho_inicio'], 0, 5) ?> hrs</div>
                                    </td>
                                    <td class="td-monto">S/ <?= number_format($ct['monto_total'], 2) ?></td>
                                    <td class="td-saldo <?= (float) $ct['saldo'] <= 0 ? 'saldo-ok' : 'saldo-pendiente' ?>">
                                        S/ <?= number_format($ct['saldo'], 2) ?>
                                    </td>
                                    <td><span class="badge <?= $estado['clase'] ?>"><?= $estado['label'] ?></span></td>
                                    <td class="td-acciones">

                                        <!-- VER -->
                                        <button class="btn-icon btn-ver" title="Ver contrato"
                                            onclick='verContrato(<?= htmlspecialchars(json_encode($ct)) ?>)'>
                                            <svg viewBox="0 0 16 16" fill="none">
                                                <ellipse cx="8" cy="8" rx="7" ry="5" stroke="currentColor" stroke-width="1.3" />
                                                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.3" />
                                            </svg>
                                        </button>

                                        <!-- EDITAR -->
                                        <button class="btn-icon btn-edit" title="Editar" onclick='abrirEditar(<?= htmlspecialchars(json_encode($ct)) ?>,
                                                     <?= htmlspecialchars(json_encode($condIds)) ?>)'>
                                            <svg viewBox="0 0 16 16" fill="none">
                                                <path d="M11.5 2.5l2 2-9 9H2.5v-2l9-9z" stroke="currentColor" stroke-width="1.3"
                                                    stroke-linejoin="round" />
                                            </svg>
                                        </button>

                                        <!-- PDF -->
                                        <a class="btn-icon btn-pdf" title="Imprimir / PDF"
                                            href="contrato_pdf.php?id=<?= $ct['id_contrato'] ?>&origen=edicion" target="_blank">
                                            <svg viewBox="0 0 16 16" fill="none">
                                                <path d="M3 2h7l3 3v9a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z"
                                                    stroke="currentColor" stroke-width="1.3" />
                                                <path d="M10 2v3h3" stroke="currentColor" stroke-width="1.3" />
                                                <line x1="5" y1="9" x2="11" y2="9" stroke="currentColor" stroke-width="1.2"
                                                    stroke-linecap="round" />
                                                <line x1="5" y1="11" x2="9" y2="11" stroke="currentColor" stroke-width="1.2"
                                                    stroke-linecap="round" />
                                            </svg>
                                        </a>

                                        <!-- HISTORIAL -->
                                        <button class="btn-icon btn-hist"
                                            title="Historial de cambios (<?= $nHist ?> sesión<?= $nHist !== 1 ? 'es' : '' ?>)"
                                            onclick='verHistorial(
                                    <?= $ct['id_contrato'] ?>,
                                    <?= htmlspecialchars(json_encode($ct['historial_sesiones'])) ?>,
                                    <?= htmlspecialchars(json_encode($ct['cliente'])) ?>)'>
                                            <svg viewBox="0 0 16 16" fill="none">
                                                <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="1.3" />
                                                <path d="M8 5v3.5l2 1.5" stroke="currentColor" stroke-width="1.3"
                                                    stroke-linecap="round" />
                                            </svg>
                                            <?php if ($nHist > 0): ?>
                                                <span
                                                    style="font-size:9px;font-weight:800;color:#f07c00;position:absolute;top:-4px;right:-4px;
                                             background:#fff;border:1px solid #f07c00;padding:0 3px;line-height:12px;border-radius:2px;">
                                                    <?= $nHist ?>
                                                </span>
                                            <?php endif; ?>
                                        </button>

                                        <!-- ELIMINAR -->
                                        <button class="btn-icon btn-del" title="Eliminar" onclick="confirmarEliminar(
                                    <?= $ct['id_contrato'] ?>,
                                    <?= htmlspecialchars(json_encode($ct['cliente'])) ?>)">
                                            <svg viewBox="0 0 16 16" fill="none">
                                                <path d="M3 4h10M6 4V3h4v1M5 4v8h6V4H5z" stroke="currentColor"
                                                    stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>

                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="acciones">
                <a href="../index.php" class="btn btn-secondary">← Volver al inicio</a>
            </div>

        </div>

    </div>

    <script>
        const HOY = '<?= $hoy ?>';
        const COND_FIJA = <?= json_encode($CONDICION_FIJA) ?>;

        function abrir(id) { document.getElementById(id).classList.add('visible'); }
        function cerrar(id) { document.getElementById(id).classList.remove('visible'); }
        function formatearHora(hora) {
            if (!hora) return '—';

            let partes = hora.split(':');
            let h = parseInt(partes[0]);
            let m = partes[1];

            let ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            h = h ? h : 12;

            return h + ':' + m + ' ' + ampm;
        }

        document.querySelectorAll('.modal-g-overlay,.doc-view-overlay').forEach(o => {
            o.addEventListener('click', e => { if (e.target === o) { cerrar(o.id); if (o.id === 'modal-ver') document.getElementById('ver-hoja').innerHTML = ''; } });
        });

        // Tabs modal edición
        function mt(panelId, btn) {
            document.querySelectorAll('.mpanel').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.mtab').forEach(b => b.classList.remove('active'));
            document.getElementById(panelId).classList.add('active');
            btn.classList.add('active');
        }

        // Saldo edición
        function editSaldo() {
            const t = parseFloat(document.getElementById('e-monto_total').value) || 0;
            const a = parseFloat(document.getElementById('e-adelanto').value) || 0;
            const s = t - a;
            const d = document.getElementById('e-saldo-d');
            const h = document.getElementById('e-saldo-h');
            d.value = s.toFixed(2); h.value = s.toFixed(2);
            d.style.color = s < 0 ? '#c00' : '#1a7a30';
            document.getElementById('e-saldo-err').style.display = s < 0 ? 'block' : 'none';
        }

        // Abrir edición
        function abrirEditar(c, condActuales) {
            document.getElementById('e-id').value = c.id_contrato;
            document.getElementById('e-nombre').value = c.cliente || '';
            document.getElementById('e-dni').value = c.dni || '';
            document.getElementById('e-email').value = c.email || '';
            document.getElementById('e-telefono').value = c.telefono || '';
            document.getElementById('e-proforma').value = c.proforma || '';
            document.getElementById('e-provincia').value = c.provincia || '';
            document.getElementById('e-direccion').value = c.direccion || '';
            // Solo cargar hora si es válida
            const horaVal = c.ho_inicio || '';
            document.getElementById('e-ho_inicio').value = (horaVal && horaVal !== '00:00:00') ? horaVal.substring(0,5) : '';
            // Solo cargar fecha si es válida (no 0000-00-00 ni año negativo)
            const fechaVal = c.fecha_evento || '';
            const fechaOk = fechaVal && !fechaVal.startsWith('0000') && !fechaVal.startsWith('-') && parseInt(fechaVal.split('-')[0]) >= 1900;
            document.getElementById('e-fecha_evento').value = fechaOk ? fechaVal : '';
            document.getElementById('e-monto_total').value = parseFloat(c.monto_total || 0).toFixed(2);
            document.getElementById('e-adelanto').value = parseFloat(c.adelanto || 0).toFixed(2);
            // Fechas de pago y entrega — solo si válidas
            const flVal = c.fecha_limite || '';
            const flOk = flVal && !flVal.startsWith('0000') && !flVal.startsWith('-') && parseInt(flVal.split('-')[0]) >= 1900;
            document.getElementById('e-fecha_limite').value = flOk ? flVal : '';
            const feVal = c.fecha_entrega || '';
            const feOk = feVal && !feVal.startsWith('0000') && !feVal.startsWith('-') && parseInt(feVal.split('-')[0]) >= 1900;
            document.getElementById('e-fecha_entrega').value = feOk ? feVal : '';
            document.getElementById('e-unidades').value = c.unidades || '';
            selVal('e-servicio_id', c.servicio_id);
            selVal('e-forma_id', c.forma_id);
            selVal('e-formato_id', c.formato_id);
            // Cargar servicio_detalle
            const sdEl = document.getElementById('e-servicio_detalle');
            if (sdEl) sdEl.value = c.servicio_detalle || '';
            editSaldo();

            document.querySelectorAll('#e-cond-lista input[type="checkbox"]').forEach(cb => {
                const m = condActuales.includes(parseInt(cb.value));
                cb.checked = m;
                cb.closest('.econd-item').classList.toggle('sel', m);
            });

            document.getElementById('tit-editar').textContent =
                'Editar Contrato N° ' + String(c.id_contrato).padStart(3, '0');

            mt('mpDatos', document.querySelector('.mtab'));
            abrir('modal-editar');
        }

        function selVal(id, val) {
            const s = document.getElementById(id);
            if (!s || val == null) return;
            [...s.options].forEach(o => { o.selected = (o.value == val); });
        }

        function validarEd() {
            const s = parseFloat(document.getElementById('e-saldo-h').value);
            if (isNaN(s) || s < 0) {
                alert('⚠ El adelanto no puede superar el monto total.');
                mt('mpPago', document.querySelectorAll('.mtab')[1]);
                return false;
            }
            return true;
        }

        // Utilidades
        function fmtFecha(f) {
            if (!f || f === '' || f.startsWith('0000') || f.startsWith('-')) return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
            const p = f.split('-');
            if (!p[0] || parseInt(p[0]) < 1900) return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
            return p[2] + '/' + p[1] + '/' + p[0];
        }
        function fmtHora(h) {
            if (!h || h === '' || h === '00:00:00') return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
            const parts = h.split(':');
            let hh = parseInt(parts[0]);
            const mm = parts[1] || '00';
            const ampm = hh >= 12 ? 'PM' : 'AM';
            hh = hh % 12 || 12;
            return hh + ':' + mm + ' ' + ampm;
        }
        function fmtMoney(v) {
            return 'S/ ' + parseFloat(v || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }
        function esc(s) {
            if (!s) return '';
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function calcEstado(saldo, fl) {
            if (saldo <= 0) return { label: 'Pagado', bg: '#e6f4ea', color: '#1a7a30' };
            if (fl && fl < HOY) return { label: 'No pago', bg: '#fdecea', color: '#c0392b' };
            return { label: 'Proceso', bg: '#fff3e0', color: '#e65100' };
        }

        // Ver contrato
        function verContrato(c) {
            const saldo = parseFloat(c.saldo || 0);
            const estado = calcEstado(saldo, c.fecha_limite);
            const num = String(c.id_contrato).padStart(3, '0');
            const conds = Array.isArray(c.condiciones_obj) ? c.condiciones_obj : [];
            const liConds = conds.map(co => `<li>${esc(co.detalle)}</li>`).join('');

            document.getElementById('btn-pdf-link').href = 'contrato_pdf.php?id=' + c.id_contrato + '&origen=edicion';
            document.getElementById('ver-hoja').innerHTML = `
        <div class="doc-header">
            <div class="doc-header-logo"><img src="../img/logo.png" alt="ISHUME"/></div>
            <div class="doc-header-info">
                <h1>Xiomara Tasayco H.</h1>
                <p>Gerenta General &nbsp;|&nbsp; 991157028 &nbsp;|&nbsp; ✉ IshumeAgencia@hotmail.com</p>
            </div>
        </div>
        <div class="cv-body">
            <div class="cv-title">
                <h2>Contrato N° ${num} — Servicios Audiovisuales</h2>
                <span>Chincha, ${fmtFecha(c.fecha_contrato)}</span>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Datos del Cliente</div>
                <table class="cv-tbl">
                    <tr><td class="l">Nombre</td><td>${esc(c.cliente)}</td></tr>
                    <tr><td class="l">DNI</td><td>${esc(c.dni)}</td></tr>
                    <tr><td class="l">Teléfono</td><td>${esc(c.telefono) || '—'}</td></tr>
                    <tr><td class="l">Email</td><td>${esc(c.email) || '—'}</td></tr>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Detalles del contrato</div>
                <table class="cv-tbl">
                    <tr><td class="l">Evento</td><td>${esc(c.nombre_servicio)}</td></tr>
                    ${c.servicio_detalle ? `<tr><td class="l">Servicio</td><td>${esc(c.servicio_detalle)}</td></tr>` : ''}
                    <tr><td class="l">Fecha</td><td>${fmtFecha(c.fecha_evento)}</td></tr>
                    <tr><td class="l">Hora inicio</td><td>${fmtHora(c.ho_inicio)}</td></tr>
                    <tr><td class="l">Dirección</td><td>${esc(c.direccion)}</td></tr>
                    <tr><td class="l">Provincia</td><td>${esc(c.provincia)}</td></tr>
                    <tr><td class="l">N° personas</td><td>${esc(c.proforma)}</td></tr>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Estado de Pago</div>
                <table class="cv-tbl-pago">
                    <thead><tr><th>Monto Total</th><th>Adelanto</th><th>Saldo</th><th>Forma Pago</th><th>Fecha Límite</th><th>Estado</th></tr></thead>
                    <tbody><tr>
                        <td style="font-weight:700">${fmtMoney(c.monto_total)}</td>
                        <td style="font-weight:700">${fmtMoney(c.adelanto)}</td>
                        <td style="font-weight:800;color:${saldo <= 0 ? '#1a7a30' : '#c0392b'}">${fmtMoney(c.saldo)}</td>
                        <td>${esc(c.nombre_pago) || '—'}</td>
                        <td>${fmtFecha(c.fecha_limite)}</td>
                        <td><span class="cv-estado" style="background:${estado.bg};color:${estado.color}">${estado.label}</span></td>
                    </tr></tbody>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Entrega del Material</div>
                <table class="cv-tbl">
                    <tr><td class="l">Fecha entrega</td><td>${fmtFecha(c.fecha_entrega)}</td></tr>
                    <tr><td class="l">Formato</td><td>${esc(c.formato) || '—'}</td></tr>
                    <tr><td class="l">Unidades</td><td>${esc(c.unidades) || '—'}</td></tr>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Condiciones del Contrato</div>
                <div class="cv-conds">
                    ${liConds ? '<ol>' + liConds + '</ol>' : ''}
                    <div class="cv-cond-fija">${esc(COND_FIJA)}</div>
                </div>
            </div>
            <div class="cv-firmas">
                <div class="cv-firma"><div class="cv-linea"></div><span class="cv-flabel">Firma del Cliente</span></div>
                <div class="cv-firma"><div class="cv-linea"></div><span class="cv-flabel">Firma de la empresa — ISHUME</span></div>
            </div>
        </div>
    `;
            abrir('modal-ver');
            document.getElementById('modal-ver').scrollTop = 0;
        }

        // Ver historial agrupado por sesión
        function verHistorial(id, sesiones, cliente) {
            document.getElementById('tit-hist').textContent =
                'Historial — Contrato N° ' + String(id).padStart(3, '0') + ' · ' + cliente;

            let html = '';

            if (!sesiones || sesiones.length === 0) {
                html = '<div class="hist-vacio">Sin cambios registrados en este contrato.</div>';
            } else {
                sesiones.forEach((ses, idx) => {
                    const fechaHora = fmtFechaHora(ses.fecha_hora);
                    const nCambios = ses.cambios.length;
                    html += `
                <div class="hist-sesion">
                    <div class="hist-sesion-header">
                        <span class="hist-sesion-fecha">
                            📝 Edición ${sesiones.length - idx} — ${fechaHora}
                            <span style="font-size:10px;color:#aaa;font-weight:400;margin-left:8px;">
                                ${nCambios} campo${nCambios !== 1 ? 's' : ''} modificado${nCambios !== 1 ? 's' : ''}
                            </span>
                        </span>
                    </div>
                    <div class="hist-sesion-body">`;

                    ses.cambios.forEach(cambio => {
                        html += `
                        <div class="hist-fila">
                            <div class="hf-campo">${esc(cambio.campo)}</div>
                            <div class="hf-ant">${esc(cambio.anterior) || '—'}</div>
                            <div class="hf-nvo">${esc(cambio.nuevo) || '—'}</div>
                        </div>`;
                    });

                    html += `    </div>
                </div>`;
                });
            }

            document.getElementById('hist-body').innerHTML = html;
            abrir('modal-hist');
        }

        function fmtFechaHora(dt) {
            if (!dt) return '—';
            const [fecha, hora] = dt.split(' ');
            return fmtFecha(fecha) + ' a las ' + formatearHora(hora);
        }

        let deleteId = null;

        function confirmarEliminar(id, nombre) {
            deleteId = id;

            document.getElementById('confirm-text').innerHTML =
                `¿Eliminar contrato N° <b>${id}</b> de "<b>${nombre}</b>"?<br><br>
        <span style="color:#c0392b;">⚠ Esta acción no se puede deshacer.</span>`;

            abrir('modal-confirm');
        }

        function ejecutarDelete() {
            document.getElementById('del-id').value = deleteId;
            document.getElementById('form-delete').submit();
        }
    </script>

</body>

</html>