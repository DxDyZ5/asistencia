<?php
// carnets_diseno.php - IMPRESIÓN + EDICIÓN CON API BACKGROUND-REMOVAL4
require 'auth.php'; 

// =============================================================================
// 1. PROXY PHP PARA RAPIDAPI (API: background-removal4)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_bg_proxy') {
    // Limpiar buffer para evitar basura en el JSON
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    
    $base64_img = $_POST['image_base64'] ?? '';
    if (empty($base64_img)) {
        echo json_encode(['success' => false, 'error' => 'No se recibió imagen para procesar.']);
        exit;
    }

    // Convertir Base64 a Archivo Temporal
    $data = explode(',', $base64_img);
    $content = base64_decode(count($data) > 1 ? $data[1] : $data[0]);
    $temp_file = sys_get_temp_dir() . '/bg_' . uniqid() . '.jpg';
    file_put_contents($temp_file, $content);

    // Configurar cURL para background-removal4
    $curl = curl_init();
    $cfile = new CURLFile($temp_file, 'image/jpeg', 'image.jpg');

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://background-removal4.p.rapidapi.com/v1/results?mode=fg-image", // Endpoint solicitado
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        // NOTA: Al enviar un array con CURLFile, PHP usa automáticamente multipart/form-data
        // El nombre del campo clave aquí es 'image'
        CURLOPT_POSTFIELDS => ['image' => $cfile], 
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: background-removal4.p.rapidapi.com",
            "x-rapidapi-key: 604f6d2c21msh2345134076338dap12b545jsn487fe26df58a"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    curl_close($curl);
    @unlink($temp_file);

    if ($err) {
        echo json_encode(['success' => false, 'error' => "Error de conexión cURL: $err"]);
    } elseif ($http_code !== 200) {
        // Intentar leer mensaje de error de la API
        $api_err = json_decode($response, true);
        $msg = $api_err['detail'] ?? $api_err['message'] ?? substr(strip_tags($response), 0, 200);
        echo json_encode(['success' => false, 'error' => "La API rechazó la imagen ($http_code): $msg"]);
    } else {
        // La API devuelve la imagen binaria (PNG). Validamos si tiene contenido.
        if (strlen($response) > 0) {
            $base64_resp = 'data:image/png;base64,' . base64_encode($response);
            echo json_encode(['success' => true, 'image' => $base64_resp]);
        } else {
            echo json_encode(['success' => false, 'error' => "La API devolvió una respuesta vacía."]);
        }
    }
    exit; 
}
// =============================================================================


// --- CONEXIÓN BD ---
if (!isset($pdo)) {
    $host = 'localhost';
    $db   = 'reynoteja_control_asistencia';
    $user = 'reynoteja_carlos';
    $pass = 'M22300435397'; 
    $charset = 'utf8mb4';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) { die("Error DB: " . $e->getMessage()); }
}

// --- AJAX SAVE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_images'])) {
    try {
        $cedula_safe = preg_replace('/[^0-9]/', '', $_POST['cedula_emp']);
        $dir = 'carnets_generados/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        if (!empty($_POST['img_front'])) {
            $data = explode(',', $_POST['img_front'])[1];
            file_put_contents($dir . $cedula_safe . '-front.jpg', base64_decode($data));
        }
        if (!empty($_POST['img_back'])) {
            $data = explode(',', $_POST['img_back'])[1];
            file_put_contents($dir . $cedula_safe . '-back.jpg', base64_decode($data));
        }

        if (isset($_POST['id_reloj'])) {
            $stmt = $pdo->prepare("UPDATE empleados SET solicitud_carnet = 2 WHERE id_reloj = ?");
            $stmt->execute([$_POST['id_reloj']]);
        }
        echo "ok";
    } catch (Exception $e) { echo "Error PHP: " . $e->getMessage(); }
    exit;
}

// --- PARÁMETROS ---
$id = $_GET['id'] ?? 0;
$diseno_id = $_GET['diseno_id'] ?? 0;
$regenerar = isset($_GET['regenerar']);

