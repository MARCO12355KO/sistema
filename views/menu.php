<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

require_once '../config/conexion.php';
require_once '../models/menumodelo.php'; 
$nombre_usuario = htmlspecialchars((string)($_SESSION["nombre_completo"] ?? 'Usuario'), ENT_QUOTES, 'UTF-8');
$rol = htmlspecialchars((string)($_SESSION["role"] ?? 'Invitado'), ENT_QUOTES, 'UTF-8');
$inicial = strtoupper(substr($nombre_usuario, 0, 1));

try {
    $dashboard = new DashboardModel($pdo); 
    $stats = $dashboard->getCounters(); 
    $totalEst  = $stats['estudiantes'];
    $totalTut  = $stats['tutores'];
    $totalAsig = $stats['asignaciones'];
} catch (Exception $e) {
    error_log("Critical Error: " . $e->getMessage());
    $totalEst = $totalTut = $totalAsig = "--";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>UNIOR - Universidad Privada de Oruro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
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
            --ease-spring: cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--bg-main); 
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            background-image: 
                radial-gradient(at 0% 0%, rgba(99, 102, 241, 0.1) 0, transparent 50%),
                radial-gradient(at 100% 100%, rgba(129, 140, 248, 0.08) 0, transparent 50%);
            overflow-x: hidden;
        }

        /* ============== SIDEBAR MEJORADO ============== */
        .sidebar {
            width: 85px;
            height: 95vh;
            margin: 2.5vh 0 2.5vh 24px;
            background: var(--glass);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 32px;
            border: 1.5px solid var(--glass-border);
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 0;
            position: sticky;
            top: 2.5vh;
            transition: all 0.5s var(--ease);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
        }

        @media (min-width: 992px) {
            .sidebar:hover { 
                width: 280px; 
                align-items: flex-start; 
                padding: 32px 24px;
                background: rgba(255, 255, 255, 0.85);
            }
            .sidebar:hover .nav-item-ae span { 
                opacity: 1; 
                transform: translateX(0);
                margin-left: 12px; 
                display: inline;
            }
            .sidebar:hover .logo-text { 
                opacity: 1; 
                width: auto;
                margin-left: 12px;
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
            padding: 16px 18px;
            margin-bottom: 6px;
            border-radius: 20px;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s var(--ease);
            position: relative;
            overflow: hidden;
        }

        .nav-item-ae::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background: var(--accent);
            transform: scaleY(0);
            transition: transform 0.3s var(--ease);
        }

        .nav-item-ae i { 
            font-size: 1.25rem;
            min-width: 50px;
            text-align: center;
            transition: all 0.3s var(--ease);
        }

        .nav-item-ae span { 
            opacity: 0;
            font-weight: 600;
            font-size: 0.95rem;
            white-space: nowrap;
            transition: all 0.3s var(--ease);
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

        .nav-item-ae.active::before {
            transform: scaleY(1);
        }

        .nav-item-ae.active i {
            transform: scale(1.1);
        }

        .nav-item-ae.text-danger:hover {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05));
            color: var(--danger);
        }

        /* ============== HEADER DE USUARIO MEJORADO ============== */
        .main-wrapper { 
            flex: 1; 
            padding: 48px 48px 100px; 
            max-width: 1600px; 
            margin: 0 auto; 
            width: 100%; 
        }

        .user-header { 
            display: flex; 
            justify-content: flex-end; 
            align-items: center; 
            gap: 16px; 
            margin-bottom: 40px; 
        }

        .mobile-logo-top { 
            display: none; 
        }

        .user-pill {
            background: var(--glass);
            padding: 8px 10px 8px 24px;
            border-radius: 100px;
            border: 1.5px solid var(--glass-border);
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s var(--ease);
            cursor: pointer;
        }

        .user-pill:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }

        .user-info .user-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: var(--text-dark);
            line-height: 1.2;
        }

        .user-info .user-role {
            font-size: 0.7rem;
            color: var(--text-muted);
            letter-spacing: 1.5px;
            text-transform: uppercase;
            font-weight: 500;
        }

        /* ============== HERO SECTION MEJORADO ============== */
        .hero-section {
            margin-bottom: 48px;
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.1));
            padding: 8px 20px;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 2px;
            color: var(--accent);
            margin-bottom: 20px;
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .hero-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: clamp(2.5rem, 6vw, 5rem);
            font-weight: 300;
            letter-spacing: -2px;
            line-height: 0.95;
            margin-bottom: 16px;
            background: linear-gradient(135deg, var(--text-dark) 0%, var(--text-muted) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-accent {
            font-weight: 800;
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-subtitle {
            font-size: 1.1rem;
            color: var(--text-muted);
            max-width: 600px;
            line-height: 1.6;
        }

        /* ============== TARJETAS ESTADÍSTICAS MEJORADAS ============== */
        .cloud-card {
            background: var(--glass);
            border: 1.5px solid var(--glass-border);
            border-radius: 32px;
            padding: clamp(24px, 4vw, 40px);
            box-shadow: var(--shadow-md);
            backdrop-filter: blur(12px);
            transition: all 0.4s var(--ease);
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .cloud-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.05) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.4s var(--ease);
        }

        .cloud-card:hover {
            transform: translateY(-8px);
            background: white;
            box-shadow: var(--shadow-lg);
            border-color: rgba(99, 102, 241, 0.2);
        }

        .cloud-card:hover::before {
            opacity: 1;
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 20px;
            transition: all 0.3s var(--ease);
        }

        .cloud-card:hover .stat-icon {
            transform: scale(1.1) rotate(5deg);
        }

        .stat-icon.students {
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
        }

        .stat-icon.tutors {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.15));
            color: var(--success);
        }

        .stat-icon.processes {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(245, 158, 11, 0.15));
            color: var(--warning);
        }

        .stat-label {
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 12px;
        }

        .stat-num {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 800;
            line-height: 1;
            margin-bottom: 12px;
            color: var(--text-dark);
        }

        .stat-description {
            font-size: 0.9rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .stat-trend {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-top: 12px;
            padding: 6px 12px;
            border-radius: 100px;
            background: rgba(16, 185, 129, 0.1);
            color: var(--success);
        }

        /* ============== TARJETA DE ACCIÓN MEJORADA ============== */
        .action-card {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            border: none;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .action-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.2) 0%, transparent 70%);
            border-radius: 50%;
        }

        .action-card .stat-description {
            color: rgba(255, 255, 255, 0.9);
        }

        .btn-ae {
            background: white;
            color: var(--accent);
            border: none;
            padding: 16px 32px;
            border-radius: 100px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s var(--ease);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-ae:hover {
            transform: scale(1.05) translateY(-2px);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
            color: var(--accent-dark);
        }

        .btn-ae i {
            transition: transform 0.3s var(--ease);
        }

        .btn-ae:hover i {
            transform: rotate(90deg);
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

        /* ============== RESPONSIVE MÓVIL ============== */
        @media (max-width: 991px) {
            body { 
                flex-direction: column; 
            }

            .mobile-logo-top { 
                display: flex; 
                align-items: center; 
                gap: 12px; 
                font-family: 'Bricolage Grotesque'; 
                font-weight: 800; 
                color: var(--accent);
                font-size: 1.3rem;
            }

            .sidebar {
                position: fixed;
                bottom: 20px;
                top: auto;
                left: 20px;
                right: 20px;
                width: auto;
                height: 75px;
                margin: 0;
                flex-direction: row;
                justify-content: space-around;
                padding: 0 20px;
                border-radius: 28px;
            }

            .sidebar .logo-aesthetic, 
            .sidebar .mt-auto, 
            .sidebar span,
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
                border-radius: 16px;
            }

            .nav-item-ae i {
                min-width: auto;
                font-size: 1.3rem;
            }

            .main-wrapper { 
                padding: 24px 20px 120px 20px; 
            }

            .user-header { 
                justify-content: space-between; 
                margin-bottom: 28px; 
            }

            .hero-title { 
                font-size: 2.5rem; 
            }

            .stat-num {
                font-size: 3rem;
            }
        }

        /* ============== TOOLTIP ============== */
        .tooltip-custom {
            position: relative;
        }

        .tooltip-custom::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-8px);
            background: var(--text-dark);
            color: white;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.8rem;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: all 0.3s var(--ease);
        }

        .tooltip-custom:hover::after {
            opacity: 1;
            transform: translateX(-50%) translateY(-12px);
        }

        /* ============== ESTADOS DE CARGA ============== */
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
    </style>
