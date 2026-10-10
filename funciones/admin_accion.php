<?php
//Procesa Agregar / Editar / Borrar

require_once '../conexion/conexion.php';
function redirigir(string $tab, string $param, string $mensaje): never
{
    $url = 'admin.php?tab=' . urlencode($tab)
        . '&' . $param . '=' . urlencode($mensaje);
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

$accion = $_POST['accion'] ?? '';
$tipo = $_POST['tipo'] ?? '';
$tab_retorno = $_POST['tab_retorno'] ?? 'servicios';

//  AGREGAR

if ($accion === 'agregar') {

    if ($tipo === 'servicio') {
        $nombre = trim($_POST['nombre_servicio'] ?? '');
        if ($nombre === '')
            redirigir($tab_retorno, 'error', 'El nombre del servicio es obligatorio.');
        try {
            $pdo->prepare("INSERT INTO tipo_servicio (nombre_servicio) VALUES (?)")
                ->execute([$nombre]);
            redirigir($tab_retorno, 'ok', "Servicio \"$nombre\" agregado correctamente.");
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }

    if ($tipo === 'formato') {
        $tipo_val = trim($_POST['tipo_formato'] ?? '');
        if ($tipo_val === '')
            redirigir($tab_retorno, 'error', 'El nombre del formato es obligatorio.');
        try {
            $pdo->prepare("INSERT INTO formato (tipo) VALUES (?)")
                ->execute([$tipo_val]);
            redirigir($tab_retorno, 'ok', "Formato \"$tipo_val\" agregado correctamente.");
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }

    if ($tipo === 'condicion') {
        // Tabla: condiciones  |  Columna: detalle
        $detalle = trim($_POST['detalle_condicion'] ?? '');
        if ($detalle === '')
            redirigir($tab_retorno, 'error', 'El texto de la condición es obligatorio.');
        try {
            $pdo->prepare("INSERT INTO condiciones (detalle) VALUES (?)")
                ->execute([$detalle]);
            redirigir($tab_retorno, 'ok', 'Condición agregada correctamente.');
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error al insertar en condiciones: ' . $e->getMessage());
        }
    }
}

//  EDITAR

if ($accion === 'editar') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0)
        redirigir($tab_retorno, 'error', 'ID inválido.');

    if ($tipo === 'servicio') {
        $nombre = trim($_POST['nombre_servicio'] ?? '');
        if ($nombre === '')
            redirigir($tab_retorno, 'error', 'El nombre del servicio es obligatorio.');
        try {
            $pdo->prepare("UPDATE tipo_servicio SET nombre_servicio = ? WHERE id_servicios = ?")
                ->execute([$nombre, $id]);
            redirigir($tab_retorno, 'ok', 'Servicio actualizado correctamente.');
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }

    if ($tipo === 'formato') {
        $tipo_val = trim($_POST['tipo_formato'] ?? '');
        if ($tipo_val === '')
            redirigir($tab_retorno, 'error', 'El nombre del formato es obligatorio.');
        try {
            $pdo->prepare("UPDATE formato SET tipo = ? WHERE id_formato = ?")
                ->execute([$tipo_val, $id]);
            redirigir($tab_retorno, 'ok', 'Formato actualizado correctamente.');
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }

    if ($tipo === 'condicion') {
        $detalle = trim($_POST['detalle_condicion'] ?? '');
        if ($detalle === '')
            redirigir($tab_retorno, 'error', 'El texto de la condición es obligatorio.');
        try {
            $pdo->prepare("UPDATE condiciones SET detalle = ? WHERE id_condiciones = ?")
                ->execute([$detalle, $id]);
            redirigir($tab_retorno, 'ok', 'Condición actualizada correctamente.');
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }
}

//  BORRAR

if ($accion === 'borrar') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0)
        redirigir($tab_retorno, 'error', 'ID inválido.');

    if ($tipo === 'servicio') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM detalle_contrato WHERE servicio_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            redirigir(
                $tab_retorno,
                'error',
                'No se puede eliminar: este servicio está en uso en uno o más contratos.'
            );
        }
        try {
            $pdo->prepare("DELETE FROM tipo_servicio WHERE id_servicios = ?")->execute([$id]);
            redirigir($tab_retorno, 'ok', 'Servicio eliminado correctamente.');
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }

    if ($tipo === 'formato') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM entrega WHERE formato_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            redirigir(
                $tab_retorno,
                'error',
                'No se puede eliminar: este formato está en uso en una o más entregas.'
            );
        }
        try {
            $pdo->prepare("DELETE FROM formato WHERE id_formato = ?")->execute([$id]);
            redirigir($tab_retorno, 'ok', 'Formato eliminado correctamente.');
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }

    if ($tipo === 'condicion') {
        // Borrar primero las relaciones en contrato_condicion
        $pdo->prepare("DELETE FROM contrato_condicion WHERE condicion_id = ?")->execute([$id]);
        try {
            $pdo->prepare("DELETE FROM condiciones WHERE id_condiciones = ?")->execute([$id]);
            redirigir($tab_retorno, 'ok', 'Condición eliminada correctamente.');
        } catch (PDOException $e) {
            redirigir($tab_retorno, 'error', 'Error: ' . $e->getMessage());
        }
    }
}

redirigir($tab_retorno, 'error', 'Acción no reconocida.');
