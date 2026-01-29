<?php
// carnets_config.php - EDITOR VISUAL DE CARNETS (CON BORRADO SEGURO)
require 'auth.php'; 
verificarPermiso(['admin']); // Solo administradores pueden entrar aquí

// --- CONEXIÓN BD ---
if (!isset($pdo)) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (Exception $e) { die("Error DB"); }
}

// --- CONFIGURACIÓN TABLA ---
$pdo->exec("CREATE TABLE IF NOT EXISTS disenos_carnets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_empresa VARCHAR(100) NOT NULL,
    configuracion JSON NOT NULL,
    activo TINYINT DEFAULT 1
)");

$mensaje = '';
$edit_data = null; 

// --- 1. PROCESAR DUPLICACIÓN (GET) ---
if (isset($_GET['duplicar'])) {
    $stmt = $pdo->prepare("SELECT * FROM disenos_carnets WHERE id = ?");
    $stmt->execute([$_GET['duplicar']]);
    $original = $stmt->fetch();
    if ($original) {
        $edit_data = $original;
        $edit_data['id'] = ''; // Limpiar ID para insertar nuevo
        $edit_data['nombre_empresa'] .= ' (Copia)'; 
        $mensaje = "Modo Duplicación: Guarda para crear un nuevo diseño.";
    }
}

// --- 2. PROCESAR ELIMINACIÓN (POST - SOLO ADMIN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_diseno'])) {
    // Doble verificación de seguridad
    if ($rol_logueado === 'admin') {
        $id_borrar = $_POST['id_diseno_borrar'];
        $stmt = $pdo->prepare("DELETE FROM disenos_carnets WHERE id = ?");
        $stmt->execute([$id_borrar]);
        header("Location: carnets_config.php"); // Redirigir al inicio limpio
        exit;
    } else {
        $mensaje = "⛔ Error: No tienes permisos de Super Usuario para borrar diseños.";
    }
}

// --- 3. PROCESAR GUARDADO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_diseno'])) {
    $nombre = $_POST['nombre_empresa'];
    
    // Subida Imagen Fondo
    $bg_image_final = $_POST['bg_image']; 
    if (isset($_FILES['bg_image_file']) && $_FILES['bg_image_file']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['bg_image_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $dir = 'uploads/carnets/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $newName = 'bg_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['bg_image_file']['tmp_name'], $dir . $newName)) {
                $bg_image_final = $dir . $newName;
            }
        }
    }

    // Construcción del JSON de Configuración
    $config = [
        'bg_color' => $_POST['bg_color'],
        'bg_image' => $bg_image_final,
        'card_width' => $_POST['card_width'] ?? 85.60,
        'card_height' => $_POST['card_height'] ?? 53.98,
        'foto' => [
            'visible' => isset($_POST['foto_visible']) ? 1 : 0,
            'top' => $_POST['foto_top'], 'left' => $_POST['foto_left'], 
            'width' => $_POST['foto_width'], 'height' => $_POST['foto_height'], 
            'radius' => $_POST['foto_radius'],
            'zoom' => $_POST['foto_zoom'] ?? 1,
            'pos_x' => $_POST['foto_pos_x'] ?? 50,
            'pos_y' => $_POST['foto_pos_y'] ?? 50
        ],
        'nombre' => [
            'visible' => isset($_POST['nombre_visible']) ? 1 : 0,
            'top' => $_POST['nombre_top'], 'left' => $_POST['nombre_left'], 
            'size' => $_POST['nombre_size'], 'color' => $_POST['nombre_color'],
            'align' => $_POST['nombre_align'], 'width' => $_POST['nombre_width']
        ],
        'cedula' => [
            'visible' => isset($_POST['cedula_visible']) ? 1 : 0,
            'top' => $_POST['cedula_top'], 'left' => $_POST['cedula_left'], 
            'size' => $_POST['cedula_size'], 'color' => $_POST['cedula_color'],
            'align' => $_POST['cedula_align'], 'width' => $_POST['cedula_width']
        ],
        'cargo' => [
            'visible' => isset($_POST['cargo_visible']) ? 1 : 0,
            'top' => $_POST['cargo_top'], 'left' => $_POST['cargo_left'], 
            'size' => $_POST['cargo_size'], 'color' => $_POST['cargo_color'],
            'align' => $_POST['cargo_align'], 'width' => $_POST['cargo_width']
        ],
        'depto' => [
            'visible' => isset($_POST['depto_visible']) ? 1 : 0,
            'top' => $_POST['depto_top'], 'left' => $_POST['depto_left'], 
            'size' => $_POST['depto_size'], 'color' => $_POST['depto_color'],
            'align' => $_POST['depto_align'], 'width' => $_POST['depto_width']
        ],
        'shapes' => [
            [
                'visible' => isset($_POST['s1_visible']) ? 1 : 0,
                'top'=>$_POST['s1_top'], 'left'=>$_POST['s1_left'], 'width'=>$_POST['s1_width'], 'height'=>$_POST['s1_height'], 'color'=>$_POST['s1_color'], 'radius'=>$_POST['s1_radius']
            ],
            [
                'visible' => isset($_POST['s2_visible']) ? 1 : 0,
                'top'=>$_POST['s2_top'], 'left'=>$_POST['s2_left'], 'width'=>$_POST['s2_width'], 'height'=>$_POST['s2_height'], 'color'=>$_POST['s2_color'], 'radius'=>$_POST['s2_radius']
            ]
        ],
        'custom_texts' => [
            [
                'visible' => isset($_POST['t1_visible']) ? 1 : 0,
                'content' => $_POST['t1_content'],
                'top'=>$_POST['t1_top'], 'left'=>$_POST['t1_left'], 'width'=>$_POST['t1_width'], 
                'size'=>$_POST['t1_size'], 'color'=>$_POST['t1_color'], 'align'=>$_POST['t1_align']
            ],
            [
                'visible' => isset($_POST['t2_visible']) ? 1 : 0,
                'content' => $_POST['t2_content'],
                'top'=>$_POST['t2_top'], 'left'=>$_POST['t2_left'], 'width'=>$_POST['t2_width'], 
                'size'=>$_POST['t2_size'], 'color'=>$_POST['t2_color'], 'align'=>$_POST['t2_align']
            ]
        ]
    ];
    
    $json_config = json_encode($config);
    
    if (!empty($_POST['id_diseno'])) {
        $stmt = $pdo->prepare("UPDATE disenos_carnets SET nombre_empresa=?, configuracion=? WHERE id=?");
        $stmt->execute([$nombre, $json_config, $_POST['id_diseno']]);
        $mensaje = "Diseño actualizado correctamente.";
        // Recargar datos actualizados
        $stmt = $pdo->prepare("SELECT * FROM disenos_carnets WHERE id = ?");
        $stmt->execute([$_POST['id_diseno']]);
        $edit_data = $stmt->fetch();
    } else {
        $stmt = $pdo->prepare("INSERT INTO disenos_carnets (nombre_empresa, configuracion) VALUES (?, ?)");
        $stmt->execute([$nombre, $json_config]);
        $id_nuevo = $pdo->lastInsertId();
        header("Location: carnets_config.php?editar=$id_nuevo");
        exit;
    }
}

