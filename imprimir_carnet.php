<?php
// imprimir_carnet.php - VISTA INDIVIDUAL FINAL (SOLUCIÓN HOJA BLANCA)
// Recibe las rutas de las imágenes y las dimensiones

$front = isset($_GET['front']) ? $_GET['front'] : '';
$back  = isset($_GET['back']) ? $_GET['back'] : '';
// Dimensiones por defecto (formato tarjeta CR80)
$w     = isset($_GET['w']) ? $_GET['w'] : '53.98'; 
$h     = isset($_GET['h']) ? $_GET['h'] : '85.60';

if (!$front && !$back) die("No hay imágenes para imprimir.");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Imprimir Carnet</title>
    <style>
        /* --- CONFIGURACIÓN DE PÁGINA IMPRESORA (Márgenes 0 Absolutos) --- */
        @page {
            size: <?php echo $w; ?>mm <?php echo $h; ?>mm;
            margin: 0 !important;
            padding: 0 !important;
        }

        /* --- ESTILOS GLOBALES --- */
        html, body {
            width: 100%;
            height: 100%;
            margin: 0 !important;
            padding: 0 !important;
            background-color: #f0f0f0; 
            font-family: sans-serif;
            -webkit-print-color-adjust: exact; 
            print-color-adjust: exact;
            overflow: hidden; 
        }

        /* --- CONTENEDOR DE LA IMAGEN --- */
        .page-container {
            width: <?php echo $w; ?>mm;
            height: <?php echo $h; ?>mm;
            
            /* CENTRADO PARA PANTALLA (Modo Visualización) */
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            
            display: none; /* Oculto por defecto */
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.5); 
            z-index: 10;
        }

        /* Clase para activar la vista actual */
        .page-container.active {
            display: block;
        }

        /* --- IMAGEN --- */
        img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: fill; /* Forzar llenado exacto */
            border: none;
            margin: 0;
            padding: 0;
        }

        /* --- BARRA DE CONTROL FLOTANTE (UI) --- */
        .control-bar {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.85);
            padding: 10px 20px;
            border-radius: 50px;
            display: flex;
            gap: 15px;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            backdrop-filter: blur(5px);
        }

        .btn {
            background: white;
            border: none;
            padding: 10px 20px;
            border-radius: 25px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
            transition: all 0.2s;
            color: #333;
            outline: none;
            border: 2px solid transparent;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(255,255,255,0.2);
        }

        .btn.active-tab {
            background: #007bff;
            color: white;
            border-color: #0056b3;
        }
        
        .btn-print {
            background: #28a745;
            color: white;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-print:hover {
            background: #218838;
        }

        /* --- REGLAS CRÍTICAS DE IMPRESIÓN --- */
        @media print {
            /* Ocultar interfaz */
            .control-bar, .no-print {
                display: none !important;
            }

            /* Resetear html/body */
            html, body {
                background-color: white !important;
                height: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }

            /* Configuración de la imagen para IMPRESIÓN */
            .page-container.active {
                display: block !important;
                
                /* ANCLAJE ABSOLUTO 0,0 */
                position: absolute !important;
                top: 0 !important;
                left: 0 !important;
                
                /* IMPORTANTE: Resetear el transform del centrado de pantalla */
                transform: none !important; 
                
                width: <?php echo $w; ?>mm !important;
                height: <?php echo $h; ?>mm !important;
                
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }
            
            /* Asegurar que la imagen llene el contenedor */
            img {
                width: 100% !important;
                height: 100% !important;
            }
            
            /* Ocultar cualquier contenedor inactivo explícitamente */
            .page-container:not(.active) {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <!-- FRENTE (Sin wrapper padre que lo oculte) -->
    <?php if ($front): ?>
        <div id="view-front" class="page-container active">
            <img src="<?php echo htmlspecialchars($front); ?>" onload="checkLoad()" alt="Frente">
        </div>
    <?php endif; ?>

    <!-- REVERSO (Sin wrapper padre que lo oculte) -->
    <?php if ($back): ?>
        <div id="view-back" class="page-container">
            <img src="<?php echo htmlspecialchars($back); ?>" onload="checkLoad()" alt="Reverso">
        </div>
    <?php endif; ?>

    <!-- BARRA DE CONTROL (Solo Pantalla) -->
    <div class="control-bar no-print">
        <?php if ($front): ?>
            <button class="btn active-tab" id="btn-front" onclick="switchView('front')">Ver Frente</button>
        <?php endif; ?>
        
        <?php if ($back): ?>
            <button class="btn" id="btn-back" onclick="switchView('back')">Ver Reverso</button>
        <?php endif; ?>

        <div style="width: 1px; background: rgba(255,255,255,0.3); margin: 0 5px;"></div>
        
        <button class="btn btn-print" onclick="window.print()">
            <span>🖨️</span> Imprimir
        </button>
    </div>

    <script>
        var totalImages = <?php echo ($front && $back) ? 2 : 1; ?>;
        var loadedImages = 0;
        var autoPrintDone = false;

        function checkLoad() {
            loadedImages++;
            // Auto-imprimir SOLO la primera vez
            if (loadedImages >= totalImages && !autoPrintDone) {
                autoPrintDone = true;
                setTimeout(function() {
                    window.print();
                }, 800);
            }
        }

        function switchView(side) {
            // Ocultar todo
            var containers = document.querySelectorAll('.page-container');
            var btns = document.querySelectorAll('.btn');
            
            containers.forEach(el => el.classList.remove('active'));
            btns.forEach(el => {
                if(!el.classList.contains('btn-print')) el.classList.remove('active-tab');
            });

            // Mostrar seleccionado
            var target = document.getElementById('view-' + side);
            var btnTarget = document.getElementById('btn-' + side);
            
            if (target) target.classList.add('active');
            if (btnTarget) btnTarget.classList.add('active-tab');
        }
        
        // Fallback de seguridad
        window.onload = function() {
            if (document.images.length > 0 && !autoPrintDone) {
                 setTimeout(function() {
                    // Verificación extra para asegurar que no se lance doble
                    if (!autoPrintDone) {
                        autoPrintDone = true;
                        window.print();
                    }
                }, 1500);
            }
        };
    </script>
</body>
</html>