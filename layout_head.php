<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($sys_nombre ?? 'CRT-RRHH'); ?> | Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Estilos globales */
        body { background-color: #f3f4f6; }
        
        /* Ajuste para impresión */
        @media print {
            .no-print { display: none !important; }
            nav { display: none !important; }
        }
        
        /* Scrollbar personalizada */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="font-sans min-h-screen flex flex-col">

<?php include 'layout_menu.php'; ?>

<!-- CONTENIDO PRINCIPAL -->
<main class="flex-grow p-4 lg:p-6 w-full max-w-[1600px] mx-auto">