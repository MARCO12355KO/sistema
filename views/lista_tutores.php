<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

$rol_usuario = $_SESSION["role"] ?? 'Invitado';
$es_admin = (strtolower($rol_usuario) === 'administrador');
$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($nombre_usuario, 0, 1));
$estado_filtro = $_GET['estado'] ?? '';
$carrera_filtro = $_GET['carrera'] ?? '';
$especialidad_filtro = $_GET['especialidad'] ?? '';

try {
    $carreras = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera ASC")->fetchAll(PDO::FETCH_ASSOC);

    $especialidades = $pdo->query("SELECT DISTINCT especialidad FROM docentes WHERE especialidad IS NOT NULL AND especialidad != '' ORDER BY especialidad ASC")->fetchAll(PDO::FETCH_ASSOC);
    $params = [];
    $condiciones = [];

    if (!empty($estado_filtro)) {
        $condiciones[] = "p.estado = ?";
        $params[] = $estado_filtro;
    }

    if (!empty($carrera_filtro)) {
        $condiciones[] = "d.id_carrera = ?";
        $params[] = (int)$carrera_filtro;
    }

    if (!empty($especialidad_filtro)) {
        $condiciones[] = "d.especialidad = ?";
        $params[] = $especialidad_filtro;
    }

    $sql = "SELECT p.id_persona, p.primer_nombre, p.primer_apellido, p.ci, p.celular, p.estado,
                   d.especialidad, d.es_tutor, d.es_tribunal, c.nombre_carrera,
                   (SELECT COUNT(*) FROM asignaciones_tutor at 
                    WHERE at.id_docente = d.id_persona AND at.estado = 'ACTIVO') as total_estudiantes
            FROM personas p
            JOIN docentes d ON p.id_persona = d.id_persona
            LEFT JOIN carreras c ON d.id_carrera = c.id_carrera";

    if (!empty($condiciones)) {
        $sql .= " WHERE " . implode(" AND ", $condiciones);
    }

    $sql .= " ORDER BY p.primer_apellido ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $docentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_docentes = count($docentes);
    $total_activos = count(array_filter($docentes, fn($d) => $d['estado'] === 'ACTIVO'));
    $total_con_estudiantes = count(array_filter($docentes, fn($d) => $d['total_estudiantes'] > 0));
    $sqlAsig = "SELECT at.id_asignacion, 
                       p_est.primer_nombre as est_nom, p_est.primer_apellido as est_ape, 
                       p_doc.primer_nombre as tut_nom, p_doc.primer_apellido as tut_ape, 
                       c.nombre_carrera, at.gestion, at.estado as asig_estado
                FROM asignaciones_tutor at
                JOIN estudiantes e ON at.id_estudiante = e.id_persona
                JOIN personas p_est ON e.id_persona = p_est.id_persona
                JOIN docentes d ON at.id_docente = d.id_persona
                JOIN personas p_doc ON d.id_persona = p_doc.id_persona
                JOIN carreras c ON e.id_carrera = c.id_carrera
                ORDER BY at.fecha_asignacion DESC";
    $asignaciones = $pdo->query($sqlAsig)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Tutores | UNIOR</title>
    <link rel="icon" type="image/png" href="../assets/img/logo_unior1.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@300;500;800&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            --glass: rgba(255, 255, 255, 0.75);
            --glass-border: rgba(255, 255, 255, 0.9);
            --accent: #6366f1;
            --accent-light: #818cf8;
            --accent-dark: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --bg-main: #f8fafc;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 40px rgba(0, 0, 0, 0.08);
            --ease: cubic-bezier(0.4, 0, 0.2, 1);
            --sidebar-closed: 85px;
            --sidebar-open: 280px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-main);
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.1) 0, transparent 50%),
                radial-gradient(at 100% 100%, rgba(129, 140, 248, 0.08) 0, transparent 50%);
            min-height: 100vh;
            color: var(--text-dark);
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        /* ============== SIDEBAR (igual que otros módulos) ============== */
        .sidebar {
            background: var(--glass);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1.5px solid var(--glass-border);
            z-index: 2000;
            transition: all 0.5s var(--ease);
            box-shadow: var(--shadow-lg);
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
            .sidebar:hover .nav-item-ae span { 
                opacity: 1; 
                display: inline; 
                transform: translateX(0);
            }
            .sidebar:hover .logo-text {
                opacity: 1;
                width: auto;
                margin-left: 12px;
            }
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
                width: auto;
                border-radius: 28px;
                flex-direction: row;
                justify-content: space-around;
                display: flex;
                align-items: center;
                padding: 0 20px;
            }
            .sidebar .logo-aesthetic, 
            .sidebar .nav-item-ae span, 
            .sidebar .mt-auto,
            .sidebar .logo-text { 
                display: none; 
            }
            .sidebar nav { 
                display: flex; 
                width: 100%; 
                justify-content: space-around;
                align-items: center;
            }
            .nav-item-ae { 
                width: auto; 
                padding: 14px; 
                margin-bottom: 0;
            }
            .main-stage { 
                padding: 90px 20px 120px 20px; 
            }
            .mobile-top-bar {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: 70px;
                background: var(--glass);
                backdrop-filter: blur(15px);
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 0 20px;
                z-index: 1500;
                border-bottom: 1.5px solid var(--glass-border);
                box-shadow: var(--shadow-md);
            }
        }

        .logo-aesthetic {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 40px;
            padding: 0 12px;
        }

        .logo-text {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-weight: 800;
            font-size: 1.4rem;
            color: var(--accent);
            opacity: 0;
            width: 0;
            overflow: hidden;
            white-space: nowrap;
            transition: all 0.4s var(--ease);
        }

        .nav-item-ae {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px 18px;
            margin-bottom: 6px;
            border-radius: 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s var(--ease);
            position: relative;
        }

        @media (min-width: 992px) {
            .sidebar:hover .nav-item-ae { 
                justify-content: flex-start; 
            }
        }

        .nav-item-ae i { 
            font-size: 1.25rem; 
            min-width: 50px; 
            text-align: center;
            transition: all 0.3s var(--ease);
        }

        .nav-item-ae span { 
            display: none; 
            opacity: 0; 
            font-weight: 600; 
            font-size: 0.95rem;
            margin-left: 10px; 
            transition: all 0.3s var(--ease); 
            white-space: nowrap;
            transform: translateX(-10px);
        }

        .nav-item-ae:hover { 
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.05));
            color: var(--accent);
            transform: translateX(4px);
        }

        .nav-item-ae.active { 
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3);
        }

        .nav-item-ae.text-danger:hover { 
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));
            color: var(--danger);
        }

        /* ============== USER BADGE ============== */
        .user-badge {
            background: var(--glass);
            backdrop-filter: blur(10px);
            padding: 8px 10px 8px 24px;
            border-radius: 100px;
            border: 1.5px solid var(--glass-border);
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s var(--ease);
        }

        .user-badge:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        /* ============== HEADER ============== */
        .section-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: clamp(2.5rem, 8vw, 4.5rem);
            font-weight: 300;
            letter-spacing: -2px;
            margin-bottom: 12px;
            line-height: 0.95;
            background: linear-gradient(135deg, var(--text-dark) 0%, var(--text-muted) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .section-accent {
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .breadcrumb-custom {
            background: rgba(99, 102, 241, 0.05);
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 32px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.85rem;
            font-weight: 500;
        }

        .breadcrumb-custom a {
            color: var(--accent);
            text-decoration: none;
        }

        /* ============== STATS CARDS ============== */
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s var(--ease);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 16px;
        }

        .stat-icon.total {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
        }

        .stat-icon.active {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.15));
            color: var(--success);
        }

        .stat-icon.working {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.15));
            color: var(--warning);
        }

        .stat-number {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        /* ============== TABS ============== */
        .nav-pills .nav-link {
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.08);
            padding: 12px 24px;
            border-radius: 14px;
            color: var(--text-muted);
            font-weight: 600;
            transition: all 0.3s var(--ease);
        }

        .nav-pills .nav-link:hover {
            border-color: var(--accent);
            color: var(--accent);
            transform: translateY(-2px);
        }

        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border-color: transparent;
            box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3);
        }

        /* ============== FILTROS ============== */
        .filter-section {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-md);
        }

        .search-input {
            padding: 14px 20px 14px 45px;
            border-radius: 16px;
            border: 2px solid rgba(0, 0, 0, 0.08);
            background: white;
            transition: all 0.3s var(--ease);
            font-weight: 500;
        }

        .search-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        .btn-search {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 700;
            transition: all 0.3s var(--ease);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .btn-search:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
        }

        .btn-new {
            background: linear-gradient(135deg, var(--text-dark), #334155);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 16px;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s var(--ease);
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.3);
        }

        .btn-new:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.4);
            color: white;
        }

        /* ============== TABLA ============== */
        .cloud-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 28px;
            padding: 28px;
            box-shadow: var(--shadow-md);
        }

        .table-modern {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
        }

        .table-modern thead th {
            border: none;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            font-weight: 700;
            padding: 12px 16px;
            background: transparent;
        }

        .table-modern tbody tr {
            background: white;
            border-radius: 16px;
            transition: all 0.3s var(--ease);
        }

        .table-modern tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.08);
        }

        .table-modern tbody td {
            padding: 20px 16px;
            border: none;
            background: white;
            vertical-align: middle;
        }

        .table-modern tbody tr td:first-child {
            border-radius: 16px 0 0 16px;
        }

        .table-modern tbody tr td:last-child {
            border-radius: 0 16px 16px 0;
        }

        .tutor-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tutor-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--warning), #fb923c);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .tutor-name {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 4px;
            font-size: 0.95rem;
        }

        .tutor-meta {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        .status-badge {
            padding: 6px 16px;
            border-radius: 100px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-activo {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
        }

        .status-inactivo {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }

        .badge-students {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .btn-action {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(0, 0, 0, 0.08);
            background: white;
            color: var(--text-dark);
            transition: all 0.3s var(--ease);
            text-decoration: none;
            font-size: 0.9rem;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .btn-action.view:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
        }

        .btn-action.edit:hover {
            background: var(--warning);
            color: white;
            border-color: var(--warning);
        }

        .btn-action.toggle:hover {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .btn-action.danger:hover {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-icon {
            font-size: 4rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 20px;
        }

        .empty-text {
            color: var(--text-muted);
            font-size: 1.1rem;
            font-weight: 500;
        }

        /* ============== ANIMACIONES ============== */
        .reveal {
            animation: fadeInUp 0.8s var(--ease) forwards;
            opacity: 0;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ============== RESPONSIVE ============== */
        @media (max-width: 768px) {
            .section-title {
                font-size: 2.5rem;
            }

            .stats-container {
                grid-template-columns: 1fr;
            }

            .tutor-avatar {
                width: 38px;
                height: 38px;
                font-size: 0.9rem;
            }

            .table-modern tbody td {
                padding: 16px 12px;
            }
        }
    </style>
</head>
<body>

    <!-- MOBILE TOP BAR -->
    <div class="mobile-top-bar">
        <div class="d-flex align-items-center">
            <img src="../assets/img/logo_unior1.png" height="40" alt="Logo">
            <span class="ms-2 fw-800" style="font-family: 'Bricolage Grotesque'; color: var(--accent);">UNIOR</span>
        </div>
        <div class="user-badge">
            <div class="user-avatar">
                <?= $inicial ?>
            </div>
        </div>
    </div>

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="logo-aesthetic d-none d-lg-flex">
            <img src="../assets/img/logo_unior1.png" width="48" alt="Logo">
            <span class="logo-text">UNIOR</span>
        </div>
        <nav>
            <a href="menu.php" class="nav-item-ae">
                <i class="fas fa-home-alt"></i> <span>Menú</span>
            </a>
            <a href="lisra_estudiantes.php" class="nav-item-ae">
                <i class="fas fa-users-rays"></i> <span>Estudiantes</span>
            </a>
            <a href="registro_tutores.php" class="nav-item-ae">
                <i class="fas fa-user-tie"></i> <span>Registrar Tutor</span>
            </a>
            <a href="lista_tutores.php" class="nav-item-ae active">
                <i class="fas fa-fingerprint"></i> <span>Lista Tutores</span>
            </a>
            <a href="predefensas.php" class="nav-item-ae">
                <i class="fas fa-signature"></i> <span>Predefensas</span>
            </a>
            <?php if($es_admin): ?>
            <a href="logs.php" class="nav-item-ae">
                <i class="bi bi-backpack4-fill"></i> <span>Logs</span>
            </a>
            <?php endif; ?>
        </nav>
        <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex">
            <i class="fas fa-power-off"></i> <span>Salir</span>
        </a>
    </aside>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="main-stage">
        <!-- USER BADGE DESKTOP -->
        <div class="d-none d-lg-flex justify-content-end mb-4 reveal">
            <div class="user-badge">
                <div class="text-end">
                    <div class="fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($nombre_usuario) ?></div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; font-weight: 600;">
                        <?= strtoupper($rol_usuario) ?>
                    </div>
                </div>
                <div class="user-avatar">
                    <?= $inicial ?>
                </div>
            </div>
        </div>

        <!-- BREADCRUMB -->
        <div class="reveal">
            <nav class="breadcrumb-custom">
                <a href="menu.php">
                    <i class="fas fa-home"></i> Inicio
                </a>
                <i class="fas fa-chevron-right" style="font-size: 0.7rem; color: var(--text-muted);"></i>
                <span>Gestión de Tutores</span>
            </nav>
        </div>

        <!-- HEADER -->
        <header class="reveal" style="animation-delay: 0.1s;">
            <p class="text-muted fw-600 mb-2" style="letter-spacing: 2px; font-size: 0.7rem; text-transform: uppercase;">
                <i class="fas fa-chalkboard-teacher me-2"></i>Gestión Académica
            </p>
            <h1 class="section-title">
                Gestión de <span class="section-accent">Tutores.</span>
            </h1>
        </header>

        <!-- ESTADÍSTICAS -->
        <div class="stats-container reveal" style="animation-delay: 0.2s;">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-chalkboard-teacher"></i>
                </div>
                <div class="stat-number"><?= $total_docentes ?></div>
                <div class="stat-label">Total Docentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?= $total_activos ?></div>
                <div class="stat-label">Docentes Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon working">
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="stat-number"><?= $total_con_estudiantes ?></div>
                <div class="stat-label">Con Estudiantes</div>
            </div>
        </div>

        <!-- TABS -->
        <div class="cloud-card reveal" style="animation-delay: 0.3s;">
            <ul class="nav nav-pills mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-docentes" type="button">
                        <i class="fas fa-users me-2"></i>Plantel de Docentes
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-asignaciones" type="button">
                        <i class="fas fa-link me-2"></i>Asignaciones Activas
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <!-- TAB: DOCENTES -->
                <div class="tab-pane fade show active" id="tab-docentes" role="tabpanel">
                    <!-- FILTROS -->
                    <div class="filter-section mb-4">
                        <form method="GET" class="row g-3">
                            <div class="col-12 col-md-4">
                                <select name="carrera" class="form-select search-input" style="padding-left: 20px;">
                                    <option value="">Todas las carreras</option>
                                    <?php foreach($carreras as $c): ?>
                                        <option value="<?= $c['id_carrera'] ?>" <?= $carrera_filtro == $c['id_carrera'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['nombre_carrera']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-12 col-md-3">
                                <select name="especialidad" class="form-select search-input" style="padding-left: 20px;">
                                    <option value="">Todas especialidades</option>
                                    <?php foreach($especialidades as $e): ?>
                                        <option value="<?= $e['especialidad'] ?>" <?= $especialidad_filtro == $e['especialidad'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($e['especialidad']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php if($es_admin): ?>
                            <div class="col-6 col-md-2">
                                <select name="estado" class="form-select search-input" style="padding-left: 20px;">
                                    <option value="">Todos</option>
                                    <option value="ACTIVO" <?= $estado_filtro === 'ACTIVO' ? 'selected' : '' ?>>✓ Activos</option>
                                    <option value="INACTIVO" <?= $estado_filtro === 'INACTIVO' ? 'selected' : '' ?>>✗ Inactivos</option>
                                </select>
                            </div>
                            <?php endif; ?>

                            <div class="col-6 col-md-<?= $es_admin ? '3' : '5' ?>">
                                <button type="submit" class="btn btn-search w-100">
                                    <i class="fas fa-filter me-2"></i>Filtrar
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- BOTÓN NUEVO -->
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="position-relative flex-grow-1 me-3">
                            <i class="fas fa-search position-absolute" style="left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                            <input 
                                type="text" 
                                id="searchDocentes"
                                class="form-control search-input" 
                                placeholder="Buscar por nombre, apellido, CI..."
                                style="padding-left: 45px;"
                            >
                        </div>
                        <?php if($es_admin): ?>
                        <a href="registro_tutores.php" class="btn-new">
                            <i class="fas fa-user-plus"></i>
                            <span>Nuevo Docente</span>
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- TABLA DOCENTES -->
                    <div class="table-responsive">
                        <table class="table-modern" id="tablaDocentes">
                            <thead>
                                <tr>
                                    <th>Docente</th>
                                    <th class="d-none d-md-table-cell">Carrera</th>
                                    <th class="d-none d-lg-table-cell">Especialidad</th>
                                    <th>Estudiantes</th>
                                    <th>Estado</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($docentes)): ?>
                                    <tr>
                                        <td colspan="6" style="border-radius: 16px;">
                                            <div class="empty-state">
                                                <div class="empty-icon">
                                                    <i class="fas fa-user-slash"></i>
                                                </div>
                                                <div class="empty-text">
                                                    No se encontraron docentes con los filtros aplicados
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($docentes as $d): 
                                        $inicialDoc = strtoupper(substr($d['primer_nombre'], 0, 1));
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="tutor-info">
                                                <div class="tutor-avatar">
                                                    <?= $inicialDoc ?>
                                                </div>
                                                <div>
                                                    <div class="tutor-name">
                                                        <?= htmlspecialchars($d['primer_apellido'] . ' ' . $d['primer_nombre']) ?>
                                                    </div>
                                                    <div class="tutor-meta">
                                                        <i class="fas fa-id-card" style="font-size: 0.7rem;"></i> <?= $d['ci'] ?>
                                                        <?php if($d['celular']): ?>
                                                        <span class="mx-1">•</span>
                                                        <i class="fas fa-mobile-alt" style="font-size: 0.7rem;"></i> <?= $d['celular'] ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <div style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">
                                                <i class="fas fa-book me-1" style="font-size: 0.75rem;"></i>
                                                <?= htmlspecialchars($d['nombre_carrera'] ?? 'No asignada') ?>
                                            </div>
                                        </td>
                                        <td class="d-none d-lg-table-cell">
                                            <div style="color: var(--text-muted); font-size: 0.85rem;">
                                                <?= htmlspecialchars($d['especialidad'] ?? 'General') ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge-students">
                                                <i class="fas fa-user-graduate me-1"></i>
                                                <?= $d['total_estudiantes'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($d['estado']) ?>">
                                                <?= $d['estado'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="d-flex justify-content-end gap-2">
                                                <a href="perfil_tutor.php?id=<?= $d['id_persona'] ?>" 
                                                   class="btn-action view" 
                                                   title="Ver perfil">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <?php if($es_admin): ?>
                                                    <a href="editar_tutor.php?id=<?= $d['id_persona'] ?>" 
                                                       class="btn-action edit"
                                                       title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <button 
                                                        onclick="cambiarEstadoDocente(<?= $d['id_persona'] ?>, '<?= $d['estado'] ?>')" 
                                                        class="btn-action toggle"
                                                        title="Cambiar estado">
                                                        <i class="fas fa-sync-alt"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: ASIGNACIONES -->
                <div class="tab-pane fade" id="tab-asignaciones" role="tabpanel">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div class="position-relative flex-grow-1 me-3">
                            <i class="fas fa-search position-absolute" style="left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                            <input 
                                type="text" 
                                id="searchAsignaciones"
                                class="form-control search-input" 
                                placeholder="Buscar por estudiante, tutor, carrera..."
                                style="padding-left: 45px;"
                            >
                        </div>
                        <?php if($es_admin): ?>
                        <a href="asignar_tutores.php" class="btn-new">
                            <i class="fas fa-plus-circle"></i>
                            <span>Nueva Asignación</span>
                        </a>
                        <?php endif; ?>
                    </div>

                    <div class="table-responsive">
                        <table class="table-modern" id="tablaAsignaciones">
                            <thead>
                                <tr>
                                    <th>Estudiante</th>
                                    <th>Tutor Asignado</th>
                                    <th class="d-none d-md-table-cell">Carrera</th>
                                    <th>Gestión</th>
                                    <th>Estado</th>
                                    <?php if($es_admin): ?>
                                    <th class="text-end">Acciones</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($asignaciones)): ?>
                                    <tr>
                                        <td colspan="<?= $es_admin ? '6' : '5' ?>" style="border-radius: 16px;">
                                            <div class="empty-state">
                                                <div class="empty-icon">
                                                    <i class="fas fa-unlink"></i>
                                                </div>
                                                <div class="empty-text">
                                                    No hay asignaciones registradas
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($asignaciones as $a): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark">
                                                <?= htmlspecialchars($a['est_ape'] . ' ' . $a['est_nom']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="color: var(--warning); font-weight: 600;">
                                                <i class="fas fa-user-tie me-1"></i>
                                                <?= htmlspecialchars($a['tut_ape'] . ' ' . $a['tut_nom']) ?>
                                            </div>
                                        </td>
                                        <td class="d-none d-md-table-cell">
                                            <div style="color: var(--text-muted); font-size: 0.85rem;">
                                                <?= htmlspecialchars($a['nombre_carrera']) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-3 py-2">
                                                <?= htmlspecialchars($a['gestion']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= strtolower($a['asig_estado']) ?>">
                                                <?= $a['asig_estado'] ?>
                                            </span>
                                        </td>
                                        <?php if($es_admin): ?>
                                        <td>
                                            <div class="d-flex justify-content-end gap-2">
                                                <button 
                                                    onclick="cambiarEstadoAsignacion(<?= $a['id_asignacion'] ?>, '<?= $a['asig_estado'] ?>')" 
                                                    class="btn-action <?= $a['asig_estado'] === 'ACTIVO' ? 'danger' : 'toggle' ?>"
                                                    title="Cambiar estado">
                                                    <i class="fas <?= $a['asig_estado'] === 'ACTIVO' ? 'fa-times' : 'fa-check' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // BÚSQUEDA EN TIEMPO REAL
        document.getElementById('searchDocentes').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const rows = document.querySelectorAll('#tablaDocentes tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchValue) ? '' : 'none';
            });
        });

        document.getElementById('searchAsignaciones').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const rows = document.querySelectorAll('#tablaAsignaciones tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchValue) ? '' : 'none';
            });
        });

        // CAMBIAR ESTADO DOCENTE
        function cambiarEstadoDocente(id, estadoActual) {
            const nuevoEstado = estadoActual.toUpperCase() === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
            const mensaje = nuevoEstado === 'INACTIVO' 
                ? '¿Desactivar este docente?' 
                : '¿Reactivar este docente?';
            
            Swal.fire({
                title: mensaje,
                text: `El docente pasará a estado ${nuevoEstado}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: nuevoEstado === 'INACTIVO' ? '#ef4444' : '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: nuevoEstado === 'INACTIVO' ? 'Sí, desactivar' : 'Sí, reactivar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `../controllers/cambiar_estado.php?id=${id}&estado=${nuevoEstado}&tipo=docente`;
                }
            });
        }

        // CAMBIAR ESTADO ASIGNACIÓN
        function cambiarEstadoAsignacion(id, estadoActual) {
            const nuevoEstado = estadoActual.toUpperCase() === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
            
            Swal.fire({
                title: '¿Cambiar estado de asignación?',
                text: `La asignación pasará a estado ${nuevoEstado}`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#6366f1',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, cambiar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `../controllers/eliminar_asignacion.php?id=${id}&estado=${nuevoEstado}`;
                }
            });
        }
    </script>
</body>
</html>