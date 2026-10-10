<?php
//Gestión Post-Contrato
require_once '../conexion/conexion.php';
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
    $a = ($a === null || $a === false) ? '' : trim((string) $a);
    $b = ($b === null || $b === false) ? '' : trim((string) $b);
    if ($a === $b)
        return false;
    // Fechas inválidas (0000-00-00, año negativo) = vacío
    $invalida = fn($v) => !$v || str_starts_with($v, '0000') || str_starts_with($v, '-') || (preg_match('/^-?\d+/', $v) && (int) explode('-', ltrim($v, '-'))[0] < 1900);
    if ($invalida($a))
        $a = '';
    if ($invalida($b))
        $b = '';
    if ($a === $b)
        return false;
    // Decimales: comparar como float redondeado a 2
    if (is_numeric($a) && is_numeric($b)) {
        return (string) (float) $a !== (string) (float) $b;
    }
    // Horas (HH:MM:SS vs HH:MM): comparar solo HH:MM
    if (preg_match('/^\d{2}:\d{2}/', $a) && preg_match('/^\d{2}:\d{2}/', $b)) {
        return substr($a, 0, 5) !== substr($b, 0, 5);
    }
    return $a !== $b;
}

function formatearValor(string $campo, $valor): string
{
    if ($valor === null || $valor === '')
        return '—';
    // Montos
    if (in_array($campo, ['Monto total', 'Adelanto', 'Saldo'])) {
        return 'S/ ' . number_format((float) $valor, 2);
    }
    // Fechas — validar que no sean inválidas
    if (str_contains($campo, 'echa')) {
        $v = (string) $valor;
        if (!$v || str_starts_with($v, '0000') || str_starts_with($v, '-'))
            return 'Por confirmar';
        $ts = @strtotime($v);
        if (!$ts || $ts < 0 || date('Y', $ts) < 1900)
            return 'Por confirmar';
        return date('d/m/Y', $ts);
    }
    // Horas — mostrar en formato 12h AM/PM
    if (str_contains($campo, 'ora') || str_contains($campo, 'inicio')) {
        $v = (string) $valor;
        if (!$v || $v === '00:00:00')
            return 'Por confirmar';
        $ts = @strtotime('1970-01-01 ' . $v);
        return $ts ? date('g:i A', $ts) : substr($v, 0, 5);
    }
    return (string) $valor;
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
                dc.cliente2_nombre, dc.cliente2_dni, dc.cliente2_telefono,
                dc.cliente2_email, dc.cliente2_direccion,
                c.nombre, c.dni, c.email, c.telefono, c.direccion AS dir_cliente,
                ts.nombre_servicio,
                p.monto_total, p.adelanto, p.saldo, p.fecha_limite,
                fp.nombre_pago,
                e.fecha AS fecha_entrega, e.fecha_formato, e.unidades, e.material,
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
        $monto_total = ($_POST['monto_total'] === '')
            ? null
            : (float) $_POST['monto_total'];
        $adelanto = (float) $_POST['adelanto'];
        $saldo = ($monto_total === null)
            ? null
            : round($monto_total - $adelanto, 2);
        $fecha_limite = trim($_POST['fecha_limite'] ?? '');
        $forma_id = (int) $_POST['forma_id'];
        $fecha_entrega = trim($_POST['fecha_entrega'] ?? '');
        $fecha_formato = trim($_POST['fecha_formato'] ?? '');
        $material = trim($_POST['material'] ?? '');
        $unidades = $_POST['unidades'] !== '' ? (int) $_POST['unidades'] : null;
        $formato_id = (int) $_POST['formato_id'];
        $c2_nombre = trim($_POST['c2_nombre'] ?? '');
        $c2_dni = trim($_POST['c2_dni'] ?? '');
        $c2_telefono = trim($_POST['c2_telefono'] ?? '');
        $c2_email = trim($_POST['c2_email'] ?? '');
        $c2_direccion = trim($_POST['c2_direccion'] ?? '');
        $dir_cliente = trim($_POST['direccion_cliente'] ?? '');
        $condiciones_sel = array_map('intval', $_POST['condiciones_sel'] ?? []);

        // ── Obtener nombres legibles para campos foráneos
        $nuevoServicio = $pdo->query("SELECT nombre_servicio FROM tipo_servicio WHERE id_servicios=$servicio_id")->fetchColumn();
        $nuevaFormaPago = $pdo->query("SELECT nombre_pago FROM forma_pago WHERE id_forma=$forma_id")->fetchColumn();
        $nuevoFormato = $pdo->query("SELECT tipo FROM formato WHERE id_formato=$formato_id")->fetchColumn();

        // ── Ejecutar actualizaciones
        $stmtCli = $pdo->prepare("SELECT cliente_id FROM detalle_contrato WHERE id_contrato=?");
        $stmtCli->execute([$id]);
        $id_cliente = $stmtCli->fetchColumn();

        $pdo->prepare("UPDATE cliente SET nombre=?,dni=?,email=?,telefono=?,direccion=? WHERE id_cliente=?")
            ->execute([$nombre, $dni, $email, $telefono, $dir_cliente ?: null, $id_cliente]);

        $servicio_detalle_nuevo = trim($_POST['servicio_detalle'] ?? '');
        $pdo->prepare("UPDATE detalle_contrato SET proforma=?,provincia=?,direccion=?,ho_inicio=?,fecha_evento=?,servicio_id=?,servicio_detalle=?,
            cliente2_nombre=?,cliente2_dni=?,cliente2_telefono=?,cliente2_email=?,cliente2_direccion=?
            WHERE id_contrato=?")
            ->execute([
                $proforma,
                $provincia,
                $direccion,
                $ho_inicio ?: null,
                $fecha_evento ?: null,
                $servicio_id,
                $servicio_detalle_nuevo ?: null,
                $c2_nombre ?: null,
                $c2_dni ?: null,
                $c2_telefono ?: null,
                $c2_email ?: null,
                $c2_direccion ?: null,
                $id
            ]);

        $pdo->prepare("UPDATE pago SET monto_total=?,adelanto=?,saldo=?,fecha_limite=?,forma_id=? WHERE contrato_id=?")
            ->execute([$monto_total, $adelanto, $saldo, $fecha_limite, $forma_id, $id]);

        $pdo->prepare("UPDATE entrega SET fecha=?,fecha_formato=?,unidades=?,formato_id=?,material=? WHERE detalle_contrato_id=?")
            ->execute([$fecha_entrega ?: null, $fecha_formato ?: null, $unidades, $formato_id ?: null, $material ?: null, $id]);

        // ── Actualizar condiciones
        $pdo->prepare("DELETE FROM contrato_condicion WHERE contrato_id=?")->execute([$id]);
        if (!empty($condiciones_sel)) {
            $stmtCond = $pdo->prepare("INSERT INTO contrato_condicion (contrato_id,condicion_id) VALUES(?,?)");
            foreach ($condiciones_sel as $cid) {
                if ($cid > 0)
                    $stmtCond->execute([$id, $cid]);
            }
        }
        // ── Actualizar modelos del contrato
        $mod_ids  = $_POST['modelo_id']   ?? [];
        $mod_cant = $_POST['modelo_cant'] ?? [];
        $modelos_nuevos = [];
        foreach ($mod_ids as $i => $mid) {
            $mid  = (int)$mid;
            $cant = (int)($mod_cant[$i] ?? 0);
            if ($mid > 0 && $cant > 0) {
                $modelos_nuevos[$mid] = ($modelos_nuevos[$mid] ?? 0) + $cant;
            }
        }
        $pdo->prepare("DELETE FROM contrato_modelo WHERE id_contrato=?")->execute([$id]);
        $stmtMod = $pdo->prepare("INSERT INTO contrato_modelo (id_contrato,id_modelo,cantidad) VALUES(?,?,?)");
        foreach ($modelos_nuevos as $mid => $cant) {
            $stmtMod->execute([$id, $mid, $cant]);
        }

        // ── Detectar cambios reales y registrar en historial
        $comparar = [
            'Nombre cliente' => [$old['nombre'], $nombre],
            'DNI' => [$old['dni'], $dni],
            'Email' => [$old['email'], $email],
            'Teléfono' => [$old['telefono'], $telefono],
            'Dirección cliente' => [$old['dir_cliente'] ?? '', $dir_cliente],
            'Cliente 2 nombre' => [$old['cliente2_nombre'] ?? '', $c2_nombre],
            'Cliente 2 DNI' => [$old['cliente2_dni'] ?? '', $c2_dni],
            'Cliente 2 tel' => [$old['cliente2_telefono'] ?? '', $c2_telefono],
            'Cliente 2 email' => [$old['cliente2_email'] ?? '', $c2_email],
            'Cliente 2 dirección' => [$old['cliente2_direccion'] ?? '', $c2_direccion],
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
            'Fecha entrega material' => [$old['fecha_entrega'], $fecha_entrega],
            'Fecha entrega formato' => [$old['fecha_formato'] ?? '', $fecha_formato],
            'Material' => [$old['material'] ?? '', $material],
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
            dc.cliente2_nombre, dc.cliente2_dni, dc.cliente2_telefono,
            dc.cliente2_email, dc.cliente2_direccion,
            c.nombre AS cliente, c.dni, c.email, c.telefono,
            c.direccion AS dir_cliente,
            ts.nombre_servicio, ts.id_servicios AS servicio_id,
            p.monto_total, p.adelanto, p.saldo, p.fecha_limite,
            fp.nombre_pago, p.forma_id,
            e.fecha AS fecha_entrega, e.fecha_formato, e.unidades, e.material,
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
        // Modelos
        $m = $pdo->prepare("SELECT id_modelo, cantidad FROM contrato_modelo WHERE id_contrato = ?");
        $m->execute([$ct['id_contrato']]);
        $ct['modelos'] = $m->fetchAll(PDO::FETCH_ASSOC);

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
$modelos_cat = $pdo ? $pdo->query("SELECT id_modelo, nivel, categoria, tipo, nombre FROM modelo WHERE activo=1 ORDER BY nivel, categoria, tipo, nombre")->fetchAll(PDO::FETCH_ASSOC) : [];

function calcEstado(array $ct, string $hoy): array
{
    $monto = $ct['monto_total'] ?? null;
    $s = (float) ($ct['saldo'] ?? 0);
    $f = $ct['fecha_limite'] ?? '';

    // 🔹 NUEVO: Reserva
    if ($monto === null) {
        return ['label' => 'Reserva', 'clase' => 'badge-reserva'];
    }

    if ($s <= 0) {
        return ['label' => 'Pagado', 'clase' => 'badge-ok'];
    }

    if ($f && $f < $hoy) {
        return ['label' => 'No pago', 'clase' => 'badge-pendiente'];
    }

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
    <link href="../css/lista.css" rel="stylesheet" />
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
                <button class="mtab" onclick="mt('mpModelos',this)">Modelos</button>
            </div>
            <form method="POST" action="" id="form-editar">
                <input type="hidden" name="action" value="editar" />
                <input type="hidden" name="id_contrato" id="e-id" />

                <!-- TAB DATOS -->
                <div class="mpanel active" id="mpDatos">
                    <div class="esec">
                        <div class="esec-t">Cliente Principal</div>
                        <div class="eg2">
                            <div class="ec es2"><label>Nombre completo *</label><input type="text" name="nombre"
                                    id="e-nombre" autocomplete="off" /></div>
                            <div class="ec"><label>DNI *</label><input type="text" name="dni" id="e-dni" maxlength="8"
                                    autocomplete="off" /></div>
                            <div class="ec"><label>Teléfono</label><input type="text" name="telefono" id="e-telefono"
                                    maxlength="20" autocomplete="off" /></div>
                            <div class="ec es2"><label>Email</label><input type="email" name="email" id="e-email"
                                    autocomplete="off" /></div>
                            <div class="ec es2"><label>Dirección cliente</label><input type="text"
                                    name="direccion_cliente" id="e-dir_cliente" autocomplete="off" /></div>
                        </div>
                    </div>
                    <div class="esec">
                        <div class="esec-t" style="color:#888;">Segundo Cliente <span
                                style="font-size:9px;font-weight:400;">(opcional)</span></div>
                        <div class="eg2">
                            <div class="ec es2"><label>Nombre completo</label><input type="text" name="c2_nombre"
                                    id="e-c2_nombre" autocomplete="off" /></div>
                            <div class="ec"><label>DNI</label><input type="text" name="c2_dni" id="e-c2_dni"
                                    maxlength="8" autocomplete="off" /></div>
                            <div class="ec"><label>Teléfono</label><input type="text" name="c2_telefono"
                                    id="e-c2_telefono" maxlength="20" autocomplete="off" /></div>
                            <div class="ec es2"><label>Email</label><input type="email" name="c2_email" id="e-c2_email"
                                    autocomplete="off" /></div>
                            <div class="ec es2"><label>Dirección</label><input type="text" name="c2_direccion"
                                    id="e-c2_direccion" autocomplete="off" /></div>
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
                                    id="e-direccion" autocomplete="off" /></div>
                            <div class="ec"><label>Provincia *</label><input type="text" name="provincia"
                                    id="e-provincia" autocomplete="off" /></div>
                            <div class="ec"><label>Proforma</label><input type="number" name="proforma" id="e-proforma"
                                    min="1" /></div>
                            <div class="ec es2"><label>Evento *</label>
                                <select name="servicio_id" id="e-servicio_id" required>
                                    <?php foreach ($servicios as $s): ?>
                                        <option value="<?= $s['id_servicios'] ?>">
                                            <?= htmlspecialchars($s['nombre_servicio']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ec es2"><label>Servicio (descripción)</label>
                                <input type="text" name="servicio_detalle" id="e-servicio_detalle" maxlength="100"
                                    placeholder="Descripción del servicio contratado..." />
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
                                    id="e-monto_total" min="0" step="0.01" oninput="editSaldo()" /></div>
                            <div class="ec"><label>Adelanto (S/) *</label><input type="number" name="adelanto"
                                    id="e-adelanto" min="0" step="0.01" oninput="editSaldo()" /></div>
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
                        <div class="eg2">
                            <div class="ec"><label>Fecha entrega material</label><input type="date" name="fecha_entrega"
                                    id="e-fecha_entrega" /></div>
                            <div class="ec"><label>Fecha entrega formato</label><input type="date" name="fecha_formato"
                                    id="e-fecha_formato" /></div>
                            <div class="ec es2"><label>Material</label><input type="text" name="material"
                                    id="e-material" maxlength="200" placeholder="Ej: Álbum, USB, Video..." /></div>
                            <div class="ec"><label>Formato</label>
                                <select name="formato_id" id="e-formato_id">
                                    <option value="">— Confirmar —</option>
                                    <?php foreach ($formatos as $f): ?>
                                        <option value="<?= $f['id_formato'] ?>"><?= htmlspecialchars($f['tipo']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="ec"><label>N° unidades</label><input type="number" name="unidades"
                                    id="e-unidades" min="1" placeholder="Confirmar" /></div>
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
                <!-- TAB MODELOS -->
                <div class="mpanel" id="mpModelos">
                    <div class="esec">
                        <div class="esec-t">Modelos del Contrato</div>
                        <div id="e-modelos-lista"></div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="agregarModelo()">+ Agregar modelo</button>
                        <div id="e-modelos-total" style="font-size:12px;margin-top:10px;"></div>
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
                                $nHist = count($ct['historial_sesiones']); ?>
                                <tr>
                                    <td class="td-num"><?= str_pad($ct['id_contrato'], 3, '0', STR_PAD_LEFT) ?></td>
                                    <td>
                                        <div class="cliente-nombre"><?= htmlspecialchars($ct['cliente']) ?></div>
                                        <div class="cliente-dni">DNI <?= htmlspecialchars($ct['dni']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($ct['nombre_servicio']) ?></td>
                                    <td>
                                        <?php
                                        $fe2 = $ct['fecha_evento'] ?? '';
                                        $ts2 = $fe2 ? @strtotime($fe2) : false;
                                        echo ($ts2 && $ts2 > 0 && date('Y', $ts2) > 1900)
                                            ? date('d/m/Y', $ts2)
                                            : '<em style="color:#aaa;font-size:11px;font-style:italic">Por confirmar</em>'; ?>
                                        <div class="sub-dato"><?php
                                        $hi2 = $ct['ho_inicio'] ?? '';
                                        if ($hi2 && $hi2 !== '00:00:00') {
                                            $tsH = @strtotime('1970-01-01 ' . $hi2);
                                            echo $tsH ? date('g:i A', $tsH) : '';
                                        }
                                        ?></div>
                                    </td>
                                    <td class="td-monto">S/ <?= number_format($ct['monto_total'], 2) ?></td>
                                    <td class="td-saldo <?= (float) $ct['saldo'] <= 0 ? 'saldo-ok' : 'saldo-pendiente' ?>">
                                        S/ <?= number_format($ct['saldo'], 2) ?>
                                    </td>
                                    <td><span class="badge <?= $estado['clase'] ?>"><?= $estado['label'] ?></span></td>
                                    <td class="td-acciones">

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
    </script>
    <script src="../js/edicion.js"></script>
    <script>
        const MODELOS = <?= json_encode($modelos_cat) ?>;

        function agregarModelo(id, cant) {
            const fila = document.createElement('div');
            fila.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';

            const sel = document.createElement('select');
            sel.name = 'modelo_id[]';
            sel.style.flex = '1';
            sel.add(new Option('— Elegir modelo —', ''));
            MODELOS.forEach(m => {
                const etiqueta = (m.tipo ? m.tipo + ' · ' : '') + m.nombre + ' (' + (m.nivel.startsWith('Inicial') ? 'Inicial' : 'Prim/Sec') + ')';
                sel.add(new Option(etiqueta, m.id_modelo));
            });
            sel.value = id || '';

            const num = document.createElement('input');
            num.type = 'number'; num.name = 'modelo_cant[]'; num.min = '1';
            num.placeholder = 'Cant.'; num.style.width = '90px'; num.value = cant || '';
            num.addEventListener('input', totalModelos);

            const btn = document.createElement('button');
            btn.type = 'button'; btn.className = 'btn btn-secondary btn-sm'; btn.textContent = '×';
            btn.onclick = () => { fila.remove(); totalModelos(); };

            fila.append(sel, num, btn);
            document.getElementById('e-modelos-lista').appendChild(fila);
        }

        function totalModelos() {
            const suma = [...document.querySelectorAll('#e-modelos-lista input[name="modelo_cant[]"]')]
                .reduce((s, i) => s + (parseInt(i.value) || 0), 0);
            const un = parseInt(document.getElementById('e-unidades').value) || 0;
            const el = document.getElementById('e-modelos-total');
            el.textContent = 'Suma de modelos: ' + suma + ' · Unidades del contrato: ' + (un || 'sin definir');
            el.style.color = (un && suma !== un) ? '#c00' : '#555';
        }

        const _abrirEditarOrig = abrirEditar;
        abrirEditar = function (ct, condIds) {
            _abrirEditarOrig(ct, condIds);
            document.getElementById('e-modelos-lista').innerHTML = '';
            (ct.modelos || []).forEach(m => agregarModelo(m.id_modelo, m.cantidad));
            totalModelos();
        };
        document.getElementById('e-unidades').addEventListener('input', totalModelos);
    </script>

</body>

</html>