</head>
<body>
    <!-- ============== SIDEBAR ============== -->
    <aside class="sidebar">
        <div class="logo-aesthetic d-none d-lg-flex">
            <img src="../assets/img/logo_unior1.png" width="48" alt="Logo UNIOR">
            <span class="logo-text">UNIOR</span>
        </div>
        
        <nav>
            <a href="menu.php" class="nav-item-ae active" data-tooltip="Menú Principal">
                <i class="fas fa-home-alt"></i> 
                <span>Menú</span>
            </a>
            <a href="lisra_estudiantes.php" class="nav-item-ae" data-tooltip="Estudiantes">
                <i class="fas fa-users-rays"></i> 
                <span>Estudiantes</span>
            </a>
            <a href="registro_tutores.php" class="nav-item-ae" data-tooltip="Registrar Tutor">
                <i class="fas fa-user-tie"></i> 
                <span>Registrar Tutor</span>
            </a>
            <a href="lista_tutores.php" class="nav-item-ae" data-tooltip="Lista de Tutores">
                <i class="fas fa-fingerprint"></i> 
                <span>Lista Tutores</span>
            </a>
            <a href="predefensas.php" class="nav-item-ae" data-tooltip="Predefensas">
                <i class="fas fa-signature"></i> 
                <span>Predefensas</span>
            </a>
