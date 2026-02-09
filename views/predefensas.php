<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit(); }
require_once '../config/conexion.php';

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$rol = htmlspecialchars((string)($_SESSION["role"] ?? 'Invitado'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($nombre_usuario, 0, 1));

// ===================== POST ACTIONS =====================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Nueva Aula
    if ($_POST['action'] == 'nueva_aula') {
        try {
            $s = $pdo->prepare("INSERT INTO aulas (nombre_aula) VALUES (?) RETURNING id_aula");
            $s->execute([$_POST['nombre_aula']]);
            echo json_encode(['exito'=>true,'id'=>$s->fetch()['id_aula']]);
        } catch(Exception $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
        exit;
    }

    // Programar Predefensa (para estudiantes SIN PROGRAMAR)
    if ($_POST['action'] == 'programar_predefensa') {
        try {
            $pdo->beginTransaction();
            
            // Verificar si ya existe una predefensa
            $check = $pdo->prepare("SELECT id_pre_defensa FROM pre_defensas WHERE id_estudiante = ? AND estado != 'APROBADA'");
            $check->execute([$_POST['id_estudiante']]);
            $existe = $check->fetch();
            
            if ($existe) {
                // Actualizar predefensa existente
                $stmt = $pdo->prepare("
                    UPDATE pre_defensas 
                    SET fecha = ?, hora = ?, id_aula = ?, modalidad_titulacion = ?, tema = ?, estado = 'PENDIENTE'
                    WHERE id_pre_defensa = ?
                ");
                $stmt->execute([
                    $_POST['fecha'],
                    $_POST['hora'],
                    $_POST['id_aula'],
                    $_POST['modalidad_titulacion'] ?? 'PROYECTO_GRADO',
                    $_POST['tema'] ?? null,
                    $existe['id_pre_defensa']
                ]);
                $id_pd = $existe['id_pre_defensa'];
            } else {
                // Obtener id_tutor de la asignación
                $tutor_query = $pdo->prepare("SELECT id_docente FROM asignaciones_tutor WHERE id_estudiante = ? AND estado = 'ACTIVO'");
                $tutor_query->execute([$_POST['id_estudiante']]);
                $tutor_data = $tutor_query->fetch();
                $id_tutor = $tutor_data ? $tutor_data['id_docente'] : null;
                
                // Crear nueva predefensa
                $stmt = $pdo->prepare("
                    INSERT INTO pre_defensas (id_estudiante, gestion, fecha, hora, id_aula, modalidad_titulacion, tema, estado, id_tutor, id_proyecto) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?, ?) 
                    RETURNING id_pre_defensa
                ");
                $stmt->execute([
                    $_POST['id_estudiante'],
                    $_POST['gestion'] ?? date('Y'),
                    $_POST['fecha'],
                    $_POST['hora'],
                    $_POST['id_aula'],
                    $_POST['modalidad_titulacion'] ?? 'PROYECTO_GRADO',
                    $_POST['tema'] ?? null,
                    $id_tutor,
                    $_POST['id_proyecto'] ?? null
                ]);
                $id_pd = $stmt->fetch()['id_pre_defensa'];
            }
            
            $pdo->commit();
            echo json_encode(['exito'=>true,'mensaje'=>'Predefensa programada exitosamente','id_pre_defensa'=>$id_pd]);
        } catch(Exception $e) { 
            $pdo->rollBack(); 
            echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); 
        }
        exit;
    }

    // Calificar Predefensa
    if ($_POST['action'] == 'calificar_predefensa') {
        try {
            $pdo->beginTransaction();
            
            $nota = (float)$_POST['nota'];
            $id_pd = $_POST['id_pre_defensa'];
            $observaciones = $_POST['observaciones'] ?? '';
            
            // Verificar si ya tiene calificación
            $check = $pdo->prepare("SELECT id_calificacion FROM calificaciones_predefensa WHERE id_pre_defensa = ?");
            $check->execute([$id_pd]);
            $existe_calif = $check->fetch();
            
            if ($existe_calif) {
                // Actualizar calificación
                $stmt = $pdo->prepare("
                    UPDATE calificaciones_predefensa 
                    SET nota = ?, observaciones = ?, fecha_calificacion = CURRENT_DATE
                    WHERE id_calificacion = ?
                ");
                $stmt->execute([$nota, $observaciones, $existe_calif['id_calificacion']]);
            } else {
                // Insertar nueva calificación
                $stmt = $pdo->prepare("
                    INSERT INTO calificaciones_predefensa (id_pre_defensa, nota, observaciones, fecha_calificacion) 
                    VALUES (?, ?, ?, CURRENT_DATE)
                ");
                $stmt->execute([$id_pd, $nota, $observaciones]);
            }
            
            // Actualizar estado de la predefensa
            $estado = $nota >= 41 ? 'APROBADA' : 'REPROBADA';
            $pdo->prepare("UPDATE pre_defensas SET estado = ? WHERE id_pre_defensa = ?")->execute([$estado, $id_pd]);
            
            // Si está aprobado, crear defensa final automática (30 días hábiles después)
            if ($estado === 'APROBADA') {
                $fecha_predefensa = $_POST['fecha_predefensa'];
                $fecha_defensa = calcularFechaDefensa($fecha_predefensa, 30);
                
                $check_defensa = $pdo->prepare("SELECT id_defensa FROM defensas_finales WHERE id_pre_defensa = ?");
                $check_defensa->execute([$id_pd]);
                
                if (!$check_defensa->fetch()) {
                    $pdo->prepare("
                        INSERT INTO defensas_finales (id_pre_defensa, fecha_defensa, estado) 
                        VALUES (?, ?, 'PENDIENTE')
                    ")->execute([$id_pd, $fecha_defensa]);
                }
            }
            
            $pdo->commit();
            echo json_encode([
                'exito'=>true,
                'mensaje'=> $estado === 'APROBADA' ? 'Estudiante aprobado. Defensa final programada.' : 'Estudiante reprobado. Puede reprogramar predefensa.',
                'estado'=>$estado,
                'nota'=>$nota
            ]);
        } catch(Exception $e) { 
            $pdo->rollBack(); 
            echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); 
        }
        exit;
    }

    // Reprogramar Predefensa (para reprobados)
    if ($_POST['action'] == 'reprogramar_predefensa') {
        try {
            $stmt = $pdo->prepare("
                UPDATE pre_defensas 
                SET fecha = ?, hora = ?, id_aula = ?, estado = 'PENDIENTE'
                WHERE id_pre_defensa = ?
            ");
            $stmt->execute([
                $_POST['fecha'],
                $_POST['hora'],
                $_POST['id_aula'],
                $_POST['id_pre_defensa']
            ]);
            
            echo json_encode(['exito'=>true,'mensaje'=>'Predefensa reprogramada exitosamente']);
        } catch(Exception $e) { 
            echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); 
        }
        exit;
    }

    // Quitar aula
    if ($_POST['action'] == 'quitar_aula') {
        try {
            $pdo->prepare("UPDATE pre_defensas SET id_aula = NULL WHERE id_pre_defensa = ?")->execute([$_POST['id_pre_defensa']]);
            echo json_encode(['exito'=>true]);
        } catch(Exception $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
        exit;
    }

    // Asignar/Cambiar Aula
    if ($_POST['action'] == 'asignar_aula') {
        try {
            $pdo->prepare("UPDATE pre_defensas SET id_aula = ? WHERE id_pre_defensa = ?")->execute([$_POST['id_aula'], $_POST['id_pre_defensa']]);
            echo json_encode(['exito'=>true]);
        } catch(Exception $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
        exit;
    }

    // Eliminar miembro tribunal
    if ($_POST['action'] == 'quitar_tribunal') {
        try {
            $pdo->prepare("DELETE FROM tribunales_asignados WHERE id_pre_defensa=? AND id_docente=?")->execute([$_POST['id_pre_defensa'],$_POST['id_docente']]);
            echo json_encode(['exito'=>true]);
        } catch(Exception $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
        exit;
    }

    // Agregar miembro tribunal
    if ($_POST['action'] == 'agregar_tribunal') {
        try {
            $pdo->prepare("INSERT INTO tribunales_asignados (id_pre_defensa,id_docente,rol_tribunal) VALUES (?,?,?)")->execute([$_POST['id_pre_defensa'],$_POST['id_docente'],$_POST['rol_tribunal']]);
            echo json_encode(['exito'=>true]);
        } catch(Exception $e) { echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
        exit;
    }

    // Obtener tribunal de una predefensa
    if ($_POST['action'] == 'obtener_tribunal') {
        try {
            $stmt = $pdo->prepare("
                SELECT ta.id_docente, ta.rol_tribunal, 
                       p.primer_nombre || ' ' || p.primer_apellido AS nombre_completo
                FROM tribunales_asignados ta
                INNER JOIN personas p ON ta.id_docente = p.id_persona
                WHERE ta.id_pre_defensa = ?
                ORDER BY ta.rol_tribunal
            ");
            $stmt->execute([$_POST['id_pre_defensa']]);
            $tribunal = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['exito'=>true,'tribunal'=>$tribunal]);
        } catch(Exception $e) { 
            echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); 
        }
        exit;
    }

    // Obtener datos de estudiante para programar
    if ($_POST['action'] == 'obtener_estudiante') {
        try {
            $stmt = $pdo->prepare("
                SELECT e.id_persona AS id_estudiante,
                       p.primer_nombre || ' ' || p.primer_apellido AS nombre_completo,
                       c.nombre_carrera,
                       a.id_asignacion, a.id_docente AS id_tutor, a.id_proyecto, a.gestion,
                       pt.primer_nombre || ' ' || pt.primer_apellido AS tutor_nombre,
                       pro.titulo_proyecto
                FROM estudiantes e
                INNER JOIN personas p ON e.id_persona = p.id_persona
                INNER JOIN carreras c ON e.id_carrera = c.id_carrera
                LEFT JOIN asignaciones_tutor a ON a.id_estudiante = e.id_persona AND a.estado = 'ACTIVO'
                LEFT JOIN personas pt ON a.id_docente = pt.id_persona
                LEFT JOIN proyectos pro ON a.id_proyecto = pro.id_proyecto
                WHERE e.id_persona = ?
            ");
            $stmt->execute([$_POST['id_estudiante']]);
            $estudiante = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Obtener tribunal asignado
            $stmt_trib = $pdo->prepare("
                SELECT t.id_presidente, t.id_secretario,
                       p1.primer_nombre || ' ' || p1.primer_apellido AS presidente_nombre,
                       p2.primer_nombre || ' ' || p2.primer_apellido AS secretario_nombre
                FROM tribunales t
                INNER JOIN asignaciones_tutor a ON t.id_asignacion = a.id_asignacion
                INNER JOIN personas p1 ON t.id_presidente = p1.id_persona
                INNER JOIN personas p2 ON t.id_secretario = p2.id_persona
                WHERE a.id_estudiante = ? AND t.estado = 'ACTIVO'
            ");
            $stmt_trib->execute([$_POST['id_estudiante']]);
            $tribunal = $stmt_trib->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['exito'=>true,'estudiante'=>$estudiante,'tribunal'=>$tribunal]);
        } catch(Exception $e) { 
            echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); 
        }
        exit;
    }

    // Guardar predefensa completa (nuevo registro)
    if ($_POST['action'] == 'guardar_todo') {
        try {
            $pdo->beginTransaction();
            
            // Obtener id_tutor de la asignación activa
            $tutor_query = $pdo->prepare("SELECT id_docente FROM asignaciones_tutor WHERE id_estudiante = ? AND estado = 'ACTIVO'");
            $tutor_query->execute([$_POST['id_estudiante']]);
            $tutor_data = $tutor_query->fetch();
            $id_tutor = $tutor_data ? $tutor_data['id_docente'] : null;
            
            // Insertar predefensa
            $st = $pdo->prepare("INSERT INTO pre_defensas (id_estudiante,gestion,fecha,hora,id_tutor,id_proyecto,id_aula,modalidad_titulacion,tema,estado) VALUES (?,?,?,?,?,?,?,?,?,'PENDIENTE') RETURNING id_pre_defensa");
            $st->execute([
                $_POST['id_estudiante'],
                $_POST['gestion'],
                $_POST['fecha'],
                $_POST['hora'],
                $id_tutor,
                $_POST['id_proyecto']?:null,
                $_POST['id_aula'],
                $_POST['modalidad_titulacion'],
                $_POST['tema']?:null
            ]);
            $id_pd = $st->fetch()['id_pre_defensa'];
            
            // Insertar tribunal
            $ti = $pdo->prepare("INSERT INTO tribunales_asignados (id_pre_defensa,id_docente,rol_tribunal) VALUES (?,?,?)");
            $ti->execute([$id_pd,$_POST['id_presidente'],'PRESIDENTE']);
            $ti->execute([$id_pd,$_POST['id_secretario'],'SECRETARIO']);
            
            // Sincronizar tabla tribunales
            if(!empty($_POST['id_asignacion'])){
                $ch=$pdo->prepare("SELECT id_tribunal FROM tribunales WHERE id_asignacion=? AND estado='ACTIVO'");$ch->execute([$_POST['id_asignacion']]);$te=$ch->fetch();
                if(!$te){$pdo->prepare("INSERT INTO tribunales (id_asignacion,id_presidente,id_secretario,estado) VALUES (?,?,?,'ACTIVO')")->execute([$_POST['id_asignacion'],$_POST['id_presidente'],$_POST['id_secretario']]);}
                else{$pdo->prepare("UPDATE tribunales SET id_presidente=?,id_secretario=? WHERE id_tribunal=?")->execute([$_POST['id_presidente'],$_POST['id_secretario'],$te['id_tribunal']]);}
            }
            $pdo->commit();
            echo json_encode(['exito'=>true,'mensaje'=>'Predefensa registrada correctamente.']);
        } catch(Exception $e) { $pdo->rollBack(); echo json_encode(['exito'=>false,'mensaje'=>$e->getMessage()]); }
        exit;
    }
}

// Función para calcular fecha de defensa (30 días hábiles)
function calcularFechaDefensa($fecha_inicio, $dias_habiles = 30) {
    $fecha = new DateTime($fecha_inicio);
    $dias_agregados = 0;
    
    while ($dias_agregados < $dias_habiles) {
        $fecha->modify('+1 day');
        $dia_semana = (int)$fecha->format('N'); // 1=Lunes, 7=Domingo
        if ($dia_semana >= 1 && $dia_semana <= 5) {
            $dias_agregados++;
        }
    }
    
    return $fecha->format('Y-m-d');
}

// ===================== DATA LOADING =====================
try {
    // Carreras para filtros
    $carreras = $pdo->query("SELECT * FROM carreras ORDER BY nombre_carrera")->fetchAll(PDO::FETCH_ASSOC);

    // Estudiantes con tutor y asignación
    $est_data = $pdo->query("
        SELECT e.id_persona AS id_estudiante,
               (p.primer_nombre||' '||p.primer_apellido) AS est_nombre,
               p.celular AS tel_est,
               a.id_asignacion, a.id_docente AS id_tutor, a.id_proyecto, a.gestion,
               (pt.primer_nombre||' '||pt.primer_apellido) AS tutor_nombre,
               pt.celular AS tel_tutor,
               pro.titulo_proyecto,
               c.nombre_carrera, c.id_carrera
        FROM estudiantes e
        INNER JOIN personas p ON e.id_persona=p.id_persona
        INNER JOIN carreras c ON e.id_carrera=c.id_carrera
        LEFT JOIN asignaciones_tutor a ON a.id_estudiante=e.id_persona AND a.estado='ACTIVO'
        LEFT JOIN personas pt ON a.id_docente=pt.id_persona
        LEFT JOIN proyectos pro ON a.id_proyecto=pro.id_proyecto
        WHERE p.estado='activo'
        ORDER BY c.nombre_carrera, p.primer_apellido
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Docentes para tribunal
    $docentes = $pdo->query("SELECT d.id_persona,(p.primer_nombre||' '||p.primer_apellido) AS nombre FROM docentes d INNER JOIN personas p ON d.id_persona=p.id_persona WHERE d.es_tribunal=true ORDER BY p.primer_apellido")->fetchAll(PDO::FETCH_ASSOC);

    $aulas = $pdo->query("SELECT * FROM aulas ORDER BY nombre_aula")->fetchAll(PDO::FETCH_ASSOC);

    // LISTADO PRINCIPAL MEJORADO con más información
    $lista = $pdo->query("
        SELECT t.id_tribunal,
               c.nombre_carrera,
               e.id_persona AS id_estudiante,
               p_est.primer_nombre||' '||p_est.primer_apellido AS estudiante,
               p_est.celular AS tel_estudiante,
               p_tuto.primer_nombre||' '||p_tuto.primer_apellido AS tutor,
               p_tuto.celular AS tel_tutor,
               p_presi.primer_nombre||' '||p_presi.primer_apellido AS presidente,
               p_presi.celular AS tel_presi,
               p_secre.primer_nombre||' '||p_secre.primer_apellido AS secretario,
               p_secre.celular AS tel_secre,
               pd.id_pre_defensa, pd.fecha, pd.hora, pd.estado AS estado_pd, pd.tema, pd.modalidad_titulacion,
               au.nombre_aula, au.id_aula,
               a.id_asignacion, a.id_docente AS id_tutor, a.id_proyecto, a.gestion,
               cp.nota, cp.observaciones,
               pro.titulo_proyecto
        FROM tribunales t
        JOIN asignaciones_tutor a ON t.id_asignacion=a.id_asignacion
        JOIN personas p_est ON a.id_estudiante=p_est.id_persona
        JOIN estudiantes e ON p_est.id_persona=e.id_persona
        JOIN carreras c ON e.id_carrera=c.id_carrera
        JOIN personas p_tuto ON a.id_docente=p_tuto.id_persona
        JOIN personas p_presi ON t.id_presidente=p_presi.id_persona
        JOIN personas p_secre ON t.id_secretario=p_secre.id_persona
        LEFT JOIN pre_defensas pd ON pd.id_estudiante=a.id_estudiante
        LEFT JOIN aulas au ON pd.id_aula=au.id_aula
        LEFT JOIN calificaciones_predefensa cp ON cp.id_pre_defensa=pd.id_pre_defensa
        LEFT JOIN proyectos pro ON a.id_proyecto=pro.id_proyecto
        WHERE t.estado='ACTIVO'
        ORDER BY c.nombre_carrera, p_est.primer_apellido
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Estadísticas
    $stats = [
        'total_tribunales' => count($lista),
        'sin_programar' => 0,
        'pendientes' => 0,
        'aprobados' => 0,
        'reprobados' => 0
    ];

    foreach ($lista as $item) {
        $estado = $item['estado_pd'] ?? 'SIN PROG.';
        if (!$item['fecha']) $stats['sin_programar']++;
        else if ($estado === 'PENDIENTE') $stats['pendientes']++;
        else if ($estado === 'APROBADA') $stats['aprobados']++;
        else if ($estado === 'REPROBADA') $stats['reprobados']++;
    }

} catch(PDOException $e) { die("Error: ".$e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
    <title>Predefensas Profesional - UNIOR</title>
    <link rel="icon" type="image/png" href="../assets/img/logo_unior1.png">
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{--glass:rgba(255,255,255,0.75);--glass-border:rgba(255,255,255,0.9);--accent:#6366f1;--accent-light:#818cf8;--accent-dark:#4f46e5;--success:#10b981;--warning:#f59e0b;--danger:#ef4444;--info:#3b82f6;--text-dark:#0f172a;--text-muted:#64748b;--bg-main:#f8fafc;--shadow-sm:0 2px 8px rgba(0,0,0,.04);--shadow-md:0 8px 24px rgba(0,0,0,.06);--shadow-lg:0 20px 40px rgba(0,0,0,.08);--ease:cubic-bezier(.4,0,.2,1)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg-main);color:var(--text-dark);min-height:100vh;display:flex;background-image:radial-gradient(at 0% 0%,rgba(99,102,241,.1) 0,transparent 50%),radial-gradient(at 100% 100%,rgba(129,140,248,.08) 0,transparent 50%);overflow-x:hidden}
.sidebar{width:85px;height:95vh;margin:2.5vh 0 2.5vh 24px;background:var(--glass);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-radius:32px;border:1.5px solid var(--glass-border);display:flex;flex-direction:column;align-items:center;padding:32px 0;position:sticky;top:2.5vh;transition:all .5s var(--ease);box-shadow:var(--shadow-lg);z-index:1000}
@media(min-width:992px){.sidebar:hover{width:280px;align-items:flex-start;padding:32px 24px;background:rgba(255,255,255,.85)}.sidebar:hover .nv span{opacity:1;transform:translateX(0);margin-left:12px;display:inline}.sidebar:hover .logo-text{opacity:1;width:auto;margin-left:12px}}
.logo-a{display:flex;align-items:center;gap:12px;margin-bottom:40px;padding:0 12px}
.logo-text{font-family:'Bricolage Grotesque',sans-serif;font-weight:800;font-size:1.4rem;color:var(--accent);opacity:0;width:0;overflow:hidden;white-space:nowrap;transition:all .4s var(--ease)}
.nv{width:100%;display:flex;align-items:center;padding:16px 18px;margin-bottom:6px;border-radius:20px;color:var(--text-muted);text-decoration:none;transition:all .3s var(--ease);position:relative}
.nv::before{content:'';position:absolute;left:0;top:0;height:100%;width:4px;background:var(--accent);transform:scaleY(0);transition:transform .3s var(--ease)}
.nv i{font-size:1.25rem;min-width:50px;text-align:center;transition:all .3s var(--ease)}
.nv span{opacity:0;font-weight:600;font-size:.95rem;white-space:nowrap;transition:all .3s var(--ease);transform:translateX(-10px)}
.nv:hover{background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(129,140,248,.05));color:var(--accent);transform:translateX(4px)}
.nv.active{background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#fff;box-shadow:0 8px 16px rgba(99,102,241,.3)}
.nv.active::before{transform:scaleY(1)}.nv.active i{transform:scale(1.1)}
.nv.text-danger:hover{background:linear-gradient(135deg,rgba(239,68,68,.1),rgba(239,68,68,.05));color:var(--danger)}
.mw{flex:1;padding:48px 48px 100px;max-width:1800px;margin:0 auto;width:100%}
.uh{display:flex;justify-content:flex-end;align-items:center;gap:16px;margin-bottom:40px}
.mlt{display:none}
.up{background:var(--glass);padding:8px 10px 8px 24px;border-radius:100px;border:1.5px solid var(--glass-border);display:flex;align-items:center;gap:14px;box-shadow:var(--shadow-md);transition:all .3s var(--ease);cursor:pointer}
.up:hover{background:#fff;transform:translateY(-2px);box-shadow:var(--shadow-lg)}
.ua{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem;box-shadow:0 4px 12px rgba(99,102,241,.3)}
.un{font-weight:600;font-size:.9rem;line-height:1.2}
.ur{font-size:.7rem;color:var(--text-muted);letter-spacing:1.5px;text-transform:uppercase;font-weight:500}
.eyb{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(129,140,248,.1));padding:8px 20px;border-radius:100px;font-size:.75rem;font-weight:600;letter-spacing:2px;color:var(--accent);margin-bottom:16px;border:1px solid rgba(99,102,241,.2)}
.pt{font-family:'Bricolage Grotesque',sans-serif;font-size:clamp(2rem,4vw,3.2rem);font-weight:300;letter-spacing:-1.5px;line-height:1;margin-bottom:8px}
.pt .ac{font-weight:800;background:linear-gradient(135deg,var(--accent),var(--accent-light));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.ps{font-size:1rem;color:var(--text-muted);margin-bottom:36px}
.sr{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;margin-bottom:36px}
.cc{background:var(--glass);border:1.5px solid var(--glass-border);border-radius:28px;padding:26px;box-shadow:var(--shadow-md);backdrop-filter:blur(12px);transition:all .4s var(--ease);position:relative;overflow:hidden}
.cc::before{content:'';position:absolute;top:-50%;right:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(99,102,241,.05) 0%,transparent 70%);opacity:0;transition:opacity .4s var(--ease)}
.cc:hover{transform:translateY(-6px);background:#fff;box-shadow:var(--shadow-lg)}.cc:hover::before{opacity:1}
.si{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin-bottom:14px;transition:all .3s var(--ease)}.cc:hover .si{transform:scale(1.1) rotate(5deg)}
.si.p{background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(129,140,248,.18));color:var(--accent)}
.si.y{background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.18));color:var(--warning)}
.si.g{background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(16,185,129,.18));color:var(--success)}
.si.r{background:linear-gradient(135deg,rgba(239,68,68,.12),rgba(239,68,68,.18));color:var(--danger)}
.si.b{background:linear-gradient(135deg,rgba(59,130,246,.12),rgba(59,130,246,.18));color:var(--info)}
.sl{font-size:.75rem;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px}
.sn{font-family:'Bricolage Grotesque',sans-serif;font-size:2.5rem;font-weight:800;line-height:1;color:var(--text-dark)}
.tn{display:flex;gap:6px;background:var(--glass);border:1.5px solid var(--glass-border);border-radius:20px;padding:6px;margin-bottom:28px;flex-wrap:wrap;box-shadow:var(--shadow-sm)}
.tb{padding:12px 24px;border:none;background:transparent;border-radius:16px;font-size:.9rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:all .3s var(--ease);display:flex;align-items:center;gap:8px}
.tb:hover{color:var(--accent);background:rgba(99,102,241,.06)}
.tb.active{background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#fff;box-shadow:0 4px 12px rgba(99,102,241,.3)}
.tp{display:none}.tp.active{display:block}
.gc{background:var(--glass);border:1.5px solid var(--glass-border);border-radius:24px;margin-bottom:20px;box-shadow:var(--shadow-sm);backdrop-filter:blur(12px);overflow:hidden;transition:all .3s var(--ease)}.gc:hover{box-shadow:var(--shadow-md)}
.gh{padding:20px 28px;border-bottom:1px solid rgba(0,0,0,.04);display:flex;align-items:center;gap:14px}
.gh .gi{width:44px;height:44px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.gh h5{font-weight:700;font-size:1rem;margin:0}.gh small{font-size:.8rem;color:var(--text-muted)}
.gb{padding:24px 28px}
label.fl{font-size:.82rem;font-weight:600;color:var(--text-dark);margin-bottom:6px;display:block}
.form-control,.form-select{border-radius:14px;border:1.5px solid rgba(0,0,0,.08);padding:11px 16px;font-size:.9rem;transition:all .25s var(--ease);font-family:'Inter',sans-serif;background:rgba(255,255,255,.6)}
.form-control:focus,.form-select:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(99,102,241,.1);background:#fff}
.btn-main{background:linear-gradient(135deg,var(--accent),var(--accent-light));border:none;color:#fff;padding:16px 32px;border-radius:100px;font-weight:700;font-size:1rem;cursor:pointer;transition:all .3s var(--ease);display:inline-flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(99,102,241,.3);width:100%;justify-content:center}
.btn-main:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(99,102,241,.4)}
.btn-aula{background:linear-gradient(135deg,var(--success),#34d399);border:none;color:#fff;padding:11px 16px;border-radius:14px;cursor:pointer;transition:all .2s;font-size:1rem}.btn-aula:hover{transform:scale(1.05)}
.ts{background:var(--glass);border:1.5px solid var(--glass-border);border-radius:24px;overflow:hidden;box-shadow:var(--shadow-md);backdrop-filter:blur(12px)}
.tsh{padding:20px 28px;border-bottom:1px solid rgba(0,0,0,.04);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.tsh h5{font-weight:700;margin:0;font-size:1.05rem}
.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.filters select,.filters input{border-radius:12px;border:1.5px solid rgba(0,0,0,.06);padding:8px 14px;font-size:.85rem;font-family:'Inter',sans-serif;background:rgba(255,255,255,.6);transition:all .2s}
.filters select:focus,.filters input:focus{border-color:var(--accent);background:#fff;outline:none;box-shadow:0 0 0 3px rgba(99,102,241,.08)}
.table-responsive{overflow-x:auto}
table.tbl{width:100%;border-collapse:collapse}
table.tbl thead th{background:rgba(0,0,0,.02);padding:13px 16px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);border-bottom:1px solid rgba(0,0,0,.05);white-space:nowrap}
table.tbl tbody td{padding:14px 16px;font-size:.85rem;border-bottom:1px solid rgba(0,0,0,.03);vertical-align:middle}
table.tbl tbody tr{transition:all .2s}table.tbl tbody tr:hover{background:rgba(99,102,241,.03)}
table.tbl tbody tr:last-child td{border-bottom:none}
.be{padding:5px 14px;border-radius:100px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;display:inline-flex;align-items:center;gap:4px}
.bp{background:rgba(245,158,11,.12);color:var(--warning)}
.ba{background:rgba(16,185,129,.12);color:var(--success)}
.bre{background:rgba(239,68,68,.12);color:var(--danger)}
.bsn{background:rgba(99,102,241,.12);color:var(--accent)}
.cell-phone{font-size:.78rem;color:var(--text-muted)}
.btn-xs{padding:6px 12px;border-radius:10px;font-size:.75rem;border:none;cursor:pointer;font-weight:600;transition:all .2s;display:inline-flex;align-items:center;gap:5px}
.btn-xs:hover{transform:scale(1.05)}
.btn-xs.red{background:rgba(239,68,68,.1);color:var(--danger)}
.btn-xs.green{background:rgba(16,185,129,.1);color:var(--success)}
.btn-xs.blue{background:rgba(59,130,246,.1);color:var(--info)}
.btn-xs.orange{background:rgba(245,158,11,.1);color:var(--warning)}
.info-card{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:16px;background:rgba(99,102,241,.04);border:1px solid rgba(99,102,241,.1);margin-bottom:10px}
.info-card i{color:var(--accent);font-size:1.1rem;flex-shrink:0}
.info-card .ic-label{font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;font-weight:600}
.info-card .ic-val{font-weight:700;font-size:.95rem}
.info-card .ic-tel{font-size:.78rem;color:var(--text-muted)}
.es{padding:60px 20px;text-align:center}.es i{font-size:3.5rem;color:rgba(0,0,0,.08);margin-bottom:16px}.es p{color:var(--text-muted)}
.reveal{animation:fiu .7s var(--ease) forwards;opacity:0}
@keyframes fiu{from{opacity:0;transform:translateY(25px)}to{opacity:1;transform:translateY(0)}}
.nota-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:12px;font-size:.75rem;font-weight:700}
.nota-badge.aprobado{background:rgba(16,185,129,.15);color:var(--success)}
.nota-badge.reprobado{background:rgba(239,68,68,.15);color:var(--danger)}
@media(max-width:991px){body{flex-direction:column}.mlt{display:flex;align-items:center;gap:12px;font-family:'Bricolage Grotesque';font-weight:800;color:var(--accent);font-size:1.3rem}.sidebar{position:fixed;bottom:20px;top:auto;left:20px;right:20px;width:auto;height:75px;margin:0;flex-direction:row;justify-content:space-around;padding:0 20px;border-radius:28px}.sidebar .logo-a,.sidebar .mt-auto,.sidebar span,.sidebar .logo-text{display:none}.sidebar nav{display:flex;width:100%;justify-content:space-around;align-items:center}.nv{width:auto;padding:14px;margin-bottom:0;border-radius:16px}.nv i{min-width:auto;font-size:1.3rem}.mw{padding:24px 20px 120px}.uh{justify-content:space-between;margin-bottom:28px}}
@media(max-width:576px){.sr{grid-template-columns:1fr 1fr;gap:12px}.pt{font-size:1.8rem}.gb{padding:16px 18px}.tb{padding:10px 16px;font-size:.82rem}.filters{flex-direction:column;width:100%}.filters select,.filters input{width:100%}}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="logo-a d-none d-lg-flex"><img src="../assets/img/logo_unior1.png" width="48" alt="Logo"><span class="logo-text">UNIOR</span></div>
    <nav>
        <a href="menu.php" class="nv"><i class="fas fa-home-alt"></i><span>Menú</span></a>
        <a href="lisra_estudiantes.php" class="nv"><i class="fas fa-users-rays"></i><span>Estudiantes</span></a>
        <a href="registro_tutores.php" class="nv"><i class="fas fa-user-tie"></i><span>Registrar Tutor</span></a>
        <a href="lista_tutores.php" class="nv"><i class="fas fa-fingerprint"></i><span>Lista Tutores</span></a>
        <a href="predefensas.php" class="nv active"><i class="fas fa-signature"></i><span>Predefensas</span></a>
        <?php if(isset($_SESSION["role"])&&strtoupper($_SESSION["role"])==='ADMINISTRADOR'):?>
        <a href="logs.php" class="nv"><i class="bi bi-backpack4-fill"></i><span>Logs Sistema</span></a>
        <?php endif;?>
    </nav>
    <a href="../controllers/logout.php" class="nv text-danger mt-auto d-none d-lg-flex"><i class="fas fa-power-off"></i><span>Salir</span></a>
</aside>

<main class="mw">
    <!-- Header -->
    <div class="uh reveal">
        <div class="mlt"><img src="../assets/img/logo_unior1.png" width="38" alt="Logo"><span>UNIOR</span></div>
        <div class="up">
            <div class="text-end d-none d-sm-block"><div class="un"><?=$nombre_usuario?></div><div class="ur"><?=$rol?></div></div>
            <div class="ua"><?=$inicial?></div>
        </div>
    </div>

    <div class="reveal" style="animation-delay:.05s">
        <div class="eyb"><i class="fas fa-file-signature"></i> GESTIÓN ACADÉMICA PROFESIONAL</div>
        <h1 class="pt">Sistema de <span class="ac">Predefensas.</span></h1>
        <p class="ps">Gestión completa: programación, calificación, reprogramación y tribunales.</p>
    </div>

    <!-- Stats Mejoradas -->
    <div class="sr reveal" style="animation-delay:.1s">
        <div class="cc"><div class="si p"><i class="fas fa-calendar-check"></i></div><div class="sl">Tribunales Activos</div><div class="sn"><?=$stats['total_tribunales']?></div></div>
        <div class="cc"><div class="si b"><i class="fas fa-clock"></i></div><div class="sl">Sin Programar</div><div class="sn"><?=$stats['sin_programar']?></div></div>
        <div class="cc"><div class="si y"><i class="fas fa-hourglass-half"></i></div><div class="sl">Pendientes</div><div class="sn"><?=$stats['pendientes']?></div></div>
        <div class="cc"><div class="si g"><i class="fas fa-check-circle"></i></div><div class="sl">Aprobados</div><div class="sn"><?=$stats['aprobados']?></div></div>
        <div class="cc"><div class="si r"><i class="fas fa-times-circle"></i></div><div class="sl">Reprobados</div><div class="sn"><?=$stats['reprobados']?></div></div>
    </div>

    <!-- Tabs -->
    <div class="tn reveal" style="animation-delay:.15s">
        <button class="tb active" data-tab="tab-list"><i class="fas fa-list-ul"></i> Listado General</button>
        <button class="tb" data-tab="tab-reg"><i class="fas fa-plus-circle"></i> Nuevo Registro</button>
    </div>

    <!-- TAB 1: LISTADO PROFESIONAL -->
    <div class="tp active" id="tab-list">
        <div class="ts reveal" style="animation-delay:.2s">
            <div class="tsh">
                <h5><i class="fas fa-list-ul" style="color:var(--accent);margin-right:8px"></i>Gestión Completa de Predefensas</h5>
                <div class="filters">
                    <select id="filtCarrera" onchange="filtrar()">
                        <option value="">Todas las Carreras</option>
                        <?php foreach($carreras as $car):?>
                        <option value="<?=htmlspecialchars($car['nombre_carrera'])?>"><?=htmlspecialchars($car['nombre_carrera'])?></option>
                        <?php endforeach;?>
                    </select>
                    <select id="filtEstado" onchange="filtrar()">
                        <option value="">Todos los Estados</option>
                        <option value="SIN PROG.">Sin Programar</option>
                        <option value="PENDIENTE">Pendientes</option>
                        <option value="APROBADA">Aprobados</option>
                        <option value="REPROBADA">Reprobados</option>
                    </select>
                    <input type="text" id="filtBuscar" placeholder="Buscar estudiante..." oninput="filtrar()">
                </div>
            </div>
            <?php if($stats['total_tribunales']>0):?>
            <div class="table-responsive">
                <table class="tbl">
                    <thead><tr>
                        <th>#</th><th>Carrera</th><th>Estudiante</th>
                        <th>Tutor</th>
                        <th>Tribunal</th>
                        <th>Aula</th><th>Fecha/Hora</th><th>Estado</th><th>Nota</th><th>Acciones</th>
                    </tr></thead>
                    <tbody id="tBody">
                    <?php foreach($lista as $i=>$r):
                        $estado = $r['estado_pd'] ? strtoupper($r['estado_pd']) : 'SIN PROG.';
                        $bc = 'bsn';
                        if($estado=='PENDIENTE') $bc='bp';
                        else if($estado=='APROBADA') $bc='ba';
                        else if($estado=='REPROBADA') $bc='bre';
                        
                        $nota_html = '';
                        if($r['nota']) {
                            $nota_class = $r['nota'] >= 41 ? 'aprobado' : 'reprobado';
                            $nota_html = '<span class="nota-badge '.$nota_class.'"><i class="fas fa-star"></i>'.number_format($r['nota'],1).'</span>';
                        }
                    ?>
                    <tr data-carrera="<?=htmlspecialchars($r['nombre_carrera'])?>" data-estado="<?=$estado?>" 
                        data-id-estudiante="<?=$r['id_estudiante']?>" 
                        data-id-predefensa="<?=$r['id_pre_defensa']??''?>"
                        data-id-proyecto="<?=$r['id_proyecto']??''?>"
                        data-id-asignacion="<?=$r['id_asignacion']??''?>"
                        data-gestion="<?=$r['gestion']??''?>"
                        data-proyecto="<?=htmlspecialchars($r['titulo_proyecto']??'')?>">
                        <td style="font-weight:700;color:var(--text-muted)"><?=$i+1?></td>
                        <td><span style="font-size:.78rem;font-weight:600"><?=htmlspecialchars($r['nombre_carrera'])?></span></td>
                        <td style="font-weight:600">
                            <?=htmlspecialchars($r['estudiante'])?>
                            <div class="cell-phone"><?=htmlspecialchars($r['tel_estudiante']??'-')?></div>
                        </td>
                        <td>
                            <?=htmlspecialchars($r['tutor'])?>
                            <div class="cell-phone"><?=htmlspecialchars($r['tel_tutor']??'-')?></div>
                        </td>
                        <td style="font-size:.8rem">
                            <strong>P:</strong> <?=htmlspecialchars($r['presidente'])?>
                            <div class="cell-phone"><?=htmlspecialchars($r['tel_presi']??'-')?></div>
                            <strong>S:</strong> <?=htmlspecialchars($r['secretario'])?>
                            <div class="cell-phone"><?=htmlspecialchars($r['tel_secre']??'-')?></div>
                            <?php if($r['id_pre_defensa']):?>
                            <button class="btn-xs blue mt-1" onclick="gestionarTribunal(<?=$r['id_pre_defensa']?>)" title="Gestionar Tribunal"><i class="fas fa-gavel"></i></button>
                            <?php endif;?>
                        </td>
                        <td>
                            <?php if($r['nombre_aula']):?>
                                <span style="font-size:.82rem"><?=htmlspecialchars($r['nombre_aula'])?></span>
                            <?php else:?>
                                <span style="font-size:.78rem;color:var(--text-muted)">Sin aula</span>
                            <?php endif;?>
                        </td>
                        <td style="white-space:nowrap">
                            <?php if($r['fecha']):?>
                                <div><?=date('d/m/Y',strtotime($r['fecha']))?></div>
                                <div class="cell-phone"><?=$r['hora']?></div>
                            <?php else:?>
                                <span style="font-size:.78rem;color:var(--text-muted)">No programado</span>
                            <?php endif;?>
                        </td>
                        <td><span class="be <?=$bc?>"><i class="fas <?=$estado=='APROBADA'?'fa-check-circle':($estado=='REPROBADA'?'fa-times-circle':($estado=='PENDIENTE'?'fa-clock':'fa-calendar'))?>"></i><?=$estado?></span></td>
                        <td><?=$nota_html?></td>
                        <td style="white-space:nowrap">
                            <?php if($estado === 'SIN PROG.'):?>
                                <button class="btn-xs blue" onclick="programarPredefensa(this)" title="Programar Predefensa"><i class="fas fa-calendar-plus"></i></button>
                            <?php elseif($estado === 'PENDIENTE'):?>
                                <button class="btn-xs green" onclick="calificarPredefensa(<?=$r['id_pre_defensa']?>, '<?=$r['fecha']?>')" title="Calificar"><i class="fas fa-star"></i></button>
                                <button class="btn-xs orange" onclick="reprogramarPredefensa(<?=$r['id_pre_defensa']?>)" title="Reprogramar"><i class="fas fa-calendar-alt"></i></button>
                            <?php elseif($estado === 'REPROBADA'):?>
                                <button class="btn-xs orange" onclick="reprogramarPredefensa(<?=$r['id_pre_defensa']?>)" title="Nueva Fecha"><i class="fas fa-redo"></i> Reprogramar</button>
                            <?php endif;?>
                        </td>
                    </tr>
                    <?php endforeach;?>
                    </tbody>
                </table>
            </div>
            <?php else:?>
            <div class="es"><i class="fas fa-inbox"></i><p>No hay tribunales activos registrados.<br>Registra una predefensa desde la pestaña "Nuevo Registro".</p></div>
            <?php endif;?>
        </div>
    </div>

    <!-- TAB 2: NUEVO REGISTRO -->
    <div class="tp" id="tab-reg">
        <form id="formPrincipal">
            <input type="hidden" name="action" value="guardar_todo">
            <input type="hidden" name="id_asignacion" id="h_asig">
            <input type="hidden" name="id_proyecto" id="h_pro">
            <input type="hidden" name="id_tutor" id="h_tutor">

            <!-- Estudiante -->
            <div class="gc">
                <div class="gh">
                    <div class="gi" style="background:linear-gradient(135deg,rgba(99,102,241,.12),rgba(129,140,248,.18));color:var(--accent)"><i class="fas fa-user-graduate"></i></div>
                    <div><h5>Datos del Estudiante</h5><small>Al seleccionar se cargan su tutor y datos del proyecto</small></div>
                </div>
                <div class="gb">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fl">Filtrar por Carrera</label>
                            <select id="filtCarreraForm" class="form-select" onchange="filtrarEstudiantes()">
                                <option value="">Todas las Carreras</option>
                                <?php foreach($carreras as $car):?>
                                <option value="<?=$car['id_carrera']?>"><?=htmlspecialchars($car['nombre_carrera'])?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fl">Estudiante</label>
                            <select name="id_estudiante" id="sel_est" class="form-select" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach($est_data as $e):?>
                                <option value="<?=$e['id_estudiante']?>"
                                    data-carrera="<?=$e['id_carrera']??''?>"
                                    data-asig="<?=$e['id_asignacion']??''?>"
                                    data-pro="<?=$e['id_proyecto']??''?>"
                                    data-tutor="<?=$e['id_tutor']??''?>"
                                    data-tutor-nombre="<?=htmlspecialchars($e['tutor_nombre']??'Sin tutor')?>"
                                    data-tutor-tel="<?=htmlspecialchars($e['tel_tutor']??'')?>"
                                    data-gestion="<?=htmlspecialchars($e['gestion']??date('Y'))?>"
                                    data-tema="<?=htmlspecialchars($e['titulo_proyecto']??'')?>"
                                    data-carrera-nombre="<?=htmlspecialchars($e['nombre_carrera']??'')?>">
                                    <?=htmlspecialchars($e['est_nombre'])?> — <?=htmlspecialchars($e['nombre_carrera']??'')?>
                                </option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="col-12" id="tutor_info" style="display:none">
                            <div class="info-card">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <div>
                                    <div class="ic-label">Tutor Asignado</div>
                                    <div class="ic-val" id="tutor_nombre_show">—</div>
                                    <div class="ic-tel" id="tutor_tel_show"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="fl">Gestión</label>
                            <input type="text" name="gestion" id="txt_gestion" class="form-control" value="<?=date('Y')?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="fl">Modalidad de Titulación</label>
                            <select name="modalidad_titulacion" id="sel_mod" class="form-select" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="EXAMEN_GRADO">Examen de Grado</option>
                                <option value="PROYECTO_GRADO">Proyecto de Grado</option>
                                <option value="TESIS">Tesis</option>
                                <option value="TRABAJO_DIRIGIDO">Trabajo Dirigido</option>
                            </select>
                        </div>
                        <div class="col-12" id="tema_div">
                            <label class="fl">Tema del Proyecto</label>
                            <input type="text" name="tema" id="txt_tema" class="form-control" placeholder="Ingrese el tema...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tribunal -->
            <div class="gc">
                <div class="gh">
                    <div class="gi" style="background:linear-gradient(135deg,rgba(245,158,11,.12),rgba(245,158,11,.18));color:var(--warning)"><i class="fas fa-gavel"></i></div>
                    <div><h5>Asignación de Tribunal</h5><small>Presidente y Secretario del tribunal evaluador</small></div>
                </div>
                <div class="gb">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="fl">Presidente del Tribunal</label>
                            <select name="id_presidente" id="sel_t1" class="form-select" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach($docentes as $d):?><option value="<?=$d['id_persona']?>"><?=htmlspecialchars($d['nombre'])?></option><?php endforeach;?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="fl">Secretario del Tribunal</label>
                            <select name="id_secretario" id="sel_t2" class="form-select" required>
                                <option value="">-- Seleccionar --</option>
                                <?php foreach($docentes as $d):?><option value="<?=$d['id_persona']?>"><?=htmlspecialchars($d['nombre'])?></option><?php endforeach;?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Programación -->
            <div class="gc">
                <div class="gh">
                    <div class="gi" style="background:linear-gradient(135deg,rgba(16,185,129,.12),rgba(16,185,129,.18));color:var(--success)"><i class="fas fa-calendar-alt"></i></div>
                    <div><h5>Programación de Predefensa</h5><small>Define lugar, fecha y hora</small></div>
                </div>
                <div class="gb">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="fl">Aula / Lugar</label>
                            <div class="d-flex gap-2">
                                <select name="id_aula" id="sel_aula" class="form-select" required style="flex:1">
                                    <option value="">-- Seleccionar --</option>
                                    <?php foreach($aulas as $a):?><option value="<?=$a['id_aula']?>"><?=htmlspecialchars($a['nombre_aula'])?></option><?php endforeach;?>
                                </select>
                                <button type="button" class="btn-aula" onclick="agregarAula()"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4"><label class="fl">Fecha</label><input type="date" name="fecha" class="form-control" required></div>
                        <div class="col-md-3"><label class="fl">Hora</label><input type="time" name="hora" class="form-control" required></div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-main" style="margin-top:8px"><i class="fas fa-save"></i> REGISTRAR PREDEFENSA COMPLETA</button>
        </form>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Tabs
document.querySelectorAll('.tb').forEach(b=>{b.addEventListener('click',()=>{document.querySelectorAll('.tb').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.tp').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.getElementById(b.dataset.tab).classList.add('active')})});

// Filtros
function filtrar(){
    const car=$('#filtCarrera').val().toLowerCase();
    const est=$('#filtEstado').val();
    const bus=$('#filtBuscar').val().toLowerCase();
    $('#tBody tr').each(function(){
        const rc=$(this).data('carrera').toLowerCase();
        const re=$(this).data('estado');
        const txt=$(this).text().toLowerCase();
        let show=true;
        if(car&&!rc.includes(car))show=false;
        if(est&&re!==est)show=false;
        if(bus&&!txt.includes(bus))show=false;
        $(this).toggle(show);
    });
}

function filtrarEstudiantes(){
    const car=$('#filtCarreraForm').val();
    $('#sel_est option').each(function(){
        if(!$(this).val()){return}
        $(this).toggle(!car||$(this).data('carrera')==car);
    });
    $('#sel_est').val('');
    limpiarForm();
}

$('#sel_est').change(function(){
    const o=$(this).find(':selected');
    if(!o.val()){limpiarForm();return}
    $('#h_asig').val(o.data('asig'));
    $('#h_pro').val(o.data('pro'));
    $('#h_tutor').val(o.data('tutor'));
    if(o.data('gestion'))$('#txt_gestion').val(o.data('gestion'));
    if(o.data('tema'))$('#txt_tema').val(o.data('tema'));
    const tn=o.data('tutor-nombre');
    const tt=o.data('tutor-tel');
    if(tn&&tn!=='Sin tutor'){
        $('#tutor_nombre_show').text(tn);
        $('#tutor_tel_show').text(tt?'Tel: '+tt:'');
        $('#tutor_info').slideDown(200);
    }else{$('#tutor_info').slideUp(200)}
});

function limpiarForm(){
    $('#h_asig,#h_pro,#h_tutor').val('');
    $('#txt_gestion').val('<?=date("Y")?>');
    $('#sel_mod,#sel_t1,#sel_t2').val('');
    $('#txt_tema').val('');
    $('#tutor_info').slideUp(200);
}

$('#sel_mod').change(function(){
    $(this).val()==='EXAMEN_GRADO'?$('#tema_div').slideUp(200):$('#tema_div').slideDown(200);
});

// Agregar aula
async function agregarAula(){
    const{value:n}=await Swal.fire({title:'Nueva Aula / Lugar',input:'text',inputLabel:'Nombre del lugar',inputPlaceholder:'Ej: Sala de Juntas 1',showCancelButton:true,confirmButtonColor:'#6366f1',inputValidator:v=>{if(!v)return'Debes escribir un nombre'}});
    if(n)$.post('',{action:'nueva_aula',nombre_aula:n},r=>{if(r.exito){$('#sel_aula').append(`<option value="${r.id}" selected>${n}</option>`);Swal.fire({icon:'success',title:'Registrada',timer:1500,showConfirmButton:false})}},'json');
}

// ============ PROGRAMAR PREDEFENSA (SIN PROGRAMAR) ============
async function programarPredefensa(btn) {
    const row = $(btn).closest('tr');
    const idEstudiante = row.data('id-estudiante');
    const idProyecto = row.data('id-proyecto');
    const gestion = row.data('gestion') || '<?=date("Y")?>';
    const proyecto = row.data('proyecto');
    
    // Obtener datos completos
    $.post('', {action: 'obtener_estudiante', id_estudiante: idEstudiante}, function(resp) {
        if (!resp.exito) {
            Swal.fire('Error', 'No se pudieron cargar los datos', 'error');
            return;
        }
        
        const est = resp.estudiante;
        const trib = resp.tribunal;
        
        Swal.fire({
            title: '<i class="fas fa-calendar-plus"></i> Programar Predefensa',
            html: `
                <div style="text-align:left;max-width:600px;margin:0 auto">
                    <div style="background:#f1f5f9;padding:16px;border-radius:12px;margin-bottom:20px">
                        <strong style="font-size:1rem;color:#6366f1">${est.nombre_completo}</strong>
                        <div style="font-size:.85rem;color:#64748b;margin-top:4px">${est.nombre_carrera}</div>
                        ${est.tutor_nombre ? '<div style="font-size:.8rem;margin-top:4px"><i class="fas fa-user-tie"></i> Tutor: '+est.tutor_nombre+'</div>' : ''}
                    </div>
                    
                    ${trib ? '<div style="background:#fef3c7;padding:12px;border-radius:10px;margin-bottom:20px;font-size:.85rem"><i class="fas fa-info-circle"></i> <strong>Tribunal ya asignado:</strong> P: '+trib.presidente_nombre+' / S: '+trib.secretario_nombre+'</div>' : ''}
                    
                    <div class="row g-3">
                        <div class="col-12">
                            <label style="font-size:.82rem;font-weight:600;margin-bottom:6px;display:block">Modalidad de Titulación</label>
                            <select id="swal_modalidad" class="swal2-select" style="width:100%;padding:10px;border-radius:12px">
                                <option value="PROYECTO_GRADO">Proyecto de Grado</option>
                                <option value="TESIS">Tesis</option>
                                <option value="TRABAJO_DIRIGIDO">Trabajo Dirigido</option>
                                <option value="EXAMEN_GRADO">Examen de Grado</option>
                            </select>
                        </div>
                        <div class="col-12" id="swal_tema_div">
                            <label style="font-size:.82rem;font-weight:600;margin-bottom:6px;display:block">Tema del Proyecto</label>
                            <input type="text" id="swal_tema" class="swal2-input" style="width:100%;padding:10px;border-radius:12px" value="${proyecto || ''}" placeholder="Ingrese el tema...">
                        </div>
                        <div class="col-12">
                            <label style="font-size:.82rem;font-weight:600;margin-bottom:6px;display:block">Aula / Lugar</label>
                            <select id="swal_aula" class="swal2-select" style="width:100%;padding:10px;border-radius:12px">
                                <option value="">-- Seleccionar Aula --</option>
                                <?php foreach($aulas as $a):?>
                                <option value="<?=$a['id_aula']?>"><?=htmlspecialchars($a['nombre_aula'])?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="col-7">
                            <label style="font-size:.82rem;font-weight:600;margin-bottom:6px;display:block">Fecha</label>
                            <input type="date" id="swal_fecha" class="swal2-input" style="width:100%;padding:10px;border-radius:12px" min="<?=date('Y-m-d')?>">
                        </div>
                        <div class="col-5">
                            <label style="font-size:.82rem;font-weight:600;margin-bottom:6px;display:block">Hora</label>
                            <input type="time" id="swal_hora" class="swal2-input" style="width:100%;padding:10px;border-radius:12px">
                        </div>
                    </div>
                </div>
            `,
            width: 700,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Programar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#6366f1',
            didOpen: () => {
                $('#swal_modalidad').change(function() {
                    $(this).val() === 'EXAMEN_GRADO' ? $('#swal_tema_div').hide() : $('#swal_tema_div').show();
                });
            },
            preConfirm: () => {
                const aula = $('#swal_aula').val();
                const fecha = $('#swal_fecha').val();
                const hora = $('#swal_hora').val();
                if (!aula || !fecha || !hora) {
                    Swal.showValidationMessage('Por favor completa todos los campos obligatorios');
                    return false;
                }
                return {
                    modalidad: $('#swal_modalidad').val(),
                    tema: $('#swal_tema').val(),
                    aula, fecha, hora
                };
            }
        }).then(result => {
            if (result.isConfirmed) {
                $.post('', {
                    action: 'programar_predefensa',
                    id_estudiante: idEstudiante,
                    id_proyecto: idProyecto,
                    gestion: gestion,
                    modalidad_titulacion: result.value.modalidad,
                    tema: result.value.tema,
                    id_aula: result.value.aula,
                    fecha: result.value.fecha,
                    hora: result.value.hora
                }, function(r) {
                    if (r.exito) {
                        Swal.fire({
                            icon: 'success',
                            title: '¡Programada!',
                            text: r.mensaje,
                            timer: 2000
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', r.mensaje, 'error');
                    }
                }, 'json');
            }
        });
    }, 'json');
}

// ============ CALIFICAR PREDEFENSA ============
async function calificarPredefensa(idPd, fechaPd) {
    Swal.fire({
        title: '<i class="fas fa-star"></i> Calificar Predefensa',
        html: `
            <div style="text-align:left;max-width:500px;margin:0 auto">
                <div style="background:#f1f5f9;padding:16px;border-radius:12px;margin-bottom:20px">
                    <div style="font-size:.9rem;color:#64748b">Predefensa ID: <strong>#${idPd}</strong></div>
                    <div style="font-size:.85rem;color:#64748b;margin-top:4px">Fecha: ${fechaPd}</div>
                </div>
                
                <div style="background:#fef3c7;padding:14px;border-radius:10px;margin-bottom:20px;font-size:.85rem">
                    <i class="fas fa-info-circle"></i> <strong>Nota mínima de aprobación: 41 puntos</strong>
                </div>
                
                <label style="font-size:.9rem;font-weight:600;margin-bottom:8px;display:block">Nota (0-100)</label>
                <input type="number" id="swal_nota" class="swal2-input" min="0" max="100" step="0.5" placeholder="Ingrese la nota..." style="width:100%;padding:14px;border-radius:12px;font-size:1.2rem;font-weight:700;text-align:center">
                
                <label style="font-size:.9rem;font-weight:600;margin:16px 0 8px;display:block">Observaciones (Opcional)</label>
                <textarea id="swal_obs" class="swal2-textarea" placeholder="Comentarios sobre la evaluación..." style="width:100%;padding:12px;border-radius:12px;min-height:100px"></textarea>
            </div>
        `,
        width: 600,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check"></i> Guardar Calificación',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#10b981',
        preConfirm: () => {
            const nota = parseFloat($('#swal_nota').val());
            if (isNaN(nota) || nota < 0 || nota > 100) {
                Swal.showValidationMessage('Ingresa una nota válida entre 0 y 100');
                return false;
            }
            return {
                nota: nota,
                obs: $('#swal_obs').val()
            };
        }
    }).then(result => {
        if (result.isConfirmed) {
            const nota = result.value.nota;
            const aprobado = nota >= 41;
            
            Swal.fire({
                title: aprobado ? '¿Confirmar Aprobación?' : '¿Confirmar Reprobación?',
                html: `
                    <div style="text-align:center;padding:20px">
                        <div style="font-size:3rem;margin-bottom:16px">${aprobado ? '✅' : '❌'}</div>
                        <div style="font-size:1.8rem;font-weight:700;margin-bottom:12px;color:${aprobado ? '#10b981' : '#ef4444'}">
                            ${nota.toFixed(1)} puntos
                        </div>
                        <div style="font-size:1rem;color:#64748b">
                            ${aprobado ? 
                                '<strong>APROBADO</strong><br>Se programará automáticamente la defensa final en 30 días hábiles.' : 
                                '<strong>REPROBADO</strong><br>El estudiante deberá reprogramar su predefensa.'}
                        </div>
                    </div>
                `,
                icon: aprobado ? 'success' : 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, confirmar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: aprobado ? '#10b981' : '#ef4444'
            }).then(confirm => {
                if (confirm.isConfirmed) {
                    $.post('', {
                        action: 'calificar_predefensa',
                        id_pre_defensa: idPd,
                        nota: nota,
                        observaciones: result.value.obs,
                        fecha_predefensa: fechaPd
                    }, function(r) {
                        if (r.exito) {
                            Swal.fire({
                                icon: r.estado === 'APROBADA' ? 'success' : 'info',
                                title: r.estado === 'APROBADA' ? '¡Aprobado!' : 'Reprobado',
                                text: r.mensaje,
                                timer: 3000
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error', r.mensaje, 'error');
                        }
                    }, 'json');
                }
            });
        }
    });
}

// ============ REPROGRAMAR PREDEFENSA ============
async function reprogramarPredefensa(idPd) {
    Swal.fire({
        title: '<i class="fas fa-redo"></i> Reprogramar Predefensa',
        html: `
            <div style="text-align:left;max-width:500px;margin:0 auto">
                <div style="background:#f1f5f9;padding:16px;border-radius:12px;margin-bottom:20px">
                    <div style="font-size:.9rem;color:#64748b">Predefensa ID: <strong>#${idPd}</strong></div>
                </div>
                
                <div style="background:#fef3c7;padding:14px;border-radius:10px;margin-bottom:20px;font-size:.85rem">
                    <i class="fas fa-info-circle"></i> Asigna una nueva fecha para la predefensa
                </div>
                
                <label style="font-size:.9rem;font-weight:600;margin-bottom:8px;display:block">Nueva Aula</label>
                <select id="swal_nueva_aula" class="swal2-select" style="width:100%;padding:12px;border-radius:12px;margin-bottom:16px">
                    <option value="">-- Seleccionar --</option>
                    <?php foreach($aulas as $a):?>
                    <option value="<?=$a['id_aula']?>"><?=htmlspecialchars($a['nombre_aula'])?></option>
                    <?php endforeach;?>
                </select>
                
                <div class="row g-2">
                    <div class="col-7">
                        <label style="font-size:.9rem;font-weight:600;margin-bottom:8px;display:block">Nueva Fecha</label>
                        <input type="date" id="swal_nueva_fecha" class="swal2-input" style="width:100%;padding:12px;border-radius:12px" min="<?=date('Y-m-d')?>">
                    </div>
                    <div class="col-5">
                        <label style="font-size:.9rem;font-weight:600;margin-bottom:8px;display:block">Nueva Hora</label>
                        <input type="time" id="swal_nueva_hora" class="swal2-input" style="width:100%;padding:12px;border-radius:12px">
                    </div>
                </div>
            </div>
        `,
        width: 600,
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-check"></i> Reprogramar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#f59e0b',
        preConfirm: () => {
            const aula = $('#swal_nueva_aula').val();
            const fecha = $('#swal_nueva_fecha').val();
            const hora = $('#swal_nueva_hora').val();
            if (!aula || !fecha || !hora) {
                Swal.showValidationMessage('Completa todos los campos');
                return false;
            }
            return {aula, fecha, hora};
        }
    }).then(result => {
        if (result.isConfirmed) {
            $.post('', {
                action: 'reprogramar_predefensa',
                id_pre_defensa: idPd,
                id_aula: result.value.aula,
                fecha: result.value.fecha,
                hora: result.value.hora
            }, function(r) {
                if (r.exito) {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Reprogramada!',
                        text: r.mensaje,
                        timer: 2000
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', r.mensaje, 'error');
                }
            }, 'json');
        }
    });
}

// ============ GESTIONAR TRIBUNAL ============
async function gestionarTribunal(idPd) {
    // Obtener tribunal actual
    $.post('', {action: 'obtener_tribunal', id_pre_defensa: idPd}, function(resp) {
        if (!resp.exito) {
            Swal.fire('Error', 'No se pudo cargar el tribunal', 'error');
            return;
        }
        
        let tribunalHtml = '<div style="max-height:200px;overflow-y:auto;margin-bottom:16px">';
        if (resp.tribunal.length > 0) {
            resp.tribunal.forEach(m => {
                tribunalHtml += `
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;background:#f1f5f9;border-radius:10px;margin-bottom:8px">
                        <div>
                            <strong>${m.nombre_completo}</strong>
                            <div style="font-size:.8rem;color:#64748b">${m.rol_tribunal}</div>
                        </div>
                        <button onclick="quitarMiembroTribunal(${idPd}, ${m.id_docente})" class="btn btn-sm btn-danger" style="border-radius:8px">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            });
        } else {
            tribunalHtml += '<div style="text-align:center;color:#64748b;padding:20px">No hay miembros asignados</div>';
        }
        tribunalHtml += '</div>';
        
        Swal.fire({
            title: '<i class="fas fa-gavel"></i> Gestionar Tribunal',
            html: `
                <div style="text-align:left">
                    <h6 style="margin-bottom:12px;color:#6366f1">Miembros Actuales:</h6>
                    ${tribunalHtml}
                    
                    <hr style="margin:20px 0">
                    
                    <h6 style="margin-bottom:12px;color:#6366f1">Agregar Nuevo Miembro:</h6>
                    <select id="swal_docente" class="swal2-select" style="width:100%;padding:10px;border-radius:12px;margin-bottom:10px">
                        <option value="">-- Seleccionar Docente --</option>
                        <?php foreach($docentes as $d):?>
                        <option value="<?=$d['id_persona']?>"><?=htmlspecialchars($d['nombre'])?></option>
                        <?php endforeach;?>
                    </select>
                    <select id="swal_rol" class="swal2-select" style="width:100%;padding:10px;border-radius:12px">
                        <option value="PRESIDENTE">Presidente</option>
                        <option value="SECRETARIO">Secretario</option>
                        <option value="VOCAL">Vocal</option>
                    </select>
                </div>
            `,
            width: 650,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-plus"></i> Agregar',
            cancelButtonText: 'Cerrar',
            confirmButtonColor: '#6366f1',
            preConfirm: () => {
                const doc = $('#swal_docente').val();
                if (!doc) {
                    Swal.showValidationMessage('Selecciona un docente');
                    return false;
                }
                return {doc: doc, rol: $('#swal_rol').val()};
            }
        }).then(result => {
            if (result.isConfirmed) {
                $.post('', {
                    action: 'agregar_tribunal',
                    id_pre_defensa: idPd,
                    id_docente: result.value.doc,
                    rol_tribunal: result.value.rol
                }, function(r) {
                    if (r.exito) {
                        Swal.fire({icon:'success',title:'Agregado',timer:1200,showConfirmButton:false}).then(() => gestionarTribunal(idPd));
                    } else {
                        Swal.fire('Error', r.mensaje, 'error');
                    }
                }, 'json');
            }
        });
    }, 'json');
}

function quitarMiembroTribunal(idPd, idDocente) {
    Swal.fire({
        title: '¿Quitar del tribunal?',
        text: 'Este docente será removido del tribunal',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Sí, quitar'
    }).then(r => {
        if (r.isConfirmed) {
            $.post('', {action: 'quitar_tribunal', id_pre_defensa: idPd, id_docente: idDocente}, function(resp) {
                if (resp.exito) {
                    Swal.fire({icon:'success',title:'Removido',timer:1200,showConfirmButton:false}).then(() => gestionarTribunal(idPd));
                } else {
                    Swal.fire('Error', resp.mensaje, 'error');
                }
            }, 'json');
        }
    });
}

// Submit form
$('#formPrincipal').submit(function(e){
    e.preventDefault();
    const t1=$('#sel_t1').val(),t2=$('#sel_t2').val(),tu=$('#h_tutor').val();
    if(t1&&t2&&t1===t2){Swal.fire({icon:'warning',title:'Error',text:'Presidente y secretario no pueden ser la misma persona.'});return}
    if(tu&&(tu===t1||tu===t2)){Swal.fire({icon:'warning',title:'Conflicto',text:'El tutor no puede ser parte del tribunal.'});return}
    const est=$('#sel_est option:selected').text().trim();
    Swal.fire({title:'¿Confirmar Registro?',html:`Predefensa de <strong>${est}</strong> con tribunal asignado.`,icon:'question',showCancelButton:true,confirmButtonColor:'#6366f1',confirmButtonText:'<i class="fas fa-check"></i> Registrar',cancelButtonText:'Cancelar'}).then(r=>{
        if(r.isConfirmed)$.post('',$(this).serialize(),r=>{r.exito?Swal.fire({icon:'success',title:'¡Registrado!',text:r.mensaje}).then(()=>location.reload()):Swal.fire('Error',r.mensaje,'error')},'json');
    });
});

// Counter animation
document.querySelectorAll('.sn').forEach(c=>{const t=parseInt(c.textContent);if(isNaN(t)||t===0)return;let cur=0;const inc=t/(1200/16);const up=()=>{cur+=inc;cur<t?(c.textContent=Math.floor(cur),requestAnimationFrame(up)):c.textContent=t};c.textContent='0';const ob=new IntersectionObserver(e=>{e.forEach(en=>{en.isIntersecting&&(up(),ob.unobserve(en.target))})});ob.observe(c)});
</script>
</body>
</html>