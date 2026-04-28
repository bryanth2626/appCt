<?php
//  Página principal ISHUME

$archivoFecha = __DIR__ . '/funciones/ultimo_backup.txt';
$ultimoBackup = file_exists($archivoFecha) ? trim(file_get_contents($archivoFecha)) : null;
$diasDesdeBackup = null;
$nivelAlerta = null; // null = no mostrar | 'aviso' | 'urgente'

if ($ultimoBackup) {
  $diff = (new DateTime())->diff(new DateTime($ultimoBackup));
  $diasDesdeBackup = $diff->days;
  if ($diasDesdeBackup >= 14)
    $nivelAlerta = 'urgente';
  elseif ($diasDesdeBackup >= 7)
    $nivelAlerta = 'aviso';
} else {
  $nivelAlerta = 'urgente'; // nunca se ha hecho backup
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>ISHUME — Sistema de Contratos</title>
  <link href="css/estilo.css" rel="stylesheet" />
</head>

<body>

  <!-- HEADER -->
  <header>
    <div class="header-brand">
      <div class="header-brand-name">ISHUME</div>
      <div class="header-brand-sub">PRODUCTORA AUDIOVISUAL</div>
    </div>
    <div class="header-divider"></div>
    <div class="header-manager">
      <span class="header-manager-name">Xiomara Tasayco H.</span>
      <span class="header-manager-cargo">Gerenta General</span>
    </div>
  </header>

  <!-- MAIN -->
  <main>

    <div class="page-title">
      <h2>Sistema Interno</h2>
      <h3>Gestión de Contratos</h3>
    </div>

    <div class="underline-bar"></div>

    <div class="cards">

      <!-- 01 Nuevo Contrato -->
      <a href="funciones/registrar.php" class="card">
        <div class="card-head">
          <span class="card-head-num">01</span>
          <svg viewBox="0 0 28 28" fill="none">
            <path d="M7 3h9l8 8v14a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z" stroke="#fff" stroke-width="1.5" />
            <path d="M16 3v8h8" stroke="rgba(255,255,255,0.6)" stroke-width="1.5" />
            <line x1="10" y1="16" x2="18" y2="16" stroke="#fff" stroke-width="2" stroke-linecap="round" />
            <line x1="14" y1="12" x2="14" y2="20" stroke="#fff" stroke-width="2" stroke-linecap="round" />
          </svg>
        </div>
        <div class="card-body">
          <h2>Nuevo<br />Contrato</h2>
          <p>Registra un nuevo contrato con los datos del cliente, evento, servicios y condiciones de pago.</p>
          <span class="card-cta">Crear contrato →</span>
        </div>
      </a>

      <!-- 02 Lista -->
      <a href="funciones/lista.php" class="card">
        <div class="card-head">
          <span class="card-head-num">02</span>
          <svg viewBox="0 0 28 28" fill="none">
            <rect x="4" y="5" width="20" height="5" rx="1" stroke="#fff" stroke-width="1.5" />
            <rect x="4" y="12" width="20" height="5" rx="1" stroke="rgba(255,255,255,0.65)" stroke-width="1.5" />
            <rect x="4" y="19" width="20" height="5" rx="1" stroke="rgba(255,255,255,0.3)" stroke-width="1.5" />
            <line x1="7" y1="7.5" x2="11" y2="7.5" stroke="#fff" stroke-width="1.4" stroke-linecap="round" />
            <line x1="7" y1="14.5" x2="11" y2="14.5" stroke="rgba(255,255,255,0.65)" stroke-width="1.4"
              stroke-linecap="round" />
          </svg>
        </div>
        <div class="card-body">
          <h2>Lista de<br />Contratos</h2>
          <p>Consulta todos los contratos registrados. Puedes visualizar, imprimir. No se permite edición.
          </p>
          <span class="card-cta">Ver contratos →</span>
        </div>
      </a>

      <!-- 03 Post-Contrato -->
      <a href="funciones/edicion.php" class="card card-edicion">
        <div class="card-head card-head-edicion">
          <span class="card-head-num">03</span>
          <svg viewBox="0 0 28 28" fill="none">
            <path d="M6 4h11l7 7v13a1 1 0 01-1 1H6a1 1 0 01-1-1V5a1 1 0 011-1z" stroke="rgba(255,255,255,0.5)"
              stroke-width="1.4" />
            <path d="M17 4v7h7" stroke="rgba(255,255,255,0.3)" stroke-width="1.4" />
            <path d="M10 20l1.5-1.5 3 3L13 23l-3-.5z" stroke="#fff" stroke-width="1.3" stroke-linejoin="round" />
            <path d="M14.5 15.5l3 3-2.5 2.5-3-3z" stroke="#fff" stroke-width="1.3" stroke-linejoin="round" />
            <path d="M17.5 15.5l1-1a1 1 0 011.4 1.4l-1 1" stroke="#fff" stroke-width="1.3" stroke-linecap="round" />
          </svg>
        </div>
        <div class="card-body">
          <h2>Gestión<br />Post-Contrato</h2>
          <p>Modifica datos del contrato ya registrado: pagos, adelantos, estado y otros ajustes necesarios.</p>
          <span class="card-cta card-cta-edicion">Gestionar →</span>
        </div>
      </a>

      <!-- 04 Admin -->
      <a href="funciones/admin.php" class="card card-admin">
        <div class="card-head card-head-dark">
          <span class="card-head-num">04</span>
          <svg viewBox="0 0 28 28" fill="none">
            <circle cx="14" cy="14" r="3.5" stroke="#fff" stroke-width="1.5" />
            <path d="M14 3v3M14 22v3M3 14h3M22 14h3" stroke="rgba(255,255,255,0.5)" stroke-width="1.5"
              stroke-linecap="round" />
            <path d="M6.22 6.22l2.12 2.12M19.66 19.66l2.12 2.12M6.22 21.78l2.12-2.12M19.66 8.34l2.12-2.12"
              stroke="rgba(255,255,255,0.35)" stroke-width="1.5" stroke-linecap="round" />
          </svg>
        </div>
        <div class="card-body">
          <h2>Administrar<br />Catálogos</h2>
          <p>Agrega, edita o elimina servicios, formatos de entrega y condiciones del contrato.</p>
          <span class="card-cta card-cta-dark">Gestionar →</span>
        </div>
      </a>

      <!-- 05 Backup -->
      <a href="funciones/backup.php" class="card card-backup"
        style="grid-column:1/-1;max-width:340px;justify-self:center;">
        <div class="card-head card-head-backup">
          <span class="card-head-num">05</span>
          <svg viewBox="0 0 28 28" fill="none">
            <rect x="4" y="6" width="20" height="16" rx="2" stroke="#fff" stroke-width="1.4" />
            <path d="M4 11h20" stroke="rgba(255,255,255,0.4)" stroke-width="1.4" />
            <path d="M14 15v5M11 18l3 2 3-2" stroke="#fff" stroke-width="1.5" stroke-linecap="round"
              stroke-linejoin="round" />
          </svg>
        </div>
        <div class="card-body">
          <h2>Respaldo<br />de Datos</h2>
          <p>
            <?php if ($nivelAlerta === 'urgente'): ?>
              <span style="color:#c0392b;font-weight:700;">
                🚨
                <?= $ultimoBackup ? "Hace {$diasDesdeBackup} días sin backup. ¡Hazlo ahora!" : "Nunca se ha hecho backup. ¡Los datos están en riesgo!" ?>
              </span>
            <?php elseif ($nivelAlerta === 'aviso'): ?>
              <span style="color:#e65100;font-weight:700;">
                ⚠ Hace <?= $diasDesdeBackup ?> días sin backup. Se recomienda hacerlo.
              </span>
            <?php else: ?>
              Descarga el respaldo completo de la base de datos.
              <?= $diasDesdeBackup !== null ? "Último backup hace {$diasDesdeBackup} día" . ($diasDesdeBackup !== 1 ? 's' : '') . "." : "" ?>
            <?php endif; ?>
          </p>
          <span class="card-cta card-cta-backup">Hacer respaldo →</span>
        </div>
      </a>

    </div>

  </main>

 

  <!-- ALERTA FLOTANTE (solo aparece si hace 7+ días sin backup) -->
  <?php if ($nivelAlerta): ?>
    <div class="alerta-backup <?= $nivelAlerta ?>" id="alerta-bk">
      <span class="ab-emoji"><?= $nivelAlerta === 'urgente' ? '🚨' : '⚠️' ?></span>
      <div class="ab-cuerpo">
        <strong>
          <?= $nivelAlerta === 'urgente' ? '¡Backup urgente!' : 'Backup recomendado' ?>
        </strong>
        <span>
          <?php if (!$ultimoBackup): ?>
            Nunca se ha guardado un respaldo de los datos.
          <?php else: ?>
            Hace <strong><?= $diasDesdeBackup ?> días</strong> sin guardar un respaldo.
          <?php endif; ?>
        </span>
        <br>
        <a class="ab-link" href="funciones/backup.php">Ir a Backup →</a>
      </div>
      <button class="ab-cerrar" onclick="this.closest('.alerta-backup').remove()" title="Cerrar">×</button>
    </div>
  <?php endif; ?>

</body>

</html>