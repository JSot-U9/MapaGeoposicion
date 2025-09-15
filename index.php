<?php
// index.php - Multiusuario con rutas/historial + SSE en tiempo real
// Versión mejorada: acepta device_name, accuracy, provider, ts (ms), steps, steps_total, accel
// GET?json=1 devuelve todos los usuarios con history enriquecido
// GET?history=1&user=...&from=...&to=... devuelve history filtrado por rango
// POST recibe los campos y los guarda en ubicaciones/<user_id>.json

$baseDir = __DIR__;
$dir = $baseDir . '/ubicaciones';
$logPath  = $baseDir . '/debug.log';
define('MAX_POINTS', 5000); // máximo puntos por usuario en history

if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

function log_msg($msg) {
    global $logPath;
    @file_put_contents($logPath, "[" . date("Y-m-d H:i:s") . "] " . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// leer JSON seguro
function read_json_file($file) {
    if (!file_exists($file)) return null;
    $txt = @file_get_contents($file);
    if ($txt === false) return null;
    $arr = @json_decode($txt, true);
    return is_array($arr) ? $arr : null;
}

// Ajusta/normaliza timestamp: si es numérico (ms) -> devuelve "Y-m-d H:i:s", si es string y ya con formato devuelve tal cual
function normalize_ts($raw) {
    if ($raw === null) return null;
    if ($raw === '') return null;
    if (is_numeric($raw)) {
        $n = (int)$raw;
        if ($n > 100000000000) { // probable ms
            $seconds = (int)floor($n / 1000);
        } else {
            $seconds = $n;
        }
        return date("Y-m-d H:i:s", $seconds);
    }
    $s = trim($raw);
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= ' 00:00:00';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    if ($dt) return $s;
    return null;
}

// function que devuelve todas las ubicaciones en el formato que usa el front
function collect_all_locations($dir) {
    $ubicaciones = [];
    foreach (glob($dir . "/*.json") as $file) {
        $json = read_json_file($file);
        if ($json && isset($json['latitud']) && isset($json['longitud'])) {
            $hist = [];
            if (isset($json['history']) && is_array($json['history'])) {
                foreach ($json['history'] as $p) {
                    if (isset($p['lat']) && isset($p['lon'])) {
                        $hist[] = [
                            'lat' => floatval($p['lat']),
                            'lon' => floatval($p['lon']),
                            'ts'  => $p['ts'] ?? null,
                            'accuracy' => isset($p['accuracy']) ? $p['accuracy'] : null,
                            'provider' => isset($p['provider']) ? $p['provider'] : null,
                            'steps' => isset($p['steps']) ? (int)$p['steps'] : 0,
                            'steps_total' => isset($p['steps_total']) ? (int)$p['steps_total'] : null,
                            'accel' => isset($p['accel']) ? floatval($p['accel']) : null,
                            'device_name' => isset($p['device_name']) ? $p['device_name'] : null
                        ];
                    }
                }
            }
            $last = end($hist);
            $ubicaciones[] = [
                'user_id' => $json['user_id'] ?? basename($file, '.json'),
                'latitud' => floatval($json['latitud']),
                'longitud' => floatval($json['longitud']),
                'fecha'   => $json['fecha'] ?? null,
                'accuracy' => $json['accuracy'] ?? ($last['accuracy'] ?? null),
                'steps_total' => isset($json['steps_total']) ? (int)$json['steps_total'] : ($last['steps_total'] ?? null),
                'device_name' => $json['device_name'] ?? ($last['device_name'] ?? null),
                'history' => $hist
            ];
        }
    }
    return $ubicaciones;
}

/* -------------------- BACKEND (POST / ?json=1 / ?stream=1 / ?history=1) -------------------- */

// POST: guardar ubicación por user_id
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $raw = file_get_contents("php://input");
    $lat = null; $lon = null; $user_id = null;

    if (!empty($_POST)) {
        $lat = isset($_POST['lat']) ? filter_var($_POST['lat'], FILTER_VALIDATE_FLOAT) : null;
        $lon = isset($_POST['lon']) ? filter_var($_POST['lon'], FILTER_VALIDATE_FLOAT) : null;
        $user_id = isset($_POST['user_id']) ? $_POST['user_id'] : null;
        $extra = $_POST;
        log_msg("POST form-data: " . json_encode($_POST));
    } elseif (!empty($raw)) {
        $decoded = json_decode($raw, true);
        log_msg("POST raw body: " . $raw);
        if (is_array($decoded)) {
            $lat = isset($decoded['lat']) ? filter_var($decoded['lat'], FILTER_VALIDATE_FLOAT) : null;
            $lon = isset($decoded['lon']) ? filter_var($decoded['lon'], FILTER_VALIDATE_FLOAT) : null;
            $user_id = isset($decoded['user_id']) ? $decoded['user_id'] : null;
            $extra = $decoded;
        } else {
            $extra = [];
        }
    } else {
        $extra = [];
        log_msg("POST vacío");
    }

    if ($user_id === null || $user_id === '') {
        $user_id = 'anon_' . substr(uniqid(), -8);
    }
    $user_id = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $user_id);

    $accuracy_raw = $extra['accuracy'] ?? null;
    $provider_raw = $extra['provider'] ?? null;
    $ts_raw = $extra['ts'] ?? null;
    $steps_raw = $extra['steps'] ?? null;
    $steps_total_raw = $extra['steps_total'] ?? null;
    $accel_raw = $extra['accel'] ?? null;
    $device_name_raw = $extra['device_name'] ?? null;

    $accuracy = $accuracy_raw !== null ? $accuracy_raw : null;
    $provider = $provider_raw !== null ? preg_replace('/[^\w\-\s\.]/u','', (string)$provider_raw) : null;
    $device_name = $device_name_raw !== null ? preg_replace('/[^\w\-\s\.]/u','', (string)$device_name_raw) : null;
    $steps = is_numeric($steps_raw) ? (int)$steps_raw : (is_string($steps_raw) && $steps_raw !== '' ? (int)$steps_raw : 0);
    $steps_total = is_numeric($steps_total_raw) ? (int)$steps_total_raw : (is_string($steps_total_raw) && $steps_total_raw !== '' ? (int)$steps_total_raw : null);
    $accel = is_numeric($accel_raw) ? floatval($accel_raw) : (is_string($accel_raw) && $accel_raw !== '' ? floatval($accel_raw) : null);
    $ts = normalize_ts($ts_raw) ?? date("Y-m-d H:i:s");

    if ($lat !== false && $lon !== false && $lat !== null && $lon !== null) {
        $filePath = $dir . '/' . $user_id . '.json';

        $existing = read_json_file($filePath);
        $history = is_array($existing['history'] ?? null) ? $existing['history'] : [];

        $addPoint = true;
        if (!empty($history)) {
            $last = end($history);
            if (isset($last['lat']) && isset($last['lon'])) {
                if (abs(floatval($last['lat']) - $lat) < 0.0000001 && abs(floatval($last['lon']) - $lon) < 0.0000001) {
                    $lastTs = isset($last['ts']) ? $last['ts'] : null;
                    if ($lastTs === $ts) {
                        $addPoint = false;
                    }
                }
            }
        }

        if ($addPoint) {
            $point = [
                'lat' => $lat,
                'lon' => $lon,
                'ts' => $ts,
                'accuracy' => $accuracy,
                'provider' => $provider,
                'steps' => $steps,
                'steps_total' => $steps_total,
                'accel' => $accel,
                'device_name' => $device_name
            ];
            $history[] = $point;
            if (count($history) > MAX_POINTS) {
                $history = array_slice($history, -MAX_POINTS);
            }
        }

        $data = [
            'user_id' => $user_id,
            'latitud' => $lat,
            'longitud' => $lon,
            'fecha' => date("Y-m-d H:i:s"),
            'accuracy' => $accuracy,
            'provider' => $provider,
            'steps' => $steps,
            'steps_total' => $steps_total,
            'accel' => $accel,
            'device_name' => $device_name,
            'history' => $history
        ];

        if (@file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            log_msg("ERROR: No se pudo escribir $filePath");
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(["status"=>"error","msg"=>"no write"]);
        } else {
            log_msg("OK: guardado $filePath -> " . json_encode(['user_id'=>$user_id,'lat'=>$lat,'lon'=>$lon,'ts'=>$ts]));
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(["status"=>"ok","data"=>$data]);
        }
    } else {
        log_msg("ERROR: lat/lon inválidos lat=" . var_export($lat,true) . " lon=" . var_export($lon,true));
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(["status"=>"error","msg"=>"invalid lat/lon"]);
    }
    exit;
}

