<?php
// layout_menu.php - MENÚ DE NAVEGACIÓN (CORREGIDO Y ACTUALIZADO)

// 1. GARANTIZAR QUE TENEMOS EL ROL (Solución al error de menú oculto)
if (session_status() === PHP_SESSION_NONE) session_start();
$rol_logueado = $_SESSION['rol'] ?? 'guest'; 

// Definición de Menú para Topbar
$nav_structure = [
    'Dashboard' => [
        'icon' => 'fas fa-chart-line',
        'url' => 'index.php',
        'roles' => ['admin', 'rrhh']
    ],
    'Gestión' => [
        'icon' => 'fas fa-users',
        'roles' => ['admin', 'rrhh'],
        'subitems' => [
            ['title' => 'Empleados', 'url' => 'perfil_empleado.php', 'icon' => 'fas fa-user-tie', 'roles' => ['admin', 'rrhh']],
            ['title' => 'Calendario', 'url' => 'feriados.php', 'icon' => 'fas fa-calendar-alt', 'roles' => ['admin', 'rrhh']],
            // AGREGO LA NUEVA HERRAMIENTA AQUÍ:
            ['title' => 'Fusión de Usuarios', 'url' => 'fusionar_usuarios.php', 'icon' => 'fas fa-random', 'roles' => ['admin']]
        ]
    ],
    'Asistencia' => [
        'icon' => 'fas fa-clock',
        'roles' => ['admin', 'rrhh'],
        'subitems' => [
            ['title' => 'Cargar Datos', 'url' => 'cargar_datos.php', 'icon' => 'fas fa-upload', 'roles' => ['admin', 'rrhh']],
            ['title' => 'Ausencias', 'url' => 'ausencias_prolongadas.php', 'icon' => 'fas fa-user-clock', 'roles' => ['admin', 'rrhh']]
        ]
    ],
    'Análisis' => [
        'icon' => 'fas fa-chart-pie',
        'roles' => ['admin', 'rrhh'],
        'subitems' => [
            ['title' => 'Analítica', 'url' => 'analisis_asistencia.php', 'icon' => 'fas fa-chart-bar', 'roles' => ['admin', 'rrhh']],
            ['title' => 'Historial', 'url' => 'historial.php', 'icon' => 'fas fa-history', 'roles' => ['admin', 'rrhh']]
        ]
    ],
    'Configuración' => [
        'icon' => 'fas fa-cogs',
        'roles' => ['admin'],
        'subitems' => [
            ['title' => 'Usuarios Admin', 'url' => 'admin_usuarios.php', 'icon' => 'fas fa-user-shield', 'roles' => ['admin']]
        ]
    ]
];

// Obtener nombre actual para mostrar
$nombre_mostrar = $_SESSION['usuario'] ?? 'Usuario';
?>

<nav class="bg-slate-900 text-white shadow-lg sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4">
        <div class="flex justify-between h-16">
            
            <div class="flex items-center gap-3">
                <div class="bg-blue-600 w-8 h-8 rounded-lg flex items-center justify-center shadow-lg shadow-blue-500/50">
                    <i class="fas fa-fingerprint text-white"></i>
                </div>
                <span class="font-bold text-lg tracking-tight">Control<span class="text-blue-400">Asistencia</span></span>
            </div>

            <div class="hidden md:flex space-x-1 items-center">
                <?php foreach($nav_structure as $label => $data): 
                    // Filtro de Seguridad
                    if($rol_logueado !== 'admin' && !in_array($rol_logueado, $data['roles'])) continue;
                ?>
                    
                    <?php if(isset($data['subitems'])): ?>
                        <div class="relative group">
                            <button class="px-3 py-2 rounded-md text-sm font-medium hover:bg-slate-800 transition flex items-center gap-2 group-hover:text-blue-400">
                                <i class="<?php echo $data['icon']; ?>"></i> 
                                <?php echo $label; ?>
                                <i class="fas fa-chevron-down text-[10px] opacity-50"></i>
                            </button>
                            <div class="absolute left-0 mt-0 w-48 bg-white rounded-md shadow-xl py-1 hidden group-hover:block border border-gray-100 transform origin-top-left transition">
                                <?php foreach($data['subitems'] as $sub): 
                                     if($rol_logueado !== 'admin' && !in_array($rol_logueado, $sub['roles'])) continue;
                                ?>
                                    <a href="<?php echo $sub['url']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-600">
                                        <i class="<?php echo $sub['icon']; ?> w-5 text-center mr-2 opacity-70"></i> <?php echo $sub['title']; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?php echo $data['url']; ?>" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-slate-800 transition flex items-center gap-2 hover:text-blue-400">
                            <i class="<?php echo $data['icon']; ?>"></i> <?php echo $label; ?>
                        </a>
                    <?php endif; ?>

                <?php endforeach; ?>
            </div>

            <div class="flex items-center gap-4">
                <div class="text-right hidden sm:block">
                    <div class="text-xs text-gray-400 uppercase font-bold"><?php echo htmlspecialchars($rol_logueado); ?></div>
                    <div class="text-sm font-bold"><?php echo htmlspecialchars($nombre_mostrar); ?></div>
                </div>
                <a href="logout.php" class="bg-red-600 hover:bg-red-700 text-white p-2 rounded-lg transition shadow-lg shadow-red-600/30" title="Cerrar Sesión">
                    <i class="fas fa-power-off"></i>
                </a>
                
                <button onclick="document.getElementById('mobile-menu').classList.toggle('hidden')" class="md:hidden text-gray-300 hover:text-white p-2">
                    <i class="fas fa-bars text-xl"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="mobile-menu" class="hidden md:hidden bg-slate-800 border-t border-slate-700">
        <div class="px-2 pt-2 pb-3 space-y-1">
            <?php foreach($nav_structure as $label => $data): 
                 if($rol_logueado !== 'admin' && !in_array($rol_logueado, $data['roles'])) continue;
                 
                 if(isset($data['subitems'])): ?>
                    <div class="text-xs font-bold text-slate-500 uppercase px-3 pt-2"><?php echo $label; ?></div>
                    <?php foreach($data['subitems'] as $sub):
                        if($rol_logueado !== 'admin' && !in_array($rol_logueado, $sub['roles'])) continue;
                    ?>
                        <a href="<?php echo $sub['url']; ?>" class="block px-3 py-2 rounded-md text-base font-medium text-slate-300 hover:text-white hover:bg-slate-700">
                            <i class="<?php echo $sub['icon']; ?> mr-2"></i> <?php echo $sub['title']; ?>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <a href="<?php echo $data['url']; ?>" class="block px-3 py-2 rounded-md text-base font-medium text-white bg-slate-900">
                        <i class="<?php echo $data['icon']; ?> mr-2"></i> <?php echo $label; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</nav>