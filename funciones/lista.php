<?php

//  lista.php — Lista de Contratos (datos originales)
require_once '../conexion/conexion.php';

$busqueda   = trim($_GET['q']   ?? '');
$filtro_mes = trim($_GET['mes'] ?? '');
$hoy        = date('Y-m-d');

// ── Consulta desde snapshot (datos originales firmados)
// El estado se calcula en tiempo real desde pago actual (para saber si pagó)
$contratos = [];
if ($pdo) {
    $sql = "
        SELECT
            sn.contrato_id,
            sn.cliente_nombre  AS cliente,
            sn.cliente_dni     AS dni,
            sn.cliente_email   AS email,
            sn.cliente_tel     AS telefono,
            sn.servicio        AS nombre_servicio,
            -- servicio_detalle: del snapshot si existe, si no del contrato actual
            COALESCE(sn.servicio_detalle, dc.servicio_detalle) AS servicio_detalle,
            sn.fecha_evento,
            sn.ho_inicio,
            sn.direccion,
            sn.provincia,
            sn.proforma,
            sn.monto_total,
            sn.adelanto,
            sn.saldo           AS saldo_original,
            sn.fecha_limite    AS fecha_limite_original,
            sn.forma_pago      AS nombre_pago,
            sn.fecha_entrega,
            sn.unidades,
            sn.formato,
            sn.condiciones_json,
            sn.fecha_contrato,
            -- Saldo ACTUAL (puede haber cambiado en post-contrato)
            p.saldo            AS saldo_actual,
            p.fecha_limite     AS fecha_limite_actual
        FROM snapshot_contrato sn
        JOIN detalle_contrato dc ON dc.id_contrato = sn.contrato_id
        LEFT JOIN pago p ON p.contrato_id = sn.contrato_id
        WHERE 1=1
    ";
    $params = [];

    if ($busqueda !== '') {
        $sql   .= " AND (sn.cliente_nombre LIKE ? OR sn.cliente_dni LIKE ? OR sn.provincia LIKE ?)";
        $like   = '%' . $busqueda . '%';
        $params = array_merge($params, [$like, $like, $like]);
    }
    if ($filtro_mes !== '') {
        $sql     .= " AND DATE_FORMAT(sn.fecha_evento,'%Y-%m')=?";
        $params[] = $filtro_mes;
    }

    $sql .= " ORDER BY sn.fecha_contrato DESC, sn.contrato_id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $contratos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Estado: usa saldo y fecha_limite ACTUALES (para reflejar si ya pagó)
function calcEstado(array $ct, string $hoy): array
{
    $monto = $ct['monto_total'] ?? null;
    $saldo = (float)($ct['saldo_actual'] ?? $ct['saldo_original'] ?? 0);
    $fl    = $ct['fecha_limite_actual'] ?? $ct['fecha_limite_original'] ?? '';

    // 🔹 NUEVO: detectar reserva
    if ($monto === null) {
        return ['label' => 'Reserva', 'clase' => 'badge-reserva'];
    }

    if ($saldo <= 0) {
        return ['label' => 'Pagado', 'clase' => 'badge-ok'];
    }

    if ($fl && $fl < $hoy) {
        return ['label' => 'No pago', 'clase' => 'badge-pendiente'];
    }

    return ['label' => 'Proceso', 'clase' => 'badge-parcial'];
}

$total_contratos = count($contratos);
$total_monto     = array_sum(array_column($contratos, 'monto_total'));
$total_saldo     = array_sum(array_column($contratos, 'saldo_original'));

$CONDICION_FIJA = '* Después de ser entregado el material, el cliente tiene 1 semana para realizar algún reclamo o cambio. Pasando la semana no hay reclamo alguno *';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Lista de Contratos — ISHUME</title>
    <link href="../css/lista.css" rel="stylesheet" />
</head>

<body>

    <form id="form-delete" method="POST" style="display:none;">
        <input type="hidden" name="action" value="delete" />
        <input type="hidden" name="id_contrato" id="delete-id" />
    </form>


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
                <div class="msg msg-ok"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="msg msg-error"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <div class="doc-title-row">
                <h2>Lista de Contratos</h2>
                <a href="registrar.php" class="btn btn-primary btn-sm">+ Nuevo Contrato</a>
            </div>

            <div class="resumen-strip">
                <div class="resumen-item">
                    <span class="resumen-valor"><?= $total_contratos ?></span>
                    <span class="resumen-label">Contratos</span>
                </div>
                <div class="resumen-sep"></div>
                <div class="resumen-item">
                    <span class="resumen-valor">S/ <?= number_format($total_monto, 2) ?></span>
                    <span class="resumen-label">Monto Total Original</span>
                </div>
                <div class="resumen-sep"></div>
                <div class="resumen-item">
                    <span class="resumen-valor saldo-pendiente">S/ <?= number_format($total_saldo, 2) ?></span>
                    <span class="resumen-label">Saldo Original</span>
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
                    <a href="lista.php" class="btn btn-secondary btn-sm">Limpiar</a>
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
                    <p>No se encontraron contratos<?= $busqueda ? " para «$busqueda»" : '' ?>.</p>
                </div>
            <?php else: ?>
                <div class="tabla-wrapper">
                    <table class="tabla-contratos">
                        <thead>
                            <tr>
                                <th>N°</th>
                                <th>Cliente</th>
                                <th>Evento</th>
                                <th>Fecha Evento</th>
                                <th>Monto Total</th>
                                <th>Saldo Original</th>
                                <th>Estado</th>
                                <th class="th-acciones">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contratos as $ct):
                                $estado       = calcEstado($ct, $hoy);
                                $saldoTabla   = (float)($ct['saldo_original'] ?? 0);   // tabla: original
                                $saldoActual  = (float)($ct['saldo_actual']   ?? $ct['saldo_original']); // estado: actual
                                $condiciones  = json_decode($ct['condiciones_json'] ?? '[]', true) ?: [];
                            ?>
                                <tr>
                                    <td class="td-num"><?= str_pad($ct['contrato_id'], 3, '0', STR_PAD_LEFT) ?></td>
                                    <td>
                                        <div class="cliente-nombre"><?= htmlspecialchars($ct['cliente']) ?></div>
                                        <div class="cliente-dni">DNI <?= htmlspecialchars($ct['dni']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($ct['nombre_servicio']) ?></td>
                                    <td>
                                        <?php
                                        $fe = $ct['fecha_evento'] ?? '';
                                        $ts = $fe ? @strtotime($fe) : false;
                                        echo ($ts && $ts > 0 && date('Y', $ts) > 1900)
                                            ? date('d/m/Y', $ts)
                                            : '<em style="color:#aaa;font-size:11px;font-style:italic">Por confirmar</em>';
                                        ?>
                                        <div class="sub-dato"><?php
                                                                $hi = $ct['ho_inicio'] ?? '';
                                                                if ($hi && $hi !== '00:00:00') {
                                                                    $tsH = @strtotime('1970-01-01 ' . $hi);
                                                                    echo $tsH ? date('g:i A', $tsH) : '';
                                                                }
                                                                ?></div>
                                    </td>
                                    <td class="td-monto">S/ <?= number_format($ct['monto_total'], 2) ?></td>
                                    <td class="td-saldo <?= $saldoTabla <= 0 ? 'saldo-ok' : 'saldo-pendiente' ?>">
                                        S/ <?= number_format($saldoTabla, 2) ?>
                                    </td>
                                    <td><span class="badge <?= $estado['clase'] ?>"><?= $estado['label'] ?></span></td>
                                    <td class="td-acciones">

                                        <!-- PDF (contrato original) -->
                                        <a class="btn-icon btn-pdf" title="Ver contrato / PDF (impresion)"
                                            href="contrato_pdf.php?id=<?= $ct['contrato_id'] ?>&origen=lista" target="_blank">
                                            <svg viewBox="0 0 16 16" fill="none">
                                                <path d="M3 2h7l3 3v9a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z" stroke="currentColor" stroke-width="1.3" />
                                                <path d="M10 2v3h3" stroke="currentColor" stroke-width="1.3" />
                                                <line x1="5" y1="9" x2="11" y2="9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                                                <line x1="5" y1="11" x2="9" y2="11" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" />
                                            </svg>
                                        </a>
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

<script src="../js/lista.js"></script>

</body>

</html>