// GET JSON: devolver todas las ubicaciones
if (isset($_GET['json']) && $_GET['json'] == '1') {
    $ubicaciones = collect_all_locations($dir);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($ubicaciones, JSON_UNESCAPED_UNICODE);
    exit;
}

// HISTORY endpoint: devolver history filtrado por user y rango (from/to) opcionales
if (isset($_GET['history']) && $_GET['history'] == '1') {
    $user = isset($_GET['user']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '_', $_GET['user']) : null;
    if (!$user) {
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status'=>'error','msg'=>'missing user']);
        exit;
    }
    $filePath = $dir . '/' . $user . '.json';
    $json = read_json_file($filePath);
    if (!$json) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['status'=>'error','msg'=>'user not found']);
        exit;
    }

    $normalize = function($s) {
        if ($s === null) return null;
        $s = trim($s);
        if ($s === '') return null;
        $s = str_replace('T', ' ', $s);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= ' 00:00:00';
        return $s;
    };

    $from = isset($_GET['from']) ? $normalize($_GET['from']) : null;
    $to   = isset($_GET['to'])   ? $normalize($_GET['to'])   : null;

    $history = is_array($json['history'] ?? null) ? $json['history'] : [];
    $filtered = [];

    foreach ($history as $p) {
        $include = true;
        if (isset($p['ts']) && $p['ts']) {
            $ts = DateTime::createFromFormat('Y-m-d H:i:s', $p['ts']);
            if (!$ts) { $include = true; }
            else {
                if ($from) {
                    $fdt = DateTime::createFromFormat('Y-m-d H:i:s', $from);
                    if ($fdt && $ts < $fdt) $include = false;
                }
                if ($to && $include) {
                    $tdt = DateTime::createFromFormat('Y-m-d H:i:s', $to);
                    if ($tdt && $ts > $tdt) $include = false;
                }
            }
        }
        if ($include) $filtered[] = $p;
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status'=>'ok','user_id'=>$user,'from'=>$from,'to'=>$to,'history'=>$filtered], JSON_UNESCAPED_UNICODE);
    exit;
}

