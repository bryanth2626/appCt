<?php

//Panel de Administración de Catálogos

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

if (!empty($_GET['ok']))
    $success_msg = htmlspecialchars($_GET['ok']);
if (!empty($_GET['error']))
    $error_msg = htmlspecialchars($_GET['error']);

$tab_activa = $_GET['tab'] ?? 'servicios';
$servicios = [];
$formatos = [];
$condiciones = [];

if ($pdo) {
    $servicios = $pdo->query("SELECT * FROM tipo_servicio ORDER BY id_servicios")->fetchAll(PDO::FETCH_ASSOC);
    $formatos = $pdo->query("SELECT * FROM formato ORDER BY id_formato")->fetchAll(PDO::FETCH_ASSOC);
    // Tabla correcta: condiciones | columna: detalle
    $condiciones = $pdo->query("SELECT * FROM condiciones ORDER BY id_condiciones")->fetchAll(PDO::FETCH_ASSOC);
}

function iconoEditar(): string
{
    return '<svg viewBox="0 0 16 16" fill="none">
        <path d="M11.5 2.5l2 2-9 9H2.5v-2l9-9z"
              stroke="currentColor" stroke-width="1.3" stroke-linejoin="round"/>
    </svg>';
}
function iconoBorrar(): string
{
    return '<svg viewBox="0 0 16 16" fill="none">
        <path d="M3 4h10M6 4V3h4v1M5 4v8h6V4H5z"
              stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Administración — ISHUME</title>
    <link href="../css/admin.css" rel="stylesheet" />
</head>

<body>

    <div class="pagina">

        <div class="doc-header">
            <div class="doc-header-logo">
                <img src="../img/logo.png" alt="ISHUME" />
            </div>
            <div class="doc-header-info">
                <h1>Xiomara Tasayco H.</h1>
                <p>Gerenta General &nbsp;|&nbsp; Administración de Catálogos</p>
            </div>
        </div>

        <div class="doc-body">

            <?php if ($success_msg): ?>
                <div class="msg msg-ok"><?= $success_msg ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="msg msg-error"><?= $error_msg ?></div>
            <?php endif; ?>

            <div class="doc-title-row">
                <h2>Gestión de Catálogos del Sistema</h2>
            </div>

            <div class="tabs">
                <button class="tab-btn <?= $tab_activa === 'servicios' ? 'active' : '' ?>"
                    onclick="cambiarTab('servicios', this)">Servicios</button>
                <button class="tab-btn <?= $tab_activa === 'formatos' ? 'active' : '' ?>"
                    onclick="cambiarTab('formatos', this)">Formatos de Entrega</button>
                <button class="tab-btn <?= $tab_activa === 'condiciones' ? 'active' : '' ?>"
                    onclick="cambiarTab('condiciones', this)">Condiciones</button>
            </div>

            <!-- ══ TAB 1 — SERVICIOS ══ -->
            <div id="panel-servicios" class="tab-panel <?= $tab_activa === 'servicios' ? 'active' : '' ?>">
                <div class="seccion-header">
                    <h3>Tipos de Servicio</h3>
                    <button class="btn btn-primary btn-sm" onclick="abrirAgregar('servicio')">+ Agregar</button>
                </div>
                <div class="nota-info">Estos servicios aparecen en el selector al crear un nuevo contrato.</div>
                <table class="tabla-admin">
                    <thead>
                        <tr>
                            <th class="td-id">#</th>
                            <th>Nombre del Servicio</th>
                            <th class="th-acciones">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($servicios)): ?>
                            <tr class="empty-row">
                                <td colspan="3">No hay servicios registrados aún.</td>
                            </tr>
                        <?php else:
                            foreach ($servicios as $s): ?>
                                <tr>
                                    <td class="td-id"><?= $s['id_servicios'] ?></td>
                                    <td><strong><?= htmlspecialchars($s['nombre_servicio']) ?></strong></td>
                                    <td class="td-acciones">
                                        <button class="btn-icon btn-edit" title="Editar" onclick="abrirEditar('servicio',
                                    <?= $s['id_servicios'] ?>,
                                    <?= htmlspecialchars(json_encode($s['nombre_servicio'])) ?>)">
                                            <?= iconoEditar() ?>
                                        </button>
                                        <button class="btn-icon btn-del" title="Eliminar" onclick="abrirBorrar('servicio',
                                    <?= $s['id_servicios'] ?>,
                                    <?= htmlspecialchars(json_encode($s['nombre_servicio'])) ?>)">
                                            <?= iconoBorrar() ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ══ TAB 2 — FORMATOS ══ -->
            <div id="panel-formatos" class="tab-panel <?= $tab_activa === 'formatos' ? 'active' : '' ?>">
                <div class="seccion-header">
                    <h3>Formatos de Entrega</h3>
                    <button class="btn btn-primary btn-sm" onclick="abrirAgregar('formato')">+ Agregar</button>
                </div>
                <div class="nota-info">Los formatos aparecen en la sección de entrega del material. Ej: USB, DVD,
                    Online.</div>
                <table class="tabla-admin">
                    <thead>
                        <tr>
                            <th class="td-id">#</th>
                            <th>Tipo / Nombre</th>
                            <th class="th-acciones">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($formatos)): ?>
                            <tr class="empty-row">
                                <td colspan="3">No hay formatos registrados aún.</td>
                            </tr>
                        <?php else:
                            foreach ($formatos as $f): ?>
                                <tr>
                                    <td class="td-id"><?= $f['id_formato'] ?></td>
                                    <td><strong><?= htmlspecialchars($f['tipo']) ?></strong></td>
                                    <td class="td-acciones">
                                        <button class="btn-icon btn-edit" title="Editar" onclick="abrirEditar('formato',
                                    <?= $f['id_formato'] ?>,
                                    <?= htmlspecialchars(json_encode($f['tipo'])) ?>)">
                                            <?= iconoEditar() ?>
                                        </button>
                                        <button class="btn-icon btn-del" title="Eliminar" onclick="abrirBorrar('formato',
                                    <?= $f['id_formato'] ?>,
                                    <?= htmlspecialchars(json_encode($f['tipo'])) ?>)">
                                            <?= iconoBorrar() ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ══ TAB 3 — CONDICIONES ══ -->
            <div id="panel-condiciones" class="tab-panel <?= $tab_activa === 'condiciones' ? 'active' : '' ?>">
                <div class="seccion-header">
                    <h3>Condiciones del Contrato</h3>
                    <button class="btn btn-primary btn-sm" onclick="abrirAgregar('condicion')">+ Agregar</button>
                </div>
                <div class="nota-info">
                    Al registrar un contrato podrás elegir cuáles de estas condiciones incluir en ese contrato.
                </div>
                <table class="tabla-admin">
                    <thead>
                        <tr>
                            <th class="td-id">#</th>
                            <th>Detalle de la Condición</th>
                            <th class="th-acciones">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($condiciones)): ?>
                            <tr class="empty-row">
                                <td colspan="3">No hay condiciones registradas aún.</td>
                            </tr>
                        <?php else:
                            foreach ($condiciones as $c): ?>
                                <tr>
                                    <td class="td-id"><?= $c['id_condiciones'] ?></td>
                                    <td class="cond-texto"><?= htmlspecialchars($c['detalle']) ?></td>
                                    <td class="td-acciones">
                                        <button class="btn-icon btn-edit" title="Editar" onclick="abrirEditar('condicion',
                                    <?= $c['id_condiciones'] ?>,
                                    <?= htmlspecialchars(json_encode($c['detalle'])) ?>)">
                                            <?= iconoEditar() ?>
                                        </button>
                                        <button class="btn-icon btn-del" title="Eliminar" onclick="abrirBorrar('condicion',
                                    <?= $c['id_condiciones'] ?>,
                                            'esta condición')">
                                            <?= iconoBorrar() ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="acciones">
                <a href="../index.php" class="btn btn-secondary">← Volver al inicio</a>
            </div>

        </div>

    </div>


    <!-- ══ MODAL AGREGAR ══ -->
    <div class="modal-overlay" id="modal-agregar">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="tit-agregar">Agregar</h3>
                <button class="modal-close" onclick="cerrar('modal-agregar')">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="admin_accion.php">
                    <input type="hidden" name="accion" value="agregar" />
                    <input type="hidden" name="tipo" id="ag-tipo" />
                    <input type="hidden" name="tab_retorno" id="ag-tab" />
                    <div id="ag-campos"></div>
                    <div class="modal-footer-btns">
                        <button type="button" class="btn btn-secondary btn-sm"
                            onclick="cerrar('modal-agregar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ MODAL EDITAR ══ -->
    <div class="modal-overlay" id="modal-editar">
        <div class="modal-box">
            <div class="modal-header">
                <h3 id="tit-editar">Editar</h3>
                <button class="modal-close" onclick="cerrar('modal-editar')">×</button>
            </div>
            <div class="modal-body">
                <form method="POST" action="admin_accion.php">
                    <input type="hidden" name="accion" value="editar" />
                    <input type="hidden" name="tipo" id="ed-tipo" />
                    <input type="hidden" name="id" id="ed-id" />
                    <input type="hidden" name="tab_retorno" id="ed-tab" />
                    <div id="ed-campos"></div>
                    <div class="modal-footer-btns">
                        <button type="button" class="btn btn-secondary btn-sm"
                            onclick="cerrar('modal-editar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm">Actualizar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ══ MODAL BORRAR ══ -->
    <div class="modal-overlay" id="modal-borrar">
        <div class="modal-box">
            <div class="modal-header">
                <h3>Confirmar Eliminación</h3>
                <button class="modal-close" onclick="cerrar('modal-borrar')">×</button>
            </div>
            <div class="modal-body">
                <p class="modal-confirm-texto">
                    ¿Estás segura de que deseas eliminar
                    <span class="modal-confirm-nombre" id="del-nombre"></span>?
                </p>
                <p class="aviso-borrado">⚠ Esta acción no se puede deshacer.</p>
                <form method="POST" action="admin_accion.php">
                    <input type="hidden" name="accion" value="borrar" />
                    <input type="hidden" name="tipo" id="del-tipo" />
                    <input type="hidden" name="id" id="del-id" />
                    <input type="hidden" name="tab_retorno" id="del-tab" />
                    <div class="modal-footer-btns">
                        <button type="button" class="btn btn-secondary btn-sm"
                            onclick="cerrar('modal-borrar')">Cancelar</button>
                        <button type="submit" class="btn btn-danger btn-sm">Sí, eliminar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function tabActiva() {
            const p = document.querySelector('.tab-panel.active');
            return p ? p.id.replace('panel-', '') : 'servicios';
        }

        function cambiarTab(nombre, btn) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            document.getElementById('panel-' + nombre).classList.add('active');
            btn.classList.add('active');
        }

        function abrir(id) { document.getElementById(id).classList.add('visible'); }
        function cerrar(id) { document.getElementById(id).classList.remove('visible'); }

        document.querySelectorAll('.modal-overlay').forEach(o => {
            o.addEventListener('click', e => { if (e.target === o) cerrar(o.id); });
        });

        function abrirAgregar(tipo) {
            document.getElementById('ag-tipo').value = tipo;
            document.getElementById('ag-tab').value = tabActiva();
            document.getElementById('tit-agregar').textContent = 'Agregar ' + label(tipo);
            document.getElementById('ag-campos').innerHTML = campos(tipo, null);
            abrir('modal-agregar');
        }

        function abrirEditar(tipo, id, val) {
            document.getElementById('ed-tipo').value = tipo;
            document.getElementById('ed-id').value = id;
            document.getElementById('ed-tab').value = tabActiva();
            document.getElementById('tit-editar').textContent = 'Editar ' + label(tipo);
            document.getElementById('ed-campos').innerHTML = campos(tipo, val);
            abrir('modal-editar');
        }

        function abrirBorrar(tipo, id, nombre) {
            document.getElementById('del-tipo').value = tipo;
            document.getElementById('del-id').value = id;
            document.getElementById('del-tab').value = tabActiva();
            document.getElementById('del-nombre').textContent = nombre;
            abrir('modal-borrar');
        }

        function campos(tipo, val) {
            const v = val ? esc(val) : '';

            if (tipo === 'servicio') {
                return `<div class="campo-modal">
            <label>Nombre del Servicio *</label>
            <input type="text" name="nombre_servicio" value="${v}"
                   required autocomplete="off" maxlength="25"/>
        </div>`;
            }
            if (tipo === 'formato') {
                return `<div class="campo-modal">
            <label>Nombre del Formato *</label>
            <input type="text" name="tipo_formato" value="${v}"
                   required autocomplete="off" maxlength="15"/>
        </div>`;
            }
            if (tipo === 'condicion') {
                // name: detalle_condicion → admin_accion usa $_POST['detalle_condicion']
                return `<div class="campo-modal">
            <label>Detalle de la Condición *</label>
            <textarea name="detalle_condicion" required maxlength="150">${v}</textarea>
        </div>`;
            }
            return '';
        }

        function label(tipo) {
            return { servicio: 'Servicio', formato: 'Formato', condicion: 'Condición' }[tipo] || tipo;
        }

        function esc(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                .replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
    </script>

</body>

</html>