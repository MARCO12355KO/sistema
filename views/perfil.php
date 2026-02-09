<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once '../config/conexion.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { 
    header("Location: lisra_estudiantes.php"); 
    exit(); 
}

$rol_usuario = $_SESSION["role"] ?? 'Invitado';
$es_admin = (strtolower($rol_usuario) === 'administrador');

try {
    $sql = "SELECT p.*, e.ru, c.nombre_carrera 
            FROM personas p 
            JOIN estudiantes e ON p.id_persona = e.id_persona 
            JOIN carreras c ON e.id_carrera = c.id_carrera 
            WHERE p.id_persona = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$est) { 
        die("Estudiante no encontrado."); 
    }

    $nombre_completo = mb_convert_case(($est['primer_nombre'] ?? '') . " " . ($est['primer_apellido'] ?? ''), MB_CASE_TITLE, "UTF-8");
    $iniciales = strtoupper(substr($est['primer_nombre'] ?? 'E', 0, 1) . substr($est['primer_apellido'] ?? 'S', 0, 1));
    $nombre_admin = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
    $inicial_admin = strtoupper(substr($nombre_admin, 0, 1));

} catch (PDOException $e) {
    die("Error en el sistema: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil | <?= $est['primer_apellido'] ?> - UNIOR</title>
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

        /* ============== BREADCRUMB ============== */
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

        /* ============== PROFILE CARD ============== */
        .profile-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 32px;
            overflow: hidden;
            box-shadow: var(--shadow-lg);
            margin-bottom: 24px;
        }

        .profile-banner {
            height: 200px;
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-light) 100%);
            position: relative;
            overflow: hidden;
        }

        .profile-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            border-radius: 50%;
        }

        .profile-banner::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.08) 0%, transparent 70%);
            border-radius: 50%;
        }

        .profile-avatar {
            width: 140px;
            height: 140px;
            border-radius: 28px;
            background: linear-gradient(135deg, white, #f8fafc);
            border: 6px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 3.5rem;
            font-weight: 800;
            color: var(--accent);
            position: absolute;
            bottom: -70px;
            left: 40px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .profile-body {
            padding: 90px 40px 40px 40px;
        }

        .status-badge-lg {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }

        .status-activo-lg {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #065f46;
        }

        .status-inactivo-lg {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }

        .profile-name {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: clamp(2rem, 5vw, 3.5rem);
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 12px;
            background: linear-gradient(135deg, var(--text-dark), var(--text-muted));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .profile-subtitle {
            color: var(--text-muted);
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 32px;
        }

        /* ============== INFO CARDS ============== */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .info-card {
            background: white;
            border: 2px solid rgba(0, 0, 0, 0.05);
            border-radius: 24px;
            padding: 28px;
            transition: all 0.3s var(--ease);
            position: relative;
            overflow: hidden;
        }

        .info-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.03) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s var(--ease);
        }

        .info-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.08);
            border-color: rgba(99, 102, 241, 0.2);
        }

        .info-card:hover::before {
            opacity: 1;
        }

        .info-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 16px;
            position: relative;
            z-index: 1;
        }

        .info-icon.ci {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
        }

        .info-icon.ru {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.15));
            color: var(--success);
        }

        .info-icon.carrera {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.15));
            color: var(--warning);
        }

        .info-icon.celular {
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1), rgba(139, 92, 246, 0.15));
            color: #8b5cf6;
        }

        .info-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .info-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--text-dark);
            line-height: 1.3;
        }

        /* ============== BUTTONS ============== */
        .btn-ae {
            padding: 14px 32px;
            border-radius: 16px;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.3s var(--ease);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-primary-ae {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border: none;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
        }

        .btn-primary-ae:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(99, 102, 241, 0.4);
            color: white;
        }

        .btn-secondary-ae {
            background: white;
            color: var(--text-dark);
            border: 2px solid rgba(0, 0, 0, 0.1);
            box-shadow: var(--shadow-sm);
        }

        .btn-secondary-ae:hover {
            background: var(--bg-main);
            border-color: var(--accent);
            color: var(--accent);
            transform: translateY(-2px);
        }

        /* ============== ACTIVITY CARD ============== */
        .activity-card {
            background: var(--glass);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--glass-border);
            border-radius: 24px;
            padding: 28px;
            box-shadow: var(--shadow-md);
        }

        .activity-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ============== ANIMATIONS ============== */
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
            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
                left: 50%;
                transform: translateX(-50%);
            }

            .profile-body {
                padding: 70px 24px 24px 24px;
                text-align: center;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .profile-name {
                font-size: 2rem;
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
                <?= $inicial_admin ?>
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
                    <div class="fw-bold" style="font-size: 0.9rem;"><?= htmlspecialchars($nombre_admin) ?></div>
                    <div style="font-size: 0.7rem; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; font-weight: 600;">
                        <?= strtoupper($rol_usuario) ?>
                    </div>
                </div>
                <div class="user-avatar">
                    <?= $inicial_admin ?>
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
                <a href="lisra_estudiantes.php">Estudiantes</a>
                <i class="fas fa-chevron-right" style="font-size: 0.7rem; color: var(--text-muted);"></i>
                <span>Perfil</span>
            </nav>
        </div>

        <!-- PROFILE CARD -->
        <div class="profile-card reveal" style="animation-delay: 0.1s;">
            <div class="profile-banner">
                <div class="profile-avatar">
                    <?= $iniciales ?>
                </div>
            </div>
            
            <div class="profile-body">
                <div class="status-badge-lg status-<?= strtolower($est['estado']) ?>-lg">
                    <i class="fas fa-<?= $est['estado'] === 'ACTIVO' ? 'check-circle' : 'times-circle' ?>"></i>
                    Expediente <?= $est['estado'] ?>
                </div>
                
                <h1 class="profile-name"><?= $nombre_completo ?></h1>
                <p class="profile-subtitle">
                    <i class="fas fa-university me-2"></i>
                    Universidad Privada de Oruro
                </p>

                <!-- INFO GRID -->
                <div class="info-grid">
                    <div class="info-card">
                        <div class="info-icon ci">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div class="info-label">Cédula de Identidad</div>
                        <div class="info-value"><?= htmlspecialchars($est['ci']) ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-icon ru">
                            <i class="fas fa-hashtag"></i>
                        </div>
                        <div class="info-label">Registro Universitario</div>
                        <div class="info-value"><?= htmlspecialchars($est['ru']) ?></div>
                    </div>

                    <div class="info-card">
                        <div class="info-icon carrera">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        <div class="info-label">Carrera</div>
                        <div class="info-value" style="font-size: 1.1rem;">
                            <?= htmlspecialchars($est['nombre_carrera']) ?>
                        </div>
                    </div>

                    <div class="info-card">
                        <div class="info-icon celular">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <div class="info-label">Celular</div>
                        <div class="info-value"><?= htmlspecialchars($est['celular'] ?? 'No registrado') ?></div>
                    </div>
                </div>

                <!-- ACTIONS -->
                <div class="d-flex flex-column flex-md-row gap-3 mt-4">
                    <a href="lisra_estudiantes.php" class="btn-ae btn-secondary-ae">
                        <i class="fas fa-arrow-left"></i>
                        <span>Volver al Listado</span>
                    </a>
                    
                    <?php if($es_admin): ?>
                    <a href="editar_estudiante.php?id=<?= $id ?>" class="btn-ae btn-primary-ae">
                        <i class="fas fa-edit"></i>
                        <span>Editar Información</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ACTIVITY LOG -->
        <div class="activity-card reveal" style="animation-delay: 0.2s;">
            <div class="activity-title">
                <div class="activity-icon">
                    <i class="fas fa-history"></i>
                </div>
                Última Actividad
            </div>
            <p class="text-muted mb-0">
                <i class="fas fa-clock me-2"></i>
                Expediente consultado el <strong><?= date('d/m/Y') ?></strong> a las <strong><?= date('H:i') ?></strong> 
                por <?= htmlspecialchars($nombre_admin) ?> 
                <span class="badge bg-light text-dark ms-2"><?= strtoupper($rol_usuario) ?></span>
            </p>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>