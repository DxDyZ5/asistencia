<?php
// subir_foto_movil.php - CÁMARA, FLASH Y PROXY PHP PARA RAPIDAPI (NUEVA API)
$host = 'localhost'; $db = 'reynoteja_control_asistencia'; $user = 'reynoteja_carlos'; $pass = 'M22300435397'; $charset = 'utf8mb4';

// --- A) PROXY PARA LA API (Manejo del lado del servidor) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_bg_proxy') {
    header('Content-Type: application/json');
    
    // 1. Obtener imagen base64
    $base64_img = $_POST['image_base64'] ?? '';
    if (empty($base64_img)) {
        echo json_encode(['success' => false, 'error' => 'No se recibió imagen']);
        exit;
    }

    // 2. Convertir a archivo temporal
    $data = explode(',', $base64_img);
    $content = base64_decode(count($data) > 1 ? $data[1] : $data[0]);
    $temp_file = sys_get_temp_dir() . '/bg_' . uniqid() . '.jpg';
    file_put_contents($temp_file, $content);

    // 3. Configurar cURL para RapidAPI (NUEVA API)
    $curl = curl_init();
    
    // Preparar archivo para cURL
    $cfile = new CURLFile($temp_file, 'image/jpeg', 'image.jpg');

    curl_setopt_array($curl, [
        CURLOPT_URL => "https://people-photo-background-removal.p.rapidapi.com/v1/results", // NUEVA URL
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => ['image' => $cfile], // El campo esperado suele ser 'image' o 'image_file'
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: people-photo-background-removal.p.rapidapi.com", // NUEVO HOST
            "x-rapidapi-key: 604f6d2c21msh2345134076338dap12b545jsn487fe26df58a" // TU KEY
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
    curl_close($curl);
    unlink($temp_file); // Borrar archivo temporal

    // 4. Procesar respuesta
    if ($err) {
        echo json_encode(['success' => false, 'error' => "cURL Error: $err"]);
    } elseif ($http_code !== 200) {
        // Intentar leer error JSON de la API
        $api_error = json_decode($response, true);
        $msg = $api_error['message'] ?? $api_error['detail'] ?? substr($response, 0, 100);
        echo json_encode(['success' => false, 'error' => "API Error ($http_code): $msg"]);
    } else {
        // Esta API devuelve la imagen binaria (PNG) directamente
        $base64_resp = 'data:image/png;base64,' . base64_encode($response);
        echo json_encode(['success' => true, 'image' => $base64_resp]);
    }
    exit; // Detener ejecución aquí
}

// --- B) LÓGICA NORMAL (HTML) ---
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("Error de conexión."); }

$id = $_GET['id'] ?? 0;
$token = $_GET['token'] ?? '';
$type = $_GET['type'] ?? 'perfil'; 
$mensaje = '';

if ($token !== md5($id . "secret_salt" . date('Y-m-d'))) die("<h1 style='text-align:center;padding:50px;font-family:sans-serif'>Enlace Expirado</h1>");

