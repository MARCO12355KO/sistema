<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

// ==================== VERIFICACIÓN DE SESIÓN ====================
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($nombre_usuario, 0, 1));
$rol = strtolower($_SESSION["role"] ?? 'registro');

// ==================== MANEJO DE ACCIONES AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        switch ($_POST['ajax_action']) {
            
            // ===== HABILITAR AL MINISTERIO =====
            case 'habilitar_ministerio':
                $ru = trim($_POST['ru_estudiante'] ?? '');
                if (empty($ru)) {
                    echo json_encode(['success' => false, 'message' => 'RU del estudiante es requerido']);
                    exit;
                }
                
                // Verificar que el estudiante está aprobado
                $stmt = $pdo->prepare("
                    SELECT pd.estado, pd.nota 
                    FROM pre_defensas pd 
                    JOIN estudiantes e ON pd.id_estudiante = e.id_persona 
                    WHERE e.ru = ? AND pd.estado = 'APROBADA'
                    ORDER BY pd.fecha DESC LIMIT 1
                ");
                $stmt->execute([$ru]);
                $pre = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$pre) {
                    echo json_encode(['success' => false, 'message' => 'El estudiante no tiene pre-defensa aprobada']);
                    exit;
                }
                
                // Insertar o actualizar habilitación
                $stmt = $pdo->prepare("
                    INSERT INTO habilitacion_ministerio (ru_estudiante, esta_habilitado, fecha_validacion)
                    VALUES (?, true, NOW())
                    ON CONFLICT (ru_estudiante) 
                    DO UPDATE SET esta_habilitado = true, fecha_validacion = NOW()
                ");
                $stmt->execute([$ru]);
                
                echo json_encode(['success' => true, 'message' => 'Estudiante habilitado exitosamente al Ministerio']);
                exit;
            
            // ===== REGISTRAR PRE-DEFENSA =====
            case 'registrar_predefensa':
                $id_estudiante = (int)($_POST['id_estudiante'] ?? 0);
                $id_proyecto = (int)($_POST['id_proyecto'] ?? 0);
                $modalidad = trim($_POST['modalidad_titulacion'] ?? '');
                $tema = trim($_POST['tema'] ?? '');
                $fecha = trim($_POST['fecha'] ?? '');
                $hora = trim($_POST['hora'] ?? '');
                $id_aula = (int)($_POST['id_aula'] ?? 0);
                $gestion = trim($_POST['gestion'] ?? date('Y'));
                $id_presidente = (int)($_POST['id_presidente'] ?? 0);
                $id_secretario = (int)($_POST['id_secretario'] ?? 0);
                
                // Validaciones
                $errores = [];
                if ($id_estudiante <= 0) $errores[] = 'Estudiante no válido';
                if ($id_proyecto <= 0) $errores[] = 'Proyecto no válido';
                if (empty($modalidad)) $errores[] = 'Modalidad de titulación requerida';
                if ($modalidad !== 'EXAMEN_GRADO' && empty($tema)) $errores[] = 'El tema es requerido para esta modalidad';
                if (empty($fecha)) $errores[] = 'Fecha requerida';
                if (empty($hora)) $errores[] = 'Hora requerida';
                if ($id_aula <= 0) $errores[] = 'Aula requerida';
                if ($id_presidente <= 0) $errores[] = 'Presidente del tribunal requerido';
                if ($id_secretario <= 0) $errores[] = 'Secretario del tribunal requerido';
                
                // Validar fecha no sea pasada
                if (!empty($fecha) && strtotime($fecha) < strtotime(date('Y-m-d'))) {
                    $errores[] = 'No se pueden registrar fechas pasadas';
                }
                
                // Validar que presidente y secretario sean diferentes
                if ($id_presidente === $id_secretario) {
                    $errores[] = 'Presidente y Secretario deben ser diferentes';
                }
                
                // Obtener id_tutor del proyecto
                $stmt = $pdo->prepare("SELECT id_tutor FROM proyectos WHERE id_proyecto = ?");
                $stmt->execute([$id_proyecto]);
                $tutor_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $id_tutor = $tutor_row ? (int)$tutor_row['id_tutor'] : 0;
                
                // Validar que tribunal no incluya al tutor
                if ($id_tutor > 0 && ($id_presidente === $id_tutor || $id_secretario === $id_tutor)) {
                    $errores[] = 'Los miembros del tribunal no pueden ser el mismo tutor';
                }
                
                // Verificar que no exista ya una pre-defensa para este estudiante en esta gestión
                $stmt = $pdo->prepare("SELECT id_pre_defensa FROM pre_defensas WHERE id_estudiante = ? AND gestion = ?");
                $stmt->execute([$id_estudiante, $gestion]);
                if ($stmt->fetch()) {
                    $errores[] = 'Ya existe una pre-defensa registrada para este estudiante en esta gestión';
                }
                
                if (!empty($errores)) {
                    echo json_encode(['success' => false, 'message' => implode('. ', $errores)]);
                    exit;
                }
                
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO pre_defensas (
                        id_estudiante, gestion, fecha, hora, estado, id_tutor, 
                        id_proyecto, id_aula, modalidad_titulacion, tema,
                        id_presidente, id_secretario
                    ) VALUES (?, ?, ?, ?, 'PENDIENTE', ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $id_estudiante, $gestion, $fecha, $hora, $id_tutor,
                    $id_proyecto, $id_aula, $modalidad, 
                    $modalidad === 'EXAMEN_GRADO' ? null : $tema,
                    $id_presidente, $id_secretario
                ]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Pre-defensa registrada exitosamente']);
                exit;
            
            // ===== PROGRAMAR DEFENSA FORMAL =====
            case 'programar_defensa':
                $id_pre_defensa = (int)($_POST['id_pre_defensa'] ?? 0);
                $fecha_defensa = trim($_POST['fecha_defensa'] ?? '');
                $hora_defensa = trim($_POST['hora_defensa'] ?? '');
                $id_aula = (int)($_POST['id_aula_defensa'] ?? 0);
                
                $errores = [];
                if ($id_pre_defensa <= 0) $errores[] = 'Pre-defensa no válida';
                if (empty($fecha_defensa)) $errores[] = 'Fecha de defensa requerida';
                if (empty($hora_defensa)) $errores[] = 'Hora de defensa requerida';
                if ($id_aula <= 0) $errores[] = 'Aula requerida';
                
                // Validar fecha no pasada
                if (!empty($fecha_defensa) && strtotime($fecha_defensa) < strtotime(date('Y-m-d'))) {
                    $errores[] = 'No se pueden programar fechas pasadas';
                }
                
                // Obtener datos de la pre-defensa y habilitación
                $stmt = $pdo->prepare("
                    SELECT pd.id_estudiante, pd.id_proyecto, e.ru,
                           hm.fecha_validacion, hm.esta_habilitado
                    FROM pre_defensas pd
                    JOIN estudiantes e ON pd.id_estudiante = e.id_persona
                    LEFT JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante
                    WHERE pd.id_pre_defensa = ? AND pd.estado = 'APROBADA'
                ");
                $stmt->execute([$id_pre_defensa]);
                $pd_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$pd_data) {
                    $errores[] = 'Pre-defensa no encontrada o no está aprobada';
                } elseif (!$pd_data['esta_habilitado']) {
                    $errores[] = 'El estudiante no está habilitado por el Ministerio';
                } else {
                    // Validar 30 días desde habilitación
                    $fecha_hab = new DateTime($pd_data['fecha_validacion']);
                    $fecha_def = new DateTime($fecha_defensa);
                    $diff = $fecha_hab->diff($fecha_def);
                    if ($diff->days < 30) {
                        $dias_restantes = 30 - $diff->days;
                        $errores[] = "Deben pasar 30 días desde la habilitación. Faltan {$dias_restantes} días";
                    }
                }
                
                // Verificar que no exista ya defensa formal
                if ($id_pre_defensa > 0) {
                    $stmt = $pdo->prepare("SELECT id_defensa FROM defensa_formal WHERE id_pre_defensa = ?");
                    $stmt->execute([$id_pre_defensa]);
                    if ($stmt->fetch()) {
                        $errores[] = 'Ya existe una defensa formal programada para esta pre-defensa';
                    }
                }
                
                if (!empty($errores)) {
                    echo json_encode(['success' => false, 'message' => implode('. ', $errores)]);
                    exit;
                }
                
                $pdo->beginTransaction();
                
                $stmt = $pdo->prepare("
                    INSERT INTO defensa_formal (
                        id_pre_defensa, id_estudiante, id_proyecto, 
                        fecha_defensa, hora, id_aula, estado
                    ) VALUES (?, ?, ?, ?, ?, ?, 'PROGRAMADA')
                ");
                $stmt->execute([
                    $id_pre_defensa,
                    $pd_data['id_estudiante'],
                    $pd_data['id_proyecto'],
                    $fecha_defensa,
                    $hora_defensa,
                    $id_aula
                ]);
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Defensa formal programada exitosamente']);
                exit;
            
            default:
                echo json_encode(['success' => false, 'message' => 'Acción no válida']);
                exit;
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error AJAX: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error de base de datos: ' . $e->getMessage()]);
        exit;
    }
}

