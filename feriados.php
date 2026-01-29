<?php
// feriados.php - GESTIÓN DE FERIADOS CON IA (OPENAI)
require 'auth.php'; 
verificarPermiso(['admin', 'rrhh']);

// --- CONFIGURACIÓN DE BASE DE DATOS Y TABLAS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS feriados (fecha DATE PRIMARY KEY, descripcion VARCHAR(255))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dias_sin_sistema (fecha DATE PRIMARY KEY, motivo VARCHAR(255))");
} catch (Exception $e) {}

$mensaje = '';
$tipo_msg = '';
$resultados_ia = []; // Para almacenar temporalmente la respuesta de la IA

// --------------------------------------------------------------------------
// 1. GUARDAR FERIADOS DESDE LA IA (MASIVO)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_seleccion_ia'])) {
    if (!empty($_POST['feriados_seleccionados'])) {
        $count = 0;
        foreach ($_POST['feriados_seleccionados'] as $json_feriado) {
            $f = json_decode(htmlspecialchars_decode($json_feriado), true);
            if ($f) {
                $stmt = $pdo->prepare("INSERT INTO feriados (fecha, descripcion) VALUES (?, ?) ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)");
                $stmt->execute([$f['fecha'], $f['descripcion']]);
                $count++;
            }
        }
        $mensaje = "✅ Se guardaron <b>$count</b> feriados correctamente.";
        $tipo_msg = "success";
    }
}

// --------------------------------------------------------------------------
// 2. CONSULTAR A LA IA (OPENAI API)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consultar_ia'])) {
    $anio_consulta = $_POST['anio_ia'];
    $api_key = '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'; // <--- COLOCA TU API KEY AQUÍ

    if ($api_key === 'TU_API_KEY_AQUI') {
        $mensaje = "⚠ Falta configurar la API Key de OpenAI en el código.";
        $tipo_msg = "error";
    } else {
        // Prompt optimizado para Ley 139-97 RD
        $prompt = "Eres un experto en legislación laboral de República Dominicana. 
        Genera un JSON con los días feriados NO LABORABLES para el año $anio_consulta. 
        IMPORTANTE: Aplica las reglas de la Ley 139-97 sobre el traslado de fechas (si cae martes/miércoles se mueve, etc.) tal como lo hace el Ministerio de Trabajo.
        Devuelve la fecha REAL en que se toma el descanso.
        Formato de respuesta JSON estricto:
        { \"feriados\": [ { \"fecha\": \"YYYY-MM-DD\", \"descripcion\": \"Nombre del feriado\" } ] }";

        $data = [
            "model" => "gpt-3.5-turbo-0125", // Modelo económico y rápido compatible con JSON mode
            "messages" => [
                ["role" => "system", "content" => "Eres un asistente útil que responde solo en JSON válido."],
                ["role" => "user", "content" => $prompt]
            ],
            "response_format" => ["type" => "json_object"]
        ];

        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer $api_key"
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $json_response = json_decode($response, true);
        
        if (isset($json_response['choices'][0]['message']['content'])) {
            $content = json_decode($json_response['choices'][0]['message']['content'], true);
            if (isset($content['feriados'])) {
                $resultados_ia = $content['feriados'];
                $mensaje = "🤖 La IA ha calculado los feriados para $anio_consulta. Revisa y guarda.";
                $tipo_msg = "success";
            }
        } else {
            $mensaje = "❌ Error al consultar OpenAI. Verifica tu API Key o saldo.";
            $tipo_msg = "error";
        }
    }
}

// --------------------------------------------------------------------------
// 3. GUARDADO MANUAL (LEGACY)
// --------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_feriado_manual'])) {
        $pdo->prepare("INSERT INTO feriados (fecha, descripcion) VALUES (?, ?) ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion)")->execute([$_POST['fecha'], $_POST['descripcion']]);
        $mensaje = "✅ Feriado manual guardado."; $tipo_msg = "success";
    }
    if (isset($_POST['guardar_fallo'])) {
        $pdo->prepare("INSERT INTO dias_sin_sistema (fecha, motivo) VALUES (?, ?) ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)")->execute([$_POST['fecha_fallo'], $_POST['motivo_fallo']]);
        $mensaje = "✅ Fallo registrado."; $tipo_msg = "success";
    }
}

// --- OBTENER DATOS EXISTENTES ---
$anio_filtro = $_GET['anio'] ?? date('Y');
$feriados_db = $pdo->query("SELECT * FROM feriados WHERE YEAR(fecha) = $anio_filtro ORDER BY fecha")->fetchAll();
$fallos_db = $pdo->query("SELECT * FROM dias_sin_sistema ORDER BY fecha DESC LIMIT 20")->fetchAll();

require 'layout_head.php';
?>

