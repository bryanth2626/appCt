<?php

date_default_timezone_set('America/Lima');

// Respaldo de Base de Datos
// Respaldo de Base de Datos

$host    = '127.0.0.1';
$port    = '3307';
$db      = 'administracion';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

// Archivo que registra la fecha del último backup
$archivoFecha = __DIR__ . '/ultimo_backup.txt';

// Conectar
try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die('<div style="font-family:Arial;padding:40px;color:#c00;">
        <strong>Error de conexión:</strong> ' . htmlspecialchars($e->getMessage()) . '
        <br><br><a href="../index.html">← Volver</a>
    </div>');
}

// ── Si se solicita la descarga, generar y enviar el SQL
if (isset($_GET['descargar'])) {

    $ahora    = date('Y-m-d_H-i-s');
    $filename = "backup_ishume_{$ahora}.sql";
    $sql      = generarSQL($pdo, $db, $ahora);

    // Registrar fecha y hora del backup
    file_put_contents($archivoFecha, date('Y-m-d H:i:s'));

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');
    echo $sql;
    exit;
}

// ── Generar el SQL completo de la base de datos
function generarSQL(PDO $pdo, string $db, string $fecha): string {
    $out  = "";
    $out .= "-- ══════════════════════════════════════════════════════\n";
    $out .= "--  BACKUP ISHUME · Base de datos: {$db}\n";
    $out .= "--  Generado el: {$fecha}\n";
    $out .= "--  Para restaurar: importar en phpMyAdmin → pestaña Importar\n";
    $out .= "-- ══════════════════════════════════════════════════════\n\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $out .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $out .= "SET NAMES utf8mb4;\n\n";

    $tablas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tablas as $tabla) {
        $out .= "-- Tabla: `{$tabla}`\n";
        $out .= "DROP TABLE IF EXISTS `{$tabla}`;\n";

        $create = $pdo->query("SHOW CREATE TABLE `{$tabla}`")->fetch(PDO::FETCH_ASSOC);
        $out .= $create['Create Table'] . ";\n\n";

        $filas = $pdo->query("SELECT * FROM `{$tabla}`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($filas)) {
            $cols  = '`' . implode('`, `', array_keys($filas[0])) . '`';
            $out  .= "INSERT INTO `{$tabla}` ({$cols}) VALUES\n";
            $vals  = [];
            foreach ($filas as $fila) {
                $escaped = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), array_values($fila));
                $vals[]  = '(' . implode(', ', $escaped) . ')';
            }
            $out .= implode(",\n", $vals) . ";\n\n";
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    $out .= "-- Fin del backup\n";
    return $out;
}

// ── Calcular estado del backup para mostrar en pantalla
$ultimoBackup    = file_exists($archivoFecha) ? trim(file_get_contents($archivoFecha)) : null;
$diasDesdeBackup = null;
$nivel           = 'nunca'; // nunca | ok | aviso | urgente

if ($ultimoBackup) {
    $diff            = (new DateTime())->diff(new DateTime($ultimoBackup));
    $diasDesdeBackup = $diff->days;
    if ($diasDesdeBackup === 0)      $nivel = 'ok';
    elseif ($diasDesdeBackup <= 6)   $nivel = 'ok';
    elseif ($diasDesdeBackup <= 13)  $nivel = 'aviso';
    else                             $nivel = 'urgente';
}

// Estadísticas rápidas
function contar(PDO $pdo, string $tabla): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM `{$tabla}`")->fetchColumn(); }
    catch (Exception $e) { return 0; }
}

$nContratos  = contar($pdo, 'detalle_contrato');
$nClientes   = contar($pdo, 'cliente');
$nPagos      = contar($pdo, 'pago');
$nHistorial  = contar($pdo, 'historial_contrato');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Backup — ISHUME</title>
    <link href="../estilos/admin.css" rel="stylesheet"/>
    <style>
    </style>
</head>
<body>