// --- 4. CARGAR DATOS PARA EDICIÓN ---
if (isset($_GET['editar']) && !$edit_data) {
    $stmt = $pdo->prepare("SELECT * FROM disenos_carnets WHERE id = ?");
    $stmt->execute([$_GET['editar']]);
    $edit_data = $stmt->fetch();
}

// Lista para el sidebar
$disenos = $pdo->query("SELECT * FROM disenos_carnets ORDER BY id DESC")->fetchAll();

// Valores por defecto
$def = $edit_data ? json_decode($edit_data['configuracion'], true) : [
    'bg_color' => '#ffffff', 'bg_image' => '', 
    'card_width' => 85.60, 'card_height' => 53.98,
    'foto' => ['visible'=>1, 'top' => 15, 'left' => 5, 'width' => 25, 'height' => 30, 'radius' => 2, 'zoom'=>1, 'pos_x'=>50, 'pos_y'=>50],
    'nombre' => ['visible'=>1, 'top' => 15, 'left' => 35, 'width' => 45, 'size' => 14, 'color' => '#000000', 'align' => 'left'],
    'cedula' => ['visible'=>1, 'top' => 35, 'left' => 35, 'width' => 45, 'size' => 10, 'color' => '#333333', 'align' => 'left'],
    'cargo'  => ['visible'=>1, 'top' => 25, 'left' => 35, 'width' => 45, 'size' => 10, 'color' => '#003366', 'align' => 'left'],
    'depto'  => ['visible'=>1, 'top' => 40, 'left' => 35, 'width' => 45, 'size' => 8, 'color' => '#666666', 'align' => 'left'],
    'shapes' => [
        ['visible'=>1, 'top'=>50, 'left'=>0, 'width'=>86, 'height'=>5, 'color'=>'#cc0000', 'radius'=>0],
        ['visible'=>0, 'top'=>0, 'left'=>0, 'width'=>20, 'height'=>20, 'color'=>'#000000', 'radius'=>0]
    ],
    'custom_texts' => [
        ['visible'=>0, 'content'=>'VÁLIDO 2026', 'top'=>5, 'left'=>5, 'width'=>30, 'size'=>8, 'color'=>'#000000', 'align'=>'left'],
        ['visible'=>0, 'content'=>'TEXTO EXTRA', 'top'=>10, 'left'=>5, 'width'=>30, 'size'=>8, 'color'=>'#000000', 'align'=>'left']
    ]
];