<div class="max-w-7xl mx-auto pb-10">
    <h1 class="text-2xl font-bold text-gray-800 mb-6"><i class="fas fa-calendar-alt text-blue-600 mr-2"></i>Gestión de Calendario Laboral</h1>

    <?php if($mensaje): ?>
        <div class="mb-6 p-4 rounded-lg border shadow-sm <?php echo $tipo_msg=='success'?'bg-green-100 border-green-400 text-green-800':'bg-red-100 border-red-400 text-red-800'; ?>">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="bg-gradient-to-r from-slate-800 to-slate-900 rounded-xl shadow-lg p-6 mb-8 text-white relative overflow-hidden">
        <div class="absolute top-0 right-0 opacity-10 text-9xl transform translate-x-10 -translate-y-10"><i class="fas fa-robot"></i></div>
        <div class="relative z-10">
            <h2 class="text-xl font-bold mb-2 flex items-center"><i class="fas fa-brain mr-2 text-purple-400"></i> Consultar Ministerio de Trabajo (IA)</h2>
            <p class="text-slate-300 text-sm mb-4">La Inteligencia Artificial revisará las fechas y aplicará los cambios de días según la Ley 139-97.</p>
            
            <form method="POST" class="flex gap-3 items-center">
                <select name="anio_ia" class="bg-slate-700 border border-slate-600 text-white rounded px-4 py-2 font-bold focus:ring-2 focus:ring-purple-500 outline-none">
                    <?php 
                    $y = date('Y');
                    for($i=$y; $i<=$y+3; $i++) echo "<option value='$i'>Año $i</option>"; 
                    ?>
                </select>
                <button type="submit" name="consultar_ia" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded font-bold shadow-lg transition transform hover:scale-105 flex items-center">
                    <i class="fas fa-search mr-2"></i> Consultar Fechas
                </button>
            </form>
        </div>
    </div>

    <?php if (!empty($resultados_ia)): ?>
    <div class="bg-white rounded-xl shadow-lg border border-purple-200 mb-8 p-6 animate-fade-in-down">
        <h3 class="font-bold text-gray-800 text-lg mb-4 flex items-center"><i class="fas fa-check-double text-green-500 mr-2"></i> Resultados Encontrados</h3>
        <form method="POST">
            <input type="hidden" name="guardar_seleccion_ia" value="1">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                <?php foreach($resultados_ia as $index => $r): ?>
                <label class="flex items-start gap-3 p-3 border rounded-lg hover:bg-purple-50 cursor-pointer transition">
                    <input type="checkbox" name="feriados_seleccionados[]" value="<?php echo htmlspecialchars(json_encode($r)); ?>" checked class="mt-1 w-4 h-4 text-purple-600 rounded focus:ring-purple-500">
                    <div>
                        <div class="font-bold text-gray-800 text-sm"><?php echo date('d/m/Y', strtotime($r['fecha'])); ?></div>
                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($r['descripcion']); ?></div>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="text-right">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-6 rounded shadow">
                    <i class="fas fa-save mr-1"></i> Confirmar y Guardar en Base de Datos
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        
        <div class="bg-white p-6 rounded-xl shadow border border-gray-200">
            <div class="flex justify-between items-center mb-4">
                <h2 class="font-bold text-lg text-gray-700"><i class="fas fa-list-ul mr-2"></i> Feriados Registrados (<?php echo $anio_filtro; ?>)</h2>
                <form method="GET">
                    <select name="anio" onchange="this.form.submit()" class="border rounded text-xs p-1">
                        <?php for($i=date('Y')-2; $i<=date('Y')+3; $i++) echo "<option value='$i' ".($i==$anio_filtro?'selected':'').">$i</option>"; ?>
                    </select>
                </form>
            </div>

            <form method="POST" class="flex gap-2 mb-4 bg-gray-50 p-3 rounded border">
                <input type="date" name="fecha" required class="border rounded p-2 text-sm w-1/3">
                <input type="text" name="descripcion" placeholder="Nombre del Feriado" required class="border rounded p-2 text-sm flex-grow">
                <button type="submit" name="guardar_feriado_manual" class="bg-slate-700 hover:bg-slate-800 text-white px-3 py-2 rounded text-sm"><i class="fas fa-plus"></i></button>
            </form>

            <div class="overflow-y-auto max-h-[400px]">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-100 text-xs text-gray-500 uppercase">
                        <tr><th class="px-4 py-2">Fecha</th><th class="px-4 py-2">Motivo</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if(empty($feriados_db)): ?>
                            <tr><td colspan="2" class="p-4 text-center text-gray-400">Sin feriados registrados este año.</td></tr>
                        <?php else: foreach($feriados_db as $f): 
                            $es_pasado = strtotime($f['fecha']) < time();
                            $clase = $es_pasado ? 'text-gray-400' : 'text-gray-800 font-bold';
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 <?php echo $clase; ?>"><?php echo date('d/m/Y', strtotime($f['fecha'])); ?></td>
                            <td class="px-4 py-3 <?php echo $clase; ?>"><?php echo htmlspecialchars($f['descripcion']); ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-white p-6 rounded-xl shadow border border-red-200">
            <h2 class="font-bold text-lg text-red-800 mb-4"><i class="fas fa-server mr-2"></i> Registro Fallos Sistema</h2>
            <form method="POST" class="flex gap-2 mb-4">
                <input type="date" name="fecha_fallo" required class="border rounded p-2 text-sm">
                <input type="text" name="motivo_fallo" placeholder="Motivo del fallo" required class="border rounded p-2 text-sm flex-grow">
                <button type="submit" name="guardar_fallo" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded text-sm">Reportar</button>
            </form>
            <div class="overflow-y-auto max-h-[400px]">
                <table class="w-full text-sm">
                    <thead class="bg-red-50 text-red-800 text-xs uppercase">
                        <tr><th class="px-4 py-2 text-left">Fecha</th><th class="px-4 py-2 text-left">Incidente</th></tr>
                    </thead>
                    <tbody class="divide-y divide-red-100">
                        <?php foreach($fallos_db as $f): ?>
                        <tr>
                            <td class="px-4 py-2 font-mono text-red-600"><?php echo $f['fecha']; ?></td>
                            <td class="px-4 py-2 text-gray-600"><?php echo htmlspecialchars($f['motivo']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require 'layout_footer.php'; ?>