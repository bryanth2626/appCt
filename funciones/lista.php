<?php

//  lista.php — Lista de Contratos (datos originales)

$host    = '127.0.0.1';
$port    = '3307';
$db      = 'administracion';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$pdo = null;
$error_msg   = '';
$success_msg = '';

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    $error_msg = 'Error de conexión: ' . $e->getMessage();
}

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
        $like   = '%'.$busqueda.'%';
        $params = array_merge($params, [$like,$like,$like]);
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
function calcEstado(array $ct, string $hoy): array {
    $saldo = (float)($ct['saldo_actual'] ?? $ct['saldo_original'] ?? 0);
    $fl    = $ct['fecha_limite_actual'] ?? $ct['fecha_limite_original'] ?? '';
    if ($saldo <= 0)         return ['label'=>'Pagado',  'clase'=>'badge-ok'];
    if ($fl && $fl < $hoy)   return ['label'=>'No pago', 'clase'=>'badge-pendiente'];
    return                          ['label'=>'Proceso', 'clase'=>'badge-parcial'];
}

$total_contratos = count($contratos);
$total_monto     = array_sum(array_column($contratos, 'monto_total'));
$total_saldo     = array_sum(array_column($contratos, 'saldo_original'));

$CONDICION_FIJA = '* Después de ser entregado el material, el cliente tiene 1 semana para realizar algún reclamo o cambio. Pasando la semana no hay reclamo alguno *';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Lista de Contratos — ISHUME</title>
    <link href="../estilos/lista.css" rel="stylesheet"/>
    <style>
        /* ── Modal documento (contrato original) ── */
        .modal-doc-overlay {
            position:fixed; inset:0; background:rgba(0,0,0,0.6);
            display:flex; align-items:flex-start; justify-content:center;
            z-index:2000; padding:30px 20px; overflow-y:auto;
            opacity:0; pointer-events:none; transition:opacity 0.2s;
        }
        .modal-doc-overlay.visible { opacity:1; pointer-events:all; }

        .doc-barra { display:flex; justify-content:flex-end; gap:10px; width:760px; margin-bottom:10px; flex-shrink:0; }
        .doc-hoja  { background:#fff; width:760px; box-shadow:0 8px 40px rgba(0,0,0,0.3); flex-shrink:0; }

        /* Estilos internos del documento */
        .cv-body{padding:28px 36px 36px;}
        .cv-title{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #f07c00;padding-bottom:10px;margin-bottom:22px;}
        .cv-title h2{font-size:14px;font-weight:900;text-transform:uppercase;letter-spacing:0.05em;color:#111;}
        .cv-title span{font-size:12px;font-weight:600;color:#555;}
        .cv-sec{margin-bottom:18px;}
        .cv-sec-t{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:0.1em;color:#fff;background:#111;padding:5px 10px;margin-bottom:10px;display:inline-block;}
        .cv-tbl{width:100%;border-collapse:collapse;}
        .cv-tbl tr{border-bottom:1px solid #f0f0f0;}
        .cv-tbl td{padding:6px 8px;font-size:12.5px;vertical-align:top;}
        .cv-tbl td.l{font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:#666;width:170px;white-space:nowrap;}
        .cv-tbl-pago{width:100%;border-collapse:collapse;}
        .cv-tbl-pago th{background:#f07c00;color:#fff;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;padding:7px 10px;text-align:left;}
        .cv-tbl-pago td{padding:8px 10px;font-size:12.5px;border-bottom:1px solid #f0f0f0;}
        .cv-conds{background:#fafafa;border:1px solid #e8e8e8;padding:14px 18px;font-size:12px;line-height:1.8;color:#444;}
        .cv-conds ol{padding-left:20px;margin-bottom:8px;}
        .cv-conds li{margin-bottom:4px;}
        .cv-cond-fija{color:#c0392b;font-weight:700;font-size:11px;text-transform:uppercase;text-align:center;padding-top:10px;border-top:1px dashed #e0a0a0;margin-top:6px;}
        .cv-firmas{display:grid;grid-template-columns:1fr 1fr;gap:60px;margin-top:70px;margin-bottom:10px;}
        .cv-firma{display:flex;flex-direction:column;align-items:center;gap:6px;}
        .cv-linea{width:100%;border-bottom:1.5px solid #111;}
        .cv-flabel{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#555;}
        .cv-estado{display:inline-block;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;padding:3px 10px;}

        /* Aviso "datos originales" */
        .aviso-original {
            background: #fff3e0; border-left: 3px solid #f07c00;
            padding: 7px 12px; font-size: 11px; font-weight: 600;
            color: #e65100; margin-bottom: 14px;
        }
    </style>
</head>
<body>

<form id="form-delete" method="POST" style="display:none;">
    <input type="hidden" name="action" value="delete"/>
    <input type="hidden" name="id_contrato" id="delete-id"/>
</form>

<!-- ══ MODAL DOCUMENTO ORIGINAL ══ -->
<div class="modal-doc-overlay" id="modal-doc">
    <div style="display:flex;flex-direction:column;align-items:center;">
        <div class="doc-barra">
            <a id="btn-pdf-link" href="#" target="_blank" class="btn btn-primary btn-sm">
                ⬇ Imprimir / PDF
            </a>
            <button class="btn btn-secondary btn-sm" onclick="cerrarDoc()">✕ Cerrar</button>
        </div>
        <div class="doc-hoja" id="doc-hoja"></div>
    </div>
</div>


<div class="pagina">

    <div class="doc-header">
        <div class="doc-header-logo"><img src="../img/logo.png" alt="ISHUME"/></div>
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
                <span class="resumen-valor">S/ <?= number_format($total_monto,2) ?></span>
                <span class="resumen-label">Monto Total Original</span>
            </div>
            <div class="resumen-sep"></div>
            <div class="resumen-item">
                <span class="resumen-valor saldo-pendiente">S/ <?= number_format($total_saldo,2) ?></span>
                <span class="resumen-label">Saldo Original</span>
            </div>
        </div>

        <form method="GET" class="filtros-row">
            <div class="filtro-campo">
                <input type="text" name="q" placeholder="Buscar por cliente, DNI o provincia…"
                       value="<?= htmlspecialchars($busqueda) ?>"/>
            </div>
            <div class="filtro-campo filtro-mes">
                <input type="month" name="mes" value="<?= htmlspecialchars($filtro_mes) ?>"/>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Buscar</button>
            <?php if ($busqueda || $filtro_mes): ?>
                <a href="lista.php" class="btn btn-secondary btn-sm">Limpiar</a>
            <?php endif; ?>
        </form>

        <?php if (empty($contratos)): ?>
            <div class="empty-state">
                <svg viewBox="0 0 48 48" fill="none">
                    <rect x="8" y="4" width="32" height="40" rx="2" stroke="#ddd" stroke-width="2"/>
                    <line x1="14" y1="16" x2="34" y2="16" stroke="#ddd" stroke-width="2"/>
                    <line x1="14" y1="22" x2="34" y2="22" stroke="#ddd" stroke-width="2"/>
                    <line x1="14" y1="28" x2="26" y2="28" stroke="#ddd" stroke-width="2"/>
                </svg>
                <p>No se encontraron contratos<?= $busqueda ? " para «$busqueda»" : '' ?>.</p>
            </div>
        <?php else: ?>
        <div class="tabla-wrapper">
            <table class="tabla-contratos">
                <thead>
                <tr>
                    <th>N°</th><th>Cliente</th><th>Evento</th><th>Fecha Evento</th>
                    <th>Monto Total</th><th>Saldo Original</th><th>Estado</th>
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
                    <td class="td-num"><?= str_pad($ct['contrato_id'],3,'0',STR_PAD_LEFT) ?></td>
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
                    <td class="td-monto">S/ <?= number_format($ct['monto_total'],2) ?></td>
                    <td class="td-saldo <?= $saldoTabla <= 0 ? 'saldo-ok' : 'saldo-pendiente' ?>">
                        S/ <?= number_format($saldoTabla,2) ?>
                    </td>
                    <td><span class="badge <?= $estado['clase'] ?>"><?= $estado['label'] ?></span></td>
                    <td class="td-acciones">

                        <!-- VER (muestra contrato original) -->
                        <button class="btn-icon btn-ver" title="Ver contrato original"
                                onclick='verContrato(<?= htmlspecialchars(json_encode([
                                    'id'              => $ct['contrato_id'],
                                    'cliente'         => $ct['cliente'],
                                    'dni'             => $ct['dni'],
                                    'email'           => $ct['email'],
                                    'telefono'        => $ct['telefono'],
                                    'nombre_servicio' => $ct['nombre_servicio'],
                                    'servicio_detalle'=> $ct['servicio_detalle'] ?? '',
                                    'fecha_evento'    => $ct['fecha_evento'],
                                    'ho_inicio'       => $ct['ho_inicio'],
                                    'direccion'       => $ct['direccion'],
                                    'provincia'       => $ct['provincia'],
                                    'proforma'        => $ct['proforma'],
                                    'monto_total'     => $ct['monto_total'],
                                    'adelanto'        => $ct['adelanto'],
                                    'saldo'           => $ct['saldo_original'],
                                    'saldo_actual'    => $saldoActual,
                                    'fecha_limite'    => $ct['fecha_limite_original'],
                                    'fecha_limite_actual' => $ct['fecha_limite_actual'],
                                    'nombre_pago'     => $ct['nombre_pago'],
                                    'fecha_entrega'   => $ct['fecha_entrega'],
                                    'unidades'        => $ct['unidades'],
                                    'formato'         => $ct['formato'],
                                    'condiciones'     => $condiciones,
                                    'fecha_contrato'  => $ct['fecha_contrato'],
                                ])) ?>)'>
                            <svg viewBox="0 0 16 16" fill="none">
                                <ellipse cx="8" cy="8" rx="7" ry="5" stroke="currentColor" stroke-width="1.3"/>
                                <circle cx="8" cy="8" r="2" stroke="currentColor" stroke-width="1.3"/>
                            </svg>
                        </button>

                        <!-- PDF (contrato original) -->
                        <a class="btn-icon btn-pdf" title="Imprimir / PDF (original)"
                           href="contrato_pdf.php?id=<?= $ct['contrato_id'] ?>&origen=lista" target="_blank">
                            <svg viewBox="0 0 16 16" fill="none">
                                <path d="M3 2h7l3 3v9a1 1 0 01-1 1H3a1 1 0 01-1-1V3a1 1 0 011-1z" stroke="currentColor" stroke-width="1.3"/>
                                <path d="M10 2v3h3" stroke="currentColor" stroke-width="1.3"/>
                                <line x1="5" y1="9" x2="11" y2="9" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                                <line x1="5" y1="11" x2="9" y2="11" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
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
const HOY       = '<?= $hoy ?>';
const COND_FIJA = <?= json_encode($CONDICION_FIJA) ?>;

function fmtFecha(f) {
    if (!f || f === '' || f.startsWith('0000') || f.startsWith('-')) return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
    const p = f.split('-');
    if (!p[0] || parseInt(p[0]) < 1900) return '<em style="color:#aaa;font-style:italic">Por confirmar</em>';
    return p[2]+'/'+p[1]+'/'+p[0];
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
    return 'S/ '+parseFloat(v||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
}
function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function calcEstado(saldo, fl) {
    if (saldo<=0)         return {label:'Pagado', bg:'#e6f4ea',color:'#1a7a30'};
    if (fl && fl<HOY)     return {label:'No pago',bg:'#fdecea',color:'#c0392b'};
    return                       {label:'Proceso',bg:'#fff3e0',color:'#e65100'};
}

function verContrato(c) {
    // El modal muestra DATOS ORIGINALES del snapshot
    // El estado usa saldo_actual para reflejar si ya pagó
    const saldo  = parseFloat(c.saldo_actual ?? c.saldo ?? 0);
    const estado = calcEstado(saldo, c.fecha_limite_actual ?? c.fecha_limite);
    const num    = String(c.id).padStart(3,'0');

    // Condiciones (guardadas como array de textos en el snapshot)
    const conds = Array.isArray(c.condiciones) ? c.condiciones : [];
    // La última condición del array es la fija (se guarda al registrar)
    // Separar condiciones normales de la fija
    const condsFija  = conds.filter(t => t.startsWith('*'));
    const condsNorm  = conds.filter(t => !t.startsWith('*'));
    const liNorm     = condsNorm.map(t => `<li>${esc(t)}</li>`).join('');

    document.getElementById('btn-pdf-link').href = 'contrato_pdf.php?id='+c.id+'&origen=lista';

    document.getElementById('doc-hoja').innerHTML = `
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
                    <tr><td class="l">Nombre completo</td><td>${esc(c.cliente)}</td></tr>
                    <tr><td class="l">DNI</td><td>${esc(c.dni)}</td></tr>
                    <tr><td class="l">Teléfono</td><td>${esc(c.telefono)||'—'}</td></tr>
                    <tr><td class="l">Correo electrónico</td><td>${esc(c.email)||'—'}</td></tr>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Detalles del contrato</div>
                <table class="cv-tbl">
                    <tr><td class="l">Evento</td><td>${esc(c.nombre_servicio)}</td></tr>
                    ${c.servicio_detalle ? `<tr><td class="l">Servicio</td><td>${esc(c.servicio_detalle)}</td></tr>` : ''}
                    <tr><td class="l">Fecha del evento</td><td>${fmtFecha(c.fecha_evento)}</td></tr>
                    <tr><td class="l">Hora de inicio</td><td>${fmtHora(c.ho_inicio)}</td></tr>
                    <tr><td class="l">Dirección</td><td>${esc(c.direccion)}</td></tr>
                    <tr><td class="l">Provincia</td><td>${esc(c.provincia)}</td></tr>
                    <tr><td class="l">proforma</td><td>${esc(c.proforma)}</td></tr>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Estado de Pago</div>
                <table class="cv-tbl-pago">
                    <thead><tr>
                        <th>Monto Total</th><th>Adelanto</th><th>Saldo Pendiente</th>
                        <th>Forma Pago</th><th>Fecha Límite</th><th>Estado</th>
                    </tr></thead>
                    <tbody><tr>
                        <td style="font-weight:700">${fmtMoney(c.monto_total)}</td>
                        <td style="font-weight:700">${fmtMoney(c.adelanto)}</td>
                        <td style="font-weight:800;color:${saldo<=0?'#1a7a30':'#c0392b'}">${fmtMoney(c.saldo)}</td>
                        <td>${esc(c.nombre_pago)||'—'}</td>
                        <td>${fmtFecha(c.fecha_limite)}</td>
                        <td><span class="cv-estado" style="background:${estado.bg};color:${estado.color}">${estado.label}</span></td>
                    </tr></tbody>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Entrega del Material</div>
                <table class="cv-tbl">
                    <tr><td class="l">Fecha de entrega</td><td>${fmtFecha(c.fecha_entrega)}</td></tr>
                    <tr><td class="l">Formato</td><td>${esc(c.formato)||'—'}</td></tr>
                    <tr><td class="l">N° de unidades</td><td>${esc(c.unidades)||'—'}</td></tr>
                </table>
            </div>
            <div class="cv-sec"><div class="cv-sec-t">Condiciones del Contrato</div>
                <div class="cv-conds">
                    ${liNorm?'<ol>'+liNorm+'</ol>':''}
                    <div class="cv-cond-fija">${esc(COND_FIJA)}</div>
                </div>
            </div>
            <div class="cv-firmas">
                <div class="cv-firma"><div class="cv-linea"></div><span class="cv-flabel">Firma del Cliente</span></div>
                <div class="cv-firma"><div class="cv-linea"></div><span class="cv-flabel">Firma del Servidor — ISHUME</span></div>
            </div>
        </div>
    `;

    document.getElementById('modal-doc').classList.add('visible');
    document.getElementById('modal-doc').scrollTop = 0;
}

function cerrarDoc() {
    document.getElementById('modal-doc').classList.remove('visible');
    document.getElementById('doc-hoja').innerHTML = '';
}

document.getElementById('modal-doc').addEventListener('click', function(e) {
    if (e.target === this) cerrarDoc();
});

function confirmarEliminar(id, nombre) {
    if (confirm('¿Eliminar el contrato N° '+id+' de "'+nombre+'"?\nEsta acción no se puede deshacer.')) {
        document.getElementById('delete-id').value = id;
        document.getElementById('form-delete').submit();
    }
}
</script>

</body>
</html>