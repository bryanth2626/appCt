<?php

//  registrar.php — Nuevo Contrato
require_once '../conexion/conexion.php';

if (isset($_GET['ok'])) {
    $success_msg = 'Contrato N° ' . (int)$_GET['ok'] . ' registrado exitosamente.';
}

// Crear tabla snapshot si no existe
if ($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS snapshot_contrato (
            id_snap         INT AUTO_INCREMENT PRIMARY KEY,
            contrato_id     INT NOT NULL UNIQUE,
            cliente_nombre  VARCHAR(70)  NOT NULL,
            cliente_dni     CHAR(8)      NOT NULL,
            cliente_email   VARCHAR(50),
            cliente_tel     VARCHAR(15),
            servicio        VARCHAR(25)  NOT NULL,
            servicio_detalle VARCHAR(100),
            fecha_evento    DATE         NOT NULL,
            ho_inicio       TIME,
            direccion       VARCHAR(40),
            provincia       VARCHAR(15),
            proforma        INT,
            monto_total     DECIMAL(10,2),
            adelanto        DECIMAL(10,2),
            saldo           DECIMAL(10,2),
            fecha_limite    DATE,
            forma_pago      VARCHAR(10),
            fecha_entrega   DATE,
            unidades        INT,
            formato         VARCHAR(15),
            condiciones_json TEXT,
            fecha_contrato  DATE NOT NULL,
            CONSTRAINT fk_snap_contrato
                FOREIGN KEY (contrato_id) REFERENCES detalle_contrato(id_contrato)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
}

$servicios   = [];
$formatos    = [];
$formas_pago = [];
$condiciones = [];
$modelos_cat = [];

if ($pdo) {
    $servicios   = $pdo->query("SELECT id_servicios, nombre_servicio FROM tipo_servicio ORDER BY nombre_servicio")->fetchAll(PDO::FETCH_ASSOC);
    $formatos    = $pdo->query("SELECT id_formato, tipo FROM formato ORDER BY tipo")->fetchAll(PDO::FETCH_ASSOC);
    $formas_pago = $pdo->query("SELECT id_forma, nombre_pago FROM forma_pago")->fetchAll(PDO::FETCH_ASSOC);
    $condiciones = $pdo->query("SELECT id_condiciones, detalle FROM condiciones ORDER BY id_condiciones")->fetchAll(PDO::FETCH_ASSOC);
    $modelos_cat = $pdo->query("SELECT id_modelo, nivel, categoria, tipo, nombre FROM modelo WHERE activo=1 ORDER BY nivel, categoria, tipo, nombre")->fetchAll(PDO::FETCH_ASSOC);
}

$hoy = date('Y-m-d');
$CONDICION_FIJA = '* Después de ser entregado el material, el cliente tiene 1 semana para realizar algún reclamo o cambio. Pasando la semana no hay reclamo alguno *';

// ── Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {

    $nombre        = trim($_POST['nombre']        ?? '');
    $dni           = trim($_POST['dni']           ?? '');
    $email         = trim($_POST['email']         ?? '');
    $telefono      = trim($_POST['celular']        ?? '');
    $direccion_cli = trim($_POST['direccion_cliente'] ?? '');
    // Segundo cliente (opcional)
    $c2_nombre     = trim($_POST['c2_nombre']   ?? '');
    $c2_dni        = trim($_POST['c2_dni']      ?? '');
    $c2_telefono   = trim($_POST['c2_telefono'] ?? '');
    $c2_email      = trim($_POST['c2_email']    ?? '');
    $c2_direccion  = trim($_POST['c2_direccion'] ?? '');
    $fecha_evento  = trim($_POST['fecha_evento']  ?? '');
    $fecha_limite  = trim($_POST['fecha_limite']  ?? '');
    $fecha_entrega = trim($_POST['fecha_entrega'] ?? '');
    $monto_total_input = trim($_POST['monto_total'] ?? '');
    $monto_total = ($monto_total_input === '') ? null : (float)$monto_total_input;

    $adelanto = (float)($_POST['adelanto'] ?? 0);

    // Si no hay monto total → saldo queda NULL
    if ($monto_total === null) {
        $saldo = null;
    } else {
        $saldo = round($monto_total - $adelanto, 2);
    }
    $condiciones_sel = array_map('intval', $_POST['condiciones_sel'] ?? []);
    $material      = trim($_POST['material'] ?? '');
    $fecha_formato = trim($_POST['fecha_formato'] ?? '');

    $errores = [];
    if ($fecha_evento !== '' && $fecha_evento < $hoy) $errores[] = "La fecha del evento no puede ser anterior a hoy.";
    if ($fecha_evento !== '' && $fecha_limite  < $hoy) $errores[] = "La fecha límite de pago no puede ser anterior a hoy.";
    if ($fecha_entrega !== '' && $fecha_entrega < $hoy) {
        $errores[] = "La fecha de entrega no puede ser anterior a hoy.";
    }
    if ($monto_total !== null && $adelanto > $monto_total) {
        $errores[] = "El adelanto no puede superar el monto total.";
    }
    if (strlen($telefono) < 7)    $errores[] = "El celular es obligatorio (mínimo 7 dígitos).";
    $servicio_detalle = trim($_POST['servicio_detalle'] ?? '');

    if (!empty($errores)) {
        $error_msg = implode('<br>', $errores);
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Cliente
            $stmt = $pdo->prepare("SELECT id_cliente FROM cliente WHERE dni = ?");
            $stmt->execute([$dni]);
            $clienteExistente = $stmt->fetch();

            if ($clienteExistente) {
                $id_cliente = $clienteExistente['id_cliente'];
                $pdo->prepare("UPDATE cliente SET nombre=?,email=?,telefono=?,direccion=? WHERE id_cliente=?")
                    ->execute([$nombre, $email, $telefono, $direccion_cli, $id_cliente]);
            } else {
                $pdo->prepare("INSERT INTO cliente (nombre,dni,email,telefono,direccion) VALUES (?,?,?,?,?)")
                    ->execute([$nombre, $dni, $email, $telefono, $direccion_cli]);
                $id_cliente = $pdo->lastInsertId();
            }

            // 2. Detalle del contrato
            $pdo->prepare("
                INSERT INTO detalle_contrato
                    (proforma,provincia,direccion,ho_inicio,fecha_evento,fecha_contrato,
                     cliente_id,servicio_id,servicio_detalle,
                     cliente2_nombre,cliente2_dni,cliente2_telefono,cliente2_email,cliente2_direccion)
                VALUES (?,?,?,?,?,CURDATE(),?,?,?,?,?,?,?,?)
            ")->execute([
                (int)$_POST['proforma'],
                trim($_POST['provincia']),
                trim($_POST['direccion']),
                $_POST['ho_inicio'] ?: null,
                $fecha_evento ?: null,
                $id_cliente,
                (int)$_POST['servicio_id'],
                $servicio_detalle ?: null,
                $c2_nombre ?: null,
                $c2_dni    ?: null,
                $c2_telefono ?: null,
                $c2_email  ?: null,
                $c2_direccion ?: null
            ]);
            $id_contrato = $pdo->lastInsertId();

            // 3. Condiciones seleccionadas
            if (!empty($condiciones_sel)) {
                $stmtCond = $pdo->prepare("INSERT INTO contrato_condicion (contrato_id,condicion_id) VALUES (?,?)");
                foreach ($condiciones_sel as $cid) {
                    if ($cid > 0) $stmtCond->execute([$id_contrato, $cid]);
                }
            }

            // 4. Pago
            $pdo->prepare("
                INSERT INTO pago (monto_total,adelanto,saldo,fecha_limite,contrato_id,forma_id)
                VALUES (?,?,?,?,?,?)
            ")->execute([$monto_total, $adelanto, $saldo, $fecha_limite, $id_contrato, (int)$_POST['forma_id']]);

            // 5. Entrega
            $pdo->prepare("
                INSERT INTO entrega (fecha,fecha_formato,unidades,formato_id,detalle_contrato_id,material)
                VALUES (?,?,?,?,?,?)
            ")->execute([
                $fecha_entrega ?: null,
                $fecha_formato ?: null,
                ($_POST['unidades'] !== '' ? (int)$_POST['unidades'] : null),
                ($_POST['formato_id'] !== '' && (int)$_POST['formato_id'] > 0 ? (int)$_POST['formato_id'] : null),
                $id_contrato,
                $material ?: null
            ]);

            // ──────────────────────────────────────────────
            // 6. SNAPSHOT — foto inmutable del contrato firmado
            // ──────────────────────────────────────────────
            // 5.1 Modelos del contrato
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
            $stmtMod = $pdo->prepare("INSERT INTO contrato_modelo (id_contrato,id_modelo,cantidad) VALUES (?,?,?)");
            foreach ($modelos_nuevos as $mid => $cant) {
                $stmtMod->execute([$id_contrato, $mid, $cant]);
            }

            // Obtener nombres legibles para los IDs
            $nomServicio = $pdo->query("SELECT nombre_servicio FROM tipo_servicio WHERE id_servicios=" . (int)$_POST['servicio_id'])->fetchColumn();
            $nomFormaPago = $pdo->query("SELECT nombre_pago FROM forma_pago WHERE id_forma=" . (int)$_POST['forma_id'])->fetchColumn();
            $nomFormato  = ($_POST['formato_id'] !== '' && (int)$_POST['formato_id'] > 0)
                ? $pdo->query("SELECT tipo FROM formato WHERE id_formato=" . (int)$_POST['formato_id'])->fetchColumn()
                : null;

            // Textos de condiciones seleccionadas
            $textosCond = [];
            if (!empty($condiciones_sel)) {
                $placeholders = implode(',', array_fill(0, count($condiciones_sel), '?'));
                $stmtTextos = $pdo->prepare("SELECT detalle FROM condiciones WHERE id_condiciones IN ($placeholders) ORDER BY id_condiciones");
                $stmtTextos->execute($condiciones_sel);
                $textosCond = $stmtTextos->fetchAll(PDO::FETCH_COLUMN);
            }
            // Agregar la condición fija al final del snapshot
            $textosCond[] = $CONDICION_FIJA;

            $pdo->prepare("
                INSERT INTO snapshot_contrato (
                    contrato_id,
                    cliente_nombre, cliente_dni, cliente_email, cliente_tel, cliente_dir,
                    cliente2_nombre, cliente2_dni, cliente2_telefono, cliente2_email, cliente2_direccion,
                    servicio, servicio_detalle, fecha_evento, ho_inicio, direccion, provincia, proforma,
                    monto_total, adelanto, saldo, fecha_limite, forma_pago,
                    fecha_entrega,fecha_formato, unidades, formato, material,
                    condiciones_json, fecha_contrato
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURDATE())
            ")->execute([
                $id_contrato,
                $nombre,
                $dni,
                $email,
                $telefono,
                $direccion_cli ?: null,
                $c2_nombre ?: null,
                $c2_dni ?: null,
                $c2_telefono ?: null,
                $c2_email ?: null,
                $c2_direccion ?: null,
                $nomServicio,
                $servicio_detalle ?: null,
                $fecha_evento ?: null,
                $_POST['ho_inicio'] ?: null,
                trim($_POST['direccion']),
                trim($_POST['provincia']),
                (int)$_POST['proforma'],
                $monto_total,
                $adelanto,
                $saldo,
                $fecha_limite,
                $nomFormaPago,
                $fecha_entrega ?: null,
                $fecha_formato ?: null,
                ($_POST['unidades'] !== '' ? (int)$_POST['unidades'] : null),
                $nomFormato,
                $material ?: null,
                json_encode($textosCond, JSON_UNESCAPED_UNICODE)
            ]);

            $pdo->commit();
            header("Location: registrar.php?ok=" . $id_contrato);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_msg = 'Error al guardar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nuevo Contrato — ISHUME</title>
    <link href="../css/registrar.css" rel="stylesheet" />