<a href="lista_habilitados.php" class="nav-item-ae" data-tooltip="defensas">
                <i class="fas fa-signature"></i> 
                <span>defeZnsas</span>
            </a>
            <?php if (isset($_SESSION["role"]) && strtoupper($_SESSION["role"]) === 'ADMINISTRADOR'): ?>
            <a href="logs.php" class="nav-item-ae" data-tooltip="Logs del Sistema">
                <i class="bi bi-backpack4-fill"></i> 
                <span>Logs Sistema</span>
            </a>
            <?php endif; ?>
        </nav>

        <a href="../controllers/logout.php" class="nav-item-ae text-danger mt-auto d-none d-lg-flex" data-tooltip="Cerrar Sesión">
            <i class="fas fa-power-off"></i> 
            <span>Salir</span>
        </a>
    </aside>
    
    <!-- ============== CONTENIDO PRINCIPAL ============== -->
    <main class="main-wrapper">
        <!-- Header de Usuario -->
        <div class="user-header reveal">
            <div class="mobile-logo-top">
                <img src="../assets/img/logo_unior1.png" width="38" alt="Logo">
                <span>UNIOR</span>
            </div>
            
            <div class="user-pill">
                <div class="user-info text-end d-none d-sm-block">
                    <div class="user-name"><?= $nombre_usuario ?></div>
                    <div class="user-role"><?= $rol ?></div>
                </div>
                <div class="user-avatar">
                    <?= $inicial ?>
                </div>
            </div>
        </div>

        <!-- Hero Section -->
        <header class="hero-section reveal">
            <div class="hero-eyebrow">
                <i class="fas fa-graduation-cap"></i>
                SISTEMA DE TITULACIÓN
            </div>
            <h1 class="hero-title">
                Universidad Privada<br>de Oruro <span class="hero-accent">UNIOR.</span>
            </h1>
            <p class="hero-subtitle">
                Gestiona eficientemente el proceso de titulación de estudiantes y asignación de tutores académicos.
            </p>
        </header>

        <!-- Tarjetas de Estadísticas -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-md-6 col-lg-4 reveal" style="animation-delay: 0.1s;">
                <div class="cloud-card">
                    <div class="stat-icon students">
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="stat-label">Estudiantes</div>
                    <h2 class="stat-num"><?= $totalEst ?></h2>
                    <p class="stat-description">Total de estudiantes registrados en el sistema</p>
                    <div class="stat-trend">
                        <i class="fas fa-arrow-up"></i>
                        Activos en proceso
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 reveal" style="animation-delay: 0.2s;">
                <div class="cloud-card">
                    <div class="stat-icon tutors">
                        <i class="fas fa-chalkboard-teacher"></i>
                    </div>
                    <div class="stat-label">Docentes</div>
                    <h2 class="stat-num"><?= $totalTut ?></h2>
                    <p class="stat-description">Tutores académicos disponibles para asesoría</p>
                    <div class="stat-trend">
                        <i class="fas fa-check-circle"></i>
                        Disponibles
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4 reveal" style="animation-delay: 0.3s;">
                <div class="cloud-card">
                    <div class="stat-icon processes">
                        <i class="fas fa-tasks"></i>
                    </div>
                    <div class="stat-label">Titulaciones</div>
                    <h2 class="stat-num"><?= $totalAsig ?></h2>
                    <p class="stat-description">Procesos de titulación actualmente en curso</p>
                    <div class="stat-trend">
                        <i class="fas fa-sync"></i>
                        En progreso
                    </div>
                </div>
            </div>
        </div>

        <!-- Tarjeta de Acción Rápida -->
        <div class="reveal" style="animation-delay: 0.4s;">
            <div class="cloud-card action-card">
                <div class="row align-items-center g-4">
                    <div class="col-12 col-lg-8">
                        <h3 class="fw-800 mb-3" style="font-family: 'Bricolage Grotesque'; font-size: 1.8rem;">
                            Acceso Rápido
                        </h3>
                        <p class="stat-description mb-0">
                            Registra un nuevo estudiante en el sistema para iniciar el proceso de titulación y asignación de tutor académico.
                        </p>
                    </div>
                    <div class="col-12 col-lg-4 text-lg-end">
                        <a href="registro_estudiantes.php" class="btn-ae text-decoration-none">
                            <i class="fas fa-plus"></i>
                            Nuevo Registro
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Animación de números contadores
        document.addEventListener('DOMContentLoaded', function() {
            const counters = document.querySelectorAll('.stat-num');
            
            counters.forEach(counter => {
                const target = counter.textContent.trim();
                if (target === '--') return;
                
                const targetNum = parseInt(target);
                const duration = 2000;
                const increment = targetNum / (duration / 16);
                let current = 0;
                
                const updateCounter = () => {
                    current += increment;
                    if (current < targetNum) {
                        counter.textContent = Math.floor(current);
                        requestAnimationFrame(updateCounter);
                    } else {
                        counter.textContent = targetNum;
                    }
                };
                
                // Iniciar animación cuando sea visible
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            updateCounter();
                            observer.unobserve(entry.target);
                        }
                    });
                });
                
                observer.observe(counter.parentElement);
            });
        });
    </script>
</body>
</html>