// ==================== PESTAÑA ACTIVA ====================
$tab = $_GET['tab'] ?? 'listado';
$filtro_carrera = $_GET['carrera'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';
$busqueda = trim($_GET['q'] ?? '');

// ==================== CARGAR DATOS SEGÚN PESTAÑA ====================
try {
    // Datos comunes: carreras y aulas
    $carreras = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera")->fetchAll(PDO::FETCH_ASSOC);
    $aulas = $pdo->query("SELECT id_aula, nombre_aula FROM aulas ORDER BY nombre_aula")->fetchAll(PDO::FETCH_ASSOC);
    
    // Docentes para tribunal (es_tribunal = true)
    $docentes_tribunal = $pdo->query("
        SELECT d.id_persona, 
               p.primer_nombre || ' ' || COALESCE(p.segundo_nombre || ' ', '') || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido, '') as nombre_completo
        FROM docentes d
        JOIN personas p ON d.id_persona = p.id_persona
        WHERE d.es_tribunal = true
        ORDER BY p.primer_apellido, p.primer_nombre
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    switch ($tab) {
        
        // ===== TAB 1: LISTADO GENERAL =====
        case 'listado':
            $where = [];
            $params = [];
            
            if (!empty($filtro_carrera)) {
                $where[] = "c.id_carrera = ?";
                $params[] = (int)$filtro_carrera;
            }
            if (!empty($filtro_estado)) {
                $where[] = "COALESCE(pd.estado, 'SIN_PREDEFENSA') = ?";
                $params[] = $filtro_estado;
            }
            if (!empty($busqueda)) {
                $where[] = "(LOWER(p.primer_nombre || ' ' || p.primer_apellido) LIKE LOWER(?) OR p.ci LIKE ? OR e.ru LIKE ?)";
                $params[] = "%{$busqueda}%";
                $params[] = "%{$busqueda}%";
                $params[] = "%{$busqueda}%";
            }
            
            $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
            
            $stmt = $pdo->prepare("
                SELECT e.id_persona as id_estudiante, p.ci, e.ru,
                       p.primer_nombre, COALESCE(p.segundo_nombre, '') as segundo_nombre,
                       p.primer_apellido, COALESCE(p.segundo_apellido, '') as segundo_apellido,
                       c.nombre_carrera, c.id_carrera,
                       pd.id_pre_defensa, pd.estado as estado_predefensa, pd.nota,
                       pd.modalidad_titulacion, pd.tema, pd.fecha, pd.hora,
                       COALESCE(pd.estado, 'SIN_PREDEFENSA') as estado_display,
                       hm.esta_habilitado,
                       -- Tutor
                       pt.primer_nombre || ' ' || pt.primer_apellido as tutor_nombre,
                       -- Presidente
                       pp.primer_nombre || ' ' || pp.primer_apellido as presidente_nombre,
                       -- Secretario  
                       ps.primer_nombre || ' ' || ps.primer_apellido as secretario_nombre
                FROM estudiantes e
                JOIN personas p ON e.id_persona = p.id_persona
                LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
                LEFT JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
                LEFT JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante
                LEFT JOIN personas pt ON pd.id_tutor = pt.id_persona
                LEFT JOIN personas pp ON pd.id_presidente = pp.id_persona
                LEFT JOIN personas ps ON pd.id_secretario = ps.id_persona
                {$where_sql}
                ORDER BY p.primer_apellido ASC, p.primer_nombre ASC
            ");
            $stmt->execute($params);
            $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
        
        // ===== TAB 2: HABILITACIÓN MINISTERIO =====
        case 'habilitacion':
            $stmt = $pdo->query("
                SELECT e.id_persona as id_estudiante, p.ci, e.ru,
                       p.primer_nombre || ' ' || COALESCE(p.segundo_nombre || ' ', '') || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido, '') as nombre_completo,
                       c.nombre_carrera,
                       pd.nota, pd.fecha as fecha_predefensa, pd.modalidad_titulacion,
                       pd.tema, pd.id_pre_defensa,
                       hm.esta_habilitado, hm.fecha_validacion,
                       pt.primer_nombre || ' ' || pt.primer_apellido as tutor_nombre
                FROM estudiantes e
                JOIN personas p ON e.id_persona = p.id_persona
                JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
                LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
                LEFT JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante
                LEFT JOIN personas pt ON pd.id_tutor = pt.id_persona
                WHERE pd.estado = 'APROBADA'
                ORDER BY COALESCE(hm.esta_habilitado, false) ASC, pd.fecha DESC
            ");
            $habilitaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
        
        // ===== TAB 3: DEFENSA FORMAL =====
        case 'defensa':
            $stmt = $pdo->query("
                SELECT e.id_persona as id_estudiante, p.ci, e.ru,
                       p.primer_nombre || ' ' || COALESCE(p.segundo_nombre || ' ', '') || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido, '') as nombre_completo,
                       c.nombre_carrera,
                       pd.id_pre_defensa, pd.nota as nota_predefensa, pd.modalidad_titulacion,
                       pd.tema,
                       hm.fecha_validacion,
                       -- Tutor completo
                       pt.primer_nombre || ' ' || COALESCE(pt.segundo_nombre || ' ', '') || pt.primer_apellido || ' ' || COALESCE(pt.segundo_apellido, '') as tutor_completo,
                       -- Presidente completo
                       pp.primer_nombre || ' ' || COALESCE(pp.segundo_nombre || ' ', '') || pp.primer_apellido || ' ' || COALESCE(pp.segundo_apellido, '') as presidente_completo,
                       -- Secretario completo
                       ps.primer_nombre || ' ' || COALESCE(ps.segundo_nombre || ' ', '') || ps.primer_apellido || ' ' || COALESCE(ps.segundo_apellido, '') as secretario_completo,
                       -- Defensa formal datos
                       df.id_defensa, df.fecha_defensa, df.hora as hora_defensa,
                       df.estado as estado_defensa, df.nota_final,
                       a.nombre_aula as aula_defensa,
                       -- Calcular si puede programar (30 días)
                       CASE 
                           WHEN hm.fecha_validacion IS NOT NULL 
                           THEN (CURRENT_DATE - hm.fecha_validacion::date) >= 30 
                           ELSE false 
                       END as puede_programar,
                       CASE 
                           WHEN hm.fecha_validacion IS NOT NULL 
                           THEN 30 - (CURRENT_DATE - hm.fecha_validacion::date)
                           ELSE 30 
                       END as dias_restantes
                FROM estudiantes e
                JOIN personas p ON e.id_persona = p.id_persona
                JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
                JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante AND hm.esta_habilitado = true
                LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
                LEFT JOIN personas pt ON pd.id_tutor = pt.id_persona
                LEFT JOIN personas pp ON pd.id_presidente = pp.id_persona
                LEFT JOIN personas ps ON pd.id_secretario = ps.id_persona
                LEFT JOIN defensa_formal df ON pd.id_pre_defensa = df.id_pre_defensa
                LEFT JOIN aulas a ON df.id_aula = a.id_aula
                WHERE pd.estado = 'APROBADA'
                ORDER BY df.fecha_defensa DESC NULLS FIRST, p.primer_apellido ASC
            ");
            $defensas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
        
        // ===== TAB 4: NUEVO REGISTRO PRE-DEFENSA =====
        case 'registro':
            // Estudiantes con tutor (proyecto) pero sin pre-defensa
            $stmt = $pdo->query("
                SELECT e.id_persona as id_estudiante, p.ci, e.ru,
                       p.primer_nombre || ' ' || COALESCE(p.segundo_nombre || ' ', '') || p.primer_apellido || ' ' || COALESCE(p.segundo_apellido, '') as nombre_completo,
                       c.nombre_carrera, c.id_carrera,
                       pr.id_proyecto, pr.titulo_proyecto,
                       pt.primer_nombre || ' ' || COALESCE(pt.segundo_nombre || ' ', '') || pt.primer_apellido || ' ' || COALESCE(pt.segundo_apellido, '') as tutor_completo,
                       pr.id_tutor
                FROM estudiantes e
                JOIN personas p ON e.id_persona = p.id_persona
                JOIN proyectos pr ON e.id_persona = pr.id_estudiante AND pr.id_tutor IS NOT NULL
                JOIN personas pt ON pr.id_tutor = pt.id_persona
                LEFT JOIN carreras c ON e.id_carrera = c.id_carrera
                LEFT JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
                WHERE pd.id_pre_defensa IS NULL
                ORDER BY p.primer_apellido ASC, p.primer_nombre ASC
            ");
            $sin_predefensa = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    // Contadores para badges
    $count_listado = $pdo->query("SELECT COUNT(*) FROM estudiantes")->fetchColumn();
    $count_habilitacion = $pdo->query("SELECT COUNT(*) FROM pre_defensas WHERE estado = 'APROBADA'")->fetchColumn();
    $count_defensa = $pdo->query("
        SELECT COUNT(*) FROM pre_defensas pd 
        JOIN estudiantes e ON pd.id_estudiante = e.id_persona
        JOIN habilitacion_ministerio hm ON e.ru = hm.ru_estudiante AND hm.esta_habilitado = true
        WHERE pd.estado = 'APROBADA'
    ")->fetchColumn();
    $count_registro = $pdo->query("
        SELECT COUNT(*) FROM estudiantes e
        JOIN proyectos pr ON e.id_persona = pr.id_estudiante AND pr.id_tutor IS NOT NULL
        LEFT JOIN pre_defensas pd ON e.id_persona = pd.id_estudiante
        WHERE pd.id_pre_defensa IS NULL
    ")->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error en gestion_estudiantes: " . $e->getMessage());
    die("Error al cargar datos: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Estudiantes | UNIOR</title>
    <link rel="icon" type="image/png" href="../assets/img/logo_unior1.png">
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <style>
        :root {
            --glass: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(255, 255, 255, 0.9);
            --accent: #6366f1;
            --accent-light: #818cf8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --bg-main: #f8fafc;
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.06);
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-closed: 85px;
            --sidebar-open: 280px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-main);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.1) 0, transparent 50%),
                radial-gradient(at 100% 100%, rgba(129, 140, 248, 0.08) 0, transparent 50%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        /* ===== SIDEBAR ===== */
        .sidebar {
            background: var(--glass);
            backdrop-filter: blur(24px);
            border: 1.5px solid var(--glass-border);
            z-index: 2000;
            transition: all 0.5s var(--ease);
            box-shadow: var(--shadow-md);
        }

        @media (min-width: 992px) {
            .sidebar {
                width: var(--sidebar-closed);
                height: 95vh;
                position: fixed;
                left: 24px;
                top: 2.5vh;
                border-radius: 32px;
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 32px 0;
            }
            .sidebar:hover { 
                width: var(--sidebar-open); 
                align-items: flex-start; 
                padding: 32px 24px;
                background: rgba(255, 255, 255, 0.85);
            }
            .sidebar:hover .nav-item-ae span { opacity: 1; display: inline; transform: translateX(0); }
            .sidebar:hover .logo-text { opacity: 1; width: auto; margin-left: 12px; }
            .main-stage { 
                margin-left: calc(var(--sidebar-closed) + 48px); 
                padding: 48px 80px 48px 48px;
                width: calc(100% - var(--sidebar-closed) - 48px);
            }
            .mobile-top-bar { display: none; }
        }

        @media (max-width: 991px) {
            .sidebar {
                position: fixed;
                bottom: 20px;
                left: 20px;
                right: 20px;
                height: 75px;
                border-radius: 28px;
                flex-direction: row;
                justify-content: space-around;
                display: flex;
                align-items: center;
                padding: 0 20px;
            }
            .sidebar .logo-aesthetic, .sidebar .nav-item-ae span, .sidebar .mt-auto, .sidebar .logo-text { display: none; }
            .sidebar nav { display: flex; width: 100%; justify-content: space-around; }
            .nav-item-ae { width: auto; padding: 14px; margin-bottom: 0; }
            .main-stage { padding: 90px 20px 120px 20px; }
            .mobile-top-bar {
                position: fixed; top: 0; left: 0; right: 0; height: 70px;
                background: var(--glass); backdrop-filter: blur(15px);
                display: flex; align-items: center; justify-content: space-between;
                padding: 0 20px; z-index: 1500;
                border-bottom: 1.5px solid var(--glass-border);
                box-shadow: var(--shadow-md);
            }
        }

        .logo-aesthetic { display: flex; align-items: center; gap: 12px; margin-bottom: 40px; padding: 0 12px; }
        .logo-text {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-weight: 800; font-size: 1.4rem; color: var(--accent);
            opacity: 0; width: 0; overflow: hidden; white-space: nowrap;
            transition: all 0.4s var(--ease);
        }
        .nav-item-ae {
            width: 100%; display: flex; align-items: center; justify-content: center;
            padding: 16px 18px; margin-bottom: 6px; border-radius: 20px;
            color: var(--text-muted); text-decoration: none; transition: all 0.3s var(--ease);
        }
        @media (min-width: 992px) { .sidebar:hover .nav-item-ae { justify-content: flex-start; } }
        .nav-item-ae i { font-size: 1.25rem; min-width: 50px; text-align: center; }
        .nav-item-ae span { display: none; opacity: 0; font-weight: 600; font-size: 0.95rem; margin-left: 10px; transition: all 0.3s var(--ease); white-space: nowrap; transform: translateX(-10px); }
        .nav-item-ae:hover { background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.05)); color: var(--accent); transform: translateX(4px); }
        .nav-item-ae.active { background: linear-gradient(135deg, var(--accent), var(--accent-light)); color: white; box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3); }

        .user-badge {
            background: var(--glass); backdrop-filter: blur(10px);
            padding: 8px 10px 8px 24px; border-radius: 100px;
            border: 1.5px solid var(--glass-border);
            display: flex; align-items: center; gap: 12px;
            box-shadow: var(--shadow-md);
        }
        .user-avatar {
            width: 38px; height: 38px; border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1rem;
        }

        /* ===== HEADER ===== */
        .section-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: clamp(2.5rem, 8vw, 4.5rem);
            font-weight: 300; letter-spacing: -2px;
            margin-bottom: 12px; line-height: 0.95;
            background: linear-gradient(135deg, var(--text-dark) 0%, var(--text-muted) 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }
        .section-accent {
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
        }

        /* ===== TABS ===== */
        .tab-nav {
            display: flex; gap: 8px; margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .tab-btn {
            background: var(--glass); backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border); border-radius: 16px;
            padding: 12px 24px; font-weight: 600; font-size: 0.9rem;
            color: var(--text-muted); cursor: pointer; transition: all 0.3s var(--ease);
            text-decoration: none; display: inline-flex; align-items: center; gap: 10px;
        }
        .tab-btn:hover { color: var(--accent); border-color: var(--accent); transform: translateY(-2px); }
        .tab-btn.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white; border-color: transparent;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
        }
        .tab-badge {
            background: rgba(255,255,255,0.3); border-radius: 100px;
            padding: 2px 10px; font-size: 0.75rem; font-weight: 700;
        }
        .tab-btn.active .tab-badge { background: rgba(255,255,255,0.25); }

        /* ===== CARDS Y TABLAS ===== */
        .glass-card {
            background: var(--glass); backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border); border-radius: 24px;
            padding: 28px; box-shadow: var(--shadow-md); margin-bottom: 24px;
        }
        .table-wrapper { overflow-x: auto; border-radius: 16px; }
        .table-custom {
            width: 100%; border-collapse: separate; border-spacing: 0;
            font-size: 0.88rem;
        }
        .table-custom thead th {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white; padding: 14px 16px; font-weight: 700;
            text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px;
            position: sticky; top: 0; z-index: 10;
        }
        .table-custom thead th:first-child { border-radius: 12px 0 0 0; }
        .table-custom thead th:last-child { border-radius: 0 12px 0 0; }
        .table-custom tbody tr { transition: all 0.2s var(--ease); }
        .table-custom tbody tr:hover { background: rgba(99, 102, 241, 0.04); }
        .table-custom tbody td {
            padding: 12px 16px; border-bottom: 1px solid rgba(0,0,0,0.04);
            vertical-align: middle;
        }

        /* ===== BADGES DE ESTADO ===== */
        .badge-estado {
            padding: 5px 14px; border-radius: 100px; font-size: 0.72rem;
            font-weight: 700; letter-spacing: 0.5px; display: inline-block;
        }
        .badge-aprobada { background: rgba(16, 185, 129, 0.12); color: #059669; }
        .badge-reprobada { background: rgba(239, 68, 68, 0.12); color: #dc2626; }
        .badge-pendiente { background: rgba(245, 158, 11, 0.12); color: #d97706; }
        .badge-sin { background: rgba(100, 116, 139, 0.12); color: #475569; }
        .badge-habilitado { background: rgba(6, 182, 212, 0.12); color: #0891b2; }
        .badge-programada { background: rgba(99, 102, 241, 0.12); color: #4f46e5; }

        /* ===== BOTONES ===== */
        .btn-glass {
            background: var(--glass); backdrop-filter: blur(8px);
            border: 1.5px solid var(--glass-border); border-radius: 12px;
            padding: 8px 16px; font-weight: 600; font-size: 0.82rem;
            color: var(--text-dark); cursor: pointer; transition: all 0.3s var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-glass:hover { border-color: var(--accent); color: var(--accent); transform: translateY(-1px); }
        .btn-accent {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white; border: none; border-radius: 14px;
            padding: 10px 24px; font-weight: 700; font-size: 0.88rem;
            cursor: pointer; transition: all 0.3s var(--ease);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-accent:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4); }
        .btn-success-custom {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white; border: none; border-radius: 14px;
            padding: 10px 24px; font-weight: 700; cursor: pointer;
            transition: all 0.3s var(--ease);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-success-custom:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 185, 129, 0.4); }
        .btn-warning-custom {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white; border: none; border-radius: 12px;
            padding: 7px 16px; font-weight: 600; font-size: 0.82rem;
            cursor: pointer; transition: all 0.3s var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-warning-custom:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(245, 158, 11, 0.3); }
        .btn-info-custom {
            background: linear-gradient(135deg, var(--info), #0891b2);
            color: white; border: none; border-radius: 12px;
            padding: 7px 16px; font-weight: 600; font-size: 0.82rem;
            cursor: pointer; transition: all 0.3s var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-info-custom:hover { transform: translateY(-1px); }
        .btn-danger-custom {
            background: linear-gradient(135deg, var(--danger), #dc2626);
            color: white; border: none; border-radius: 12px;
            padding: 7px 16px; font-weight: 600; font-size: 0.82rem;
            cursor: pointer; transition: all 0.3s var(--ease);
            display: inline-flex; align-items: center; gap: 6px;
        }

        /* ===== FILTROS ===== */
        .filter-bar {
            display: flex; gap: 12px; margin-bottom: 24px; flex-wrap: wrap; align-items: center;
        }
        .filter-select, .filter-input {
            background: white; border: 1.5px solid rgba(0,0,0,0.08);
            border-radius: 14px; padding: 10px 18px; font-size: 0.88rem;
            font-weight: 500; color: var(--text-dark); transition: all 0.3s var(--ease);
            outline: none;
        }
        .filter-select:focus, .filter-input:focus {
            border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        /* ===== MODAL ===== */
        .modal-content {
            border: none; border-radius: 28px;
            box-shadow: 0 25px 60px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white; border: none; padding: 24px 28px;
        }
        .modal-header .modal-title { font-family: 'Bricolage Grotesque', sans-serif; font-weight: 800; }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        .modal-body { padding: 28px; }
        .modal-footer { border: none; padding: 16px 28px 24px; }
        .form-label-custom {
            font-weight: 700; font-size: 0.82rem; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;
        }
        .form-control-custom {
            border: 1.5px solid rgba(0,0,0,0.08); border-radius: 14px;
            padding: 12px 16px; font-size: 0.92rem; transition: all 0.3s var(--ease);
        }
        .form-control-custom:focus {
            border-color: var(--accent); box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        /* ===== CHECKLIST MODAL ===== */
        .checklist-item {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 18px; border-radius: 14px;
            border: 1.5px solid rgba(0,0,0,0.06);
            margin-bottom: 10px; transition: all 0.3s var(--ease);
        }
        .checklist-item:hover { border-color: var(--accent); background: rgba(99,102,241,0.03); }
        .checklist-item input[type="checkbox"] {
            width: 22px; height: 22px; accent-color: var(--accent);
            cursor: pointer; border-radius: 6px;
        }
        .checklist-item label { cursor: pointer; font-weight: 500; font-size: 0.92rem; }

        /* ===== TOAST ===== */
        .toast-custom {
            position: fixed; top: 24px; right: 24px; z-index: 9999;
            padding: 16px 24px; border-radius: 16px; color: white;
            font-weight: 600; box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            transform: translateX(120%); transition: all 0.4s var(--ease);
            max-width: 400px;
        }
        .toast-custom.show { transform: translateX(0); }
        .toast-success { background: linear-gradient(135deg, var(--success), #059669); }
        .toast-error { background: linear-gradient(135deg, var(--danger), #dc2626); }

        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center; padding: 60px 20px; color: var(--text-muted);
        }
        .empty-state i { font-size: 3rem; margin-bottom: 16px; opacity: 0.3; }
        .empty-state p { font-size: 1rem; font-weight: 500; }

        /* ===== ANIMATIONS ===== */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .reveal { animation: fadeInUp 0.8s var(--ease) forwards; opacity: 0; }
    </style>
</head>
<body>

<!-- ===== TOAST NOTIFICATION ===== -->
<div id="toast" class="toast-custom">
    <span id="toast-message"></span>
</div>

<!-- ===== MOBILE TOP BAR ===== -->
<div class="mobile-top-bar">
    <div class="d-flex align-items-center">
        <img src="../assets/img/logo_unior1.png" height="40" alt="Logo">
        <span class="ms-2 fw-800" style="font-family: 'Bricolage Grotesque'; color: var(--accent);">UNIOR</span>
    </div>
    <div class="user-badge">
        <div class="user-avatar"><?= $inicial ?></div>
    </div>
</div>

<!-- ===== SIDEBAR ===== -->
<aside class="sidebar">
    <div class="logo-aesthetic d-none d-lg-flex">
        <img src="../assets/img/logo_unior1.png" width="48" alt="Logo">
        <span class="logo-text">UNIOR</span>
    </div>
    <nav>
        <a href="menu.php" class="nav-item-ae">
            <i class="fas fa-home-alt"></i> <span>Menú</span>
        </a>
        <a href="gestion_estudiantes.php" class="nav-item-ae active">
            <i class="fas fa-users-rays"></i> <span>Estudiantes</span>
        </a>
        <a href="reportes.php" class="nav-item-ae">
            <i class="fas fa-chart-line"></i> <span>Reportes</span>
        </a>
        <a href="logs.php" class="nav-item-ae">
            <i class="fas fa-clipboard-list"></i> <span>Logs</span>
        </a>
    </nav>
    <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
        <i class="fas fa-power-off"></i> <span>Salir</span>
    </a>
</aside>

<!-- ===== MAIN CONTENT ===== -->
<main class="main-stage">
    <!-- User Badge Desktop -->
    <div class="d-none d-lg-flex justify-content-end mb-4 reveal">
        <div class="user-badge">
            <div class="text-end">
                <div class="fw-bold" style="font-size: 0.9rem;"><?= $nombre_usuario ?></div>
                <div style="font-size: 0.7rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; font-weight: 600;">
                    <?= strtoupper($rol) ?>
                </div>
            </div>
            <div class="user-avatar"><?= $inicial ?></div>
        </div>
    </div>

    <!-- Header -->
    <header class="reveal" style="animation-delay: 0.1s;">
        <p class="text-muted fw-600 mb-2" style="letter-spacing: 2px; font-size: 0.7rem; text-transform: uppercase;">
            <i class="fas fa-graduation-cap me-2"></i>Gestión Académica
        </p>
        <h1 class="section-title">
            Gestión de <span class="section-accent">Estudiantes.</span>
        </h1>
    </header>

    <!-- Tab Navigation -->
    <div class="tab-nav reveal" style="animation-delay: 0.2s;">
        <a href="?tab=listado" class="tab-btn <?= $tab === 'listado' ? 'active' : '' ?>">
            <i class="fas fa-list"></i> Listado General
            <span class="tab-badge"><?= $count_listado ?></span>
        </a>
        <a href="?tab=habilitacion" class="tab-btn <?= $tab === 'habilitacion' ? 'active' : '' ?>">
            <i class="fas fa-clipboard-check"></i> Habilitación Ministerio
            <span class="tab-badge"><?= $count_habilitacion ?></span>
        </a>
        <a href="?tab=defensa" class="tab-btn <?= $tab === 'defensa' ? 'active' : '' ?>">
            <i class="fas fa-award"></i> Defensa Formal
            <span class="tab-badge"><?= $count_defensa ?></span>
        </a>
        <a href="?tab=registro" class="tab-btn <?= $tab === 'registro' ? 'active' : '' ?>">
            <i class="fas fa-plus-circle"></i> Nuevo Registro
            <span class="tab-badge"><?= $count_registro ?></span>
        </a>
    </div>

    <!-- ================================================================ -->
    <!-- TAB 1: LISTADO GENERAL -->
    <!-- ================================================================ -->
    <?php if ($tab === 'listado'): ?>
    <div class="glass-card reveal" style="animation-delay: 0.3s;">
        <form method="GET" class="filter-bar">
            <input type="hidden" name="tab" value="listado">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-filter" style="color: var(--accent);"></i>
                <span class="fw-700" style="font-size: 0.85rem;">Filtros:</span>
            </div>
            <select name="carrera" class="filter-select">
                <option value="">Todas las carreras</option>
                <?php foreach ($carreras as $c): ?>
                    <option value="<?= $c['id_carrera'] ?>" <?= $filtro_carrera == $c['id_carrera'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['nombre_carrera']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <select name="estado" class="filter-select">
                <option value="">Todos los estados</option>
                <option value="APROBADA" <?= $filtro_estado === 'APROBADA' ? 'selected' : '' ?>>Aprobada</option>
                <option value="REPROBADA" <?= $filtro_estado === 'REPROBADA' ? 'selected' : '' ?>>Reprobada</option>
                <option value="PENDIENTE" <?= $filtro_estado === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                <option value="SIN_PREDEFENSA" <?= $filtro_estado === 'SIN_PREDEFENSA' ? 'selected' : '' ?>>Sin Pre-defensa</option>
            </select>
            <input type="text" name="q" class="filter-input" placeholder="Buscar por nombre, CI o RU..." 
                   value="<?= htmlspecialchars($busqueda) ?>" style="min-width: 240px;">
            <button type="submit" class="btn-accent" style="padding: 10px 20px;">
                <i class="fas fa-search"></i> Filtrar
            </button>
            <?php if (!empty($filtro_carrera) || !empty($filtro_estado) || !empty($busqueda)): ?>
                <a href="?tab=listado" class="btn-glass" style="color: var(--danger);">
                    <i class="fas fa-times"></i> Limpiar
                </a>
            <?php endif; ?>
        </form>

        <div class="table-wrapper" style="max-height: 600px; overflow-y: auto;">
            <?php if (empty($estudiantes)): ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <p>No se encontraron estudiantes con los filtros seleccionados</p>
                </div>
            <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Estudiante</th>
                            <th>CI</th>
                            <th>RU</th>
                            <th>Carrera</th>
                            <th>Modalidad</th>
                            <th>Estado Pre-defensa</th>
                            <th>Nota</th>
                            <th>Tutor</th>
                            <th>Tribunal</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($estudiantes as $i => $est): ?>
                            <tr>
                                <td style="font-weight: 700; color: var(--text-muted);"><?= $i + 1 ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($est['primer_apellido'] . ' ' . $est['segundo_apellido']) ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars($est['primer_nombre'] . ' ' . $est['segundo_nombre']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($est['ci']) ?></td>
                                <td><strong><?= htmlspecialchars($est['ru']) ?></strong></td>
                                <td>
                                    <span style="font-size: 0.82rem; font-weight: 500;">
                                        <?= htmlspecialchars($est['nombre_carrera'] ?? 'N/A') ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.82rem;">
                                    <?php
                                    $mod_labels = [
                                        'EXAMEN_GRADO' => 'Examen de Grado',
                                        'PROYECTO_GRADO' => 'Proyecto de Grado',
                                        'TESIS' => 'Tesis de Grado',
                                        'TRABAJO_DIRIGIDO' => 'Trabajo Dirigido'
                                    ];
                                    echo $mod_labels[$est['modalidad_titulacion'] ?? ''] ?? '<span style="color:var(--text-muted)">—</span>';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $estado_class = match($est['estado_display']) {
                                        'APROBADA' => 'badge-aprobada',
                                        'REPROBADA' => 'badge-reprobada',
                                        'PENDIENTE' => 'badge-pendiente',
                                        default => 'badge-sin'
                                    };
                                    $estado_label = match($est['estado_display']) {
                                        'APROBADA' => 'APROBADA',
                                        'REPROBADA' => 'REPROBADA',
                                        'PENDIENTE' => 'PENDIENTE',
                                        default => 'SIN PRE-DEFENSA'
                                    };
                                    ?>
                                    <span class="badge-estado <?= $estado_class ?>"><?= $estado_label ?></span>
                                    <?php if ($est['esta_habilitado']): ?>
                                        <br><span class="badge-estado badge-habilitado" style="margin-top: 4px;">
                                            <i class="fas fa-check-circle"></i> HABILITADO
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($est['nota'] !== null): ?>
                                        <span style="font-weight: 800; font-size: 1.1rem; color: <?= (float)$est['nota'] >= 41 ? 'var(--success)' : 'var(--danger)' ?>;">
                                            <?= $est['nota'] ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.82rem;"><?= htmlspecialchars($est['tutor_nombre'] ?? '—') ?></td>
                                <td style="font-size: 0.78rem;">
                                    <?php if ($est['presidente_nombre']): ?>
                                        <div><strong>P:</strong> <?= htmlspecialchars($est['presidente_nombre']) ?></div>
                                        <div><strong>S:</strong> <?= htmlspecialchars($est['secretario_nombre'] ?? '—') ?></div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">Sin tribunal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($est['estado_display'] === 'APROBADA' && !$est['esta_habilitado']): ?>
                                        <button class="btn-info-custom" onclick="abrirModalHabilitar('<?= $est['ru'] ?>', '<?= htmlspecialchars($est['primer_apellido'] . ' ' . $est['primer_nombre']) ?>')">
                                            <i class="fas fa-clipboard-check"></i> Habilitar
                                        </button>
                                    <?php elseif ($est['estado_display'] === 'APROBADA' && $est['id_pre_defensa']): ?>
                                        <?php
                                        $es_gastronomia = stripos($est['nombre_carrera'] ?? '', 'GASTRONOM') !== false;
                                        $nombre_est_js = htmlspecialchars(addslashes($est['primer_apellido'] . ' ' . $est['primer_nombre']), ENT_QUOTES);
                                        if (!$es_gastronomia): ?>
                                            <button class="btn-warning-custom" style="font-size: 0.78rem; padding: 5px 12px;"
                                                    onclick="confirmarGenerarDoc(<?= $est['id_pre_defensa'] ?>, 'interna', '<?= $nombre_est_js ?>')">
                                                <i class="fas fa-file-alt"></i> Nota Int.
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div class="mt-3" style="font-size: 0.82rem; color: var(--text-muted); font-weight: 500;">
            <i class="fas fa-info-circle me-1"></i> Mostrando <?= count($estudiantes) ?> estudiantes
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- TAB 2: HABILITACIÓN MINISTERIO -->
    <!-- ================================================================ -->
    <?php if ($tab === 'habilitacion'): ?>
    <div class="glass-card reveal" style="animation-delay: 0.3s;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-700 m-0">
                <i class="fas fa-clipboard-check me-2" style="color: var(--info);"></i>
                Estudiantes Aprobados — Habilitación al Ministerio
            </h5>
        </div>

        <div class="table-wrapper" style="max-height: 600px; overflow-y: auto;">
            <?php if (empty($habilitaciones)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No hay estudiantes con pre-defensa aprobada</p>
                </div>
            <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Estudiante</th>
                            <th>RU</th>
                            <th>Carrera</th>
                            <th>Modalidad</th>
                            <th>Nota</th>
                            <th>Fecha Pre-defensa</th>
                            <th>Tutor</th>
                            <th>Estado Ministerio</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($habilitaciones as $i => $hab): ?>
                            <tr>
                                <td style="font-weight: 700; color: var(--text-muted);"><?= $i + 1 ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($hab['nombre_completo']) ?></td>
                                <td><strong><?= htmlspecialchars($hab['ru']) ?></strong></td>
                                <td style="font-size: 0.85rem;"><?= htmlspecialchars($hab['nombre_carrera'] ?? 'N/A') ?></td>
                                <td style="font-size: 0.82rem;">
                                    <?= $mod_labels[$hab['modalidad_titulacion'] ?? ''] ?? '—' ?>
                                </td>
                                <td>
                                    <span style="font-weight: 800; font-size: 1.1rem; color: var(--success);">
                                        <?= $hab['nota'] ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.85rem;"><?= $hab['fecha_predefensa'] ?></td>
                                <td style="font-size: 0.85rem;"><?= htmlspecialchars($hab['tutor_nombre'] ?? '—') ?></td>
                                <td>
                                    <?php if ($hab['esta_habilitado']): ?>
                                        <span class="badge-estado badge-habilitado">
                                            <i class="fas fa-check-circle"></i> HABILITADO
                                        </span>
                                        <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 4px;">
                                            <?= date('d/m/Y H:i', strtotime($hab['fecha_validacion'])) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge-estado badge-pendiente">PENDIENTE</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!$hab['esta_habilitado']): ?>
                                        <button class="btn-success-custom" style="font-size: 0.82rem; padding: 8px 16px;" 
                                                onclick="abrirModalHabilitar('<?= $hab['ru'] ?>', '<?= htmlspecialchars(addslashes($hab['nombre_completo'])) ?>')">
                                            <i class="fas fa-user-check"></i> Habilitar
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--success); font-weight: 600; font-size: 0.82rem;">
                                            <i class="fas fa-check-double"></i> Completado
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- TAB 3: DEFENSA FORMAL -->
    <!-- ================================================================ -->
    <?php if ($tab === 'defensa'): ?>
    <div class="glass-card reveal" style="animation-delay: 0.3s;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-700 m-0">
                <i class="fas fa-award me-2" style="color: var(--accent);"></i>
                Defensa Formal — Estudiantes Habilitados
            </h5>
        </div>

        <div class="table-wrapper" style="max-height: 650px; overflow-y: auto;">
            <?php if (empty($defensas)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No hay estudiantes habilitados para defensa formal</p>
                </div>
            <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Estudiante</th>
                            <th>Carrera</th>
                            <th>Tutor</th>
                            <th>Presidente</th>
                            <th>Secretario</th>
                            <th>Nota Pre-def.</th>
                            <th>Fecha Defensa</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($defensas as $i => $def): ?>
                            <tr>
                                <td style="font-weight: 700; color: var(--text-muted);"><?= $i + 1 ?></td>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($def['nombre_completo']) ?></div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">RU: <?= htmlspecialchars($def['ru']) ?></div>
                                </td>
                                <td style="font-size: 0.82rem;"><?= htmlspecialchars($def['nombre_carrera'] ?? 'N/A') ?></td>
                                <td style="font-size: 0.82rem; font-weight: 500;"><?= htmlspecialchars($def['tutor_completo'] ?? '—') ?></td>
                                <td style="font-size: 0.82rem; font-weight: 500;"><?= htmlspecialchars($def['presidente_completo'] ?? '—') ?></td>
                                <td style="font-size: 0.82rem; font-weight: 500;"><?= htmlspecialchars($def['secretario_completo'] ?? '—') ?></td>
                                <td>
                                    <span style="font-weight: 800; color: var(--success);"><?= $def['nota_predefensa'] ?></span>
                                </td>
                                <td>
                                    <?php if ($def['fecha_defensa']): ?>
                                        <div style="font-weight: 600;"><?= date('d/m/Y', strtotime($def['fecha_defensa'])) ?></div>
                                        <div style="font-size: 0.78rem; color: var(--text-muted);"><?= substr($def['hora_defensa'], 0, 5) ?> — <?= htmlspecialchars($def['aula_defensa'] ?? '') ?></div>
                                    <?php else: ?>
                                        <?php if ($def['puede_programar']): ?>
                                            <span class="badge-estado badge-aprobada">DISPONIBLE</span>
                                        <?php else: ?>
                                            <span class="badge-estado badge-pendiente" title="Faltan <?= max(0, (int)$def['dias_restantes']) ?> días">
                                                <i class="fas fa-hourglass-half"></i> <?= max(0, (int)$def['dias_restantes']) ?> días restantes
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($def['estado_defensa']): ?>
                                        <span class="badge-estado badge-programada">
                                            <i class="fas fa-calendar-check"></i> <?= $def['estado_defensa'] ?>
                                        </span>
                                        <?php
                                        $es_gastronomia_def = stripos($def['nombre_carrera'] ?? '', 'GASTRONOM') !== false;
                                        ?>
                                        <div class="d-flex flex-column gap-1 mt-2">
                                            <?php if (!$es_gastronomia_def): ?>
                                                <button class="btn-warning-custom" style="font-size: 0.76rem; padding: 5px 12px;"
                                                        onclick="confirmarGenerarDoc(<?= $def['id_pre_defensa'] ?>, 'interna', '<?= htmlspecialchars(addslashes($def['nombre_completo'])) ?>')">
                                                    <i class="fas fa-file-alt"></i> Nota Interna
                                                </button>
                                                <button class="btn-info-custom" style="font-size: 0.76rem; padding: 5px 12px;"
                                                        onclick="confirmarGenerarDoc(<?= $def['id_pre_defensa'] ?>, 'externa_uto', '<?= htmlspecialchars(addslashes($def['nombre_completo'])) ?>')">
                                                    <i class="fas fa-university"></i> Nota Ext. UTO
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn-danger-custom" style="font-size: 0.76rem; padding: 5px 12px; background: linear-gradient(135deg, #8b5cf6, #7c3aed);"
                                                    onclick="confirmarGenerarDoc(<?= $def['id_pre_defensa'] ?>, 'externa_fed', '<?= htmlspecialchars(addslashes($def['nombre_completo'])) ?>')">
                                                <i class="fas fa-building-columns"></i> Nota Ext. Federación
                                            </button>
                                        </div>
                                    <?php elseif ($def['puede_programar']): ?>
                                        <button class="btn-accent" style="font-size: 0.82rem; padding: 8px 16px;"
                                                onclick="abrirModalDefensa(<?= $def['id_pre_defensa'] ?>, '<?= htmlspecialchars(addslashes($def['nombre_completo'])) ?>', '<?= $def['fecha_validacion'] ?>')">
                                            <i class="fas fa-calendar-plus"></i> Programar
                                        </button>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">
                                            <i class="fas fa-lock"></i> Esperar 30 días
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================ -->
    <!-- TAB 4: NUEVO REGISTRO PRE-DEFENSA -->
    <!-- ================================================================ -->
    <?php if ($tab === 'registro'): ?>
    <div class="glass-card reveal" style="animation-delay: 0.3s;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="fw-700 m-0">
                <i class="fas fa-plus-circle me-2" style="color: var(--success);"></i>
                Nuevo Registro de Pre-Defensa
            </h5>
            <p class="m-0" style="font-size: 0.82rem; color: var(--text-muted);">
                <i class="fas fa-info-circle"></i> Solo estudiantes con tutor y sin pre-defensa registrada
            </p>
        </div>

        <div class="table-wrapper" style="max-height: 600px; overflow-y: auto;">
            <?php if (empty($sin_predefensa)): ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <p>Todos los estudiantes con tutor ya tienen pre-defensa registrada</p>
                </div>
            <?php else: ?>
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Estudiante</th>
                            <th>CI</th>
                            <th>RU</th>
                            <th>Carrera</th>
                            <th>Proyecto</th>
                            <th>Tutor</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sin_predefensa as $i => $sp): ?>
                            <tr>
                                <td style="font-weight: 700; color: var(--text-muted);"><?= $i + 1 ?></td>
                                <td style="font-weight: 600;"><?= htmlspecialchars($sp['nombre_completo']) ?></td>
                                <td><?= htmlspecialchars($sp['ci']) ?></td>
                                <td><strong><?= htmlspecialchars($sp['ru']) ?></strong></td>
                                <td style="font-size: 0.85rem;"><?= htmlspecialchars($sp['nombre_carrera'] ?? 'N/A') ?></td>
                                <td style="font-size: 0.82rem; max-width: 200px;" title="<?= htmlspecialchars($sp['titulo_proyecto']) ?>">
                                    <?= htmlspecialchars(mb_strimwidth($sp['titulo_proyecto'], 0, 50, '...')) ?>
                                </td>
                                <td style="font-size: 0.85rem; font-weight: 500;"><?= htmlspecialchars($sp['tutor_completo']) ?></td>
                                <td>
                                    <button class="btn-success-custom" style="font-size: 0.82rem; padding: 8px 16px;"
                                            onclick='abrirModalRegistro(<?= json_encode([
                                                "id_estudiante" => $sp["id_estudiante"],
                                                "id_proyecto" => $sp["id_proyecto"],
                                                "nombre" => $sp["nombre_completo"],
                                                "carrera" => $sp["nombre_carrera"] ?? "",
                                                "proyecto" => $sp["titulo_proyecto"],
                                                "tutor" => $sp["tutor_completo"],
                                                "id_tutor" => $sp["id_tutor"]
                                            ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                        <i class="fas fa-plus"></i> Registrar Pre-defensa
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</main>

<!-- ================================================================ -->
<!-- MODAL: HABILITACIÓN AL MINISTERIO -->
<!-- ================================================================ -->
<div class="modal fade" id="modalHabilitar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--info), #0891b2);">
                <h5 class="modal-title"><i class="fas fa-clipboard-check me-2"></i> Habilitación al Ministerio</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 text-center">
                    <div style="width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, rgba(6,182,212,0.1), rgba(6,182,212,0.2)); margin: 0 auto 12px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-user-graduate" style="font-size: 1.5rem; color: var(--info);"></i>
                    </div>
                    <h6 class="fw-700" id="hab-nombre-estudiante"></h6>
                </div>
                
                <p class="text-muted mb-3" style="font-size: 0.88rem;">
                    Verifique los siguientes requisitos antes de habilitar al estudiante:
                </p>

                <div class="checklist-item">
                    <input type="checkbox" id="check1" onchange="verificarChecklist()">
                    <label for="check1">Pre-defensa aprobada con nota igual o superior a 41 puntos</label>
                </div>
                <div class="checklist-item">
                    <input type="checkbox" id="check2" onchange="verificarChecklist()">
                    <label for="check2">Documentación completa y verificada</label>
                </div>
                <div class="checklist-item">
                    <input type="checkbox" id="check3" onchange="verificarChecklist()">
                    <label for="check3">Comprobante de pago del proceso de titulación</label>
                </div>
                <div class="checklist-item">
                    <input type="checkbox" id="check4" onchange="verificarChecklist()">
                    <label for="check4">Solicitud formal del estudiante presentada</label>
                </div>
                <div class="checklist-item">
                    <input type="checkbox" id="check5" onchange="verificarChecklist()">
                    <label for="check5">Datos del estudiante validados (CI, RU correctos)</label>
                </div>
                
                <input type="hidden" id="hab-ru-estudiante">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnConfirmarHabilitar" class="btn-success-custom" disabled onclick="confirmarHabilitar()">
                    <i class="fas fa-check-circle"></i> Confirmar Habilitación
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- MODAL: REGISTRAR PRE-DEFENSA -->
<!-- ================================================================ -->
<div class="modal fade" id="modalRegistro" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--success), #059669);">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> Registrar Pre-Defensa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="p-3" style="background: rgba(16,185,129,0.05); border-radius: 14px; border: 1px solid rgba(16,185,129,0.15);">
                            <small class="form-label-custom d-block">Estudiante</small>
                            <strong id="reg-nombre"></strong>
                            <div class="text-muted" style="font-size: 0.8rem;" id="reg-carrera"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3" style="background: rgba(99,102,241,0.05); border-radius: 14px; border: 1px solid rgba(99,102,241,0.15);">
                            <small class="form-label-custom d-block">Tutor</small>
                            <strong id="reg-tutor"></strong>
                            <div class="text-muted" style="font-size: 0.8rem;" id="reg-proyecto"></div>
                        </div>
                    </div>
                </div>

                <input type="hidden" id="reg-id-estudiante">
                <input type="hidden" id="reg-id-proyecto">
                <input type="hidden" id="reg-id-tutor">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-custom">Modalidad de Titulación *</label>
                        <select id="reg-modalidad" class="form-control form-control-custom" onchange="toggleTema()">
                            <option value="">Seleccione modalidad...</option>
                            <option value="EXAMEN_GRADO">Examen de Grado</option>
                            <option value="PROYECTO_GRADO">Proyecto de Grado</option>
                            <option value="TESIS">Tesis de Grado</option>
                            <option value="TRABAJO_DIRIGIDO">Trabajo Dirigido</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom">Gestión</label>
                        <input type="text" id="reg-gestion" class="form-control form-control-custom" value="<?= date('Y') ?>" readonly>
                    </div>
                    <div class="col-12" id="tema-container">
                        <label class="form-label-custom">Tema *</label>
                        <textarea id="reg-tema" class="form-control form-control-custom" rows="2" placeholder="Ingrese el tema de titulación..."></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-custom">Fecha *</label>
                        <input type="date" id="reg-fecha" class="form-control form-control-custom" min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-custom">Hora *</label>
                        <input type="time" id="reg-hora" class="form-control form-control-custom">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label-custom">Aula *</label>
                        <select id="reg-aula" class="form-control form-control-custom">
                            <option value="">Seleccione...</option>
                            <?php foreach ($aulas as $a): ?>
                                <option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom">Presidente del Tribunal *</label>
                        <select id="reg-presidente" class="form-control form-control-custom">
                            <option value="">Seleccione presidente...</option>
                            <?php foreach ($docentes_tribunal as $dt): ?>
                                <option value="<?= $dt['id_persona'] ?>"><?= htmlspecialchars($dt['nombre_completo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom">Secretario del Tribunal *</label>
                        <select id="reg-secretario" class="form-control form-control-custom">
                            <option value="">Seleccione secretario...</option>
                            <?php foreach ($docentes_tribunal as $dt): ?>
                                <option value="<?= $dt['id_persona'] ?>"><?= htmlspecialchars($dt['nombre_completo']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-success-custom" onclick="guardarPreDefensa()">
                    <i class="fas fa-save"></i> Registrar Pre-Defensa
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- MODAL: PROGRAMAR DEFENSA FORMAL -->
<!-- ================================================================ -->
<div class="modal fade" id="modalDefensa" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i> Programar Defensa Formal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <div style="width: 60px; height: 60px; border-radius: 50%; background: linear-gradient(135deg, rgba(99,102,241,0.1), rgba(99,102,241,0.2)); margin: 0 auto 12px; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-award" style="font-size: 1.5rem; color: var(--accent);"></i>
                    </div>
                    <h6 class="fw-700" id="def-nombre-estudiante"></h6>
                </div>

                <input type="hidden" id="def-id-pre-defensa">
                <input type="hidden" id="def-fecha-min">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label-custom">Fecha de Defensa *</label>
                        <input type="date" id="def-fecha" class="form-control form-control-custom">
                        <small class="text-muted" id="def-fecha-hint" style="font-size: 0.75rem;"></small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-custom">Hora *</label>
                        <input type="time" id="def-hora" class="form-control form-control-custom">
                    </div>
                    <div class="col-12">
                        <label class="form-label-custom">Aula *</label>
                        <select id="def-aula" class="form-control form-control-custom">
                            <option value="">Seleccione aula...</option>
                            <?php foreach ($aulas as $a): ?>
                                <option value="<?= $a['id_aula'] ?>"><?= htmlspecialchars($a['nombre_aula']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-accent" onclick="guardarDefensa()">
                    <i class="fas fa-calendar-check"></i> Programar Defensa
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ================================================================ -->
<!-- MODAL: CONFIRMAR GENERACIÓN DE DOCUMENTO -->
<!-- ================================================================ -->
<div class="modal fade" id="modalGenerarDoc" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="modalDocHeader">
                <h5 class="modal-title" id="modalDocTitle"><i class="fas fa-file-alt me-2"></i> Generar Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="modalDocIcon" style="width: 70px; height: 70px; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-exclamation-triangle" style="font-size: 2rem;"></i>
                </div>
                <h6 class="fw-700 mb-1" id="modalDocSubtitle"></h6>
                <p class="text-muted mb-2" style="font-size: 0.85rem;" id="modalDocEstudiante"></p>
                <p class="text-muted" style="font-size: 0.88rem;" id="modalDocDesc"></p>
                <input type="hidden" id="doc-id-pre-defensa">
                <input type="hidden" id="doc-tipo">
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn-glass" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnGenerarDoc" onclick="ejecutarGenerarDoc()" style="padding: 10px 24px;">
                    <i class="fas fa-file-download"></i> Generar Documento
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== SCRIPTS ===== -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ==================== TOAST ====================
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    const msg = document.getElementById('toast-message');
    msg.textContent = message;
    toast.className = 'toast-custom toast-' + type + ' show';
    setTimeout(() => { toast.classList.remove('show'); }, 4000);
}

// ==================== MODAL HABILITAR ====================
function abrirModalHabilitar(ru, nombre) {
    document.getElementById('hab-ru-estudiante').value = ru;
    document.getElementById('hab-nombre-estudiante').textContent = nombre;
    // Reset checkboxes
    for (let i = 1; i <= 5; i++) {
        document.getElementById('check' + i).checked = false;
    }
    document.getElementById('btnConfirmarHabilitar').disabled = true;
    new bootstrap.Modal(document.getElementById('modalHabilitar')).show();
}

function verificarChecklist() {
    let todosChecked = true;
    for (let i = 1; i <= 5; i++) {
        if (!document.getElementById('check' + i).checked) {
            todosChecked = false;
            break;
        }
    }
    document.getElementById('btnConfirmarHabilitar').disabled = !todosChecked;
}

function confirmarHabilitar() {
    const ru = document.getElementById('hab-ru-estudiante').value;
    const btn = document.getElementById('btnConfirmarHabilitar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';

    const formData = new FormData();
    formData.append('ajax_action', 'habilitar_ministerio');
    formData.append('ru_estudiante', ru);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('modalHabilitar')).hide();
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(data.message, 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirmar Habilitación';
            }
        })
        .catch(err => {
            showToast('Error de conexión', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check-circle"></i> Confirmar Habilitación';
        });
}

// ==================== MODAL REGISTRO PRE-DEFENSA ====================
function abrirModalRegistro(data) {
    document.getElementById('reg-id-estudiante').value = data.id_estudiante;
    document.getElementById('reg-id-proyecto').value = data.id_proyecto;
    document.getElementById('reg-id-tutor').value = data.id_tutor;
    document.getElementById('reg-nombre').textContent = data.nombre;
    document.getElementById('reg-carrera').textContent = data.carrera;
    document.getElementById('reg-tutor').textContent = data.tutor;
    document.getElementById('reg-proyecto').textContent = data.proyecto.length > 60 ? data.proyecto.substring(0, 60) + '...' : data.proyecto;
    // Reset form
    document.getElementById('reg-modalidad').value = '';
    document.getElementById('reg-tema').value = '';
    document.getElementById('reg-fecha').value = '';
    document.getElementById('reg-hora').value = '';
    document.getElementById('reg-aula').value = '';
    document.getElementById('reg-presidente').value = '';
    document.getElementById('reg-secretario').value = '';
    toggleTema();
    new bootstrap.Modal(document.getElementById('modalRegistro')).show();
}

function toggleTema() {
    const mod = document.getElementById('reg-modalidad').value;
    const container = document.getElementById('tema-container');
    container.style.display = mod === 'EXAMEN_GRADO' ? 'none' : 'block';
}

function guardarPreDefensa() {
    const presidente = document.getElementById('reg-presidente').value;
    const secretario = document.getElementById('reg-secretario').value;
    const tutor = document.getElementById('reg-id-tutor').value;

    // Validación frontend: tribunal no puede ser el tutor
    if (presidente === tutor || secretario === tutor) {
        showToast('Los miembros del tribunal no pueden ser el mismo tutor del estudiante', 'error');
        return;
    }
    if (presidente && secretario && presidente === secretario) {
        showToast('Presidente y Secretario deben ser personas diferentes', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('ajax_action', 'registrar_predefensa');
    formData.append('id_estudiante', document.getElementById('reg-id-estudiante').value);
    formData.append('id_proyecto', document.getElementById('reg-id-proyecto').value);
    formData.append('modalidad_titulacion', document.getElementById('reg-modalidad').value);
    formData.append('tema', document.getElementById('reg-tema').value);
    formData.append('fecha', document.getElementById('reg-fecha').value);
    formData.append('hora', document.getElementById('reg-hora').value);
    formData.append('id_aula', document.getElementById('reg-aula').value);
    formData.append('gestion', document.getElementById('reg-gestion').value);
    formData.append('id_presidente', presidente);
    formData.append('id_secretario', secretario);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('modalRegistro')).hide();
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => showToast('Error de conexión', 'error'));
}

// ==================== MODAL DEFENSA FORMAL ====================
function abrirModalDefensa(idPreDefensa, nombre, fechaValidacion) {
    document.getElementById('def-id-pre-defensa').value = idPreDefensa;
    document.getElementById('def-nombre-estudiante').textContent = nombre;
    
    // Calcular fecha mínima (30 días después de habilitación)
    const fechaHab = new Date(fechaValidacion);
    fechaHab.setDate(fechaHab.getDate() + 30);
    const hoy = new Date();
    const fechaMin = fechaHab > hoy ? fechaHab : hoy;
    
    const minStr = fechaMin.toISOString().split('T')[0];
    document.getElementById('def-fecha').min = minStr;
    document.getElementById('def-fecha').value = '';
    document.getElementById('def-hora').value = '';
    document.getElementById('def-aula').value = '';
    document.getElementById('def-fecha-hint').textContent = 'Fecha mínima: ' + minStr.split('-').reverse().join('/');
    
    new bootstrap.Modal(document.getElementById('modalDefensa')).show();
}

function guardarDefensa() {
    const formData = new FormData();
    formData.append('ajax_action', 'programar_defensa');
    formData.append('id_pre_defensa', document.getElementById('def-id-pre-defensa').value);
    formData.append('fecha_defensa', document.getElementById('def-fecha').value);
    formData.append('hora_defensa', document.getElementById('def-hora').value);
    formData.append('id_aula_defensa', document.getElementById('def-aula').value);

    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                bootstrap.Modal.getInstance(document.getElementById('modalDefensa')).hide();
                setTimeout(() => location.reload(), 1200);
            } else {
                showToast(data.message, 'error');
            }
        })
        .catch(() => showToast('Error de conexión', 'error'));
}

// ==================== GENERAR DOCUMENTOS (NOTA INTERNA / EXTERNA) ====================
const docConfigs = {
    interna: {
        title: 'Nota Interna',
        subtitle: '¿Está seguro de generar la Nota Interna?',
        desc: 'Solicitud de pago a Tribunal Externo - UTO. Se generará el documento Word con código correlativo.',
        headerBg: 'linear-gradient(135deg, #f59e0b, #d97706)',
        iconBg: 'rgba(245,158,11,0.1)',
        iconColor: '#f59e0b',
        btnClass: 'btn-warning-custom',
        url: '../controllers/generar_nota_interna.php?id='
    },
    externa_uto: {
        title: 'Nota Externa — UTO',
        subtitle: '¿Está seguro de generar la Nota Externa para la UTO?',
        desc: 'Solicitud de designación de Tribunal Externo a la Universidad Técnica de Oruro.',
        headerBg: 'linear-gradient(135deg, #06b6d4, #0891b2)',
        iconBg: 'rgba(6,182,212,0.1)',
        iconColor: '#06b6d4',
        btnClass: 'btn-info-custom',
        url: '../controllers/generar_nota_externa.php?tipo=UTO&id='
    },
    externa_fed: {
        title: 'Nota Externa — Federación',
        subtitle: '¿Generar Nota Externa para la Federación de Profesionales?',
        desc: 'Solicitud de designación de Veedor/Revisor a la Federación Departamental de Profesionales.',
        headerBg: 'linear-gradient(135deg, #8b5cf6, #7c3aed)',
        iconBg: 'rgba(139,92,246,0.1)',
        iconColor: '#8b5cf6',
        btnClass: 'btn-danger-custom',
        url: '../controllers/generar_nota_externa.php?tipo=FEDERACION&id='
    }
};

function confirmarGenerarDoc(idPreDefensa, tipo, nombreEstudiante) {
    const cfg = docConfigs[tipo];
    if (!cfg) return;

    document.getElementById('doc-id-pre-defensa').value = idPreDefensa;
    document.getElementById('doc-tipo').value = tipo;
    document.getElementById('modalDocTitle').innerHTML = '<i class="fas fa-file-alt me-2"></i> ' + cfg.title;
    document.getElementById('modalDocHeader').style.background = cfg.headerBg;
    document.getElementById('modalDocSubtitle').textContent = cfg.subtitle;
    document.getElementById('modalDocEstudiante').textContent = nombreEstudiante;
    document.getElementById('modalDocDesc').textContent = cfg.desc;
    document.getElementById('modalDocIcon').style.background = cfg.iconBg;
    document.getElementById('modalDocIcon').querySelector('i').style.color = cfg.iconColor;
    
    const btn = document.getElementById('btnGenerarDoc');
    btn.className = cfg.btnClass;
    btn.innerHTML = '<i class="fas fa-file-download"></i> Generar ' + cfg.title;

    new bootstrap.Modal(document.getElementById('modalGenerarDoc')).show();
}

// Mantener compatibilidad con el botón del listado general
function confirmarNotaInterna(idPreDefensa) {
    confirmarGenerarDoc(idPreDefensa, 'interna', '');
}

function ejecutarGenerarDoc() {
    const id = document.getElementById('doc-id-pre-defensa').value;
    const tipo = document.getElementById('doc-tipo').value;
    const cfg = docConfigs[tipo];
    if (!cfg) return;

    bootstrap.Modal.getInstance(document.getElementById('modalGenerarDoc')).hide();
    showToast('Generando documento... Descarga iniciará en breve', 'success');
    
    // Redirigir para descargar
    window.location.href = cfg.url + id;
}

// ==================== VALIDACIONES ADICIONALES ====================
// Prevenir selección de misma persona en presidente y secretario
document.addEventListener('change', function(e) {
    if (e.target.id === 'reg-presidente' || e.target.id === 'reg-secretario') {
        const pres = document.getElementById('reg-presidente');
        const sec = document.getElementById('reg-secretario');
        if (pres.value && sec.value && pres.value === sec.value) {
            showToast('Presidente y Secretario deben ser personas diferentes', 'error');
            e.target.value = '';
        }
    }
});
</script>
</body>
</html>