// Normalización de datos faltantes
if(!isset($def['bg_image'])) $def['bg_image'] = '';
if(!isset($def['card_width'])) $def['card_width'] = 85.60;
if(!isset($def['card_height'])) $def['card_height'] = 53.98;
if(!isset($def['custom_texts'])) $def['custom_texts'] = [
    ['visible'=>0, 'content'=>'VÁLIDO 2026', 'top'=>5, 'left'=>5, 'width'=>30, 'size'=>8, 'color'=>'#000000', 'align'=>'left'],
    ['visible'=>0, 'content'=>'TEXTO EXTRA', 'top'=>10, 'left'=>5, 'width'=>30, 'size'=>8, 'color'=>'#000000', 'align'=>'left']
];

function checkVis($arr) { return isset($arr['visible']) ? $arr['visible'] : 1; }

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constructor Visual de Carnets</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Estilos del Lienzo */
        #preview-container {
            display: flex; align-items: center; justify-content: center;
            background-image: radial-gradient(#e5e7eb 1px, transparent 1px);
            background-size: 20px 20px; overflow: hidden; height: 100%; width: 100%;
        }
        #preview-card {
            position: relative; background-color: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
            transform-origin: center center; overflow: hidden; 
            background-size: 100% 100%; 
            background-position: center; 
            background-repeat: no-repeat;
        }
        .draggable-el {
            position: absolute; border: 1px dashed rgba(0,0,0,0.1); cursor: move; user-select: none;
            white-space: nowrap; overflow: hidden; 
            z-index: 50;
        }
        .draggable-el:hover { border: 1px solid #3b82f6; background: rgba(59, 130, 246, 0.05); z-index: 60; }
        .draggable-el.active { border: 2px solid #2563eb; z-index: 100; }
        
        .shape-el { position: absolute; cursor: move; z-index: 5; }
        .shape-el:hover { outline: 1px dashed #f59e0b; }
        .shape-el.active { outline: 2px solid #f59e0b; z-index: 100; }

        .resizer {
            width: 12px; height: 12px; background: #2563eb; position: absolute; right: -6px; bottom: -6px;
            cursor: se-resize; border-radius: 50%; display: none; border: 2px solid white; z-index: 101;
        }
        .active .resizer { display: block; }
        
        .hidden-el { display: none !important; }
        
        .input-mini { @apply w-full border rounded px-1 py-0.5 text-xs focus:ring-1 focus:ring-blue-500 outline-none; }
        .label-mini { @apply text-[10px] font-bold text-gray-500 uppercase; }
        .hex-input { @apply w-16 border rounded px-1 py-0.5 text-[10px] font-mono text-gray-600 outline-none uppercase; }
    </style>
    <script>
        let currentScale = 1;
        const mmToPx = 3.7795275591; 

        window.onload = function() {
            updatePreview(); 
            autoFit();       
            
            initInteractiveElement('el-foto', 'foto', 'box');
            initInteractiveElement('el-nombre', 'nombre', 'text');
            initInteractiveElement('el-cedula', 'cedula', 'text');
            initInteractiveElement('el-cargo', 'cargo', 'text');
            initInteractiveElement('el-depto', 'depto', 'text');
            
            initInteractiveElement('el-shape-1', 's1', 'box');
            initInteractiveElement('el-shape-2', 's2', 'box');
            
            initInteractiveElement('el-text-1', 't1', 'text');
            initInteractiveElement('el-text-2', 't2', 'text');

            window.addEventListener('resize', autoFit);
        };

        function syncHex(picker, textId) {
            document.getElementById(textId).value = picker.value;
            updatePreview();
        }
        function syncColor(text, pickerId) {
            if(text.value.match(/^#[0-9A-F]{6}$/i)) {
                document.getElementById(pickerId).value = text.value;
                updatePreview();
            }
        }
        
        function toggleVisibility(id, elId) {
            const chk = document.getElementById(id + '_visible');
            const el = document.getElementById(elId);
            if(chk.checked) {
                el.classList.remove('hidden-el');
            } else {
                el.classList.add('hidden-el');
            }
        }

        function updatePreview() {
            const card = document.getElementById('preview-card');
            
            const w = document.getElementById('card_width').value;
            const h = document.getElementById('card_height').value;
            card.style.width = (w * mmToPx) + 'px';
            card.style.height = (h * mmToPx) + 'px';

            const bgColor = document.getElementById('bg_color').value;
            const bgImgUrl = document.getElementById('bg_image').value;
            card.style.backgroundColor = bgColor;
            
            if (bgImgUrl && bgImgUrl.trim() !== '') {
                if(!card.style.backgroundImage.includes('blob:') && !card.style.backgroundImage.includes('data:')) {
                     card.style.backgroundImage = 'url("' + bgImgUrl + '")';
                }
            } else if (!card.style.backgroundImage.includes('data:')) {
                card.style.backgroundImage = 'none';
            }

            // VISIBILIDAD
            toggleVisibility('foto', 'el-foto');
            toggleVisibility('nombre', 'el-nombre');
            toggleVisibility('cedula', 'el-cedula');
            toggleVisibility('cargo', 'el-cargo');
            toggleVisibility('depto', 'el-depto');
            toggleVisibility('s1', 'el-shape-1');
            toggleVisibility('s2', 'el-shape-2');
            toggleVisibility('t1', 'el-text-1');
            toggleVisibility('t2', 'el-text-2');

            // Foto y Ajustes
            updateElement('foto');
            const imgFoto = document.querySelector('#el-foto img');
            const zoom = document.getElementById('foto_zoom').value;
            const posX = document.getElementById('foto_pos_x').value;
            const posY = document.getElementById('foto_pos_y').value;
            if(imgFoto) {
                imgFoto.style.transform = `scale(${zoom})`;
                imgFoto.style.objectPosition = `${posX}% ${posY}%`;
            }

            updateElement('nombre');
            updateElement('cedula');
            updateElement('cargo');
            updateElement('depto');
            
            updateElement('s1', 'el-shape-1');
            updateElement('s2', 'el-shape-2');
            
            // Textos Custom
            updateElement('t1', 'el-text-1');
            document.getElementById('el-text-1').innerText = document.getElementById('t1_content').value;
            
            updateElement('t2', 'el-text-2');
            document.getElementById('el-text-2').innerText = document.getElementById('t2_content').value;
            
            autoFit(); 
        }

        function updateElement(key, customId = null) {
            const elId = customId ? customId : 'el-' + key;
            const el = document.getElementById(elId);
            if(!el) return;

            const top = document.getElementById(key + '_top').value;
            const left = document.getElementById(key + '_left').value;
            
            el.style.top = (top * mmToPx) + 'px';
            el.style.left = (left * mmToPx) + 'px';
            
            if(document.getElementById(key + '_width')) el.style.width = (document.getElementById(key + '_width').value * mmToPx) + 'px';
            if(document.getElementById(key + '_height')) el.style.height = (document.getElementById(key + '_height').value * mmToPx) + 'px';
            if(document.getElementById(key + '_radius')) el.style.borderRadius = (document.getElementById(key + '_radius').value * mmToPx) + 'px';
            
            if(document.getElementById(key + '_size')) {
                el.style.fontSize = (document.getElementById(key + '_size').value * 1.33) + 'px';
            }
            
            if(document.getElementById(key + '_color')) {
                const color = document.getElementById(key + '_color').value;
                if(el.classList.contains('shape-el')) el.style.backgroundColor = color;
                else el.style.color = color;
            }
            if(document.getElementById(key + '_align')) el.style.textAlign = document.getElementById(key + '_align').value;
        }

        function initInteractiveElement(elId, inputPrefix, type) {
            const el = document.getElementById(elId);
            if(!el) return;
            
            if (!el.querySelector('.resizer')) {
                const resizer = document.createElement('div');
                resizer.className = 'resizer';
                el.appendChild(resizer);
                resizer.addEventListener('mousedown', function(e) {
                    e.stopPropagation(); e.preventDefault();
                    initResize(e, el, inputPrefix, type);
                });
            }

            el.addEventListener('mousedown', function(e) {
                if (e.target.classList.contains('resizer')) return;
                e.preventDefault();
                initDrag(e, el, inputPrefix);
                document.querySelectorAll('.draggable-el, .shape-el').forEach(d => d.classList.remove('active'));
                el.classList.add('active');
            });
        }

        function initDrag(e, el, prefix) {
            let startX = e.clientX; let startY = e.clientY;
            let startLeft = parseFloat(el.style.left) || 0; let startTop = parseFloat(el.style.top) || 0;

            function onMouseMove(e) {
                const dx = (e.clientX - startX) / currentScale;
                const dy = (e.clientY - startY) / currentScale;
                let newLeft = startLeft + dx; let newTop = startTop + dy;
                
                el.style.left = newLeft + 'px'; el.style.top = newTop + 'px';
                
                document.getElementById(prefix + '_left').value = (newLeft / mmToPx).toFixed(2);
                document.getElementById(prefix + '_top').value = (newTop / mmToPx).toFixed(2);
            }
            function onMouseUp() {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            }
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        }

        function initResize(e, el, prefix, type) {
            let startX = e.clientX; let startY = e.clientY;
            let startW = parseFloat(el.style.width) || 0; let startH = parseFloat(el.style.height) || 0;
            let startSizePt = parseFloat(document.getElementById(prefix + '_size')?.value) || 10;

            function onMouseMove(e) {
                const dx = (e.clientX - startX) / currentScale;
                const dy = (e.clientY - startY) / currentScale;

                if (type === 'box') {
                    let newW = startW + dx; let newH = startH + dy;
                    if (newW > 5) { el.style.width = newW + 'px'; document.getElementById(prefix + '_width').value = (newW / mmToPx).toFixed(2); }
                    if (newH > 5) { el.style.height = newH + 'px'; document.getElementById(prefix + '_height').value = (newH / mmToPx).toFixed(2); }
                } else if (type === 'text') {
                    let sizeChange = dy / 5; 
                    let newSizePt = startSizePt + sizeChange;
                    if (newSizePt > 4) {
                        el.style.fontSize = (newSizePt * 1.33) + 'px';
                        document.getElementById(prefix + '_size').value = Math.round(newSizePt);
                    }
                }
            }
            function onMouseUp() {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
            }
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        }

        function autoFit() {
            const container = document.getElementById('preview-container');
            const card = document.getElementById('preview-card');
            const availW = container.clientWidth - 40; const availH = container.clientHeight - 40;
            const cardW = parseFloat(card.style.width) || 800; const cardH = parseFloat(card.style.height) || 500;
            currentScale = Math.min(availW / cardW, availH / cardH, 1.2); 
            card.style.transform = `scale(${currentScale})`;
        }

        function previewUploadedImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    const card = document.getElementById('preview-card');
                    card.style.backgroundImage = 'url("' + e.target.result + '")';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</head>
<body class="bg-gray-100 h-screen flex flex-col">

<nav class="bg-slate-900 text-white p-3 shadow-md flex justify-between items-center flex-shrink-0 z-20 relative">
    <div class="font-bold text-lg flex items-center">
        <i class="fas fa-palette mr-2 text-blue-400"></i> Diseñador Pro
        <?php if($edit_data): ?>
            <span class="ml-4 text-xs font-normal bg-blue-800 px-2 py-1 rounded text-blue-200 hidden sm:inline-block">
                <?php echo htmlspecialchars($edit_data['nombre_empresa']); ?>
            </span>
        <?php endif; ?>
    </div>
    <div class="flex gap-3">
        <a href="carnets_panel.php" class="text-sm hover:text-white text-gray-400 font-bold flex items-center"><i class="fas fa-arrow-left mr-1"></i> Panel</a>
    </div>
</nav>

<div class="flex flex-1 overflow-hidden">
    
    <!-- BARRA LATERAL (DISEÑOS) -->
    <div class="w-64 bg-white border-r border-gray-200 overflow-y-auto p-4 flex-shrink-0 z-10 hidden md:block">
        <a href="carnets_config.php" class="flex items-center justify-center w-full bg-slate-800 text-white border border-slate-700 rounded p-2 text-xs font-bold mb-4 hover:bg-black transition shadow-sm"><i class="fas fa-plus-circle mr-2"></i> Nuevo Diseño</a>
        <div class="space-y-2">
            <?php foreach($disenos as $d): $isActive = (isset($_GET['editar']) && $_GET['editar'] == $d['id']); ?>
            <div class="flex justify-between items-center p-2 rounded border group transition <?php echo $isActive ? 'border-blue-500 bg-blue-50 shadow-sm' : 'border-gray-100 hover:border-gray-300 hover:bg-gray-50'; ?>">
                <a href="?editar=<?php echo $d['id']; ?>" class="flex-1 truncate text-xs font-bold <?php echo $isActive ? 'text-blue-700':'text-gray-600'; ?>"><?php echo $d['nombre_empresa']; ?></a>
                <a href="?duplicar=<?php echo $d['id']; ?>" class="text-gray-300 hover:text-green-600 ml-2" title="Duplicar"><i class="fas fa-copy"></i></a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ÁREA CENTRAL (EDITOR) -->
    <form method="POST" enctype="multipart/form-data" class="flex-1 flex overflow-hidden">
        
        <!-- PANEL DE PROPIEDADES -->
        <div class="w-80 bg-white border-r border-gray-200 overflow-y-auto p-4 text-xs shadow-lg z-10">
            <input type="hidden" name="guardar_diseno" value="1">
            <input type="hidden" name="id_diseno" value="<?php echo $edit_data['id'] ?? ''; ?>">
            
            <div class="mb-4 sticky top-0 bg-white z-10 pb-2 border-b">
                <label class="label-mini text-blue-600">Nombre Diseño</label>
                <input type="text" name="nombre_empresa" value="<?php echo $edit_data['nombre_empresa'] ?? 'Nueva Empresa'; ?>" class="w-full border-2 border-blue-100 rounded p-2 font-bold text-sm outline-none" required>
            </div>

            <div class="space-y-4">
                
                <!-- BOTÓN ELIMINAR (SOLO SUPER USUARIO) -->
                <?php if ($rol_logueado === 'admin' && !empty($edit_data['id'])): ?>
                <div class="border rounded-lg p-3 bg-red-50 border-red-100">
                    <button type="submit" name="eliminar_diseno" value="1" onclick="return confirm('¿⚠️ ESTÁS SEGURO?\n\nEsta acción eliminará el diseño permanentemente.')" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 rounded shadow text-xs flex items-center justify-center gap-2">
                        <i class="fas fa-trash-alt"></i> Eliminar Diseño Actual
                    </button>
                    <input type="hidden" name="id_diseno_borrar" value="<?php echo $edit_data['id']; ?>">
                </div>
                <?php endif; ?>

                <!-- GENERAL -->
                <div class="border rounded-lg p-3 bg-gray-50">
                    <h4 class="font-bold border-b pb-1 mb-2 text-gray-700">General (mm)</h4>
                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <div><label class="label-mini">Ancho</label><input type="number" step="any" id="card_width" name="card_width" value="<?php echo $def['card_width']; ?>" class="input-mini" oninput="updatePreview()"></div>
                        <div><label class="label-mini">Alto</label><input type="number" step="any" id="card_height" name="card_height" value="<?php echo $def['card_height']; ?>" class="input-mini" oninput="updatePreview()"></div>
                    </div>
                    <div class="mb-2">
                        <label class="label-mini">Fondo</label>
                        <div class="flex gap-2">
                            <input type="color" id="bg_color" name="bg_color" value="<?php echo $def['bg_color']; ?>" class="h-6 w-8 cursor-pointer" oninput="syncHex(this, 'bg_hex')">
                            <input type="text" id="bg_hex" value="<?php echo $def['bg_color']; ?>" class="hex-input" oninput="syncColor(this, 'bg_color')">
                            <input type="file" name="bg_image_file" accept="image/*" class="w-full text-[9px]" onchange="previewUploadedImage(this)">
                        </div>
                        <input type="text" id="bg_image" name="bg_image" value="<?php echo $def['bg_image']; ?>" class="input-mini mt-1 text-gray-400" placeholder="URL Fondo..." oninput="updatePreview()">
                    </div>
                </div>

                <!-- FOTO -->
                <div class="border rounded-lg p-3 bg-gray-50">
                    <div class="flex justify-between items-center border-b pb-1 mb-2">
                        <h4 class="font-bold text-gray-700">Foto</h4>
                        <div class="flex items-center gap-1">
                            <input type="checkbox" name="foto_visible" id="foto_visible" value="1" <?php echo checkVis($def['foto']) ? 'checked' : ''; ?> onchange="toggleVisibility('foto', 'el-foto')">
                            <label for="foto_visible" class="text-[9px] uppercase cursor-pointer">Ver</label>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <div><label class="label-mini">X (mm)</label><input type="number" step="any" id="foto_left" name="foto_left" value="<?php echo $def['foto']['left']; ?>" class="input-mini" oninput="updatePreview()"></div>
                        <div><label class="label-mini">Y (mm)</label><input type="number" step="any" id="foto_top" name="foto_top" value="<?php echo $def['foto']['top']; ?>" class="input-mini" oninput="updatePreview()"></div>
                    </div>
                    <div class="grid grid-cols-3 gap-1">
                        <div><label class="label-mini">Ancho</label><input type="number" step="any" id="foto_width" name="foto_width" value="<?php echo $def['foto']['width']; ?>" class="input-mini" oninput="updatePreview()"></div>
                        <div><label class="label-mini">Alto</label><input type="number" step="any" id="foto_height" name="foto_height" value="<?php echo $def['foto']['height']; ?>" class="input-mini" oninput="updatePreview()"></div>
                        <div><label class="label-mini">Radio</label><input type="number" step="0.01" id="foto_radius" name="foto_radius" value="<?php echo $def['foto']['radius']; ?>" class="input-mini" oninput="updatePreview()"></div>
                    </div>
                    <div class="border-t pt-2 mt-2">
                        <label class="label-mini text-blue-600 block mb-1">Ajuste Interno</label>
                        <div class="grid grid-cols-3 gap-1">
                            <div><label class="label-mini">Zoom</label><input type="number" step="0.1" id="foto_zoom" name="foto_zoom" value="<?php echo $def['foto']['zoom']; ?>" class="input-mini" oninput="updatePreview()"></div>
                            <div><label class="label-mini">Pos X%</label><input type="number" step="any" id="foto_pos_x" name="foto_pos_x" value="<?php echo $def['foto']['pos_x']; ?>" class="input-mini" oninput="updatePreview()"></div>
                            <div><label class="label-mini">Pos Y%</label><input type="number" step="any" id="foto_pos_y" name="foto_pos_y" value="<?php echo $def['foto']['pos_y']; ?>" class="input-mini" oninput="updatePreview()"></div>
                        </div>
                    </div>
                </div>

                <!-- ELEMENTOS DECORATIVOS -->
                <div class="border rounded-lg p-3 bg-gray-50">
                    <h4 class="font-bold border-b pb-1 mb-2 text-gray-700">Cuadros (Shapes)</h4>
                    <?php for($i=1; $i<=2; $i++): $s = $def['shapes'][$i-1]; ?>
                    <div class="mb-2 pb-2 border-b last:border-0 border-gray-200">
                        <div class="flex justify-between items-center mb-1">
                            <label class="label-mini text-orange-600">Cuadro <?php echo $i; ?></label>
                            <div class="flex gap-1 items-center">
                                <input type="checkbox" name="s<?php echo $i; ?>_visible" id="s<?php echo $i; ?>_visible" value="1" <?php echo checkVis($s) ? 'checked' : ''; ?> onchange="toggleVisibility('s<?php echo $i; ?>', 'el-shape-<?php echo $i; ?>')">
                                <input type="color" id="s<?php echo $i; ?>_color" name="s<?php echo $i; ?>_color" value="<?php echo $s['color']; ?>" class="h-4 w-4" oninput="syncHex(this, 's<?php echo $i; ?>_hex')">
                                <input type="text" id="s<?php echo $i; ?>_hex" value="<?php echo $s['color']; ?>" class="hex-input" oninput="syncColor(this, 's<?php echo $i; ?>_color')">
                            </div>
                        </div>
                        <div class="flex gap-1 mb-1">
                            <input type="number" step="any" id="s<?php echo $i; ?>_left" name="s<?php echo $i; ?>_left" value="<?php echo $s['left']; ?>" class="input-mini bg-white" title="X">
                            <input type="number" step="any" id="s<?php echo $i; ?>_top" name="s<?php echo $i; ?>_top" value="<?php echo $s['top']; ?>" class="input-mini bg-white" title="Y">
                        </div>
                        <div class="flex gap-1">
                            <input type="number" step="any" id="s<?php echo $i; ?>_width" name="s<?php echo $i; ?>_width" value="<?php echo $s['width']; ?>" class="input-mini" placeholder="W" oninput="updatePreview()">
                            <input type="number" step="any" id="s<?php echo $i; ?>_height" name="s<?php echo $i; ?>_height" value="<?php echo $s['height']; ?>" class="input-mini" placeholder="H" oninput="updatePreview()">
                            <input type="number" step="0.01" id="s<?php echo $i; ?>_radius" name="s<?php echo $i; ?>_radius" value="<?php echo $s['radius']; ?>" class="input-mini" placeholder="R" oninput="updatePreview()">
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- TEXTOS PERSONALIZADOS (NUEVO) -->
                <div class="border rounded-lg p-3 bg-gray-50">
                    <h4 class="font-bold border-b pb-1 mb-2 text-gray-700">Textos Libres</h4>
                    <?php for($i=1; $i<=2; $i++): $t = $def['custom_texts'][$i-1]; ?>
                    <div class="mb-2 pb-2 border-b last:border-0 border-gray-200">
                        <div class="flex justify-between items-center mb-1">
                            <input type="text" id="t<?php echo $i; ?>_content" name="t<?php echo $i; ?>_content" value="<?php echo $t['content']; ?>" class="input-mini font-bold text-blue-600" oninput="updatePreview()" placeholder="Texto...">
                            <div class="flex gap-1 items-center">
                                <input type="checkbox" name="t<?php echo $i; ?>_visible" id="t<?php echo $i; ?>_visible" value="1" <?php echo checkVis($t) ? 'checked' : ''; ?> onchange="toggleVisibility('t<?php echo $i; ?>', 'el-text-<?php echo $i; ?>')">
                                <input type="color" id="t<?php echo $i; ?>_color" name="t<?php echo $i; ?>_color" value="<?php echo $t['color']; ?>" class="h-4 w-4" oninput="updatePreview()">
                            </div>
                        </div>
                        <div class="flex gap-1 mb-1">
                            <input type="number" step="any" id="t<?php echo $i; ?>_left" name="t<?php echo $i; ?>_left" value="<?php echo $t['left']; ?>" class="input-mini bg-white" title="X">
                            <input type="number" step="any" id="t<?php echo $i; ?>_top" name="t<?php echo $i; ?>_top" value="<?php echo $t['top']; ?>" class="input-mini bg-white" title="Y">
                            <input type="number" step="1" id="t<?php echo $i; ?>_size" name="t<?php echo $i; ?>_size" value="<?php echo $t['size']; ?>" class="input-mini" title="Size" oninput="updatePreview()">
                        </div>
                        <div class="flex gap-1">
                            <input type="number" step="any" id="t<?php echo $i; ?>_width" name="t<?php echo $i; ?>_width" value="<?php echo $t['width']; ?>" class="input-mini" placeholder="W" oninput="updatePreview()">
                            <select id="t<?php echo $i; ?>_align" name="t<?php echo $i; ?>_align" class="input-mini" onchange="updatePreview()">
                                <option value="left" <?php echo $t['align']=='left'?'selected':''; ?>>Izq</option>
                                <option value="center" <?php echo $t['align']=='center'?'selected':''; ?>>Cen</option>
                                <option value="right" <?php echo $t['align']=='right'?'selected':''; ?>>Der</option>
                            </select>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>

                <!-- TEXTOS BASE -->
                <div class="border rounded-lg p-3 bg-gray-50">
                    <h4 class="font-bold border-b pb-1 mb-2 text-gray-700">Datos Empleado</h4>
                    <?php 
                        $campos = [['id'=>'nombre','label'=>'Nombre'],['id'=>'cedula','label'=>'Cédula'],['id'=>'cargo','label'=>'Cargo'],['id'=>'depto','label'=>'Depto']];
                        foreach($campos as $c): $k = $c['id'];
                    ?>
                    <div class="mb-2 pb-1 border-b border-gray-200 last:border-0">
                        <div class="flex justify-between items-center">
                            <label class="font-bold text-xs text-blue-800"><?php echo $c['label']; ?></label>
                            <div class="flex gap-1 items-center">
                                <input type="checkbox" name="<?php echo $k; ?>_visible" id="<?php echo $k; ?>_visible" value="1" <?php echo checkVis($def[$k]) ? 'checked' : ''; ?> onchange="toggleVisibility('<?php echo $k; ?>', 'el-<?php echo $k; ?>')">
                                <input type="color" id="<?php echo $k; ?>_color" name="<?php echo $k; ?>_color" value="<?php echo $def[$k]['color']; ?>" class="h-4 w-4" oninput="syncHex(this, '<?php echo $k; ?>_hex')">
                                <input type="text" id="<?php echo $k; ?>_hex" value="<?php echo $def[$k]['color']; ?>" class="hex-input" oninput="syncColor(this, '<?php echo $k; ?>_color')">
                            </div>
                        </div>
                        <div class="flex gap-1 mb-1">
                            <input type="number" step="any" id="<?php echo $k; ?>_left" name="<?php echo $k; ?>_left" value="<?php echo $def[$k]['left']; ?>" class="input-mini bg-white" title="X">
                            <input type="number" step="any" id="<?php echo $k; ?>_top" name="<?php echo $k; ?>_top" value="<?php echo $def[$k]['top']; ?>" class="input-mini bg-white" title="Y">
                            <input type="number" step="1" id="<?php echo $k; ?>_size" name="<?php echo $k; ?>_size" value="<?php echo $def[$k]['size']; ?>" class="input-mini" title="Size" oninput="updatePreview()">
                        </div>
                        <div class="flex gap-1">
                            <input type="number" step="any" id="<?php echo $k; ?>_width" name="<?php echo $k; ?>_width" value="<?php echo $def[$k]['width']; ?>" class="input-mini" placeholder="Ancho" oninput="updatePreview()">
                            <select id="<?php echo $k; ?>_align" name="<?php echo $k; ?>_align" class="input-mini" onchange="updatePreview()">
                                <option value="left" <?php echo $def[$k]['align']=='left'?'selected':''; ?>>Izq</option>
                                <option value="center" <?php echo $def[$k]['align']=='center'?'selected':''; ?>>Cen</option>
                                <option value="right" <?php echo $def[$k]['align']=='right'?'selected':''; ?>>Der</option>
                            </select>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="mt-4 pt-4 sticky bottom-0 bg-white pb-4">
                <button type="submit" name="guardar_diseno" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg shadow-lg">
                    <i class="fas fa-save mr-2"></i> Guardar
                </button>
                <?php if($mensaje): ?><div class="text-green-600 font-bold text-center mt-2 text-xs"><?php echo $mensaje; ?></div><?php endif; ?>
            </div>
        </div>

        <!-- ZONA DE PREVISUALIZACIÓN -->
        <div id="preview-container" class="flex-1 bg-gray-200">
            <div id="preview-card">
                
                <!-- Decoraciones -->
                <div id="el-shape-1" class="shape-el bg-red-500"></div>
                <div id="el-shape-2" class="shape-el bg-black"></div>

                <div id="el-foto" class="draggable-el bg-gray-300 border border-gray-400 overflow-hidden flex items-center justify-center text-gray-400 text-[10px] z-20">
                    <img src="https://ui-avatars.com/api/?name=User&size=200" class="w-full h-full object-cover">
                </div>
                
                <div id="el-nombre" class="draggable-el font-black uppercase leading-tight whitespace-nowrap z-30" style="border: 1px dashed rgba(0,0,0,0.1);">NOMBRE APELLIDO</div>
                <div id="el-cedula" class="draggable-el font-bold font-mono z-30" style="border: 1px dashed rgba(0,0,0,0.1);">000-0000000-0</div>
                <div id="el-cargo" class="draggable-el font-bold uppercase z-30" style="border: 1px dashed rgba(0,0,0,0.1);">CARGO PUESTO</div>
                <div id="el-depto" class="draggable-el font-bold uppercase z-30" style="border: 1px dashed rgba(0,0,0,0.1);">DEPARTAMENTO</div>
                
                <!-- Textos Libres -->
                <div id="el-text-1" class="draggable-el font-bold z-30">TEXTO 1</div>
                <div id="el-text-2" class="draggable-el font-bold z-30">TEXTO 2</div>

            </div>
        </div>

    </form>
</div>

</body>
</html>