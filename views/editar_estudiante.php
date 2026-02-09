<?php
declare(strict_types=1);
session_start();
include_once '../config/conexion.php';

/**
 * 1. PROCESAMIENTO DE DATOS (AJAX) 
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar') {
    header('Content-Type: application/json');
    try {
        if (!isset($_SESSION["user_id"])) throw new Exception("Sesión expirada");

        $pdo->beginTransaction();
        
        // Informar al trigger de PostgreSQL quién realiza el cambio
        $pdo->exec("SET app.current_user_id = " . intval($_SESSION['user_id']));

        // Actualizamos la tabla personas
        $sqlP = "UPDATE public.personas 
                 SET primer_nombre = ?, primer_apellido = ?, ci = ?, celular = ? 
                 WHERE id_persona = ?";
        $stmtP = $pdo->prepare($sqlP);
        $stmtP->execute([
            trim($_POST['nombre']), 
            trim($_POST['apellido']), 
            trim($_POST['ci']), 
            trim($_POST['celular']), 
            $_POST['id_persona']
        ]);

        // Actualizamos la tabla estudiantes
        $sqlE = "UPDATE public.estudiantes SET id_carrera = ?, ru = ? WHERE id_persona = ?";
        $stmtE = $pdo->prepare($sqlE);
        $stmtE->execute([
            $_POST['id_carrera'], 
            trim($_POST['ru']), 
            $_POST['id_persona']
        ]);

        $pdo->commit();
        echo json_encode(['exito' => true, 'mensaje' => '¡Expediente actualizado correctamente!']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = ($e->getCode() == '23505') ? "Error: El C.I. o R.U. ya está registrado." : $e->getMessage();
        echo json_encode(['exito' => false, 'mensaje' => $msg]);
    }
    exit;
}

// 2. CARGA DE DATOS INICIAL
if (!isset($_SESSION["user_id"])) { header("Location: login.php"); exit(); }
$id = $_GET['id'] ?? null;
if (!$id) { header("Location: lisra_estudiantes.php"); exit(); }

$rol_usuario = $_SESSION["role"] ?? 'Invitado';
$nombre_usuario = $_SESSION["nombre_completo"] ?? ($_SESSION["username"] ?? 'Usuario'); 
$inicial = strtoupper(substr($nombre_usuario, 0, 1));

try {
    $stmt = $pdo->prepare("SELECT p.*, e.id_carrera, e.ru FROM personas p 
                           JOIN estudiantes e ON p.id_persona = e.id_persona 
                           WHERE p.id_persona = ?");
    $stmt->execute([$id]);
    $est = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$est) die("Estudiante no encontrado.");
    $carreras = $pdo->query("SELECT id_carrera, nombre_carrera FROM carreras ORDER BY nombre_carrera ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { die("Error de conexión"); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Estudiante | UNIOR</title>
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

        /* ============== CONTENIDO ============== */
        .section-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: clamp(2.5rem, 8vw, 4.5rem);
            font-weight: 300;
            letter-spacing: -2px;
            margin-bottom: 40px;
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

        .cloud-card {
            background: var(--glass);
            backdrop-filter: blur(15px);
            border: 1.5px solid var(--glass-border);
            border-radius: 35px;
            padding: clamp(30px, 5vw, 50px);
            box-shadow: var(--shadow-lg);
            max-width: 1000px;
            margin: 0 auto;
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

        /* ============== FORMULARIO ============== */
        .form-section {
            margin-bottom: 32px;
            padding-bottom: 32px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }

        .form-section:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .form-section-title {
            font-family: 'Bricolage Grotesque', sans-serif;
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .form-section-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.15));
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            margin-bottom: 8px;
            margin-left: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .required-mark {
            color: var(--danger);
            font-size: 0.7rem;
        }

        .form-control, .form-select {
            padding: 14px 18px 14px 45px;
            border-radius: 16px;
            border: 2px solid rgba(0, 0, 0, 0.08);
            background: rgba(255, 255, 255, 0.95);
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--text-dark);
            transition: all 0.3s var(--ease);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            background: white;
            outline: none;
        }

        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.05);
        }

        .form-control.is-valid, .form-select.is-valid {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
        }

        .invalid-feedback, .valid-feedback {
            font-size: 0.8rem;
            font-weight: 500;
            margin-top: 8px;
            margin-left: 4px;
            display: none;
        }

        .form-control.is-invalid ~ .invalid-feedback,
        .form-select.is-invalid ~ .invalid-feedback {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--danger);
        }

        .form-control.is-valid ~ .valid-feedback,
        .form-select.is-valid ~ .valid-feedback {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--success);
        }

        .input-group-icon {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 1.1rem;
            pointer-events: none;
            z-index: 10;
        }

        .form-hint {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 6px;
            margin-left: 4px;
            display: flex;
            align-items: start;
            gap: 6px;
        }

        .btn-ae {
            background: linear-gradient(135deg, var(--accent), var(--accent-light));
            color: white;
            border: none;
            padding: 18px 32px;
            border-radius: 16px;
            font-weight: 700;
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s var(--ease);
            width: 100%;
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-ae:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 12px 28px rgba(99, 102, 241, 0.4);
        }

        .btn-ae:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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
        }

        .btn-change-career {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            border: 2px solid rgba(99, 102, 241, 0.2);
            background: rgba(99, 102, 241, 0.05);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s var(--ease);
        }

        .btn-change-career:hover {
            background: var(--accent);
            color: white;
            border-color: var(--accent);
            transform: scale(1.05);
        }

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

        /* MODAL MEJORADO */
        .modal-content {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1.5px solid var(--glass-border);
            border-radius: 32px;
            box-shadow: 0 40px 80px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            border-bottom: 1px solid rgba(0, 0, 0, 0.06);
        }

        .modal-footer {
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }

        @media (max-width: 768px) {
            .cloud-card {
                padding: 24px;
            }
            .section-title {
                font-size: 2.5rem;
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
            <?php if (strtoupper($rol_usuario) === 'ADMINISTRADOR'): ?>
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
                        <?= $rol_usuario ?>
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
                <a href="lisra_estudiantes.php">Estudiantes</a>
                <i class="fas fa-chevron-right" style="font-size: 0.7rem; color: var(--text-muted);"></i>
                <span>Editar Estudiante</span>
            </nav>
        </div>

        <!-- HEADER -->
        <header class="reveal" style="animation-delay: 0.1s;">
            <h1 class="section-title">
                Editar <span class="section-accent">Estudiante.</span>
            </h1>
        </header>

        <!-- FORMULARIO -->
        <div class="cloud-card reveal" style="animation-delay: 0.2s;">
            <form id="formUpdate" novalidate>
                <input type="hidden" name="id_persona" value="<?= $est['id_persona'] ?>">
                <input type="hidden" name="accion" value="actualizar">

                <!-- SECCIÓN: DATOS PERSONALES -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon">
                            <i class="fas fa-user"></i>
                        </div>
                        Datos Personales
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label">
                                Nombres <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="nombre" 
                                    id="nombre"
                                    class="form-control" 
                                    value="<?= htmlspecialchars($est['primer_nombre']) ?>"
                                    required
                                    minlength="2"
                                    maxlength="50"
                                    pattern="^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$"
                                >
                                <i class="fas fa-user input-icon"></i>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Por favor ingrese un nombre válido (solo letras)</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Nombre válido
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Apellidos <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="apellido" 
                                    id="apellido"
                                    class="form-control" 
                                    value="<?= htmlspecialchars($est['primer_apellido']) ?>"
                                    required
                                    minlength="2"
                                    maxlength="50"
                                    pattern="^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$"
                                >
                                <i class="fas fa-user-tag input-icon"></i>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Por favor ingrese un apellido válido (solo letras)</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Apellido válido
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: IDENTIFICACIÓN -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        Identificación
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label">
                                Cédula de Identidad (CI) <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="ci" 
                                    id="ci"
                                    class="form-control" 
                                    value="<?= htmlspecialchars($est['ci']) ?>"
                                    required
                                    pattern="^[0-9]{6,10}$"
                                    maxlength="10"
                                >
                                <i class="fas fa-id-badge input-icon"></i>
                                <div class="form-hint">
                                    <i class="fas fa-info-circle"></i>
                                    <span>Ingrese solo números, entre 6 y 10 dígitos</span>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>La CI debe tener entre 6 y 10 dígitos numéricos</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    CI válida
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Registro Universitario (R.U.) <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="text" 
                                    name="ru" 
                                    id="ru"
                                    class="form-control" 
                                    value="<?= htmlspecialchars($est['ru']) ?>"
                                    required
                                    pattern="^[0-9]+$"
                                    minlength="4"
                                    maxlength="15"
                                >
                                <i class="fas fa-hashtag input-icon"></i>
                                <div class="form-hint">
                                    <i class="fas fa-info-circle"></i>
                                    <span>Ingrese solo números (mínimo 4 dígitos)</span>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>El R.U. debe contener solo números (mínimo 4 dígitos)</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    R.U. válido
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECCIÓN: CONTACTO Y CARRERA -->
                <div class="form-section">
                    <div class="form-section-title">
                        <div class="form-section-icon">
                            <i class="fas fa-graduation-cap"></i>
                        </div>
                        Contacto y Carrera
                    </div>
                    
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label">
                                Número de Celular <span class="required-mark">*</span>
                            </label>
                            <div class="input-group-icon">
                                <input 
                                    type="tel" 
                                    name="celular" 
                                    id="celular"
                                    class="form-control" 
                                    value="<?= htmlspecialchars($est['celular']) ?>"
                                    required
                                    pattern="^[67][0-9]{7}$"
                                    maxlength="8"
                                >
                                <i class="fas fa-mobile-alt input-icon"></i>
                                <div class="form-hint">
                                    <i class="fas fa-info-circle"></i>
                                    <span>8 dígitos, debe comenzar con 6 o 7</span>
                                </div>
                                <div class="invalid-feedback">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <span>Número de celular boliviano inválido</span>
                                </div>
                                <div class="valid-feedback">
                                    <i class="fas fa-check-circle"></i>
                                    Celular válido
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">
                                Carrera Universitaria <span class="required-mark">*</span>
                            </label>
                            <div class="d-flex gap-2">
                                <div class="flex-grow-1 input-group-icon">
                                    <select name="id_carrera" id="id_carrera" class="form-select" required style="padding-left: 45px;">
                                        <?php foreach($carreras as $c): ?>
                                            <option value="<?= $c['id_carrera'] ?>" <?= $c['id_carrera'] == $est['id_carrera'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($c['nombre_carrera']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fas fa-book input-icon"></i>
                                    <div class="invalid-feedback">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Por favor seleccione una carrera
                                    </div>
                                    <div class="valid-feedback">
                                        <i class="fas fa-check-circle"></i>
                                        Carrera seleccionada
                                    </div>
                                </div>
                                <button type="button" class="btn btn-change-career" data-bs-toggle="modal" data-bs-target="#modalCarrera">
                                    <i class="fas fa-exchange-alt"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BOTONES -->
                <div class="row g-3 mt-4">
                    <div class="col-md-6">
                        <button type="button" class="btn-ae btn-secondary-ae" onclick="window.location.href='lisra_estudiantes.php'">
                            <i class="fas fa-arrow-left"></i>
                            Cancelar
                        </button>
                    </div>
                    <div class="col-md-6">
                        <button type="submit" class="btn-ae" id="btnSubmit">
                            <i class="fas fa-save"></i>
                            <span>Actualizar Expediente</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </main>

    <!-- MODAL CAMBIO DE CARRERA -->
    <div class="modal fade" id="modalCarrera" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0 pt-4 px-4">
                    <h2 class="fw-800" style="font-family: 'Bricolage Grotesque'; letter-spacing: -1.5px; color: var(--text-dark); font-size: 1.8rem;">
                        Cambio de <span style="color: var(--accent);">Carrera.</span>
                    </h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 pb-2">
                    <div class="text-center mb-4">
                        <div style="width: 70px; height: 70px; background: rgba(129, 140, 248, 0.1); color: var(--accent); border-radius: 22px; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                            <i class="fas fa-university fs-2"></i>
                        </div>
                    </div>
                    <div class="input-group-icon">
                        <label class="form-label">Nueva Carrera</label>
                        <i class="fas fa-graduation-cap input-icon"></i>
                        <select id="nuevaCarreraSelect" class="form-select" style="padding-left: 45px;">
                            <?php foreach($carreras as $c): ?>
                                <option value="<?= $c['id_carrera'] ?>" <?= $c['id_carrera'] == $est['id_carrera'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['nombre_carrera']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-0 p-4 pt-2 d-flex gap-3">
                    <button type="button" class="btn btn-secondary-ae flex-grow-1" data-bs-dismiss="modal" style="padding: 12px;">
                        Cancelar
                    </button>
                    <button type="button" id="confirmarCambioCarrera" class="btn btn-ae flex-grow-1" style="padding: 12px;">
                        <i class="fas fa-check"></i> Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // ============== FORMATEO AUTOMÁTICO ==============
        document.getElementById('ci').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        document.getElementById('ru').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        document.getElementById('celular').addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Prevenir caracteres no permitidos en nombres
        document.getElementById('nombre').addEventListener('keypress', function(e) {
            if (!/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]$/.test(e.key)) e.preventDefault();
        });

        document.getElementById('apellido').addEventListener('keypress', function(e) {
            if (!/^[a-zA-ZñÑáéíóúÁÉÍÓÚ\s]$/.test(e.key)) e.preventDefault();
        });

        // ============== VALIDACIÓN SOLO AL ESCRIBIR ==============
        const form = document.getElementById('formUpdate');
        const inputs = form.querySelectorAll('input[required], select[required]');

        inputs.forEach(input => {
            input.addEventListener('input', function() {
                if (this.value.trim().length > 0) {
                    clearTimeout(this.validationTimer);
                    this.validationTimer = setTimeout(() => {
                        validateField(this);
                    }, 300);
                } else {
                    this.classList.remove('is-valid', 'is-invalid');
                }
            });
        });

        function validateField(field) {
            field.classList.remove('is-valid', 'is-invalid');
            
            if (field.value.trim().length === 0) {
                return true;
            }
            
            if (!field.validity.valid) {
                field.classList.add('is-invalid');
                return false;
            } else {
                field.classList.add('is-valid');
                return true;
            }
        }

        // ============== MODAL CAMBIO DE CARRERA ==============
        document.getElementById('confirmarCambioCarrera').addEventListener('click', function() {
            const nuevaId = document.getElementById('nuevaCarreraSelect').value;
            document.getElementById('id_carrera').value = nuevaId;
            bootstrap.Modal.getInstance(document.getElementById('modalCarrera')).hide();
            
            Swal.fire({
                icon: 'success',
                title: 'Carrera Actualizada',
                text: 'La carrera ha sido cambiada exitosamente',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 2000,
                timerProgressBar: true
            });
        });

        // ============== ENVÍO DEL FORMULARIO ==============
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Validar todos los campos
            let isValid = true;
            inputs.forEach(input => {
                if (!input.validity.valid) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else if (input.value.trim().length > 0) {
                    input.classList.add('is-valid');
                }
            });

            if (!isValid) {
                Swal.fire({
                    icon: 'error',
                    title: 'Formulario Incompleto',
                    text: 'Por favor complete todos los campos correctamente',
                    confirmButtonColor: '#6366f1'
                });
                
                const firstInvalid = form.querySelector('.is-invalid');
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalid.focus();
                }
                return;
            }

            const btn = document.getElementById('btnSubmit');
            const originalContent = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> <span>Procesando...</span>';

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: new FormData(this)
                });
                
                const data = await response.json();
                
                if (data.exito) {
                    await Swal.fire({
                        icon: 'success',
                        title: '¡Actualizado!',
                        text: data.mensaje,
                        confirmButtonColor: '#10b981'
                    });
                    window.location.href = 'lisra_estudiantes.php';
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.mensaje,
                        confirmButtonColor: '#ef4444'
                    });
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                }
            } catch (err) {
                console.error('Error:', err);
                Swal.fire({
                    icon: 'error',
                    title: 'Error de Conexión',
                    text: 'No se pudo conectar con el servidor',
                    confirmButtonColor: '#ef4444'
                });
                btn.disabled = false;
                btn.innerHTML = originalContent;
            }
        });
    </script>
</body>
</html>