<?php
// get_all_routes.php

// === CORS: nur erlaubte Domains ===
$allowedOrigins = [
    'https://www.hundezonen.ch',
    'https://hundezonen.ch', // ohne www
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin'); // wichtig fürs Caching
}

// Preflight (OPTIONS) sofort beantworten
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit();
}

header('Content-Type: application/json');
require_once 'config.php';

// Función para normalizar nombres de países al alemán
function normalizeCountryName($address) {
    if (empty($address)) return $address;

    // Normalizar Suiza a Schweiz
    $address = preg_replace('/\b(Switzerland|Suiza|Svizzera)\b/i', 'Schweiz', $address);

    // Normalizar Austria a Österreich
    $address = preg_replace('/\b(Austria)\b/i', 'Österreich', $address);

    // Normalizar Alemania a Deutschland
    $address = preg_replace('/\b(Germany|Alemania|Germania)\b/i', 'Deutschland', $address);

    // Liechtenstein se mantiene igual

    return $address;
}

// Obtener la conexión a la base de datos
$conn = getDBConnection();

// Obtener los parámetros GET, si es necesario (por ejemplo, paginación)
$userId = isset($_GET['userId']) ? intval($_GET['userId']) : 0;
$loggedInUserId = isset($_GET['loggedInUserId']) ? intval($_GET['loggedInUserId']) : 0;

// Definir la URL base de tu backend
$baseURL = 'https://web.lweb.ch/'; // Asegúrate de que esta URL es correcta y termina con '/'

