<?php
declare(strict_types=1);
session_start();

require_once('../tcpdf/tcpdf.php');
require_once('../config/conexion.php');

// ===================================================================
// VALIDACIÓN DE SESIÓN
// ===================================================================
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ===================================================================
// FUNCIONES DE UTILIDAD
// ===================================================================

/**
 * Formatea una fecha al formato español
 */
function format_date_es(string $dateString): string {
    if (empty($dateString)) {
        return 'Fecha no disponible';
    }
    
    $dateTime = new DateTime($dateString);
    $monthNames = [
        'January' => 'enero', 'February' => 'febrero', 'March' => 'marzo',
        'April' => 'abril', 'May' => 'mayo', 'June' => 'junio',
        'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre',
        'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'
    ];
    
    return $dateTime->format('d') . ' de ' . 
           $monthNames[$dateTime->format('F')] . ' de ' . 
           $dateTime->format('Y');
}

/**
 * Convierte string a formato título respetando UTF-8
 */
function title_case_es(string $string): string {
    return mb_convert_case(mb_strtolower($string, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

/**
 * Obtiene configuración desde la base de datos
 */
function get_config(PDO $pdo, string $clave, string $default = ''): string {
    static $cache = [];
    
    if (!isset($cache[$clave])) {
        $stmt = $pdo->prepare("SELECT valor FROM public.config_documentos WHERE clave = ?");
        $stmt->execute([$clave]);
        $cache[$clave] = $stmt->fetchColumn() ?: $default;
    }
    
    return $cache[$clave];
}

/**
 * Genera y almacena el correlativo del documento
 */
function generar_correlativo(
    PDO $pdo, 
    int $id_pre_defensa, 
    string $tipo_documento, 
    int $gestion
): string {
    try {
        $pdo->beginTransaction();
        
        // Verificar si ya existe un cite para este documento
        $stmt = $pdo->prepare(
            "SELECT numero_cite FROM public.registro_cites 
             WHERE id_pre_defensa = ? AND tipo_documento = ?"
        );
        $stmt->execute([$id_pre_defensa, $tipo_documento]);
        $cite_existente = $stmt->fetchColumn();
        
        if ($cite_existente) {
            $pdo->commit();
            return $cite_existente;
        }
        
        // Obtener o crear correlativo para este tipo y gestión
        $stmt_corr = $pdo->prepare(
            "SELECT ultimo_numero FROM public.correlativos_documentos 
             WHERE tipo_documento = ? AND gestion = ? 
             FOR UPDATE"
        );
        $stmt_corr->execute([$tipo_documento, $gestion]);
        $correlativo = $stmt_corr->fetch(PDO::FETCH_ASSOC);
        
        if ($correlativo) {
            // Incrementar correlativo existente
            $nuevo_numero = (int)$correlativo['ultimo_numero'] + 1;
            $stmt_upd = $pdo->prepare(
                "UPDATE public.correlativos_documentos 
                 SET ultimo_numero = ?, fecha_actualizacion = CURRENT_TIMESTAMP 
                 WHERE tipo_documento = ? AND gestion = ?"
            );
            $stmt_upd->execute([$nuevo_numero, $tipo_documento, $gestion]);
        } else {
            // Crear nuevo correlativo para esta gestión
            $nuevo_numero = 1;
            $prefijo = ($tipo_documento === 'CARTA_UTO') ? 'REC-UTO' : 'REC-COL';
            $stmt_ins = $pdo->prepare(
                "INSERT INTO public.correlativos_documentos 
                 (tipo_documento, ultimo_numero, gestion, formato_prefijo) 
                 VALUES (?, ?, ?, ?)"
            );
            $stmt_ins->execute([$tipo_documento, $nuevo_numero, $gestion, $prefijo]);
        }
        
        // Generar el código final
        $prefijo = ($tipo_documento === 'CARTA_UTO') ? 'REC-UTO' : 'REC-COL';
        $cite_final = sprintf("%s-%03d/%d", $prefijo, $nuevo_numero, $gestion);
        
        // Guardar en registro_cites
        $stmt_save = $pdo->prepare(
            "INSERT INTO public.registro_cites 
             (id_pre_defensa, tipo_documento, numero_cite, fecha_generacion) 
             VALUES (?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt_save->execute([$id_pre_defensa, $tipo_documento, $cite_final]);
        
        $pdo->commit();
        return $cite_final;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error generando correlativo: " . $e->getMessage());
        throw new Exception("Error al generar el correlativo del documento");
    }
}

/**
 * Obtiene datos del destinatario según tipo de carta
 */
function get_destinatario_data(PDO $pdo, string $tipo_carta, string $nombre_carrera): array {
    if ($tipo_carta === 'UTO') {
        return [
            'nombre' => get_config($pdo, 'rector_uto_nombre', 'Ing. Augusto Medinaceli Ortiz'),
            'cargo' => get_config($pdo, 'rector_uto_cargo', 'RECTOR'),
            'institucion' => get_config($pdo, 'institucion_uto', 'UNIVERSIDAD TÉCNICA DE ORURO'),
            'cantidad_profesionales' => 'dos profesionales',
            'ref_profesional' => 'profesionales'
        ];
    } else {
        // COLEGIO - Mapear según carrera
        $mapeo_colegios = [
            'ARQUITECTURA' => [
                'nombre' => get_config($pdo, 'presidente_col_arquitectura', 'Arq. Juan Pérez'),
                'cargo' => 'PRESIDENTE',
                'institucion' => 'COLEGIO DEPARTAMENTAL DE ARQUITECTOS DE ORURO'
            ],
            'DERECHO' => [
                'nombre' => get_config($pdo, 'presidente_col_derecho', 'Dr. David Gareca Antequera'),
                'cargo' => 'PRESIDENTE',
                'institucion' => 'ILUSTRE COLEGIO DE ABOGADOS DE ORURO'
            ],
            'CONTADURIA' => [
                'nombre' => get_config($pdo, 'presidente_col_contaduria', 'Lic. María González'),
                'cargo' => 'PRESIDENTE',
                'institucion' => 'COLEGIO DEPARTAMENTAL DE CONTADORES DE ORURO'
            ],
            // Agregar más carreras según sea necesario
        ];
        
        $carrera_key = strtoupper(trim($nombre_carrera));
        
        if (isset($mapeo_colegios[$carrera_key])) {
            $data = $mapeo_colegios[$carrera_key];
        } else {
            // Configuración genérica
            $data = [
                'nombre' => get_config($pdo, 'presidente_colegio_generico', 'Presidente del Colegio'),
                'cargo' => 'PRESIDENTE',
                'institucion' => 'COLEGIO DEPARTAMENTAL DE ' . strtoupper($nombre_carrera)
            ];
        }
        
        $data['cantidad_profesionales'] = 'un profesional cualificado';
        $data['ref_profesional'] = 'profesional';
        
        return $data;
    }
}

// ===================================================================
// VALIDACIÓN DE PARÁMETROS
// ===================================================================
$id_pre_defensa = filter_input(INPUT_GET, 'id_pre_defensa', FILTER_VALIDATE_INT);
$tipo_carta = filter_input(INPUT_GET, 'tipo', FILTER_SANITIZE_STRING) ?? 'UTO';
// Intentar obtener de GET o POST
$id_pre_defensa = filter_input(INPUT_GET, 'id_pre_defensa', FILTER_VALIDATE_INT) 
               ?? filter_input(INPUT_POST, 'id_pre_defensa', FILTER_VALIDATE_INT);
if (!$id_pre_defensa) {
    die("Error: ID de pre-defensa no válido");
}

if (!in_array($tipo_carta, ['UTO', 'COLEGIO'])) {
    die("Error: Tipo de carta no válido");
}

try {
    // ===================================================================
    // CONSULTA DE DATOS
    // ===================================================================
    $stmt = $pdo->prepare("
        SELECT 
            TRIM(
                p.primer_nombre || ' ' || 
                COALESCE(p.segundo_nombre || ' ', '') || 
                p.primer_apellido || ' ' || 
                COALESCE(p.segundo_apellido, '')
            ) AS estudiante,
            c.nombre_carrera,
            pd.fecha_pre,
            pd.hora_pre,
            au.nombre_aula,
            pd.gestion
        FROM public.pre_defensas pd
        JOIN public.personas p ON pd.id_estudiante = p.id_persona
        JOIN public.estudiantes e ON p.id_persona = e.id_persona
        JOIN public.carreras c ON e.id_carrera = c.id_carrera
        LEFT JOIN public.aulas au ON pd.id_aula = au.id_aula
        WHERE pd.id_pre_defensa = ?
    ");
    $stmt->execute([$id_pre_defensa]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        die("Error: Registro no encontrado");
    }

    // ===================================================================
    // GENERACIÓN DE CORRELATIVO
    // ===================================================================
    $gestion = (int)($data['gestion'] ?? date('Y'));
    $tipo_documento = ($tipo_carta === 'UTO') ? 'CARTA_UTO' : 'CARTA_COLEGIO';
    $cite_final = generar_correlativo($pdo, $id_pre_defensa, $tipo_documento, $gestion);

    // ===================================================================
    // CONFIGURACIÓN DEL DESTINATARIO
    // ===================================================================
    $destinatario_data = get_destinatario_data($pdo, $tipo_carta, $data['nombre_carrera']);

    // ===================================================================
    // GENERACIÓN DEL PDF
    // ===================================================================
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(25, 25, 25);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->AddPage();

    // Encabezado
    $pdf->SetFont('helvetica', '', 11);
    $ciudad = get_config($pdo, 'ciudad', 'Oruro');
    $pdf->Cell(0, 5, $ciudad . ', ' . format_date_es(date('Y-m-d')), 0, 1, 'L');
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'CITE: ' . $cite_final, 0, 1, 'L');

    // Destinatario
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, 'Señor:', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', 'B', 11);
    $destinatario_texto = $destinatario_data['nombre'] . "\n" . 
                          $destinatario_data['cargo'] . "\n" . 
                          $destinatario_data['institucion'];
    $pdf->MultiCell(0, 5, $destinatario_texto, 0, 'L');
    
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, 'Presente. -', 0, 1, 'L');

    // Referencia
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'B', 11);
    $ref_texto = 'Ref.: Solicitud de designación de ' . 
                 $destinatario_data['ref_profesional'] . 
                 ' para Tribunal Examinador';
    $pdf->Cell(0, 5, $ref_texto, 0, 1, 'R');

    // Saludo
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(0, 5, 'De mi más alta y distinguida consideración:', 0, 1, 'L');

    // Párrafo principal
    $pdf->Ln(5);
    $rectora = get_config($pdo, 'rectora_nombre', 'Rectora');
    $universidad = get_config($pdo, 'universidad_nombre', 'Universidad Privada de Oruro');
    
    $parrafo1 = sprintf(
        "Mediante la presente, y en el ejercicio de mis funciones como %s de la %s, " .
        "me dirijo a su autoridad con el debido respeto para solicitar la designación de " .
        "<strong>%s</strong> para que actúen como miembros del Tribunal Examinador en el " .
        "examen de grado de nuestra egresada <strong>%s</strong>, de la carrera de " .
        "<strong>%s</strong>.",
        $rectora,
        $universidad,
        $destinatario_data['cantidad_profesionales'],
        title_case_es($data['estudiante']),
        title_case_es($data['nombre_carrera'])
    );

    $pdf->writeHTMLCell(0, 0, '', '', $parrafo1, 0, 1, 0, true, 'J', true);

    // Introducción a la tabla
    $pdf->Ln(5);
    $pdf->Cell(0, 5, 'La defensa pública está programada conforme al siguiente detalle:', 0, 1, 'L');

    // Tabla de detalles
    $pdf->Ln(2);
    $hora_formato = (new DateTime($data['hora_pre']))->format('H:i');
    $aula = $data['nombre_aula'] ?? 'Instalaciones ' . get_config($pdo, 'universidad_sigla', 'UNIOR');
    
    $tabla_html = '
    <table border="1" cellpadding="4" style="border-collapse: collapse;">
        <thead>
            <tr style="background-color:#f2f2f2; text-align:center; font-weight:bold;">
                <th width="30%">POSTULANTE</th>
                <th width="25%">FECHA</th>
                <th width="15%">HORA</th>
                <th width="30%">LUGAR</th>
            </tr>
        </thead>
        <tbody>
            <tr style="text-align:center;">
                <td>' . title_case_es($data['estudiante']) . '</td>
                <td>' . format_date_es($data['fecha_pre']) . '</td>
                <td>' . $hora_formato . '</td>
                <td>' . htmlspecialchars($aula) . '</td>
            </tr>
        </tbody>
    </table>';
    
    $pdf->writeHTML($tabla_html, true, false, false, false, '');

    // Fundamento legal
    $pdf->Ln(5);
    $fundamento_legal = "Esta solicitud se fundamenta en la Constitución Política del Estado " .
                        "en su Art. 94 parágrafo III; la Ley Avelino Siñani y Elizardo Pérez " .
                        "en su Art. 55 y siguientes; y el Reglamento de Universidades Privadas " .
                        "en su Art. 59 del D.S. 1433, de 12 de diciembre de 2012.";
    $pdf->MultiCell(0, 5, $fundamento_legal, 0, 'J');

    // Despedida
    $pdf->Ln(5);
    $despedida = 'Con este motivo, le reitero mis consideraciones más distinguidas y ' .
                 'aprovecho la oportunidad para saludarlo cordialmente.';
    $pdf->Cell(0, 5, $despedida, 0, 1, 'L');

    // Firma
    $pdf->Ln(15);
    $pdf->Cell(0, 5, 'Atentamente,', 0, 1, 'L');
    $pdf->Ln(10);
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, $rectora, 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, get_config($pdo, 'rectora_cargo', 'RECTORA'), 0, 1, 'C');

    // ===================================================================
    // OUTPUT DEL PDF
    // ===================================================================
    $filename = 'Carta_Designacion_' . 
                $tipo_carta . '_' . 
                $id_pre_defensa . '_' . 
                date('Ymd') . '.pdf';
    
    $pdf->Output($filename, 'I');

} catch (PDOException $e) {
    error_log("Error de base de datos: " . $e->getMessage());
    die("Error al generar el documento. Por favor, contacte al administrador.");
} catch (Exception $e) {
    error_log("Error general: " . $e->getMessage());
    die("Error al generar el documento: " . $e->getMessage());
}