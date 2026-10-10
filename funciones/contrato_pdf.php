<?php
// ══════════════════════════════════════════════════════
//  contrato_pdf.php — Vista imprimible del contrato
//  Ubicación: /empresa/funciones/contrato_pdf.php
// ══════════════════════════════════════════════════════
require_once '../conexion/conexion.php';


$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: lista.php');
    exit;
}

// Modo: 'preview' (default) o 'print' (dispara window.print al cargar)
$modo = $_GET['modo'] ?? 'preview';

// ── Leer del SNAPSHOT primero (datos originales firmados)
// Si no existe snapshot (contrato registrado antes de la actualización),
// cae de vuelta a las tablas operativas
$stmt = $pdo->prepare("
    SELECT
        sn.contrato_id  AS id_contrato,
        sn.fecha_contrato,
        sn.cliente_nombre  AS cliente,
        sn.cliente_dni     AS dni,
        sn.cliente_email   AS email,
        sn.cliente_tel     AS telefono,
        sn.cliente_dir,
        sn.cliente2_nombre,
        sn.cliente2_dni,
        sn.cliente2_email,
        sn.cliente2_telefono,
        sn.cliente2_direccion,
        sn.servicio AS nombre_servicio,
        COALESCE(sn.servicio_detalle, dc.servicio_detalle) AS servicio_detalle,
        sn.fecha_evento,
        sn.ho_inicio,
        sn.direccion,
        sn.provincia,
        sn.proforma,
        sn.monto_total,
        sn.adelanto,
        sn.saldo,
        sn.fecha_limite,
        sn.forma_pago      AS nombre_pago,
        sn.fecha_entrega,
        sn.unidades,
        sn.formato,
        sn.fecha_formato,
        COALESCE(sn.material, e.material) AS material,
        sn.condiciones_json
    FROM snapshot_contrato sn
    JOIN detalle_contrato dc ON dc.id_contrato = sn.contrato_id
    LEFT JOIN cliente c2 ON c2.id_cliente = dc.cliente_id
    LEFT JOIN entrega e  ON e.detalle_contrato_id = sn.contrato_id
    WHERE sn.contrato_id = ?
");
$stmt->execute([$id]);
$ct = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Fallback: si no hay snapshot, leer de las tablas vivas
if (!$ct) {
    $stmt2 = $pdo->prepare("
        SELECT
            dc.id_contrato, dc.proforma, dc.provincia, dc.direccion,
            dc.ho_inicio, dc.fecha_evento, dc.fecha_contrato,
            dc.servicio_detalle,
            dc.cliente2_nombre, dc.cliente2_dni, dc.cliente2_telefono,
            dc.cliente2_email, dc.cliente2_direccion,
            c.nombre AS cliente, c.dni, c.email, c.telefono,
            c.direccion AS cliente_dir,
            ts.nombre_servicio,
            p.monto_total, p.adelanto, p.saldo, p.fecha_limite,
            fp.nombre_pago,
            e.fecha AS fecha_entrega, e.unidades, e.material, e.fecha_formato,
            f.tipo  AS formato,
            NULL    AS condiciones_json
        FROM detalle_contrato dc
        JOIN  cliente       c   ON c.id_cliente    = dc.cliente_id
        JOIN  tipo_servicio ts  ON ts.id_servicios = dc.servicio_id
        LEFT JOIN pago       p  ON p.contrato_id         = dc.id_contrato
        LEFT JOIN forma_pago fp ON fp.id_forma            = p.forma_id
        LEFT JOIN entrega    e  ON e.detalle_contrato_id  = dc.id_contrato
        LEFT JOIN formato    f  ON f.id_formato            = e.formato_id
        WHERE dc.id_contrato = ?
    ");
    $stmt2->execute([$id]);
    $ct = $stmt2->fetch(PDO::FETCH_ASSOC);
    if (!$ct) {
        header('Location: lista.php');
        exit;
    }

    // Condiciones desde tablas vivas
    $stmtC = $pdo->prepare("
        SELECT co.detalle
        FROM contrato_condicion cc
        JOIN condiciones co ON co.id_condiciones = cc.condicion_id
        WHERE cc.contrato_id = ?
        ORDER BY co.id_condiciones
    ");
    $stmtC->execute([$id]);
    $condiciones = $stmtC->fetchAll(PDO::FETCH_COLUMN);
} else {
    // Condiciones desde el JSON del snapshot, filtrar la fija
    $todasConds = json_decode($ct['condiciones_json'] ?? '[]', true) ?: [];
    $condiciones = array_values(array_filter($todasConds, fn($c) => !str_starts_with(trim($c), '*')));
}

$hoy = date('Y-m-d');
$origen = $_GET['origen'] ?? 'lista';

// Saldo y pago actuales (siempre necesarios para el estado)
$stmtPagoActual = $pdo->prepare("
    SELECT p.saldo, p.adelanto, p.monto_total, p.fecha_limite, p.forma_id,
           fp.nombre_pago
    FROM pago p
    LEFT JOIN forma_pago fp ON fp.id_forma = p.forma_id
    WHERE p.contrato_id = ?
");
$stmtPagoActual->execute([$id]);
$pagoActual = $stmtPagoActual->fetch(PDO::FETCH_ASSOC);
$saldoActual = (float) ($pagoActual['saldo'] ?? (float) ($ct['saldo'] ?? 0));
$fechaLimActual = $pagoActual['fecha_limite'] ?? $ct['fecha_limite'] ?? '';
$montoTotalActual = $pagoActual['monto_total'] ?? null;
// Estado siempre con valores actuales
if ($montoTotalActual === null) {
    $estado = 'Reserva';
    $estadoColor = '#555';
    $estadoBg = '#eee';
} elseif ($saldoActual <= 0) {
    $estado = 'Pagado';
    $estadoColor = '#1a7a30';
    $estadoBg = '#e6f4ea';
} elseif ($fechaLimActual && $fechaLimActual < $hoy) {
    $estado = 'No pago';
    $estadoColor = '#c0392b';
    $estadoBg = '#fdecea';
} else {
    $estado = 'Proceso';
    $estadoColor = '#e65100';
    $estadoBg = '#fff3e0';
}

// ── Si origen=edicion: sobreescribir con datos actuales de las tablas vivas
if ($origen === 'edicion') {
    $stmtVivo = $pdo->prepare("
        SELECT
            dc.id_contrato, dc.proforma, dc.provincia, dc.direccion,
            dc.ho_inicio, dc.fecha_evento, dc.fecha_contrato,
            dc.servicio_detalle,
            dc.cliente2_nombre, dc.cliente2_dni, dc.cliente2_telefono,
            dc.cliente2_email, dc.cliente2_direccion,
            c.nombre AS cliente, c.dni, c.email, c.telefono,
            c.direccion AS cliente_dir,
            ts.nombre_servicio,
            e.fecha AS fecha_entrega,e.fecha_formato, e.unidades, e.material,
            f.tipo  AS formato
        FROM detalle_contrato dc
        JOIN  cliente       c   ON c.id_cliente    = dc.cliente_id
        JOIN  tipo_servicio ts  ON ts.id_servicios = dc.servicio_id
        LEFT JOIN entrega    e  ON e.detalle_contrato_id = dc.id_contrato
        LEFT JOIN formato    f  ON f.id_formato           = e.formato_id
        WHERE dc.id_contrato = ?
    ");
    $stmtVivo->execute([$id]);
    $vivo = $stmtVivo->fetch(PDO::FETCH_ASSOC);
    if ($vivo) {
        $ct['cliente']           = $vivo['cliente'];
        $ct['dni']               = $vivo['dni'];
        $ct['email']             = $vivo['email'];
        $ct['telefono']          = $vivo['telefono'];
        $ct['cliente_dir']       = $vivo['cliente_dir'];
        $ct['cliente2_nombre']   = $vivo['cliente2_nombre'];
        $ct['cliente2_dni']      = $vivo['cliente2_dni'];
        $ct['cliente2_telefono'] = $vivo['cliente2_telefono'];
        $ct['cliente2_email']    = $vivo['cliente2_email'];
        $ct['cliente2_direccion'] = $vivo['cliente2_direccion'];
        $ct['nombre_servicio']   = $vivo['nombre_servicio'];
        $ct['servicio_detalle']  = $vivo['servicio_detalle'];
        $ct['fecha_evento']      = $vivo['fecha_evento'];
        $ct['ho_inicio']         = $vivo['ho_inicio'];
        $ct['direccion']         = $vivo['direccion'];
        $ct['provincia']         = $vivo['provincia'];
        $ct['proforma']          = $vivo['proforma'];
        $ct['fecha_entrega']     = $vivo['fecha_entrega'];
        $ct['fecha_formato']     = $vivo['fecha_formato'];
        $ct['unidades']          = $vivo['unidades'];
        $ct['material']          = $vivo['material'];
        $ct['formato']           = $vivo['formato'];
    }
    // Pago actual
    $saldo = $saldoActual;
    $adelantoDoc = (float) ($pagoActual['adelanto'] ?? 0);
    $montoTotalDoc = (float) ($pagoActual['monto_total'] ?? 0);
    $fechaLimDoc = $fechaLimActual;
    $formaPagoDoc = $pagoActual['nombre_pago'] ?? '—';

    // Condiciones actuales (pueden haber cambiado en edición)
    $stmtCvivo = $pdo->prepare("
        SELECT co.detalle
        FROM contrato_condicion cc
        JOIN condiciones co ON co.id_condiciones = cc.condicion_id
        WHERE cc.contrato_id = ?
        ORDER BY co.id_condiciones
    ");
    $stmtCvivo->execute([$id]);
    $condiciones = $stmtCvivo->fetchAll(PDO::FETCH_COLUMN);
} else {
    // origen=lista: todo del snapshot (original firmado)
    $saldo = $ct['saldo'] ?? null;
    $adelantoDoc = $ct['adelanto'] ?? null;
    $montoTotalDoc = $ct['monto_total'] ?? null;
    $fechaLimDoc = $ct['fecha_limite'] ?? '';
    $formaPagoDoc = $ct['nombre_pago'] ?? '—';
    // condiciones ya asignadas arriba (snapshot o fallback)
}

$numContrato = str_pad($ct['id_contrato'], 3, '0', STR_PAD_LEFT);
$fechaContrato = date('d/m/Y', strtotime($ct['fecha_contrato']));

function fd(?string $f): string
{
    if (!$f || $f === '' || str_starts_with($f, '0000') || str_starts_with($f, '-')) {
        return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
    }
    $ts = @strtotime($f);
    if (!$ts || $ts < 0 || date('Y', $ts) < 1900) {
        return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
    }
    return date('d/m/Y', $ts);
}
// Hora con formato 12h AM/PM
function fh(?string $h): string
{
    if (!$h || $h === '' || $h === '00:00:00') {
        return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
    }
    $ts = @strtotime('1970-01-01 ' . $h);
    if (!$ts)
        return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
    return date('g:i A', $ts); // ej: 4:25 PM
}
function fm($v): string
{
    if ($v === null) {
        return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
    }
    return 'S/ ' . number_format((float)$v, 2);
}

$CONDICION_FIJA = '* Después de ser entregado el material, el cliente tiene 1 semana para realizar algún reclamo o cambio. Pasando la semana no hay reclamo alguno *';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Contrato N° <?= $numContrato ?> — ISHUME</title>
    <link rel="stylesheet" href="../css/pdf.css">
</head>

<body>

    <!-- Barra de acciones (oculta al imprimir) -->
    <div class="barra">
        <span class="barra-info">
            Contrato N° <?= $numContrato ?> — <?= htmlspecialchars($ct['cliente']) ?>
        </span>
        <div class="barra-btns">

            <!-- IMPRIMIR: abre diálogo de impresora física -->
            <button class="btn-b btn-imprimir" onclick="imprimirDoc()">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                    <rect x="3" y="6" width="10" height="7" rx="1" stroke="currentColor" stroke-width="1.3" />
                    <path d="M5 6V3h6v3" stroke="currentColor" stroke-width="1.3" />
                    <line x1="5" y1="10" x2="11" y2="10" stroke="currentColor" stroke-width="1.2"
                        stroke-linecap="round" />
                </svg>
                Imprimir
            </button>

            <!-- PDF: abre diálogo del navegador con destino "Guardar como PDF" -->
            <button class="btn-b btn-pdf" onclick="guardarPDF()">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                    <path d="M3 2h7l3 3v9a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z" stroke="currentColor"
                        stroke-width="1.3" />
                    <path d="M10 2v3h3" stroke="currentColor" stroke-width="1.3" />
                    <line x1="8" y1="7" x2="8" y2="12" stroke="currentColor" stroke-width="1.3"
                        stroke-linecap="round" />
                    <path d="M5.5 9.5L8 12l2.5-2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
                Guardar PDF
            </button>
        </div>
    </div>

    <!-- Hoja del contrato -->
    <div class="hoja" id="hoja">

        <div class="doc-header">
            <div class="doc-header-logo">
                <img src="../img/logo.png" alt="ISHUME" />
            </div>
            <div class="doc-header-info">
                <h1>Xiomara Tasayco H.</h1>
                <p>Gerenta General &nbsp;|&nbsp; 991157028 &nbsp;|&nbsp; ✉ IshumeAgencia@hotmail.com</p>
            </div>
        </div>

        <div class="doc-body">

            <div class="doc-title">
                <h2>Contrato de Servicios Audiovisuales — N° <?= $numContrato ?></h2>
                <span>Chincha, <?= $fechaContrato ?></span>
            </div>

            <!-- CLIENTE: cliente1 izquierda, cliente2 derecha -->
            <div class="sec">
                <div class="sec-t">Datos del Cliente</div>
                <?php
                $hay_c2 = !empty($ct['cliente2_nombre']);
                ?>
                <table class="tbl-grid">
                    <tr>
                        <!-- Cliente 1 -->
                        <td class="bloque">
                            <?php if ($hay_c2): ?>
                                <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:#f07c00;margin-bottom:4px;margin-left:4px;">Cliente 1</div>
                            <?php endif; ?>
                            <table class="tbl">
                                <tr>
                                    <td class="l">Nombre</td>
                                    <td><?= htmlspecialchars($ct['cliente'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="l">DNI</td>
                                    <td><?= htmlspecialchars($ct['dni'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="l">Teléfono</td>
                                    <td><?= htmlspecialchars($ct['telefono'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="l">Email</td>
                                    <td><?= htmlspecialchars($ct['email'] ?? '—') ?></td>
                                </tr>
                                <?php if (!empty($ct['cliente_dir'])): ?>
                                    <tr>
                                        <td class="l">Dirección</td>
                                        <td><?= htmlspecialchars($ct['cliente_dir']) ?></td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </td>
                        <!-- Cliente 2 (solo si existe) -->
                        <td class="bloque">
                            <?php if ($hay_c2): ?>
                                <div style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;color:#f07c00;margin-bottom:4px;margin-left:4px;">Cliente 2</div>
                                <table class="tbl">
                                    <tr>
                                        <td class="l">Nombre</td>
                                        <td><?= htmlspecialchars($ct['cliente2_nombre'] ?? '—') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="l">DNI</td>
                                        <td><?= htmlspecialchars($ct['cliente2_dni'] ?? '—') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="l">Teléfono</td>
                                        <td><?= htmlspecialchars($ct['cliente2_telefono'] ?? '—') ?></td>
                                    </tr>
                                    <tr>
                                        <td class="l">Email</td>
                                        <td><?= htmlspecialchars($ct['cliente2_email'] ?? '—') ?></td>
                                    </tr>
                                    <?php if (!empty($ct['cliente2_direccion'])): ?>
                                        <tr>
                                            <td class="l">Dirección</td>
                                            <td><?= htmlspecialchars($ct['cliente2_direccion']) ?></td>
                                        </tr>
                                    <?php endif; ?>
                                </table>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- EVENTO: 2 columnas -->
            <div class="sec">
                <div class="sec-t">Detalles del contrato</div>
                <table class="tbl-grid">
                    <tr>
                        <td class="bloque">
                            <table class="tbl">
                                <tr>
                                    <td class="l">Evento</td>
                                    <td><?= htmlspecialchars($ct['nombre_servicio'] ?? $ct['servicio'] ?? '—') ?></td>
                                </tr>
                                <?php if (!empty($ct['servicio_detalle']) || !empty($ct['nombre_servicio'])): ?>
                                    <tr>
                                        <td class="l">Servicio</td>
                                        <td>
                                            <?= htmlspecialchars(
                                                !empty($ct['servicio_detalle'])
                                                    ? $ct['servicio_detalle']
                                                    : ($ct['nombre_servicio'] ?? '—')
                                            ) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <tr>
                                    <td class="l">Fecha evento</td>
                                    <td><?= fd($ct['fecha_evento'] ?? '') ?></td>
                                </tr>
                                <tr>
                                    <td class="l">Hora inicio</td>
                                    <td><?= fh($ct['ho_inicio'] ?? '') ?></td>
                                </tr>
                            </table>
                        </td>
                        <td class="bloque">
                            <table class="tbl">
                                <tr>
                                    <td class="l">Dirección</td>
                                    <td><?= htmlspecialchars($ct['direccion'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="l">Provincia</td>
                                    <td><?= htmlspecialchars($ct['provincia'] ?? '—') ?></td>
                                </tr>
                                <tr>
                                    <td class="l">Proforma</td>
                                    <td><?= htmlspecialchars($ct['proforma'] ?? '—') ?></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- PAGO -->
            <div class="sec">
                <div class="sec-t">Estado de Pago</div>
                <table class="tbl-pago">
                    <thead>
                        <tr>
                            <th>Monto Total</th>
                            <th>Adelanto</th>
                            <th>Saldo Pendiente</th>
                            <th>Forma de Pago</th>
                            <th>Fecha Límite</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="bold"><?= fm($montoTotalDoc) ?></td>
                            <td class="bold"><?= fm($adelantoDoc) ?></td>
                            <td class="bold">
                                <?= $montoTotalDoc === null ? '<em style="color:#aaa">Por confirmar</em>' : fm($saldo) ?>
                            </td>
                            <td><?= htmlspecialchars($formaPagoDoc) ?></td>
                            <td><?= fd($fechaLimDoc) ?></td>
                            <td>
                                <span class="estado-b" style="background:<?= $estadoBg ?>;color:<?= $estadoColor ?>">
                                    <?= $estado ?>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ENTREGA: 2 columnas -->
            <div class="sec">
                <div class="sec-t">Entrega del Material</div>
                <table class="tbl-grid">
                    <tr>
                        <td class="bloque">
                            <table class="tbl">
                                <tr>
                                    <td class="l">Fecha entrega del material</td>
                                    <td><?= fd($ct['fecha_entrega'] ?? '') ?></td>
                                </tr>
                                <tr>
                                    <td class="l">Material</td>
                                    <td>
                                        <?= !empty($ct['material']) ? htmlspecialchars($ct['material']) : '<em style="color:#aaa;font-style:italic">Confirmar</em>' ?></td>
                                </tr>
                            </table>
                        </td>
                        <td class="bloque">
                            <table class="tbl">
                                <tr>
                                    <td class="l">Formato</td>
                                    <td><?= $ct['formato'] ? htmlspecialchars($ct['formato']) : '<em style="color:#aaa;font-style:italic">Confirmar</em>' ?></td>
                                </tr>
                                <tr>
                                    <td class="l">N° unidades</td>
                                    <td><?= ($ct['unidades'] !== null && $ct['unidades'] !== '') ? htmlspecialchars($ct['unidades']) : '<em style="color:#aaa;font-style:italic">Confirmar</em>' ?></td>
                                </tr>
                                <tr>
                                    <td class="l">Fecha entrega del formato</td>
                                    <td><?= fd($ct['fecha_formato'] ?? '') ?></td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- CONDICIONES -->
            <div class="sec">
                <div class="sec-t">Condiciones del Contrato</div>
                <div class="conds">
                    <?php if (!empty($condiciones)): ?>
                        <ol>
                            <?php foreach ($condiciones as $cond): ?>
                                <li><?= htmlspecialchars($cond) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                    <div class="cond-fija"><?= htmlspecialchars($CONDICION_FIJA) ?></div>
                </div>
            </div>

            <!-- FIRMAS -->
            <?php $hayCliente2 = isset($ct['cliente2_nombre']) && trim($ct['cliente2_nombre']) !== '';?>

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

        </div>

    </div>

    </div>

    </div>

    <script>
        // IMPRIMIR: va directo al diálogo de impresora
        function imprimirDoc() {
            window.print();
        }

        // GUARDAR PDF: abre print con instrucción de guardar como PDF
        // En Chrome/Edge muestra "Guardar como PDF" como destino por defecto
        // cuando se llama desde un botón dedicado con esta técnica
        function guardarPDF() {
            // Cambiar título temporalmente para que el nombre del archivo sea descriptivo
            const tituloOriginal = document.title;
            document.title = 'Contrato_<?= $numContrato ?>_<?= preg_replace('/[^A-Za-z0-9]/', '_', $ct['cliente']) ?>';
            window.print();
            // Restaurar título después de que el diálogo se abra
            setTimeout(() => {
                document.title = tituloOriginal;
            }, 500);
        }


        <?php if ($modo === 'print'): ?>
            // Modo automático: disparar impresión al cargar
            window.addEventListener('load', () => setTimeout(window.print, 400));
        <?php endif; ?>
    </script>

</body>

</html>