// SSE stream (push)
if (isset($_GET['stream']) && $_GET['stream'] == '1') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    @set_time_limit(0);
    ignore_user_abort(true);
    echo "retry: 3000\n\n";
    @ob_flush(); @flush();

    $send_update = function() use ($dir) {
        $ubicaciones = collect_all_locations($dir);
        $payload = json_encode($ubicaciones, JSON_UNESCAPED_UNICODE);
        $payload = str_replace("\n", "\\n", $payload);
        echo "event: update\n";
        echo "data: {$payload}\n\n";
        @ob_flush(); @flush();
    };

    if (function_exists('inotify_init')) {
        log_msg("SSE: usando inotify");
        $fd = inotify_init();
        $wd = inotify_add_watch($fd, $dir, IN_CREATE | IN_MODIFY | IN_DELETE);
        $send_update();
        while (!connection_aborted()) {
            $events = @inotify_read($fd);
            if ($events && is_array($events)) {
                $send_update();
            }
            if (connection_aborted()) break;
        }
        @inotify_rm_watch($fd, $wd);
        @fclose($fd);
    } else {
        log_msg("SSE: inotify NO disponible, usando fallback de poll 200ms");
        $lastMtime = 0;
        $send_update();
        while (!connection_aborted()) {
            clearstatcache();
            $changed = false;
            foreach (glob($dir . "/*.json") as $file) {
                $mtime = @filemtime($file);
                if ($mtime && $mtime > $lastMtime) {
                    $lastMtime = $mtime;
                    $changed = true;
                }
            }
            if ($changed) $send_update();
            usleep(200000);
        }
    }
    exit;
}