$stmt = $pdo->prepare("SELECT nombre_completo, cedula FROM empleados WHERE id_reloj = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();
if (!$emp) die("Error: Empleado no encontrado.");

$cedula_clean = preg_replace('/[^0-9]/', '', $emp['cedula']);

// PROCESAR SUBIDA FINAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['imagen_recortada']) && !isset($_POST['action'])) {
    $data = $_POST['imagen_recortada'];
    if (strpos($data, 'base64') !== false) {
        $data = explode(',', $data)[1];
        $img = base64_decode($data);
        $folder = ($type == 'doc') ? 'uploads/docs/' : (($type == 'cedula') ? 'fotos_cedula/' : 'fotos/');
        if (!is_dir($folder)) mkdir($folder, 0777, true);
        $filename = $cedula_clean . ".jpg";
        if($type == 'doc') {
            $filename = uniqid() . "_" . $cedula_clean . ".jpg";
            $titulo_doc = $_POST['titulo_doc'] ?? 'Documento Móvil';
            if(file_put_contents($folder . $filename, $img)) {
                $stmtDoc = $pdo->prepare("INSERT INTO documentos_empleado (id_empleado_reloj, titulo, nombre_archivo, tipo_archivo) VALUES (?, ?, ?, 'jpg')");
                $stmtDoc->execute([$id, $titulo_doc, $filename]);
                $mensaje = "¡Documento guardado!";
            }
        } else {
            if(file_put_contents($folder . $filename, $img)) $mensaje = "¡Foto guardada correctamente!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Subir Foto - <?php echo htmlspecialchars($emp['nombre_completo']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/croppie/2.6.5/croppie.min.js"></script>
    
    <style>
        body { background: #000; color: white; height: 100dvh; width: 100vw; overflow: hidden; display: flex; flex-direction: column; touch-action: none; }
        #cam-container { position: relative; flex-grow: 1; overflow: hidden; background: #000; display: flex; align-items: center; justify-content: center; }
        video { width: 100%; height: 100%; object-fit: cover; position: absolute; z-index: 1; }
        .overlay-guide { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%); border: 2px dashed rgba(255, 255, 255, 0.5); box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5); z-index: 10; pointer-events: none; }
        .guide-perfil { width: 250px; height: 300px; border-radius: 150px 150px 20px 20px; }
        .guide-cedula, .guide-doc { width: 90%; height: 200px; border-radius: 10px; }
        .controls { padding-bottom: env(safe-area-inset-bottom, 50px); padding-top: 20px; background: rgba(0,0,0,0.8); text-align: center; z-index: 20; display: flex; justify-content: center; gap: 30px; align-items: center; }
        .btn-capture { width: 70px; height: 70px; border-radius: 50%; background: white; border: 5px solid rgba(255,255,255,0.3); cursor: pointer; transition: transform 0.1s; }
        .btn-capture:active { transform: scale(0.9); background: #eee; }
        .flash-on { color: #facc15 !important; text-shadow: 0 0 10px #facc15; }
        #flash-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: white; z-index: 9999; opacity: 0; pointer-events: none; }
        .flash-anim { animation: flash 0.2s ease-out; }
        @keyframes flash { 0% { opacity: 1; } 100% { opacity: 0; } }
        #crop-step { position: absolute; top: 0; left: 0; width: 100%; height: 100dvh; background: #222; z-index: 30; display: flex; flex-direction: column; }
        #crop-container { flex-grow: 1; position: relative; background: #111; }
        .range-slider { -webkit-appearance: none; width: 100%; height: 4px; border-radius: 5px; background: #4b5563; outline: none; }
        .range-slider::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 15px; height: 15px; border-radius: 50%; background: #3b82f6; cursor: pointer; }
    </style>
</head>
<body>
    
    <div id="flash-overlay"></div>

    <!-- PASO 1: CÁMARA -->
    <div id="step-1" class="h-full flex flex-col">
        <div class="absolute top-0 w-full p-4 z-20 flex justify-between items-center bg-gradient-to-b from-black/50 to-transparent pt-safe-top">
            <button id="btn-flash" class="text-white text-2xl p-2 hidden" onclick="toggleFlash()"><i class="fas fa-bolt"></i></button>
            <div class="text-xs font-bold bg-black/30 px-2 py-1 rounded"><?php echo strtoupper($type); ?></div>
        </div>
        <div id="cam-container">
            <video id="video" autoplay playsinline></video>
            <?php if($type == 'perfil'): ?>
                <div class="overlay-guide guide-perfil"></div>
                <div class="absolute top-28 w-full text-center text-white/80 z-20 font-bold text-shadow">Alinea el rostro</div>
            <?php else: ?>
                <div class="overlay-guide guide-cedula"></div>
                <div class="absolute top-28 w-full text-center text-white/80 z-20 font-bold text-shadow">Alinea el documento</div>
            <?php endif; ?>
        </div>
        <div class="controls">
            <button class="text-white text-2xl px-4" onclick="switchCamera()"><i class="fas fa-sync-alt"></i></button>
            <button class="btn-capture" onclick="tomarFoto()"></button>
            <label class="text-white text-2xl px-4 cursor-pointer"><i class="fas fa-image"></i><input type="file" accept="image/*" class="hidden" onchange="archivoSeleccionado(this)"></label>
        </div>
    </div>

    <!-- PASO 2: RECORTE Y EDICIÓN -->
    <div id="step-2" class="hidden h-full flex flex-col bg-gray-900">
        <div class="px-4 py-3 bg-gray-800 flex justify-between items-center shrink-0 pt-safe-top">
            <span class="font-bold text-sm">Editar</span>
            <?php if($type == 'perfil'): ?>
            <button id="btn-bg-magic" onclick="procesarFondoServer()" class="bg-purple-600 hover:bg-purple-500 text-white px-3 py-1.5 rounded-full font-bold text-[10px] flex items-center gap-1 border border-purple-400 shadow-lg">
                <i class="fas fa-magic"></i> Quitar Fondo
            </button>
            <?php endif; ?>
        </div>
        <div id="crop-container"></div>
        <div class="bg-gray-800 p-3 space-y-2 border-t border-gray-700 shrink-0">
            <div class="flex justify-center">
                <button onclick="autoMejorar()" class="bg-blue-900/50 text-blue-300 border border-blue-500/50 px-4 py-1 rounded text-xs font-bold flex items-center gap-2 hover:bg-blue-900 transition"><i class="fas fa-wand-magic-sparkles"></i> Auto-Mejorar</button>
            </div>
            <div class="grid grid-cols-3 gap-3 text-xs pb-2">
                <div class="flex flex-col items-center"><label class="text-gray-400 mb-1"><i class="fas fa-sun"></i></label><input type="range" id="sl-bright" min="50" max="150" value="100" class="range-slider" oninput="aplicarFiltrosVisuales()"></div>
                <div class="flex flex-col items-center"><label class="text-gray-400 mb-1"><i class="fas fa-adjust"></i></label><input type="range" id="sl-contrast" min="50" max="150" value="100" class="range-slider" oninput="aplicarFiltrosVisuales()"></div>
                <div class="flex flex-col items-center"><label class="text-gray-400 mb-1"><i class="fas fa-tint"></i></label><input type="range" id="sl-saturate" min="0" max="200" value="100" class="range-slider" oninput="aplicarFiltrosVisuales()"></div>
            </div>
        </div>
        <?php if($type == 'doc'): ?><div class="px-4 py-2 bg-gray-800 shrink-0"><input type="text" id="doc_title" placeholder="Nombre del Documento" class="w-full p-2 text-black rounded text-sm"></div><?php endif; ?>
        <div class="p-4 flex gap-4 bg-gray-900 border-t border-gray-700 shrink-0" style="padding-bottom: env(safe-area-inset-bottom, 60px); padding-bottom: 60px;">
            <button onclick="location.reload()" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white py-3 rounded-lg font-bold text-sm">Cancelar</button>
            <button onclick="guardarRecorte()" class="flex-1 bg-blue-600 hover:bg-blue-500 text-white py-3 rounded-lg font-bold text-sm shadow-lg">Guardar Foto</button>
        </div>
    </div>

    <?php if($mensaje): ?>
    <div class="fixed inset-0 bg-green-600 z-50 flex flex-col items-center justify-center text-white text-center p-8">
        <i class="fas fa-check-circle text-6xl mb-4 animate-bounce"></i><h2 class="text-2xl font-bold mb-2">¡Listo!</h2><p><?php echo $mensaje; ?></p><button onclick="window.close()" class="mt-8 bg-white text-green-600 px-8 py-3 rounded-full font-bold shadow-xl">Cerrar</button>
    </div>
    <?php endif; ?>

    <form id="final-form" method="POST" class="hidden"><input type="hidden" name="imagen_recortada" id="input-recorte"><input type="hidden" name="titulo_doc" id="input-titulo"></form>
    <canvas id="canvas" class="hidden"></canvas>

    <script>
        let stream = null; let track = null; let isFlashOn = false; let currentFacingMode = 'environment';
        let croppie; let rawImageBase64 = ''; 
        const video = document.getElementById('video'); const btnFlash = document.getElementById('btn-flash');

        async function initCamera() {
            if (stream) stream.getTracks().forEach(t => t.stop());
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: currentFacingMode, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
                video.srcObject = stream;
                track = stream.getVideoTracks()[0];
                const capabilities = track.getCapabilities();
                if (capabilities.torch) { btnFlash.classList.remove('hidden'); isFlashOn = false; updateFlashIcon(); } else { btnFlash.classList.add('hidden'); }
            } catch (err) { alert("Error de cámara: " + err.message); }
        }

        async function toggleFlash() {
            if (!track) return; isFlashOn = !isFlashOn;
            try { await track.applyConstraints({ advanced: [{ torch: isFlashOn }] }); updateFlashIcon(); } catch (err) { isFlashOn = false; }
        }
        function updateFlashIcon() { btnFlash.innerHTML = isFlashOn ? '<i class="fas fa-bolt text-yellow-400"></i>' : '<i class="fas fa-bolt text-gray-400"></i>'; }
        function switchCamera() { currentFacingMode = (currentFacingMode === 'user') ? 'environment' : 'user'; initCamera(); }

        function tomarFoto() {
            const flash = document.getElementById('flash-overlay'); flash.classList.add('flash-anim'); setTimeout(() => flash.classList.remove('flash-anim'), 300);
            const canvas = document.getElementById('canvas'); const context = canvas.getContext('2d');
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            if(isFlashOn) toggleFlash();
            iniciarRecorte(canvas.toDataURL('image/jpeg'));
        }

        function archivoSeleccionado(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader(); reader.onload = function(e) { iniciarRecorte(e.target.result); }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function iniciarRecorte(urlImagen) {
            rawImageBase64 = urlImagen;
            document.getElementById('step-1').classList.add('hidden'); document.getElementById('step-2').classList.remove('hidden');
            const isPerfil = '<?php echo $type; ?>' === 'perfil';
            const vW = Math.min(window.innerWidth * 0.7, 250); 
            const viewport = isPerfil ? { width: vW, height: vW * 1.25, type: 'square' } : { width: vW, height: vW * 0.65, type: 'square' };
            if(croppie) croppie.destroy();
            croppie = new Croppie(document.getElementById('crop-container'), { viewport: viewport, boundary: { width: '100%', height: window.innerHeight * 0.45 }, showZoomer: true, enableOrientation: true });
            croppie.bind({ url: urlImagen });
            document.getElementById('sl-bright').value = 100; document.getElementById('sl-contrast').value = 100; document.getElementById('sl-saturate').value = 100;
        }

        function aplicarFiltrosVisuales() {
            const b = document.getElementById('sl-bright').value; const c = document.getElementById('sl-contrast').value; const s = document.getElementById('sl-saturate').value;
            const img = document.querySelector('.cr-image'); if(img) img.style.filter = `brightness(${b}%) contrast(${c}%) saturate(${s}%)`;
        }

        function autoMejorar() {
            document.getElementById('sl-bright').value = 110; document.getElementById('sl-contrast').value = 115; document.getElementById('sl-saturate').value = 120; aplicarFiltrosVisuales();
        }

        // --- PROCESAMIENTO VIA SERVIDOR (NUEVA API) ---
        function procesarFondoServer() {
            const btn = document.getElementById('btn-bg-magic');
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
            btn.disabled = true; btn.classList.add('opacity-50');

            const formData = new FormData();
            formData.append('action', 'remove_bg_proxy');
            formData.append('image_base64', rawImageBase64);

            fetch('subir_foto_movil.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    const img = new Image(); img.src = data.image;
                    img.onload = function() {
                        const cvs = document.createElement('canvas');
                        cvs.width = img.width; cvs.height = img.height;
                        const ctx = cvs.getContext('2d');
                        ctx.fillStyle = '#FFFFFF';
                        ctx.fillRect(0, 0, cvs.width, cvs.height);
                        ctx.drawImage(img, 0, 0);
                        const newUrl = cvs.toDataURL('image/jpeg', 0.95);
                        croppie.bind({ url: newUrl });
                        rawImageBase64 = newUrl; 
                        btn.innerHTML = '<i class="fas fa-check"></i> Listo';
                        btn.classList.replace('bg-purple-600', 'bg-green-600');
                    };
                } else {
                    alert('Error: ' + data.error);
                    btn.innerHTML = originalHtml;
                }
            })
            .catch(err => { alert('Error Red: ' + err.message); btn.innerHTML = originalHtml; })
            .finally(() => { if(btn.innerText !== 'Listo') { btn.disabled = false; btn.classList.remove('opacity-50'); } });
        }
        
        function guardarRecorte() {
            croppie.result({ type: 'base64', size: 'original', format: 'jpeg', quality: 0.95 }).then(function(base64) {
                const img = new Image(); img.src = base64;
                img.onload = function() {
                    const cvs = document.createElement('canvas');
                    cvs.width = img.width; cvs.height = img.height;
                    const ctx = cvs.getContext('2d');
                    const b = document.getElementById('sl-bright').value;
                    const c = document.getElementById('sl-contrast').value;
                    const s = document.getElementById('sl-saturate').value;
                    ctx.filter = `brightness(${b}%) contrast(${c}%) saturate(${s}%)`;
                    ctx.drawImage(img, 0, 0);
                    document.getElementById('input-recorte').value = cvs.toDataURL('image/jpeg', 0.9);
                    if(document.getElementById('doc_title')) document.getElementById('input-titulo').value = document.getElementById('doc_title').value;
                    document.getElementById('final-form').submit();
                };
            });
        }

        window.onload = initCamera;
    </script>
</body>
</html>