// --- CARGAR DATOS ---
$stmt = $pdo->prepare("SELECT * FROM empleados WHERE id_reloj = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();
if(!$emp) die("Error: Empleado no encontrado.");

$disenos = $pdo->query("SELECT * FROM disenos_carnets ORDER BY id DESC")->fetchAll();

$db_config = [];
if ($diseno_id) {
    foreach($disenos as $d) { if($d['id'] == $diseno_id) { $db_config = json_decode($d['configuracion'], true); break; } }
} elseif (!empty($disenos)) {
    $db_config = json_decode($disenos[0]['configuracion'], true);
    $diseno_id = $disenos[0]['id'];
}

// --- CONFIGURACIÓN DEFAULT ---
$default_vals = [
    'bg_color' => '#ffffff', 'bg_image' => '',
    'card_width' => 53.975,   
    'card_height' => 85.7725, 
    'foto' => ['visible'=>1, 'top'=>20, 'left'=>12, 'width'=>30, 'height'=>38, 'radius'=>2, 'zoom'=>1, 'pos_x'=>50, 'pos_y'=>50],
    'nombre' => ['visible'=>1, 'top'=>60, 'left'=>2, 'width'=>50, 'size'=>12, 'color'=>'#000000', 'align'=>'center'],
    'cedula' => ['visible'=>1, 'top'=>68, 'left'=>2, 'width'=>50, 'size'=>10, 'color'=>'#333333', 'align'=>'center'],
    'cargo' => ['visible'=>1, 'top'=>73, 'left'=>2, 'width'=>50, 'size'=>9, 'color'=>'#003366', 'align'=>'center'],
    'depto' => ['visible'=>1, 'top'=>78, 'left'=>2, 'width'=>50, 'size'=>8, 'color'=>'#666666', 'align'=>'center'],
    'shapes' => [], 'custom_texts' => []
];

$config = array_replace_recursive($default_vals, $db_config ?? []);

function safe_val($val, $default) { return (is_numeric($val) && (float)$val > 0.1) ? (float)$val : $default; }
function checkVis($arr) { return isset($arr['visible']) ? $arr['visible'] : 1; }

$cw = safe_val($config['card_width'] ?? 0, 53.975);
$ch = safe_val($config['card_height'] ?? 0, 85.7725);
$bg_img = $config['bg_image'] ?? '';

$cedula_clean = preg_replace('/[^0-9]/', '', $emp['cedula']);
$ruta_foto = "fotos/" . $cedula_clean . ".jpg";
$foto_url = file_exists($ruta_foto) ? $ruta_foto . "?v=".time() : "https://ui-avatars.com/api/?name=".urlencode($emp['nombre_completo'])."&size=300&background=random";

$qr_data = urlencode($emp['cedula']); 
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=450x450&data=$qr_data";

// Nombres
$full_name = trim($emp['nombre_completo']);
$parts = explode(' ', $full_name);
$nombres_opciones = [];
$nombres_opciones['completo'] = $full_name;
if (count($parts) >= 3) {
    $apellido_index = count($parts) - 2; if ($apellido_index < 1) $apellido_index = 1;
    $nombres_opciones['corto'] = $parts[0] . ' ' . $parts[$apellido_index];
} elseif (count($parts) == 2) {
    $nombres_opciones['corto'] = $parts[0] . ' ' . $parts[1];
} else {
    $nombres_opciones['corto'] = $full_name;
}
$nombre_mostrar = $nombres_opciones['corto'];

function formatCedula($ced) {
    $c = preg_replace('/[^0-9]/', '', $ced);
    if(strlen($c) == 11) return substr($c, 0, 3) . '-' . substr($c, 3, 7) . '-' . substr($c, 10, 1);
    return $ced;
}
$cedula_formateada = formatCedula($emp['cedula']);

$f_zoom = safe_val($config['foto']['zoom'] ?? 0, 1);
$f_pos_x = safe_val($config['foto']['pos_x'] ?? 0, 50);
$f_pos_y = safe_val($config['foto']['pos_y'] ?? 0, 50);

$path_front = "carnets_generados/" . $cedula_clean . "-front.jpg";
$path_back  = "carnets_generados/" . $cedula_clean . "-back.jpg";
$carnet_listo = (file_exists($path_front) && file_exists($path_back) && !$regenerar);

$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
$url_front_abs = $base_url . '/' . $path_front . '?v=' . time();
$url_back_abs = $base_url . '/' . $path_back . '?v=' . time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Carnet: <?php echo htmlspecialchars($emp['nombre_completo']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --card-width: <?php echo $cw; ?>mm;
            --card-height: <?php echo $ch; ?>mm;
        }
        body { font-family: 'Arial', sans-serif; background-color: #f3f4f6; }
        .carnet-wrapper {
            width: var(--card-width); height: var(--card-height);
            position: relative; overflow: hidden; box-sizing: border-box;
            background-color: white; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            border: 1px solid #ccc; margin: 0 auto 10mm auto;
            transform-origin: top center;
        }
        .carnet-front {
            background-color: <?php echo $config['bg_color']; ?>;
            <?php if($bg_img): ?>
            background-image: url('<?php echo $bg_img; ?>');
            background-size: 100% 100%; background-position: center; background-repeat: no-repeat;
            <?php endif; ?>
        }
        .carnet-back { 
            display: flex; flex-direction: column; padding: 2mm 3mm; 
            background: white; align-items: center; justify-content: space-between;
        }
        .el-abs, .shape-abs { position: absolute; line-height: 1.1; white-space: nowrap; box-sizing: border-box; margin: 0; padding: 0; }
        .editable:hover { outline: 1px dashed #3b82f6; cursor: text; background-color: rgba(255, 255, 255, 0.5); }
        
        #el-foto {
            position: absolute;
            top: <?php echo safe_val($config['foto']['top'], 15); ?>mm;
            left: <?php echo safe_val($config['foto']['left'], 12); ?>mm;
            width: <?php echo safe_val($config['foto']['width'], 30); ?>mm;
            height: <?php echo safe_val($config['foto']['height'], 38); ?>mm;
            border-radius: <?php echo safe_val($config['foto']['radius'], 2); ?>mm;
            overflow: hidden; background: transparent; z-index: 50;
            display: <?php echo checkVis($config['foto']) ? 'block' : 'none'; ?>; cursor: move;
        }
        #el-foto-inner {
            width: 100%; height: 100%;
            background-image: url('<?php echo $foto_url; ?>');
            background-size: cover; background-repeat: no-repeat;
            background-position: <?php echo $f_pos_x; ?>% <?php echo $f_pos_y; ?>%;
            transform: scale(<?php echo $f_zoom; ?>); transform-origin: center center;
        }
        <?php if(isset($config['shapes'])): foreach($config['shapes'] as $i => $s): if(!checkVis($s)) continue; ?>
        #shape-<?php echo $i; ?> { 
            top: <?php echo $s['top']; ?>mm; left: <?php echo $s['left']; ?>mm; width: <?php echo $s['width']; ?>mm; height: <?php echo $s['height']; ?>mm; 
            background-color: <?php echo $s['color']; ?>; border-radius: <?php echo $s['radius'] ?? 0; ?>mm; z-index: 5; 
        }
        <?php endforeach; endif; ?>
        <?php foreach(['nombre','cedula','cargo','depto'] as $k): $c = $config[$k]; if(!checkVis($c)) { echo "#el-$k { display:none; }"; continue; } ?>
        #el-<?php echo $k; ?> {
            top: <?php echo $c['top']; ?>mm; left: <?php echo $c['left']; ?>mm; width: <?php echo $c['width']; ?>mm;
            font-size: <?php echo $c['size']; ?>pt; color: <?php echo $c['color']; ?>; text-align: <?php echo $c['align']; ?>; font-weight: bold; z-index: 60;
        }
        <?php endforeach; ?>
        <?php if(isset($config['custom_texts'])): foreach($config['custom_texts'] as $i => $t): if(!checkVis($t)) continue; ?>
        #el-text-<?php echo $i; ?> {
            top: <?php echo $t['top']; ?>mm; left: <?php echo $t['left']; ?>mm; width: <?php echo $t['width']; ?>mm;
            font-size: <?php echo $t['size']; ?>pt; color: <?php echo $t['color']; ?>; text-align: <?php echo $t['align']; ?>; font-weight: bold; z-index: 60;
        }
        <?php endforeach; endif; ?>
        
        .content-safe-area { margin: 0 auto; padding: 2rem; width: 100%; max-width: 1200px; }
        @media print { .no-print { display: none !important; } body { background: white; display: none; } .content-safe-area { margin: 0; padding: 0; } }
    </style>
    <script>
        let photoState = { scale: <?php echo $f_zoom; ?>, x: <?php echo $f_pos_x; ?>, y: <?php echo $f_pos_y; ?> };

        function initPhotoInteraction() {
            const container = document.getElementById('el-foto');
            let isDragging = false, startX, startY, startPhotoX, startPhotoY;
            if(!container) return;
            container.addEventListener('mousedown', (e) => { e.preventDefault(); isDragging = true; startX = e.clientX; startY = e.clientY; startPhotoX = photoState.x; startPhotoY = photoState.y; container.style.cursor = 'grabbing'; });
            document.addEventListener('mousemove', (e) => {
                if (!isDragging) return; e.preventDefault();
                const percentX = ((e.clientX - startX) / container.offsetWidth) * 100;
                const percentY = ((e.clientY - startY) / container.offsetHeight) * 100;
                photoState.x = Math.max(0, Math.min(100, startPhotoX + percentX));
                photoState.y = Math.max(0, Math.min(100, startPhotoY + percentY));
                applyPhotoTransform();
            });
            document.addEventListener('mouseup', () => { isDragging = false; if(container) container.style.cursor = 'move'; });
        }

        function updateZoom(val) { photoState.scale = parseFloat(val); applyPhotoTransform(); }
        function applyPhotoTransform() { const inner = document.getElementById('el-foto-inner'); if(inner) { inner.style.backgroundPosition = `${photoState.x}% ${photoState.y}%`; inner.style.transform = `scale(${photoState.scale})`; } }
        function updateName(val) { document.getElementById('el-nombre').innerText = val; }

        // --- LÓGICA IA PROXY ---
        async function quitarFondo() {
            const btn = document.getElementById('btn-bg-remove');
            const originalHtml = btn.innerHTML;
            
            if(!confirm("¿Usar 1 crédito de API para quitar fondo?")) return;

            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
            btn.disabled = true;

            try {
                // 1. Obtener imagen actual (URL local o remota)
                const currentImgUrl = "<?php echo $foto_url; ?>";
                const resImg = await fetch(currentImgUrl);
                const blobImg = await resImg.blob();
                
                // 2. Convertir a Base64 para enviar al Proxy
                const reader = new FileReader();
                reader.readAsDataURL(blobImg);
                reader.onloadend = async function() {
                    const base64data = reader.result;
                    
                    // 3. Enviar al mismo archivo (Proxy Integrado)
                    const formData = new FormData();
                    formData.append('action', 'remove_bg_proxy');
                    formData.append('image_base64', base64data);
                    
                    const resProxy = await fetch(window.location.href, { method: 'POST', body: formData });
                    const data = await resProxy.json();
                    
                    if (data.success) {
                        const elInner = document.getElementById('el-foto-inner');
                        elInner.style.backgroundImage = `url('${data.image}')`;
                        
                        // Asegurar fondo transparente/blanco
                        const elContainer = document.getElementById('el-foto');
                        elContainer.style.backgroundColor = 'transparent'; 
                        elContainer.style.border = 'none';
                        
                        btn.innerHTML = '<i class="fas fa-check"></i> Listo';
                        btn.classList.replace('bg-indigo-100', 'bg-green-100');
                        btn.classList.replace('text-indigo-700', 'text-green-700');
                    } else {
                        // Mostrar error pero NO BORRAR FOTO
                        alert('Error de API: ' + data.error);
                        btn.innerHTML = originalHtml;
                        btn.disabled = false;
                    }
                }
            } catch (error) {
                console.error(error);
                alert('Error de conexión: ' + error.message);
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }

        function generarCarnet() {
            const btn = document.getElementById('btn-generar'); const originalText = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...'; btn.disabled = true;
            const front = document.getElementById('carnet-front'); const back = document.getElementById('carnet-back');
            const opts = { scale: 3, useCORS: true, allowTaint: true, backgroundColor: null, width: front.offsetWidth, height: front.offsetHeight };
            Promise.all([html2canvas(front, opts), html2canvas(back, opts)]).then(canvases => {
                const formData = new FormData();
                formData.append('save_images', '1'); formData.append('cedula_emp', '<?php echo $cedula_clean; ?>'); formData.append('id_reloj', '<?php echo $id; ?>');
                formData.append('img_front', canvases[0].toDataURL('image/jpeg', 0.95)); formData.append('img_back', canvases[1].toDataURL('image/jpeg', 0.95));
                fetch(window.location.href, { method: 'POST', body: formData }).then(res => res.text()).then(data => {
                    if(data.includes('ok')) window.location.href = window.location.href.split('&regenerar')[0];
                    else { alert('Error al guardar: ' + data); btn.innerHTML = originalText; btn.disabled = false; }
                });
            });
        }
        
        function imprimirPopup() {
            var urlFront = '<?php echo $url_front_abs; ?>'; var urlBack = '<?php echo $url_back_abs; ?>';
            window.open(`imprimir_carnet.php?front=${encodeURIComponent(urlFront)}&back=${encodeURIComponent(urlBack)}&w=<?php echo $cw; ?>&h=<?php echo $ch; ?>`, '_blank', 'width=600,height=800');
        }

        window.onload = function() { initPhotoInteraction(); applyPhotoTransform(); };
    </script>
</head>
<body class="bg-gray-100 font-sans min-h-screen">

<?php include 'layout_menu.php'; ?>

<div class="content-safe-area flex flex-col items-center">

    <div class="no-print w-full max-w-3xl bg-white p-4 rounded-xl shadow-lg mb-10 border border-gray-200 sticky top-4 z-40">
        <div class="flex justify-between items-center mb-4 border-b pb-4">
            <div>
                <h1 class="font-bold text-gray-800 text-xl">Impresión de Carnet</h1>
                <p class="text-xs text-gray-500">
                    <?php echo $carnet_listo ? '<span class="text-green-600 font-bold"><i class="fas fa-check-circle"></i> Listo para Imprimir (JPG Generado)</span>' : '<span class="text-orange-500 font-bold"><i class="fas fa-edit"></i> Modo Ajuste Final</span>'; ?>
                </p>
            </div>
            <div class="flex gap-2">
                <a href="editar_empleado.php?id=<?php echo $id; ?>" class="bg-gray-100 text-gray-700 py-2 px-4 rounded-lg text-sm font-bold">Volver</a>
                <?php if($carnet_listo): ?>
                    <a href="?id=<?php echo $id; ?>&diseno_id=<?php echo $diseno_id; ?>&regenerar=1" class="bg-yellow-100 text-yellow-800 font-bold py-2 px-4 rounded-lg text-sm">Regenerar</a>
                    <button onclick="imprimirPopup()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded-lg shadow">Imprimir</button>
                    <a href="<?php echo $path_front; ?>" download="Carnet_<?php echo $cedula_clean; ?>_Frente.jpg" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg shadow transition text-sm flex items-center gap-2"><i class="fas fa-download"></i> Descargar</a>
                <?php else: ?>
                    <a href="carnets_config.php?editar=<?php echo $diseno_id; ?>" class="bg-yellow-500 text-white font-bold py-2 px-4 rounded-lg shadow">Plantilla</a>
                    <button id="btn-generar" onclick="generarCarnet()" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded-lg shadow">Generar</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if(!$carnet_listo): ?>
        <div class="bg-gray-50 p-3 rounded mb-4 border border-gray-200 text-sm">
            <h4 class="font-bold text-xs text-gray-500 uppercase mb-2">Ajustes Finales (Solo para esta impresión)</h4>
            <div class="flex flex-wrap gap-4 items-center">
                <div class="flex items-center gap-2">
                    <label class="font-bold text-xs">Nombre:</label>
                    <select onchange="updateName(this.value)" class="border rounded px-2 py-1 text-xs bg-white">
                        <?php foreach($nombres_opciones as $k => $v): ?><option value="<?php echo $v; ?>" <?php echo $k=='corto'?'selected':''; ?>><?php echo $v; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <label class="font-bold text-xs">Foto:</label>
                    <input type="range" id="zoom-range" min="0.5" max="3" step="0.1" value="<?php echo $f_zoom; ?>" oninput="updateZoom(this.value)" class="w-20 cursor-pointer">
                    <!-- BOTÓN MAGIA IA -->
                    <button id="btn-bg-remove" onclick="quitarFondo()" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 border border-indigo-300 px-2 py-1 rounded text-xs font-bold transition flex items-center gap-1" title="Quitar Fondo con API">
                        <i class="fas fa-magic"></i> Quitar Fondo (IA)
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <form method="GET" class="bg-blue-50 p-3 rounded-lg border border-blue-100 flex items-center gap-3">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            <label class="font-bold text-sm text-blue-800 flex items-center whitespace-nowrap">Plantilla:</label>
            <select name="diseno_id" onchange="this.form.submit()" class="border border-gray-300 rounded-md px-3 py-2 text-sm flex-grow bg-white focus:ring-2 focus:ring-blue-500 outline-none cursor-pointer">
                <?php foreach($disenos as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $d['id'] == $diseno_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['nombre_empresa']); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if($carnet_listo): ?>
        <div class="print-images-container" style="display: flex; flex-direction: column; gap: 20px;">
            <div><p class="text-center text-xs text-gray-400 mb-1 no-print">Frente</p><img src="<?php echo $path_front . '?v='.time(); ?>" class="print-img" style="width:var(--card-width);height:var(--card-height)"></div>
            <div><p class="text-center text-xs text-gray-400 mb-1 no-print">Reverso</p><img src="<?php echo $path_back . '?v='.time(); ?>" class="print-img" style="width:var(--card-width);height:var(--card-height)"></div>
        </div>
    <?php endif; ?>

    <div id="html-builder-section" style="<?php echo $carnet_listo ? 'display:none;' : ''; ?>">
        <p class="text-center text-xs text-gray-400 mb-1 no-print">Vista HTML (Frente)</p>
        <div id="carnet-front" class="carnet-wrapper carnet-front">
            <?php if(isset($config['shapes'])): foreach($config['shapes'] as $i => $s): if(!checkVis($s)) continue; ?><div id="shape-<?php echo $i; ?>" class="shape-abs"></div><?php endforeach; endif; ?>
            <div id="el-foto"><div id="el-foto-inner"></div></div>
            <div id="el-nombre" class="el-abs editable" contenteditable="true"><?php echo $nombre_mostrar; ?></div>
            <div id="el-cedula" class="el-abs editable" contenteditable="true"><?php echo $cedula_formateada; ?></div>
            <div id="el-cargo" class="el-abs editable" contenteditable="true"><?php echo $emp['cargo']; ?></div>
            <div id="el-depto" class="el-abs editable" contenteditable="true"><?php echo $emp['departamento']; ?></div>
            <?php if(isset($config['custom_texts'])): foreach($config['custom_texts'] as $i => $t): if(!checkVis($t)) continue; ?><div id="el-text-<?php echo $i; ?>" class="el-abs editable" contenteditable="true"><?php echo htmlspecialchars($t['content']); ?></div><?php endforeach; endif; ?>
        </div>
        <div class="h-8"></div>
        <p class="text-center text-xs text-gray-400 mb-1 no-print">Vista HTML (Reverso)</p>
        <div id="carnet-back" class="carnet-wrapper carnet-back">
            <div class="flex-grow flex flex-col items-center justify-center mt-2">
                <img src="<?php echo $qr_url; ?>" class="w-44 h-44 border-4 border-gray-900 p-1 mb-1" crossorigin="anonymous">
                <div class="text-6xl font-black text-gray-900 font-mono tracking-tighter leading-none mt-0">
                    <?php echo str_pad($emp['id_reloj'], 4, '0', STR_PAD_LEFT); ?>
                </div>
            </div>
            <div class="w-full text-center mb-2 px-1">
                <p class="text-sm font-black text-gray-900 uppercase leading-tight mb-2">EN CASO DE PÉRDIDA, LLAMAR AL (809) 689 0555 O DIRIGIRSE A TELEMICRO</p>
                <p class="text-xs font-bold text-gray-600 uppercase leading-tight">Este Carnet solo podrá ser usado por la persona que aparece en la fotografía.</p>
            </div>
            <div class="w-full border-t-2 border-gray-300 pt-1 flex justify-between items-end mb-1 pb-4">
                <div class="text-left"><span class="text-[9px] uppercase font-bold text-gray-400 block">Expedición</span><span class="text-xs font-bold text-gray-800"><?php echo date('d/m/Y'); ?></span></div>
                <div class="text-right"><span class="text-sm font-black text-blue-900 uppercase tracking-widest">Telemicro.com.do</span></div>
            </div>
        </div>
    </div>

</div>

</body>
</html>