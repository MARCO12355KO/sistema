<?php
declare(strict_types=1);
session_start();
require_once '../config/conexion.php';

// 1. Verificación de Sesión
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

// 2. Configuración de Roles y Permisos
$rol = strtolower($_SESSION["role"] ?? 'registro');
$es_admin = ($rol === 'administrador');

$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($nombre_usuario, 0, 1));

// 3. Captura de Filtros (GET)
$search = $_GET['search'] ?? '';
$estado_filtro = $_GET['estado'] ?? '';
$carrera_filtro = $_GET['carrera'] ?? '';

$params = [];
$condiciones = [];

// 4. LÓGICA DE SEGURIDAD DE ESTADOS
if (!$es_admin) {
    $condiciones[] = "p.estado = 'ACTIVO'";
} else {
    if (!empty($estado_filtro)) {
        $condiciones[] = "p.estado = ?";
        $params[] = $estado_filtro;
    }
}

// Filtro por Carrera
if (!empty($carrera_filtro)) {
    $condiciones[] = "c.id_carrera = ?";
    $params[] = (int)$carrera_filtro;
}

// Filtro de Búsqueda
if (!empty($search)) {
    $condiciones[] = "(p.primer_nombre ILIKE ? OR p.primer_apellido ILIKE ? OR p.ci ILIKE ? OR e.ru ILIKE ?)";
    $s = "%$search%";
    array_push($params, $s, $s, $s, $s);
}