<div class="pagina">

    <div class="doc-header">
        <div class="doc-header-logo">
            <img src="../img/logo.png" alt="ISHUME"/>
        </div>
        <div class="doc-header-info">
            <h1>Xiomara Tasayco H.</h1>
            <p>Gerenta General &nbsp;|&nbsp; Respaldo de Datos</p>
        </div>
    </div>

    <div class="doc-body">

        <div class="doc-title-row">
            <h2>Backup — Respaldo de la Base de Datos</h2>
        </div>

        <!-- ESTADO DEL BACKUP -->
        <?php if ($nivel === 'ok'): ?>
            <div class="estado-card estado-ok">
                <span class="estado-emoji">✅</span>
                <div class="estado-texto">
                    <h3>Backup al día</h3>
                    <p>
                        Último respaldo: <strong><?= date('d/m/Y \a \l\a\s h:i A', strtotime($ultimoBackup)) ?></strong>
                        <?= $diasDesdeBackup === 0 ? '(hoy)' : "(hace {$diasDesdeBackup} día" . ($diasDesdeBackup !== 1 ? 's' : '') . ")" ?>.
                        Los datos están protegidos.
                    </p>
                </div>
            </div>

        <?php elseif ($nivel === 'aviso'): ?>
            <div class="estado-card estado-aviso">
                <span class="estado-emoji">⚠️</span>
                <div class="estado-texto">
                    <h3>Backup recomendado</h3>
                    <p>
                        Último respaldo hace <strong><?= $diasDesdeBackup ?> días</strong>
                        (<?= date('d/m/Y', strtotime($ultimoBackup)) ?>).
                        Se recomienda hacer backup cada semana.
                    </p>
                </div>
            </div>

        <?php elseif ($nivel === 'urgente'): ?>
            <div class="estado-card estado-urgente">
                <span class="estado-emoji">🚨</span>
                <div class="estado-texto">
                    <h3>¡Backup urgente!</h3>
                    <p>
                        Hace <strong><?= $diasDesdeBackup ?> días</strong> sin respaldo
                        (último: <?= date('d/m/Y', strtotime($ultimoBackup)) ?>).
                        Los datos podrían perderse si ocurre una falla. Descarga el backup ahora.
                    </p>
                </div>
            </div>

        <?php else: ?>
            <div class="estado-card estado-nunca">
                <span class="estado-emoji">🚨</span>
                <div class="estado-texto">
                    <h3>Nunca se ha hecho un backup</h3>
                    <p>
                        No existe ningún respaldo de los datos del sistema.
                        Si algo falla, <strong>toda la información se perdería para siempre</strong>.
                        Descarga el backup ahora mismo.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- ESTADÍSTICAS -->
        <div class="stats-row">
            <div class="stat-box">
                <span class="stat-n"><?= $nContratos ?></span>
                <span class="stat-l">Contratos</span>
            </div>
            <div class="stat-box">
                <span class="stat-n"><?= $nClientes ?></span>
                <span class="stat-l">Clientes</span>
            </div>
            <div class="stat-box">
                <span class="stat-n"><?= $nPagos ?></span>
                <span class="stat-l">Registros de Pago</span>
            </div>
            <div class="stat-box">
                <span class="stat-n"><?= $nHistorial ?></span>
                <span class="stat-l">Cambios en Historial</span>
            </div>
        </div>

        <!-- ZONA DE DESCARGA -->
        <div class="descarga-zona">
            <p>
                El archivo generado contiene <strong>toda</strong> la información del sistema:<br>
                contratos, clientes, pagos, condiciones, historial de cambios y configuraciones.
            </p>
            <a href="backup.php?descargar=1" class="btn-descarga" id="btn-descarga">
                <svg viewBox="0 0 20 20" fill="none">
                    <path d="M10 3v10M6 9l4 4 4-4" stroke="currentColor" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3 15v1a2 2 0 002 2h10a2 2 0 002-2v-1"
                          stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                Descargar Backup .SQL
            </a>
            <div class="descarga-nota">
                El archivo se descargará como <code>backup_ishume_<?= date('Y-m-d') ?>_HORA.sql</code>
            </div>
        </div>

        <!-- INSTRUCCIONES -->
        <div class="instrucciones">
            <h4>Cómo restaurar el backup si algo sale mal</h4>
            <ol>
                <li>Abre <strong>phpMyAdmin</strong>: <code>localhost/phpmyadmin</code></li>
                <li>Selecciona la base de datos <strong>administracion</strong> en el panel izquierdo.</li>
                <li>Haz clic en la pestaña <strong>Importar</strong>.</li>
                <li>Haz clic en <strong>Seleccionar archivo</strong> y elige el archivo <code>.sql</code> descargado.</li>
                <li>Desplázate al final y haz clic en <strong>Importar</strong>.</li>
                <li>Si aparece error de tabla duplicada: ve a <strong>Operaciones → Eliminar la BD</strong>, créala de nuevo y repite desde el paso 2.</li>
            </ol>
            <div class="tip-guardar">
                💡 Guarda el backup en <strong>al menos 2 lugares distintos</strong>:
                por ejemplo un USB y Google Drive. Un solo respaldo no es suficiente protección.
            </div>
        </div>

        <div class="acciones">
            <a href="../index.php" class="btn btn-secondary">← Volver al inicio</a>
        </div>

    </div>

</div>

<script>
// Pequeña animación en el botón al hacer clic
document.getElementById('btn-descarga').addEventListener('click', function() {
    this.textContent = '⏳ Generando...';
    this.style.background = '#888';
    this.style.pointerEvents = 'none';
    // Restaurar después de 4 segundos (la descarga ya inició)
    setTimeout(() => {
        this.innerHTML = `<svg viewBox="0 0 20 20" fill="none" width="20" height="20">
            <path d="M10 3v10M6 9l4 4 4-4" stroke="currentColor" stroke-width="2"
                  stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M3 15v1a2 2 0 002 2h10a2 2 0 002-2v-1"
                  stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
        </svg> Descargar Backup .SQL`;
        this.style.background = '';
        this.style.pointerEvents = '';
    }, 4000);
});
</script>

</body>
</html>