</head>

<body>

    <div class="pagina">

        <div class="doc-header">
            <div class="doc-header-logo"><img src="../img/logo.png" alt="ISHUME" /></div>
            <div class="doc-header-info">
                <h1>Xiomara Tasayco H.</h1>
                <p>Gerenta General &nbsp;|&nbsp; 991157028 &nbsp;|&nbsp; ✉ IshumeAgencia@hotmail.com</p>
            </div>
        </div>

        <div class="doc-body">

            <?php if ($success_msg): ?>
                <div class="msg msg-ok" id="msg-ok"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="msg msg-error"><?= $error_msg ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="form-contrato">

                <div class="doc-title-row">
                    <h2>Contrato de Servicios Audiovisuales</h2>
                    <span class="fecha-contrato">
                        Chincha <?= date('d') ?> / <?= date('m') ?> / <?= date('Y') ?>
                    </span>
                </div>

                <!-- DATOS DEL CLIENTE -->
                <div class="seccion">
                    <div class="seccion-titulo">Datos del Cliente</div>

                    <!-- Cliente 1 -->
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#f07c00;margin-bottom:8px;">Cliente Principal</div>
                    <div class="grid-2">
                        <div class="campo span-2">
                            <label>Nombre completo:</label>
                            <input type="text" name="nombre" placeholder="Apellidos y nombres"
                                autocomplete="off" required
                                value="<?= htmlspecialchars($_POST['nombre'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>DNI:</label>
                            <input type="text" name="dni" maxlength="8" pattern="\d{8}"
                                placeholder="8 dígitos" autocomplete="off" required
                                value="<?= htmlspecialchars($_POST['dni'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Celular: *</label>
                            <input type="text" name="celular" placeholder="+51"
                                pattern="\d{7,20}" maxlength="20" autocomplete="off" required
                                value="<?= htmlspecialchars($_POST['celular'] ?? '') ?>" />
                        </div>
                        <div class="campo span-2">
                            <label>Correo electrónico:</label>
                            <input type="email" name="email" placeholder="correo@ejemplo.com"
                                autocomplete="off"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
                        </div>
                        <div class="campo span-2">
                            <label>Dirección:</label>
                            <input type="text" name="direccion_cliente" placeholder="Av. / Jr. / Urb. ..."
                                autocomplete="off"
                                value="<?= htmlspecialchars($_POST['direccion_cliente'] ?? '') ?>" />
                        </div>
                    </div>

                    <!-- Cliente 2 (opcional) -->
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;color:#888;margin:14px 0 8px;border-top:1px dashed #e0e0e0;padding-top:12px;">
                        Segundo Cliente <span style="font-weight:400;font-size:9px;color:#aaa;">(opcional)</span>
                    </div>
                    <div class="grid-2">
                        <div class="campo span-2">
                            <label>Nombre completo:</label>
                            <input type="text" name="c2_nombre" placeholder="Apellidos y nombres"
                                autocomplete="off"
                                value="<?= htmlspecialchars($_POST['c2_nombre'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>DNI:</label>
                            <input type="text" name="c2_dni" maxlength="8"
                                placeholder="8 dígitos" autocomplete="off"
                                value="<?= htmlspecialchars($_POST['c2_dni'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Celular:</label>
                            <input type="text" name="c2_telefono" placeholder="+51"
                                maxlength="20" autocomplete="off"
                                value="<?= htmlspecialchars($_POST['c2_telefono'] ?? '') ?>" />
                        </div>
                        <div class="campo span-2">
                            <label>Correo electrónico:</label>
                            <input type="email" name="c2_email" placeholder="correo@ejemplo.com"
                                autocomplete="off"
                                value="<?= htmlspecialchars($_POST['c2_email'] ?? '') ?>" />
                        </div>
                        <div class="campo span-2">
                            <label>Dirección:</label>
                            <input type="text" name="c2_direccion" placeholder="Av. / Jr. / Urb. ..."
                                autocomplete="off"
                                value="<?= htmlspecialchars($_POST['c2_direccion'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- DETALLES DEL EVENTO -->
                <div class="seccion">
                    <div class="seccion-titulo">Detalles del contrato</div>
                    <div class="grid-2">
                        <div class="campo">
                            <label>Fecha del evento: <span style="font-size:9px;color:#aaa;">(dejar vacío = Por confirmar)</span></label>
                            <input type="date" name="fecha_evento" min="<?= $hoy ?>"
                                value="<?= htmlspecialchars($_POST['fecha_evento'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Hora de inicio: <span style="font-size:9px;color:#aaa;">(dejar vacío = Por confirmar)</span></label>
                            <input type="time" name="ho_inicio"
                                value="<?= htmlspecialchars($_POST['ho_inicio'] ?? '') ?>" />
                        </div>
                        <div class="campo span-2">
                            <label>Dirección:</label>
                            <input type="text" name="direccion" placeholder="Av. / Jr. / Ca. ..."
                                autocomplete="off" required
                                value="<?= htmlspecialchars($_POST['direccion'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Provincia:</label>
                            <input type="text" name="provincia" placeholder="Chincha"
                                autocomplete="off" required
                                value="<?= htmlspecialchars($_POST['provincia'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Proforma(Opcional):</label>
                            <input type="number" name="proforma" min="1" placeholder="0"
                                autocomplete="off"
                                value="<?= htmlspecialchars($_POST['proforma'] ?? '') ?>" />
                        </div>
                        <div class="campo span-2">
                            <label>Evento:</label>
                            <select name="servicio_id" required>
                                <option value="">— Seleccionar tipo de evento —</option>
                                <?php foreach ($servicios as $s): ?>
                                    <option value="<?= $s['id_servicios'] ?>"
                                        <?= (($_POST['servicio_id'] ?? '') == $s['id_servicios']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['nombre_servicio']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo span-2">
                            <label>Servicio (descripción del servicio contratado):</label>
                            <input type="text" name="servicio_detalle"
                                placeholder="Ej: Fotografía y video del evento, 8 horas..."
                                maxlength="100" autocomplete="off"
                                value="<?= htmlspecialchars($_POST['servicio_detalle'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- ESTADO DE PAGO -->
                <div class="seccion">
                    <div class="seccion-titulo">Estado de Pago</div>
                    <table class="tabla-pagos">
                        <thead>
                            <tr>
                                <th>Monto Total (S/)</th>
                                <th>Adelanto (S/)</th>
                                <th>Saldo Pendiente (S/)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <input type="number" name="monto_total" id="monto_total"
                                        min="0" step="0.01" placeholder="opcional"
                                        value="<?= htmlspecialchars($_POST['monto_total'] ?? '') ?>"
                                        oninput="calcularSaldo()" />
                                </td>
                                <td>
                                    <input type="number" name="adelanto" id="adelanto"
                                        min="0" step="0.01" placeholder="0.00" required
                                        value="<?= htmlspecialchars($_POST['adelanto'] ?? '') ?>"
                                        oninput="calcularSaldo()" />
                                </td>
                                <td>
                                    <input type="text" id="saldo_display" readonly placeholder="0.00" />
                                    <input type="hidden" name="saldo" id="saldo_hidden" />
                                    <small id="saldo_error" style="color:#c00;font-size:10px;display:none;">
                                        ⚠ El adelanto supera el monto total
                                    </small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="grid-2" style="margin-top:10px;">
                        <div class="campo">
                            <label>Forma de pago:</label>
                            <div class="forma-pago-row">
                                <?php foreach ($formas_pago as $fp): ?>
                                    <label>
                                        <input type="radio" name="forma_id" value="<?= $fp['id_forma'] ?>"
                                            <?= (($_POST['forma_id'] ?? '') == $fp['id_forma']) ? 'checked' : '' ?>
                                            required />
                                        <?= htmlspecialchars($fp['nombre_pago']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="campo">
                            <label>Fecha límite de pago del saldo:</label>
                            <input type="date" name="fecha_limite" required min="<?= $hoy ?>"
                                value="<?= htmlspecialchars($_POST['fecha_limite'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- ENTREGA DEL MATERIAL -->
                <div class="seccion">
                    <div class="seccion-titulo">Entrega del Material</div>
                    <div class="grid-2">
                        <div class="campo">
                            <label>Fecha de entrega del material: <span style="font-size:9px;color:#aaa;">(dejar vacío = Por confirmar)</span></label>
                            <input type="date" name="fecha_entrega" min="<?= $hoy ?>"
                                value="<?= htmlspecialchars($_POST['fecha_entrega'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Material: <span style="font-size:9px;color:#aaa;">(descripción)</span></label>
                            <input type="text" name="material" placeholder="Ej: Álbum, USB, Video editado..."
                                maxlength="200" autocomplete="off"
                                value="<?= htmlspecialchars($_POST['material'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Formato de entrega: <span style="font-size:9px;color:#aaa;">(opcional)</span></label>
                            <select name="formato_id">
                                <option value="">— Formato —</option>
                                <?php foreach ($formatos as $f): ?>
                                    <option value="<?= $f['id_formato'] ?>"
                                        <?= (($_POST['formato_id'] ?? '') == $f['id_formato']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($f['tipo']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="campo">
                            <label>N° de unidades: <span style="font-size:9px;color:#aaa;">(opcional)</span></label>
                            <input type="number" name="unidades" min="1" placeholder="Confirmar"
                                autocomplete="off"
                                value="<?= htmlspecialchars($_POST['unidades'] ?? '') ?>" />
                        </div>
                        <div class="campo">
                            <label>Fecha de entrega del formato:
                                <span style="font-size:9px;color:#aaa;">(dejar vacío = Por confirmar)</span>
                            </label>
                            <input type="date" name="fecha_formato" min="<?= $hoy ?>"
                                value="<?= htmlspecialchars($_POST['fecha_formato'] ?? '') ?>" />
                        </div>
                    </div>
                </div>

                <!-- CONDICIONES DEL CONTRATO -->
                <div class="seccion">
                    <div class="seccion-titulo">Condiciones del Contrato</div>

                    <?php if (!empty($condiciones)): ?>
                        <div class="cond-controles">
                            <button type="button" class="btn-cond" onclick="seleccionarTodas(true)">Marcar todas</button>
                            <button type="button" class="btn-cond" onclick="seleccionarTodas(false)">Desmarcar todas</button>
                            <span class="cond-contador">
                                <span id="cond-count">0</span> de <?= count($condiciones) ?> seleccionadas
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="condiciones-lista" id="lista-condiciones">
                        <?php if (empty($condiciones)): ?>
                            <div class="msg msg-error" style="font-size:12px;">
                                No hay condiciones registradas. Ve a
                                <a href="admin.php?tab=condiciones" style="color:#c0392b;font-weight:700;">
                                    Administrar Catálogos
                                </a> para agregar condiciones.
                            </div>
                            <?php else:
                            $selPrev = array_map('intval', $_POST['condiciones_sel'] ?? []);
                            foreach ($condiciones as $c):
                                $checked = in_array((int)$c['id_condiciones'], $selPrev) ? 'checked' : '';
                            ?>
                                <label class="condicion-item <?= $checked ? 'seleccionada' : '' ?>"
                                    id="item-<?= $c['id_condiciones'] ?>">
                                    <input type="checkbox"
                                        name="condiciones_sel[]"
                                        value="<?= $c['id_condiciones'] ?>"
                                        <?= $checked ?>
                                        onchange="actualizarItem(this)" />
                                    <span><?= htmlspecialchars($c['detalle']) ?></span>
                                </label>
                        <?php endforeach;
                        endif; ?>

                        <!-- Condición fija en rojo — siempre presente y marcada -->
                        <label class="condicion-item condicion-fija seleccionada">
                            <input type="checkbox" name="condicion_fija" value="1"
                                checked onclick="return false" />
                            <span><?= htmlspecialchars($CONDICION_FIJA) ?></span>
                            <span class="badge-fija">Fija</span>
                        </label>
                    </div>

                    <div class="aviso-sin-cond" id="aviso-cond">
                        ⚠ Debes seleccionar al menos una condición.
                    </div>
                </div>
                <!-- MODELOS DEL CONTRATO -->
                <div class="seccion">
                    <div class="seccion-titulo">Modelos del Contrato</div>
                    <div id="modelos-lista"></div>
                    <button type="button" class="btn btn-secondary" onclick="agregarModelo()">+ Agregar modelo</button>
                    <div id="modelos-total" style="font-size:12px;margin-top:10px;"></div>
                </div>
                <!-- FIRMAS -->
                <?php $hayCliente2 = !empty($_POST['c2_nombre']); ?>

                <?php if ($hayCliente2): ?>

                    <!-- 2 clientes -->
                    <div class="firmas-clientes">
                        <div class="firma">
                            <div class="firma-linea"></div>
                            <div class="firma-label">Firma del Cliente 1</div>
                        </div>

                        <div class="firma">
                            <div class="firma-linea"></div>
                            <div class="firma-label">Firma del Cliente 2</div>
                        </div>
                    </div>

                    <div class="firma-centro">
                        <div class="firma">
                            <div class="firma-linea"></div>
                            <div class="firma-label">Firma de la empresa — ISHUME</div>
                        </div>
                    </div>

                <?php else: ?>

                    <!-- 1 cliente -->
                    <div class="firmas-normal">
                        <div class="firma">
                            <div class="firma-linea"></div>
                            <div class="firma-label">Firma del Cliente</div>
                        </div>

                        <div class="firma">
                            <div class="firma-linea"></div>
                            <div class="firma-label">Firma de la empresa — ISHUME</div>
                        </div>
                    </div>

                <?php endif; ?>

                <div class="acciones">
                    <a href="../index.php" class="btn btn-secondary">← Volver</a>
                    <button type="submit" class="btn btn-primary">Guardar Contrato</button>
                </div>

            </form>
        </div>

    </div>
    <script src="../js/registrar.js"></script>
    <script>
        const MODELOS = <?= json_encode($modelos_cat) ?>;
        // Si el formulario falló y se recargó, recupera lo que ya habían escrito
        const MODELOS_PREVIOS = <?= json_encode(array_map(null, $_POST['modelo_id'] ?? [], $_POST['modelo_cant'] ?? [])) ?>;

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
            btn.type = 'button'; btn.className = 'btn btn-secondary'; btn.textContent = '×';
            btn.onclick = () => { fila.remove(); totalModelos(); };

            fila.append(sel, num, btn);
            document.getElementById('modelos-lista').appendChild(fila);
        }

        function totalModelos() {
            const suma = [...document.querySelectorAll('#modelos-lista input[name="modelo_cant[]"]')]
                .reduce((s, i) => s + (parseInt(i.value) || 0), 0);
            const un = parseInt(document.querySelector('input[name="unidades"]').value) || 0;
            const el = document.getElementById('modelos-total');
            el.textContent = 'Suma de modelos: ' + suma + ' · Unidades del contrato: ' + (un || 'sin definir');
            el.style.color = (un && suma !== un) ? '#c00' : '#555';
        }

        MODELOS_PREVIOS.forEach(p => agregarModelo(p[0], p[1]));
        agregarModelo();   // deja una fila lista para llenar
        totalModelos();
        document.querySelector('input[name="unidades"]').addEventListener('input', totalModelos);
    </script>
</body>

</html>