// 5. Consultas a la Base de Datos
try {
    $carreras_btn = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera ASC")->fetchAll(PDO::FETCH_ASSOC);

    $sql = "SELECT p.*, e.ru, c.nombre_carrera 
            FROM personas p 
            JOIN estudiantes e ON p.id_persona = e.id_persona 
            JOIN carreras c ON e.id_carrera = c.id_carrera";

    if (!empty($condiciones)) {
        $sql .= " WHERE " . implode(" AND ", $condiciones);
    }
    
    $sql .= " ORDER BY p.primer_apellido ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $estudiantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Contar totales
    $total_estudiantes = count($estudiantes);
    $total_activos = count(array_filter($estudiantes, fn($e) => $e['estado'] === 'ACTIVO'));

} catch (PDOException $e) {
    die("Error en la consulta: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estudiantes | UNIOR</title>
    
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

        /* ============== SIDEBAR ============== */
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

        .carrera-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 20px;
            scrollbar-width: thin;
            scrollbar-color: var(--accent-light) transparent;
        }

        .carrera-scroll::-webkit-scrollbar {
            height: 6px;
        }

        .carrera-scroll::-webkit-scrollbar-thumb {
            background: var(--accent-light);
            border-radius: 10px;
        }

        .btn-tab {
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.08);
            padding: 10px 20px;
            border-radius: 100px;
            white-space: nowrap;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            transition: all 0.3s var(--ease);
            text-decoration: none;
            display: inline-block;
        }

        .btn-tab:hover {
            border-color: var(--accent);
            color: var(--accent);
            transform: translateY(-2px);
        }

        .btn-tab.active {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border-color: transparent;
            box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3);
        }

        .search-input {
            padding: 14px 20px;
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

        .student-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .student-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .student-name {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 4px;
            font-size: 0.95rem;
        }

        .student-meta {
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

        .btn-action.delete:hover {
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

            .student-avatar {
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
            <a href="lisra_estudiantes.php" class="nav-item-ae active">
                <i class="fas fa-users-rays"></i> <span>Estudiantes</span>
            </a>
            <a href="registro_tutores.php" class="nav-item-ae">
                <i class="fas fa-user-tie"></i> <span>Tutores</span>
            </a>
            <a href="lista_tutores.php" class="nav-item-ae">
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
                        <?= strtoupper($rol) ?>
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
                <span>Lista de Estudiantes</span>
            </nav>
        </div>

        <!-- HEADER -->
        <header class="reveal" style="animation-delay: 0.1s;">
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
                <div>
                    <p class="text-muted fw-600 mb-2" style="letter-spacing: 2px; font-size: 0.7rem; text-transform: uppercase;">
                        <i class="fas fa-graduation-cap me-2"></i>Sistema Académico
                    </p>
                    <h1 class="section-title">
                        Lista de <span class="section-accent">Estudiantes.</span>
                    </h1>
                </div>
                <?php if($es_admin): ?>
                <a href="registro_estudiantes.php" class="btn-new">
                    <i class="fas fa-plus"></i>
                    <span>Nuevo Estudiante</span>
                </a>
                <?php endif; ?>
            </div>
        </header>

        <!-- ESTADÍSTICAS -->
        <div class="stats-container reveal" style="animation-delay: 0.2s;">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-users"></i>
                </div>
                <div class="stat-number"><?= $total_estudiantes ?></div>
                <div class="stat-label">Total Estudiantes</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon active">
                    <i class="fas fa-user-check"></i>
                </div>
                <div class="stat-number"><?= $total_activos ?></div>
                <div class="stat-label">Estudiantes Activos</div>
            </div>
        </div>

        <!-- FILTROS POR CARRERA -->
        <div class="reveal" style="animation-delay: 0.3s;">
            <div class="carrera-scroll">
                <a href="?carrera=&search=<?= urlencode($search) ?>&estado=<?= urlencode($estado_filtro) ?>" 
                   class="btn-tab <?= empty($carrera_filtro) ? 'active' : '' ?>">
                    <i class="fas fa-th-large me-2"></i>Todas
                </a>
                <?php foreach($carreras_btn as $cb): ?>
                    <a href="?carrera=<?= $cb['id_carrera'] ?>&search=<?= urlencode($search) ?>&estado=<?= urlencode($estado_filtro) ?>" 
                       class="btn-tab <?= $carrera_filtro == $cb['id_carrera'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($cb['nombre_carrera']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- FILTROS DE BÚSQUEDA -->
        <div class="filter-section reveal" style="animation-delay: 0.4s;">
            <form method="GET" class="row g-3">
                <input type="hidden" name="carrera" value="<?= htmlspecialchars((string)$carrera_filtro) ?>">
                
                <div class="col-12 col-md-<?= $es_admin ? '5' : '8' ?>">
                    <div class="position-relative">
                        <i class="fas fa-search position-absolute" style="left: 18px; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
                        <input 
                            type="text" 
                            name="search" 
                            class="form-control search-input" 
                            placeholder="Buscar por nombre, CI o RU..."
                            value="<?= htmlspecialchars($search) ?>"
                            style="padding-left: 45px;"
                        >
                    </div>
                </div>
                
                <?php if($es_admin): ?>
                <div class="col-6 col-md-4">
                    <select name="estado" class="form-select search-input">
                        <option value="">Todos los estados</option>
                        <option value="ACTIVO" <?= $estado_filtro === 'ACTIVO' ? 'selected' : '' ?>>✓ Activos</option>
                        <option value="INACTIVO" <?= $estado_filtro === 'INACTIVO' ? 'selected' : '' ?>>✗ Inactivos</option>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-<?= $es_admin ? '6' : '4' ?> col-md-<?= $es_admin ? '3' : '4' ?>">
                    <button type="submit" class="btn btn-search w-100">
                        <i class="fas fa-search me-2"></i>
                        <span class="d-none d-md-inline">Buscar</span>
                    </button>
                </div>
            </form>
        </div>

        <!-- TABLA DE ESTUDIANTES -->
        <div class="cloud-card reveal" style="animation-delay: 0.5s;">
            <div class="table-responsive">
                <table class="table-modern">
                    <thead>
                        <tr>
                            <th>Estudiante</th>
                            <th class="d-none d-md-table-cell">Carrera</th>
                            <th>Estado</th>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($estudiantes)): ?>
                            <tr>
                                <td colspan="4" style="border-radius: 16px;">
                                    <div class="empty-state">
                                        <div class="empty-icon">
                                            <i class="fas fa-user-slash"></i>
                                        </div>
                                        <div class="empty-text">
                                            No se encontraron estudiantes con los filtros aplicados
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach($estudiantes as $e): 
                                $inicialEst = strtoupper(substr($e['primer_nombre'], 0, 1));
                            ?>
                            <tr>
                                <td>
                                    <div class="student-info">
                                        <div class="student-avatar">
                                            <?= $inicialEst ?>
                                        </div>
                                        <div>
                                            <div class="student-name">
                                                <?= htmlspecialchars($e['primer_apellido'] . ' ' . $e['primer_nombre']) ?>
                                            </div>
                                            <div class="student-meta">
                                                <i class="fas fa-hashtag" style="font-size: 0.7rem;"></i> <?= $e['ru'] ?> 
                                                <span class="mx-1">•</span>
                                                <i class="fas fa-id-card" style="font-size: 0.7rem;"></i> <?= $e['ci'] ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    <div style="color: var(--text-muted); font-size: 0.85rem; font-weight: 500;">
                                        <i class="fas fa-book me-1" style="font-size: 0.75rem;"></i>
                                        <?= htmlspecialchars($e['nombre_carrera']) ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= strtolower($e['estado']) ?>">
                                        <?= $e['estado'] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="perfil.php?id=<?= $e['id_persona'] ?>" 
                                           class="btn-action view" 
                                           title="Ver perfil">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <?php if($es_admin): ?>
                                            <a href="editar_estudiante.php?id=<?= $e['id_persona'] ?>" 
                                               class="btn-action edit"
                                               title="Editar">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button 
                                                onclick="cambiarEstado(<?= $e['id_persona'] ?>, '<?= $e['estado'] ?>')" 
                                                class="btn-action delete"
                                                title="Cambiar estado">
                                                <i class="fas fa-power-off"></i>
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
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        function cambiarEstado(id, actual) {
            const nuevo = actual === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
            const mensaje = nuevo === 'INACTIVO' 
                ? '¿Desactivar este estudiante?' 
                : '¿Reactivar este estudiante?';
            
            Swal.fire({
                title: mensaje,
                text: `El estudiante pasará a estado ${nuevo}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: nuevo === 'INACTIVO' ? '#ef4444' : '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: nuevo === 'INACTIVO' ? 'Sí, desactivar' : 'Sí, reactivar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
                customClass: {
                    popup: 'rounded-4',
                    confirmButton: 'rounded-3',
                    cancelButton: 'rounded-3'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Mostrar loading
                    Swal.fire({
                        title: 'Procesando...',
                        text: 'Actualizando estado del estudiante',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    // Redirigir al controlador
                    window.location.href = `../controllers/eliminar_estudiante.php?id=${id}&estado=${nuevo}`;
                }
            });
        }
    </script>
</body>
</html>