/* -------------------- CLIENT: página HTML + JS (layout: left sidebar + right map) -------------------- */
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8" />
<title>GeoMonitor — Rutas por clic</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<style>
  :root{
    --bg:#071022;
    --card:#0b1220;
    --muted:#9aa6b2;
    --accent:#4f46e5;
    --accent-2:#06b6d4;
    --panel:#eff6ff;
  }
  html,body{height:100%;margin:0;font-family:'Inter',system-ui,Arial,Helvetica,sans-serif;background:linear-gradient(180deg,#061226 0%, #08203a 60%);color:#e6eef8}
  .app {
    display:flex;
    height:100vh;
    gap:12px;
    padding:16px;
    box-sizing:border-box;
    max-width:1400px;
    margin:0 auto;
    align-items:stretch;
  }
  /* SIDEBAR izquierda */
  .sidebar {
    width:360px;
    min-width:260px;
    background:rgba(255,255,255,0.04);
    border-radius:10px;
    padding:14px;
    box-shadow:0 8px 30px rgba(2,6,23,0.45);
    color:#e6eef8;
    display:flex;
    flex-direction:column;
    gap:12px;
  }
  .brand { display:flex;flex-direction:column;gap:4px }
  .brand h1{margin:0;font-size:1.1rem}
  .panel{background:rgba(255,255,255,0.03);padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.03)}
  .searchRow{display:flex;gap:8px;align-items:center}
  .searchRow input[type="search"]{flex:1;padding:8px;border-radius:8px;border:1px solid rgba(255,255,255,0.04);background:transparent;color:inherit}
  button{background:linear-gradient(90deg,var(--accent),var(--accent-2));color:white;border:none;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:600}
  button.ghost{background:transparent;color:var(--muted);box-shadow:none;border:1px solid rgba(255,255,255,0.03)}
  button.small{padding:6px 8px;font-size:0.9rem}
  label{font-size:0.85rem;color:var(--muted);display:block;margin-bottom:6px}
  select,input{padding:6px;border-radius:8px;border:1px solid rgba(255,255,255,0.03);background:transparent;color:inherit}

  /* MAP container a la derecha, flexible */
  .mapWrap{flex:1;display:flex;flex-direction:column;gap:12px}
  #map { flex:1;border-radius:10px;overflow:hidden;border:1px solid rgba(255,255,255,0.04) }

  .legend{background:rgba(255,255,255,0.96);color:#0b1220;padding:10px;border-radius:10px;max-height:45vh;overflow:auto}
  .legend .item{display:flex;gap:8px;align-items:center;margin-bottom:8px}
  .colorbox{width:14px;height:14px;border-radius:4px;display:inline-block}
  .muted{color:var(--muted);font-size:0.9rem}

  .controlsRow{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .footer{color:rgba(230,238,248,0.6);text-align:center;margin-top:6px;font-size:0.85rem}

  /* responsive: en pantallas pequeñas apilar verticalmente */
  @media (max-width:900px){
    .app{flex-direction:column;padding:12px}
    .sidebar{width:100%;max-height:38vh;overflow:auto}
    .mapWrap{width:100%}
    #map{height:56vh}
  }
</style>
</head>
<body>
  <div class="app">
    <aside class="sidebar">
      <div class="brand">
        <h1>GeoMonitor</h1>
        <div class="muted">Mapa en tiempo real · Trazado por clic · Búsqueda</div>
      </div>

      <div class="panel">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:700">Julio Cesar Sotelo Quispe</div>
          <div class="muted">215791 - UNSAAC</div>
        </div>
      </div>

      <div class="panel">
        <label>Buscar lugar</label>
        <div class="searchRow">
          <input id="searchInput" type="search" placeholder="p.ej. 'gasolinera', 'plaza'" />
          <button id="btnSearch">🔎</button>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
          <button id="btnClearSearch" class="ghost small">Limpiar</button>
          <div class="muted" style="margin-left:auto">Resultados: <span id="searchCount">0</span></div>
        </div>
      </div>

      <div class="panel">
        <label>Herramientas de ruta</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button id="btnRoute">🛣️ Trazar ruta</button>
          <button id="btnClearRoute" class="ghost small">Limpiar ruta</button>
          <button id="btnClickRoute" class="small">🎯 Clic para ruta</button>
        </div>
        <div style="margin-top:8px" id="routeInfo" class="muted"></div>

        <div style="margin-top:10px">
          <label>Intervalo (fallback)</label>
          <select id="interval">
            <option value="3000">3s</option>
            <option value="5000" selected>5s</option>
            <option value="10000">10s</option>
            <option value="30000">30s</option>
          </select>
          <div style="margin-top:8px;display:flex;gap:8px">
            <button id="btnCenter" class="ghost small">Centrar</button>
            <button id="btnPause" class="ghost small">Pausar</button>
          </div>
        </div>
      </div>

      <div class="panel">
        <label>Usuarios conectados</label>
        <div id="legendList" class="legend"></div>
      </div>

      <div class="panel">
        <label>Ver ruta de usuario</label>
        <div style="display:flex;gap:8px;align-items:center">
          <select id="userSelect" style="flex:1"><option value="">— seleccionar —</option></select>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px">
          <div style="flex:1"><label>Desde</label><input id="fromTime" type="datetime-local" /></div>
          <div style="flex:1"><label>Hasta</label><input id="toTime" type="datetime-local" /></div>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px">
          <button id="btnShowUserRoute" class="small">Mostrar ruta</button>
          <button id="btnClearUserRoute" class="ghost small">Limpiar</button>
        </div>
      </div>

      <div style="margin-top:auto" class="muted">
        <div>Conexión: <span id="connStatus">buscando mecanismo en tiempo real...</span></div>
      </div>

    </aside>

    <main class="mapWrap">
      <div id="map"></div>
    </main>
  </div>

<script>
/* ---------- CONFIG ---------- */
const SSE_URL = 'index.php?stream=1';
const JSON_URL = 'index.php?json=1';
const HISTORY_URL = 'index.php?history=1';
const OSRM_ROUTE_URL = 'https://router.project-osrm.org/route/v1/driving';
const NOMINATIM_SEARCH_URL = 'https://nominatim.openstreetmap.org/search';

/* ---------- MAP ---------- */
let map = L.map('map', {zoomControl:true}).setView([-12.0464, -77.0428], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{ attribution: '© OpenStreetMap contributors' }).addTo(map);

/* ---------- STRUCTS ---------- */
const markers = {};
const polylines = {};
const colors = {};
let userRouteLayer = null;
let userRouteMarkers = [];
let searchMarkers = [];
let routeLayer = null, routeStartMarker = null, routeEndMarker = null;
let tempClickMarkers = [];
let routeClickMode = false, routeClickCount = 0;

/* color helper */
function colorFromId(id) {
  if (colors[id]) return colors[id];
  let h=0; for(let i=0;i<id.length;i++) h=(h*31+id.charCodeAt(i))%360;
  const color = `hsl(${h},70%,45%)`; colors[id]=color; return color;
}

/* ---------- UI: legend/user list ---------- */
function updateLegend(entries){
  const list = document.getElementById('legendList'); list.innerHTML='';
  entries.forEach(u=>{
    const el = document.createElement('div'); el.className='item'; el.style.cursor='pointer';
    const cb = document.createElement('span'); cb.className='colorbox'; cb.style.backgroundColor = colorFromId(u.user_id);
    const txt = document.createElement('div'); txt.innerHTML = `<strong>${u.user_id}</strong><br><small style="color:#64748b">${u.fecha||''}</small>`;
    el.appendChild(cb); el.appendChild(txt);
    el.addEventListener('click', ()=> {
      document.getElementById('userSelect').value = u.user_id;
      document.getElementById('fromTime').value = '';
      document.getElementById('toTime').value = '';
      fetchAndShowUserRoute(u.user_id, null, null);
    });
    list.appendChild(el);
  });
}

/* populate users select */
function populateUserSelect(entries) {
  const sel = document.getElementById('userSelect');
  const cur = sel.value;
  sel.innerHTML = '<option value="">— seleccionar —</option>';
  entries.forEach(u=>{
    const opt = document.createElement('option'); opt.value = u.user_id; opt.textContent = u.user_id; sel.appendChild(opt);
  });
  if (cur) sel.value = cur;
}

/* ---------- APPLY DATA (markers & polylines) ---------- */
function applyData(arr) {
  if (!Array.isArray(arr)) { document.getElementById('connStatus').textContent='Respuesta inválida'; return; }
  if (arr.length === 0) { document.getElementById('connStatus').textContent='No hay usuarios conectados'; clearUsers(); updateLegend([]); populateUserSelect([]); return; }

  const latlngsAll = [];

  arr.forEach(u=>{
    if (!u.user_id || !Array.isArray(u.history)) return;
    const id = u.user_id;
    const color = colorFromId(id);
    const latlngs = u.history.map(p=>[parseFloat(p.lat), parseFloat(p.lon)]).filter(p=>isFinite(p[0])&&isFinite(p[1]));

    if (!polylines[id]) {
      polylines[id] = L.polyline(latlngs, { color: color, weight: 3, opacity: 0.92 }).addTo(map);
    } else {
      polylines[id].setLatLngs(latlngs);
      polylines[id].setStyle({ color: color });
    }

    if (latlngs.length) {
      const last = latlngs[latlngs.length - 1];
      latlngsAll.push(last);
      const lastPoint = u.history[u.history.length - 1] || {};
      const popupHtml = `<b>${id}</b><br>${u.fecha || ''}<br>Device: ${u.device_name||'—'}<br>Steps: ${lastPoint.steps_total ?? lastPoint.steps ?? '—'}<br>Acc: ${lastPoint.accuracy ?? '—'}`;
      if (!markers[id]) {
        const m = L.circleMarker(last, { radius:7, color: color, fillColor: color, fillOpacity:0.95, weight:2 }).addTo(map);
        m.bindPopup(popupHtml);
        markers[id] = m;
      } else {
        markers[id].setLatLng(last);
        markers[id].setStyle({ color: color, fillColor: color });
        markers[id].getPopup().setContent(popupHtml);
      }
    } else {
      if (markers[id]) { map.removeLayer(markers[id]); delete markers[id]; }
      if (polylines[id]) { map.removeLayer(polylines[id]); delete polylines[id]; }
    }
  });

  // remove disappeared
  Object.keys(markers).forEach(id => { if (!arr.find(u=>u.user_id===id)) { map.removeLayer(markers[id]); delete markers[id]; } });
  Object.keys(polylines).forEach(id => { if (!arr.find(u=>u.user_id===id)) { map.removeLayer(polylines[id]); delete polylines[id]; } });

  document.getElementById('connStatus').innerHTML = `<strong>Usuarios:</strong> ${arr.length} • ${new Date().toLocaleString()}`;
  updateLegend(arr);
  populateUserSelect(arr);

  if (latlngsAll.length) {
    // if map is still at world view, fit to data
    if (map.getZoom() < 6) map.fitBounds(L.latLngBounds(latlngsAll).pad(0.18));
  }
}
function clearUsers() { Object.keys(markers).forEach(k=>{ map.removeLayer(markers[k]); delete markers[k]; }); Object.keys(polylines).forEach(k=>{ map.removeLayer(polylines[k]); delete polylines[k]; }); }

/* ---------- SSE / polling ---------- */
let es = null, polling=false, pollTimer = null;
function startSSE(){
  if (!window.EventSource){ document.getElementById('connStatus').textContent='SSE no soportado, usando polling'; startPolling(); return; }
  es = new EventSource(SSE_URL);
  es.addEventListener('open', ()=>{ document.getElementById('connStatus').textContent='Conexión SSE (tiempo real)'; stopPolling(); });
  es.addEventListener('update', e=>{ try{ const arr = JSON.parse(e.data); applyData(arr); } catch(err){ console.error(err); } });
  es.addEventListener('error', e=>{ document.getElementById('connStatus').textContent='SSE error — fallback polling'; stopSSE(); startPolling(); });
}
function stopSSE(){ if (es){ es.close(); es = null; } }
function startPolling(){ polling=true; document.getElementById('connStatus').textContent='Conexión: polling'; updateOnce(); if (pollTimer) clearInterval(pollTimer); const ms = parseInt(document.getElementById('interval').value,10)||5000; pollTimer = setInterval(()=>{ if (polling) updateOnce(); }, ms); }
function stopPolling(){ polling=false; if (pollTimer){ clearInterval(pollTimer); pollTimer = null; } }
function updateOnce(){ fetch(JSON_URL + '&nocache=' + Date.now()).then(r=>{ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); }).then(arr=>applyData(arr)).catch(err=>{ console.error(err); document.getElementById('connStatus').textContent='Error cargando ubicaciones'; }); }

/* ---------- BUSQUEDA (Nominatim) ---------- */
async function doSearch(query){
  if (!query || query.trim().length===0) return;
  const center = map.getCenter();
  const viewbox = [center.lng-0.5, center.lat-0.5, center.lng+0.5, center.lat+0.5].join(',');
  const url = `${NOMINATIM_SEARCH_URL}?q=${encodeURIComponent(query)}&format=json&limit=10&viewbox=${viewbox}&bounded=1`;
  document.getElementById('searchCount').textContent='...';
  try {
    const res = await fetch(url, { headers: { 'Accept':'application/json' }});
    const arr = await res.json();
    clearSearchResults();
    arr.forEach(r=>{
      const lat=parseFloat(r.lat), lon=parseFloat(r.lon);
      const popupHtml = `<div style="max-width:260px"><b>${r.display_name}</b><br/><div style="margin-top:6px;display:flex;gap:6px"><button onclick="assignFromSearch(${lat},${lon},'start')">Asignar inicio</button><button onclick="assignFromSearch(${lat},${lon},'end')">Asignar destino</button></div></div>`;
      const m = L.marker([lat,lon]).addTo(map).bindPopup(popupHtml);
      searchMarkers.push(m);
    });
    document.getElementById('searchCount').textContent = arr.length;
    if (arr.length) map.fitBounds(L.latLngBounds(arr.map(a=>[parseFloat(a.lat),parseFloat(a.lon)])).pad(0.2));
  } catch(e){ console.error('Nominatim', e); document.getElementById('searchCount').textContent='0'; }
}
function clearSearchResults(){ searchMarkers.forEach(m=>map.removeLayer(m)); searchMarkers=[]; document.getElementById('searchCount').textContent='0'; }
document.getElementById('btnSearch').addEventListener('click', ()=> doSearch(document.getElementById('searchInput').value));
document.getElementById('btnClearSearch').addEventListener('click', clearSearchResults);
window.assignFromSearch = function(lat, lon, which){ if (which==='start') placeDraggableMarker([lat,lon],'start'); else placeDraggableMarker([lat,lon],'end'); clearSearchResults(); }

/* ---------- RUTA (OSRM) ---------- */
async function drawRoute(startLatLng, endLatLng){
  if (!startLatLng || !endLatLng) return;
  const [sLat, sLon] = [startLatLng[0], startLatLng[1]];
  const [eLat, eLon] = [endLatLng[0], endLatLng[1]];
  const url = `${OSRM_ROUTE_URL}/${sLon},${sLat};${eLon},${eLat}?overview=full&geometries=geojson&steps=false`;
  try {
    const res = await fetch(url);
    const obj = await res.json();
    if (!obj || obj.code !== 'Ok' || !obj.routes || !obj.routes.length) { alert('No se pudo calcular la ruta (OSRM).'); return; }
    const route = obj.routes[0];
    if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
    routeLayer = L.geoJSON(route.geometry, { style: { color: '#06b6d4', weight:6, opacity:0.92 } }).addTo(map);
    const meters = route.distance, seconds = route.duration;
    const kms = (meters/1000).toFixed(2), mins = Math.round(seconds/60);
    document.getElementById('routeInfo').textContent = `Distancia: ${kms} km — Tiempo aprox: ${mins} min`;
    map.fitBounds(routeLayer.getBounds().pad(0.15));
  } catch(e) { console.error('OSRM', e); alert('Error llamando a OSRM: ' + e.message); }
}

function clearRoute(){
  if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
  if (routeStartMarker) { map.removeLayer(routeStartMarker); routeStartMarker = null; }
  if (routeEndMarker) { map.removeLayer(routeEndMarker); routeEndMarker = null; }
  document.getElementById('routeInfo').textContent = '';
}
document.getElementById('btnClearRoute').addEventListener('click', clearRoute);
document.getElementById('btnRoute').addEventListener('click', async ()=>{
  if (routeStartMarker && routeEndMarker) {
    await drawRoute([routeStartMarker.getLatLng().lat, routeStartMarker.getLatLng().lng], [routeEndMarker.getLatLng().lat, routeEndMarker.getLatLng().lng]);
  } else {
    alert('Coloca inicio y destino (modo Clic para ruta o usa resultados de búsqueda).');
  }
});

/* draggable markers for start/end */
function placeDraggableMarker(latlng, which){
  const opts = { draggable: true };
  if (which === 'start') {
    if (routeStartMarker) { routeStartMarker.setLatLng(latlng); return; }
    routeStartMarker = L.marker(latlng, opts).addTo(map).bindPopup('Inicio').openPopup();
    routeStartMarker.on('dragend', async (e)=> { const p = e.target.getLatLng(); if (routeEndMarker) await drawRoute([p.lat,p.lng],[routeEndMarker.getLatLng().lat,routeEndMarker.getLatLng().lng]); });
  } else {
    if (routeEndMarker) { routeEndMarker.setLatLng(latlng); return; }
    routeEndMarker = L.marker(latlng, opts).addTo(map).bindPopup('Destino').openPopup();
    routeEndMarker.on('dragend', async (e)=> { const p = e.target.getLatLng(); if (routeStartMarker) await drawRoute([routeStartMarker.getLatLng().lat,routeStartMarker.getLatLng().lng],[p.lat,p.lng]); });
  }
}

/* ---------- Selección por clic: activar modo y recoger 2 puntos ---------- */
const btnClickRoute = document.getElementById('btnClickRoute');
btnClickRoute.addEventListener('click', ()=>{
  routeClickMode = !routeClickMode; routeClickCount = 0; clearTempClickMarkers(); updateClickRouteUI();
  if (routeClickMode){ btnClickRoute.textContent = 'Clic: selecciona 2 puntos (Esc o clic derecho para cancelar)'; map.getContainer().style.cursor='crosshair'; }
  else { btnClickRoute.textContent = '🎯 Clic para ruta'; map.getContainer().style.cursor=''; }
});
function updateClickRouteUI(){ if (!routeClickMode) btnClickRoute.classList.remove('active'); else btnClickRoute.classList.add('active'); }
function clearTempClickMarkers(){ tempClickMarkers.forEach(m=>{ try{ map.removeLayer(m); }catch(e){} }); tempClickMarkers = []; }
function cancelClickRouteMode(){ routeClickMode=false; routeClickCount=0; clearTempClickMarkers(); btnClickRoute.textContent='🎯 Clic para ruta'; map.getContainer().style.cursor=''; updateClickRouteUI(); }
document.addEventListener('keydown',(ev)=>{ if (ev.key === 'Escape' && routeClickMode) cancelClickRouteMode(); });
map.getContainer().addEventListener('contextmenu',(ev)=>{ if (routeClickMode){ ev.preventDefault(); cancelClickRouteMode(); } });

map.on('click', async function(e){
  if (routeClickMode) {
    routeClickCount++; const lat = e.latlng.lat, lon = e.latlng.lng;
    const tmp = L.circleMarker([lat,lon],{radius:6,color:'#ff7800',fillColor:'#ffb86b',fillOpacity:0.95}).addTo(map); tempClickMarkers.push(tmp);
    if (routeClickCount === 1) { placeDraggableMarker([lat,lon],'start'); btnClickRoute.textContent='Clic: ahora selecciona destino'; }
    else if (routeClickCount === 2) {
      placeDraggableMarker([lat,lon],'end'); clearTempClickMarkers();
      routeClickMode=false; btnClickRoute.textContent='🎯 Clic para ruta'; map.getContainer().style.cursor=''; updateClickRouteUI(); routeClickCount=0;
      try { if (routeStartMarker && routeEndMarker) await drawRoute([routeStartMarker.getLatLng().lat, routeStartMarker.getLatLng().lng], [routeEndMarker.getLatLng().lat, routeEndMarker.getLatLng().lng]); } catch(err) { console.error(err); alert('Error trazando la ruta: '+(err.message||err)); }
    }
    return;
  }
  if (e.originalEvent.shiftKey) { placeDraggableMarker([e.latlng.lat,e.latlng.lng],'end'); }
  else if (e.originalEvent.ctrlKey || e.originalEvent.metaKey) { placeDraggableMarker([e.latlng.lat,e.latlng.lng],'start'); }
});

/* ---------- CONTROLS ---------- */
document.getElementById('btnCenter').addEventListener('click', ()=>{
  const pts=[]; Object.values(polylines).forEach(p=>pts.push(...p.getLatLngs())); Object.values(markers).forEach(m=>pts.push(m.getLatLng()));
  if (pts.length) map.fitBounds(L.latLngBounds(pts).pad(0.2));
});
document.getElementById('btnPause').addEventListener('click',(e)=>{ polling = !polling; e.target.textContent = polling ? 'Pausar' : 'Reanudar'; if (polling && !es) updateOnce(); });

/* ---------- START SSE / Poll ---------- */
startSSE(); if (!window.EventSource) startPolling();

/* interval change */
document.getElementById('interval').addEventListener('change', ()=>{ if (!es) { stopPolling(); startPolling(); }});

/* ---------- HISTORY: fetch and draw ---------- */
function datetimeLocalToParam(val){ if (!val) return ''; return val; }

async function fetchAndShowUserRoute(user, from, to){
  if (!user) { alert('Selecciona un usuario'); return; }
  if (userRouteLayer) { map.removeLayer(userRouteLayer); userRouteLayer = null; }
  userRouteMarkers.forEach(m=>map.removeLayer(m)); userRouteMarkers = [];

  const params = new URLSearchParams(); params.append('history','1'); params.append('user',user);
  if (from) params.append('from', datetimeLocalToParam(from));
  if (to) params.append('to', datetimeLocalToParam(to));

  try {
    const res = await fetch('index.php?' + params.toString());
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const obj = await res.json();
    if (!obj || obj.status !== 'ok') { alert('No se obtuvo historial: ' + (obj && obj.msg ? obj.msg : 'error')); return; }
    const pts = (obj.history || []).map(p=>[parseFloat(p.lat), parseFloat(p.lon)]).filter(p=>isFinite(p[0])&&isFinite(p[1]));
    if (!pts.length) { alert('No hay puntos en el rango seleccionado para este usuario.'); return; }
    userRouteLayer = L.polyline(pts, { color: '#ff7f50', weight:4, opacity:0.95, dashArray: '8 6' }).addTo(map);
    const start = pts[0], end = pts[pts.length - 1];
    const sM = L.circleMarker(start,{radius:6, color:'#0b6623', fillColor:'#0b6623', fillOpacity:0.95}).addTo(map).bindPopup('<b>Inicio</b>');
    const eM = L.circleMarker(end,{radius:6, color:'#8b0000', fillColor:'#8b0000', fillOpacity:0.95}).addTo(map).bindPopup('<b>Fin</b>');
    userRouteMarkers.push(sM, eM);
    map.fitBounds(userRouteLayer.getBounds().pad(0.12));
  } catch(e) { console.error(e); alert('Error obteniendo historial: ' + e.message); }
}

document.getElementById('btnShowUserRoute').addEventListener('click', ()=> {
  const user = document.getElementById('userSelect').value;
  const from = document.getElementById('fromTime').value;
  const to = document.getElementById('toTime').value;
  fetchAndShowUserRoute(user, from, to);
});
document.getElementById('btnClearUserRoute').addEventListener('click', ()=>{
  if (userRouteLayer) { map.removeLayer(userRouteLayer); userRouteLayer = null; }
  userRouteMarkers.forEach(m=>map.removeLayer(m)); userRouteMarkers=[];
  document.getElementById('userSelect').value=''; document.getElementById('fromTime').value=''; document.getElementById('toTime').value='';
});

/* ---------- helper: clear temp markers used for click-route ---------- */
function clearTempClickMarkers(){ tempClickMarkers.forEach(m=>{ try{ map.removeLayer(m); }catch(e){} }); tempClickMarkers = []; }

/* ---------- helper: clear route & search UI on load ---------- */
(function initUI(){
  document.getElementById('btnPause').textContent = 'Pausar';
  // ensure map size after DOM
  setTimeout(()=>{ map.invalidateSize(); }, 300);
})();

</script>
</body>
</html>