// Consulta para obtener todas las rutas con información del usuario
if ($userId > 0) {
    // Rutas específicas de un usuario
    // Si el usuario logueado es el mismo que el userId solicitado, mostrar todas sus rutas (incluso si es privado)
    // Si no, solo mostrar si el perfil es público (is_private = 0)
    if ($userId === $loggedInUserId) {
        // Usuario viendo sus propias rutas - mostrar todas
        $stmt = $conn->prepare("
            SELECT
                rutas.id,
                rutas.titulo,
                rutas.start_address,
                rutas.end_address,
                rutas.distance,
                rutas.route_coords,
                rutas.comentarios,
                rutas.features,
                rutas.created_at,
                rutas.user_id, -- Agregar user_id
                usuarios.username,
                usuarios.email,
                usuarios.avatar,
                rutas.imagen1,
                rutas.imagen2,
                rutas.imagen3,
                rutas.imagen4,
                rutas.imagen5,
                rutas.imagen6,
                rutas.imagen7,
                rutas.imagen8,
                rutas.video, -- Añadir la columna video
                rutas.video_thumbnail, -- Añadir la columna video_thumbnail
                rutas.video_visitas -- Añadir contador de visitas
            FROM rutas
            JOIN usuarios ON rutas.user_id = usuarios.id
            WHERE rutas.user_id = ?
            ORDER BY rutas.created_at DESC
        ");
        $stmt->bind_param("i", $userId);
    } else {
        // Otro usuario viendo rutas - solo mostrar si el perfil es público
        $stmt = $conn->prepare("
            SELECT
                rutas.id,
                rutas.titulo,
                rutas.start_address,
                rutas.end_address,
                rutas.distance,
                rutas.route_coords,
                rutas.comentarios,
                rutas.features,
                rutas.created_at,
                rutas.user_id, -- Agregar user_id
                usuarios.username,
                usuarios.email,
                usuarios.avatar,
                rutas.imagen1,
                rutas.imagen2,
                rutas.imagen3,
                rutas.imagen4,
                rutas.imagen5,
                rutas.imagen6,
                rutas.imagen7,
                rutas.imagen8,
                rutas.video, -- Añadir la columna video
                rutas.video_thumbnail, -- Añadir la columna video_thumbnail
                rutas.video_visitas -- Añadir contador de visitas
            FROM rutas
            JOIN usuarios ON rutas.user_id = usuarios.id
            WHERE rutas.user_id = ? AND usuarios.is_private = 0
            ORDER BY rutas.created_at DESC
        ");
        $stmt->bind_param("i", $userId);
    }
} else {
    // Obtener todas las rutas de todos los usuarios
    // FILTRAR USUARIOS PRIVADOS: WHERE usuarios.is_private = 0
    $stmt = $conn->prepare("
        SELECT
            rutas.id,
            rutas.titulo,
            rutas.start_address,
            rutas.end_address,
            rutas.distance,
            rutas.route_coords,
            rutas.comentarios,
            rutas.features,
            rutas.created_at,
            rutas.user_id, -- Agregar user_id
            usuarios.username,
            usuarios.email,
            usuarios.avatar,
            rutas.imagen1,
            rutas.imagen2,
            rutas.imagen3,
            rutas.imagen4,
            rutas.imagen5,
            rutas.imagen6,
            rutas.imagen7,
            rutas.imagen8,
            rutas.video, -- Añadir la columna video
            rutas.video_thumbnail, -- Añadir la columna video_thumbnail
            rutas.video_visitas -- Añadir contador de visitas
        FROM rutas
        JOIN usuarios ON rutas.user_id = usuarios.id
        WHERE usuarios.is_private = 0
        ORDER BY rutas.created_at DESC
    ");
}

if ($stmt === false) {
    echo json_encode(["status" => "error", "message" => "Error en la preparación de la consulta: " . $conn->error]);
    exit();
}

$stmt->execute();
$result = $stmt->get_result();

$rutas = [];
while ($row = $result->fetch_assoc()) {
    // Normalizar nombres de países en las direcciones
    $row['start_address'] = normalizeCountryName($row['start_address']);
    $row['end_address'] = normalizeCountryName($row['end_address']);

    // Decodificar JSON de coordenadas y features
    $decoded_route_coords = json_decode(stripslashes($row['route_coords']), true);
    $decoded_features = json_decode($row['features'], true);

    // Asignar arreglos vacíos si la decodificación falla
    $row['route_coords'] = is_array($decoded_route_coords) ? $decoded_route_coords : [];
    $row['features'] = is_array($decoded_features) ? $decoded_features : [];

    // La columna "titulo" ya se obtiene y se conservará en $row['titulo']

    // Combinar imagen1 a imagen8 en un arreglo de imágenes con URLs absolutas
    $row['images'] = [];
    for ($i = 1; $i <= 8; $i++) {
        if (!empty($row["imagen$i"])) {
            $imagePath = ltrim($row["imagen$i"], './');
            $fullImageURL = $baseURL . $imagePath;
            $row['images'][] = $fullImageURL;
        }
    }

    // Manejar la URL del video
    if (!empty($row['video'])) {
        $videoPath = ltrim($row['video'], './');
        $fullVideoURL = $baseURL . $videoPath;
        $row['video'] = $fullVideoURL;

        // Agregar thumbnail del video (usar el video_thumbnail real si existe, sino la primera imagen)
        if (!empty($row['video_thumbnail'])) {
            $thumbnailPath = ltrim($row['video_thumbnail'], './');
            $fullThumbnailURL = $baseURL . $thumbnailPath;
            $row['video_thumbnail'] = $fullThumbnailURL;
        } else {
            // Fallback: usar la primera imagen como preview si no hay thumbnail
            $row['video_thumbnail'] = !empty($row['images']) ? $row['images'][0] : null;
        }
    } else {
        $row['video'] = null;
        $row['video_thumbnail'] = null;
    }

    // Opcional: Eliminar las propiedades individuales de imagen si solo quieres el arreglo
    unset($row['imagen1'], $row['imagen2'], $row['imagen3'], $row['imagen4'], $row['imagen5'], $row['imagen6'], $row['imagen7'], $row['imagen8']);

    $rutas[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode(["status" => "success", "rutas" => $rutas]);
?>