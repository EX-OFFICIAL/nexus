<?php
/**
 * Project: Nexus IPTV Enterprise OTT Platform - Ultimate Premium Edition
 * Version: 8.0.0-Cinematic (Hot-Reloading Settings, Netflix Boot, Touch-Enhanced UI)
 * Architecture: PHP Hybrid AJAX Parser + Auto-Invalidating Cached M3U Engine
 */

session_start();

// Ensure CSRF state integrity
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$config_file = 'can.json';
$cache_dir = 'cache/';
$cache_lifetime = 1800; // 30 Minutes default cache fallback for remote URLs

// Create cache directory if missing
if (!file_exists($cache_dir)) {
    @mkdir($cache_dir, 0755, true);
}

// -------------------------------------------------------------------------
// CUSTOM PLAYLIST LOAD ENGINE
// -------------------------------------------------------------------------
$playlists = [];

if (file_exists($config_file)) {
    $config_data = json_decode(file_get_contents($config_file), true);
    if (isset($config_data['playlists']) && is_array($config_data['playlists'])) {
        $playlists = $config_data['playlists'];
    }
}

// Minimalist fallback if config is missing
if (empty($playlists)) {
    $playlists = [
        [
            "id" => "playlist_default",
            "name" => "Default Stream Deck",
            "source" => "bd.m3u"
        ]
    ];
    $config_to_save = ["playlists" => $playlists];
    @file_put_contents($config_file, json_encode($config_to_save, JSON_PRETTY_PRINT));
}

// Helper function to safely parse M3U files to array
function parse_m3u_stream_file($src) {
    $channels = [];
    $raw_data = '';

    if (filter_var($src, FILTER_VALIDATE_URL)) {
        $opts = [
            "http" => [
                "method" => "GET",
                "header" => "User-Agent: Mozilla/5.0 IPTV-OTT-Engine/8.0\r\n",
                "timeout" => 15
            ]
        ];
        $raw_data = @file_get_contents($src, false, stream_context_create($opts));
    } elseif (file_exists($src)) {
        $raw_data = @file_get_contents($src);
    }

    if (empty($raw_data)) {
        return [];
    }

    // Standardize newlines
    $raw_data = str_replace("\r", "", $raw_data);
    $lines = explode("\n", $raw_data);
    $temp_channel = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if (strpos($line, '#EXTINF:') === 0) {
            $temp_channel = [];
            $parts = explode(',', $line, 2);
            $temp_channel['name'] = isset($parts[1]) ? trim($parts[1]) : 'Live Stream Node';
            
            if (preg_match('/tvg-logo="([^"]+)"/i', $line, $matches)) {
                $temp_channel['logo'] = $matches[1];
            } else {
                $temp_channel['logo'] = 'https://imgur.com/79g2kMA.png';
            }

            if (preg_match('/group-title="([^"]+)"/i', $line, $matches)) {
                $temp_channel['category'] = $matches[1];
            } else {
                $name_lower = strtolower($temp_channel['name']);
                if (strpos($name_lower, 'news') !== false) {
                    $temp_channel['category'] = 'News';
                } elseif (strpos($name_lower, 'sports') !== false || strpos($name_lower, 'sport') !== false) {
                    $temp_channel['category'] = 'Sports';
                } elseif (strpos($name_lower, 'movie') !== false || strpos($name_lower, 'action') !== false) {
                    $temp_channel['category'] = 'Movies';
                } elseif (strpos($name_lower, 'islamic') !== false || strpos($name_lower, 'makkah') !== false) {
                    $temp_channel['category'] = 'Islamic';
                } else {
                    $temp_channel['category'] = 'General';
                }
            }
        } elseif (!empty($line) && strpos($line, '#') !== 0 && $temp_channel !== null) {
            $temp_channel['url'] = $line;
            $temp_channel['uuid'] = md5($temp_channel['name'] . $temp_channel['url']);
            $channels[] = $temp_channel;
            $temp_channel = null;
        }
    }

    return $channels;
}

// Handler: AJAX Playlist Loader with Smart Auto-Invalidating Refresh Core
if (isset($_GET['action']) && $_GET['action'] === 'get_playlist_channels') {
    header('Content-Type: application/json');
    $playlist_id = $_GET['id'] ?? '';
    $force_reload = isset($_GET['force_sync']) && $_GET['force_sync'] === 'true';
    
    $target_playlist = null;
    foreach ($playlists as $pl) {
        if ($pl['id'] === $playlist_id) {
            $target_playlist = $pl;
            break;
        }
    }

    if (!$target_playlist && !empty($playlists)) {
        $target_playlist = $playlists[0];
    }

    if (!$target_playlist) {
        echo json_encode(['error' => 'Playlist profile not found.']);
        exit;
    }

    $source = $target_playlist['source'];
    $cache_file = $cache_dir . 'cache_' . md5($target_playlist['id']) . '.json';
    $channels = [];
    $should_rebuild_cache = false;

    if (!file_exists($cache_file)) {
        $should_rebuild_cache = true;
    } elseif ($force_reload) {
        $should_rebuild_cache = true;
    } elseif (file_exists($source)) {
        if (filemtime($source) > filemtime($cache_file)) {
            $should_rebuild_cache = true; // Auto-detected local update!
        }
    } else {
        if (time() - filemtime($cache_file) > $cache_lifetime) {
            $should_rebuild_cache = true; // Cache expired
        }
    }

    if ($should_rebuild_cache) {
        $channels = parse_m3u_stream_file($source);
        if (!empty($channels)) {
            file_put_contents($cache_file, json_encode($channels));
        }
    } else {
        $channels = json_decode(file_get_contents($cache_file), true);
    }

    if (empty($channels)) {
        $channels = [
            [
                "name" => "Live Stream Node (Standby Mode)",
                "url" => "https://owrcovcrpy.gpcdn.net/bpk-tv/1702/output/index.m3u8",
                "logo" => "https://imgur.com/79g2kMA.png",
                "category" => "Standby Feed",
                "uuid" => md5("fallback_demo")
            ]
        ];
    }

    echo json_encode([
        'channels' => $channels,
        'auto_synced' => $should_rebuild_cache,
        'last_updated' => date('M d, Y H:i:s', filemtime($cache_file))
    ]);
    exit;
}

// Secure User Admin Authorization Actions
$admin_pass_hash = password_hash('admin123', PASSWORD_BCRYPT);
$is_authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

// Handle playlist creation via Web Portal Admin panel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_playlist' && $is_authenticated) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $new_pl_name = trim($_POST['pl_name'] ?? '');
        $new_pl_source = trim($_POST['pl_source'] ?? '');
        
        if (!empty($new_pl_name) && !empty($new_pl_source)) {
            $new_pl_id = 'pl_' . substr(md5(uniqid()), 0, 8);
            $playlists[] = [
                "id" => $new_pl_id,
                "name" => $new_pl_name,
                "source" => $new_pl_source
            ];
            $config_data['playlists'] = $playlists;
            file_put_contents($config_file, json_encode($config_data, JSON_PRETTY_PRINT));
            
            header("Location: " . $_SERVER['PHP_SELF'] . "?view=admin");
            exit;
        }
    }
}

// Handle playlist deletion via Admin Panel
if (isset($_GET['action']) && $_GET['action'] === 'delete_playlist' && $is_authenticated) {
    $delete_id = $_GET['id'] ?? '';
    if (!empty($delete_id) && $delete_id !== 'playlist_default') {
        $playlists = array_values(array_filter($playlists, function($pl) use ($delete_id) {
            return $pl['id'] !== $delete_id;
        }));
        $config_data['playlists'] = $playlists;
        file_put_contents($config_file, json_encode($config_data, JSON_PRETTY_PRINT));
        
        // Clean corresponding cache file
        $cache_file = $cache_dir . 'cache_' . md5($delete_id) . '.json';
        if (file_exists($cache_file)) {
            @unlink($cache_file);
        }
        
        header("Location: " . $_SERVER['PHP_SELF'] . "?view=admin");
        exit;
    }
}

// Handle playlist update/editing via Admin Panel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_playlist' && $is_authenticated) {
    if (hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $edit_id = trim($_POST['pl_id'] ?? '');
        $edit_name = trim($_POST['pl_name'] ?? '');
        $edit_source = trim($_POST['pl_source'] ?? '');

        if (!empty($edit_id) && !empty($edit_name) && !empty($edit_source)) {
            foreach ($playlists as &$pl) {
                if ($pl['id'] === $edit_id) {
                    $pl['name'] = $edit_name;
                    $pl['source'] = $edit_source;
                    break;
                }
            }
            $config_data['playlists'] = $playlists;
            file_put_contents($config_file, json_encode($config_data, JSON_PRETTY_PRINT));

            // Clean cache file to force fresh reload
            $cache_file = $cache_dir . 'cache_' . md5($edit_id) . '.json';
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }

            header("Location: " . $_SERVER['PHP_SELF'] . "?view=admin");
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $login_error = "Anti-CSRF Security Token Mismatch.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if ($username === 'admin' && password_verify($password, $admin_pass_hash)) {
            $_SESSION['authenticated'] = true;
            $is_authenticated = true;
        } else {
            $login_error = "Invalid administrator credentials.";
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION['authenticated'] = false;
    unset($_SESSION['authenticated']);
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="netflix" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Nexus Premium OTT Console - Next-Gen Live IPTV</title>
    
    <meta name="description" content="Premium high-speed global IPTV streaming console with M3U caching engine, multi-playlist capabilities, and offline diagnostics.">
    <meta name="theme-color" content="#e50914">

    <!-- Inline Base64 PWA Manifest Generation -->
    <link rel="manifest" href="data:application/json;charset=utf-8;base64,eyJuYW1lIjoiTmV4dXMgT1RUIFBsYXRmb3JtIiwic2hvcnRfbmFtZSI6Ik5leHVzT1RUIiwic3RhcnRfdXJsIjoiLiIsImRpc3BsYXkiOiJzdGFuZGFsb25lIiwiYmFja2dyb3VuZF9jb2xvciI6IiMwYTAhMGEiLCJ0aGVtZV9jb2xvciI6IiNlNTA5MTQiLCJpY29ucyI6W3sic3JjIjoiaHR0cHM6Ly9pLmltZ3VyLmNvbS83OWcyay5wbmciLCJzaXplcyI6IjUxMng1MTIiLCJ0eXBlIjoiaW1hZ2UvcG5nIn1dfQ==">
    
    <!-- Premium Styles & Global Fonts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    
    <!-- Core Streaming Library -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <script src="https://cdn.dashjs.org/latest/dash.all.min.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                        display: ['Space Grotesk', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace']
                    },
                    colors: {
                        brand: {
                            netflix: '#e50914',
                            glow: '#ff003c',
                            cardbg: '#111111',
                            dark: '#020202',
                            accent: '#3b82f6'
                        }
                    }
                }
            }
        }
    </script>

    <style>
        :root {
            --primary: #e50914;
            --bg-gradient: radial-gradient(circle at top, #160c0d 0%, #020202 100%);
            --glass-bg: rgba(12, 12, 12, 0.85);
            --glass-border: rgba(255, 255, 255, 0.05);
            --text-main: #ffffff;
            --text-muted: #94a3b8;
        }

        [data-theme="cyberpunk"] {
            --primary: #00ffcc;
            --bg-gradient: radial-gradient(circle at top, #0c0218 0%, #010103 100%);
            --glass-bg: rgba(8, 2, 14, 0.88);
            --glass-border: rgba(0, 255, 204, 0.15);
            --text-main: #00ffcc;
            --text-muted: #39ff14;
        }

        [data-theme="glass-gold"] {
            --primary: #d4af37;
            --bg-gradient: radial-gradient(circle at top, #1a150a 0%, #030201 100%);
            --glass-bg: rgba(15, 12, 8, 0.88);
            --glass-border: rgba(212, 175, 55, 0.15);
            --text-main: #f3e5ab;
            --text-muted: #bfa15f;
        }

        [data-theme="light"] {
            --primary: #2563eb;
            --bg-gradient: radial-gradient(circle at top, #f8fafc 0%, #e2e8f0 100%);
            --glass-bg: rgba(255, 255, 255, 0.88);
            --glass-border: rgba(0, 0, 0, 0.08);
            --text-main: #0f172a;
            --text-muted: #475569;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-gradient);
            color: var(--text-main);
            transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
            overflow-x: hidden;
            -webkit-tap-highlight-color: transparent;
        }

        .glass {
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--glass-border);
            transition: border-color 0.4s cubic-bezier(0.16, 1, 0.3, 1), background 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .ambient-glow {
            filter: blur(160px);
            opacity: 0.25;
            transition: all 1.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .dpad-focusable {
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .dpad-focusable:focus {
            outline: 3px solid var(--primary) !important;
            transform: scale(1.03) translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(229, 9, 20, 0.35);
        }

        /* Netflix-Style Premium Startup Screen */
        #nexus-platform-loader {
            position: fixed;
            inset: 0;
            background: #000000;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            transition: opacity 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }

        .netflix-letter-glow {
            font-size: 5rem;
            font-weight: 900;
            color: #e50914;
            letter-spacing: -4px;
            text-transform: uppercase;
            position: relative;
            transform: scale(0.9);
            opacity: 0;
            filter: blur(8px);
            animation: netflixIntro 2.2s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .netflix-beam {
            position: absolute;
            bottom: 0;
            width: 4px;
            height: 0%;
            background: linear-gradient(to top, #e50914, #ff003c, transparent);
            box-shadow: 0 0 20px #e50914;
            animation: beamRise 1.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            animation-delay: 0.3s;
        }

        @keyframes netflixIntro {
            0% {
                transform: scale(0.85);
                opacity: 0;
                filter: blur(10px) drop-shadow(0 0 0px rgba(229, 9, 20, 0));
            }
            30% {
                transform: scale(1.02);
                opacity: 1;
                filter: blur(0px) drop-shadow(0 0 30px rgba(229, 9, 20, 0.7));
            }
            85% {
                transform: scale(1.05);
                opacity: 1;
                filter: blur(0px) drop-shadow(0 0 45px rgba(229, 9, 20, 0.9));
            }
            100% {
                transform: scale(2.8);
                opacity: 0;
                filter: blur(25px) drop-shadow(0 0 0px rgba(229, 9, 20, 0));
            }
        }

        @keyframes beamRise {
            0% { height: 0%; opacity: 0; }
            50% { height: 100%; opacity: 0.8; }
            100% { height: 100%; opacity: 0; }
        }

        /* Fluid 10-Bar Audio Visualizer Wave */
        .wave-bar {
            width: 4px;
            height: 4px;
            background-color: var(--primary);
            border-radius: 99px;
            animation: bounce-wave 1s ease-in-out infinite alternate;
            transition: background-color 0.4s ease, opacity 0.3s ease;
        }
        .wave-bar:nth-child(1) { animation-delay: 0.1s; animation-duration: 0.8s; }
        .wave-bar:nth-child(2) { animation-delay: 0.3s; animation-duration: 1.1s; }
        .wave-bar:nth-child(3) { animation-delay: 0.2s; animation-duration: 0.9s; }
        .wave-bar:nth-child(4) { animation-delay: 0.5s; animation-duration: 1.2s; }
        .wave-bar:nth-child(5) { animation-delay: 0.4s; animation-duration: 0.7s; }
        .wave-bar:nth-child(6) { animation-delay: 0.25s; animation-duration: 1.0s; }
        .wave-bar:nth-child(7) { animation-delay: 0.45s; animation-duration: 0.85s; }
        .wave-bar:nth-child(8) { animation-delay: 0.15s; animation-duration: 1.15s; }
        .wave-bar:nth-child(9) { animation-delay: 0.35s; animation-duration: 0.95s; }
        .wave-bar:nth-child(10) { animation-delay: 0.55s; animation-duration: 1.3s; }

        @keyframes bounce-wave {
            0% { height: 4px; transform: scaleY(1); }
            100% { height: 28px; transform: scaleY(1.2); }
        }

        /* Custom Styled Sliders */
        input[type="range"] {
            -webkit-appearance: none;
            appearance: none;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 99px;
            height: 6px;
            outline: none;
            transition: background 0.3s ease;
        }
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            height: 16px;
            width: 16px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
            box-shadow: 0 0 12px var(--primary);
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }
        input[type="range"]::-webkit-slider-thumb:hover {
            transform: scale(1.4);
        }

        /* Aspect ratio video adapters */
        .video-aspect-16-9 { aspect-ratio: 16/9; width: 100%; height: auto; object-fit: contain; }
        .video-aspect-4-3 { aspect-ratio: 4/3; width: auto; height: 100%; margin: 0 auto; object-fit: contain; }
        .video-aspect-fill { width: 100%; height: 100%; object-fit: fill; }
        .video-aspect-zoom { width: 100%; height: 100%; object-fit: cover; }

        /* Pure Auto-Hide Controls HUD Overlay */
        .player-hud-overlay {
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), cursor 0.6s ease;
        }
        .player-hud-overlay.hud-visible {
            opacity: 1;
            pointer-events: auto;
        }
        .video-container-hide-cursor {
            cursor: none !important;
        }

        /* Premium Shimmer Layout Loaders */
        .shimmer {
            background: linear-gradient(90deg, rgba(255,255,255,0.03) 25%, rgba(255,255,255,0.08) 50%, rgba(255,255,255,0.03) 75%);
            background-size: 200% 100%;
            animation: shimmerEffect 1.5s infinite;
        }
        @keyframes shimmerEffect {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        /* Premium Cinematic Grid Scale */
        .premium-hover-card {
            transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.4s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .premium-hover-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255, 255, 255, 0.15);
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.9);
        }

        /* Custom Confirmation Modal Styling (replaces alert/confirm) */
        .modal-blur {
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            background-color: rgba(0,0,0,0.85);
        }
    </style>
</head>
<body class="min-h-screen pb-24 md:pb-6 overflow-x-hidden relative text-slate-100">

    <!-- Netflix-Style Startup Intro Screen -->
    <div id="nexus-platform-loader">
        <div class="relative flex items-center justify-center">
            <div class="netflix-beam left-[-20px]"></div>
            <div class="netflix-letter-glow font-display">NEXUS</div>
            <div class="netflix-beam right-[-20px]"></div>
        </div>
    </div>

    <!-- Active Ambient Glow Backdrop Ring -->
    <div class="absolute top-0 left-1/2 -translate-x-1/2 w-[85%] h-[400px] bg-red-600 rounded-full ambient-glow pointer-events-none z-0" id="ambientLightRing"></div>

    <div class="flex min-h-screen z-10 relative">

        <!-- Sidebar Navigation (Desktop Workspace) -->
        <aside class="hidden md:flex flex-col justify-between w-72 glass border-r border-white/5 py-8 px-6 shrink-0">
            <div class="flex flex-col gap-10">
                <div class="flex items-center gap-3 px-1">
                    <div class="bg-gradient-to-tr from-red-600 to-rose-600 p-3 rounded-2xl shadow-xl shadow-red-600/30 transition-transform duration-300 hover:rotate-6">
                        <i class="fa-solid fa-satellite-dish text-2xl text-white"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-black tracking-tight bg-gradient-to-r from-white via-slate-100 to-red-400 bg-clip-text text-transparent font-display">Nexus</h1>
                        <p class="text-[9px] text-red-500 font-extrabold tracking-widest uppercase leading-none mt-1">Smart OTT Ecosystem</p>
                    </div>
                </div>

                <!-- Global Playlist Selector Dropdown -->
                <div class="flex flex-col gap-2">
                    <div class="flex items-center justify-between px-1">
                        <label class="text-[10px] text-slate-400 font-black tracking-wider uppercase">Active Playlist Profile</label>
                        <div class="flex items-center gap-1" id="m3uAutoSyncStatus" title="M3U Dynamic Engine Active">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span class="text-[8px] text-emerald-400 font-bold font-mono">LIVE-SYNC</span>
                        </div>
                    </div>
                    <div class="relative playlist-dropdown-container z-50">
                        <button onclick="toggleCustomDropdown('desktopCustomDropdown', event)" class="w-full min-h-[44px] flex items-center justify-between bg-black/50 hover:bg-black/80 border border-white/5 hover:border-white/10 rounded-2xl px-4 py-3 text-xs text-white focus:outline-none focus:border-red-600 cursor-pointer transition-all font-semibold text-left">
                            <span id="desktopSelectedPlaylistLabel" class="truncate">Loading Profile...</span>
                            <i class="fa-solid fa-chevron-down text-slate-400 text-xs"></i>
                        </button>
                        <div id="desktopCustomDropdown" class="hidden absolute top-full left-0 right-0 mt-2 bg-neutral-950 border border-white/10 rounded-2xl p-2 shadow-2xl z-[999] flex flex-col gap-1 overflow-y-auto max-h-64">
                            <?php foreach ($playlists as $pl): ?>
                                <button onclick="selectCustomPlaylistOption('<?php echo htmlspecialchars($pl['id']); ?>', '<?php echo htmlspecialchars($pl['name']); ?>', 'desktopCustomDropdown')" class="w-full min-h-[44px] text-left px-3.5 py-2.5 rounded-xl hover:bg-red-600 text-xs font-semibold text-slate-200 hover:text-white transition-all">
                                    🔴 <?php echo htmlspecialchars($pl['name']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <nav class="flex flex-col gap-2">
                    <button onclick="navigateTab('home')" id="tabBtn_home" class="nav-button active flex items-center gap-3.5 px-4 py-4 rounded-2xl text-xs font-extrabold text-white bg-red-600 transition-all shadow-lg shadow-red-600/15 dpad-focusable" tabindex="0">
                        <i class="fa-solid fa-house text-sm w-5"></i> Home Hub
                    </button>
                    <button onclick="navigateTab('player')" id="tabBtn_player" class="nav-button flex items-center gap-3.5 px-4 py-4 rounded-2xl text-xs font-bold text-slate-400 hover:text-white hover:bg-white/5 transition-all dpad-focusable" tabindex="0">
                        <i class="fa-solid fa-play text-sm w-5"></i> Live TV Engine
                    </button>
                    <button onclick="navigateTab('multiview')" id="tabBtn_multiview" class="nav-button flex items-center gap-3.5 px-4 py-4 rounded-2xl text-xs font-bold text-slate-400 hover:text-white hover:bg-white/5 transition-all dpad-focusable" tabindex="0">
                        <i class="fa-solid fa-table-cells w-5"></i> Matrix Multi-View
                    </button>
                    <button onclick="navigateTab('settings')" id="tabBtn_settings" class="nav-button flex items-center gap-3.5 px-4 py-4 rounded-2xl text-xs font-bold text-slate-400 hover:text-white hover:bg-white/5 transition-all dpad-focusable" tabindex="0">
                        <i class="fa-solid fa-gears w-5"></i> System Settings
                    </button>
                    
                    <div class="h-px bg-white/5 my-3"></div>
                    
                    <?php if ($is_authenticated): ?>
                        <button onclick="navigateTab('admin')" id="tabBtn_admin" class="nav-button flex items-center gap-3.5 px-4 py-4 rounded-2xl text-xs font-bold text-emerald-400 hover:bg-emerald-500/10 transition-all dpad-focusable" tabindex="0">
                            <i class="fa-solid fa-user-shield w-5"></i> Admin Dashboard
                        </button>
                    <?php else: ?>
                        <button onclick="triggerLoginModal()" class="flex items-center gap-3.5 px-4 py-4 rounded-2xl text-xs font-bold text-slate-400 hover:text-white hover:bg-white/5 transition-all dpad-focusable" tabindex="0">
                            <i class="fa-solid fa-lock w-5"></i> Admin Authorization
                        </button>
                    <?php endif; ?>

                    <button id="pwaInstallBtnSidebar" onclick="triggerPwaInstallEvent()" class="hidden flex items-center gap-3.5 px-4 py-4 rounded-2xl text-xs font-bold text-red-400 bg-red-600/10 hover:bg-red-600/20 border border-red-500/15 transition-all dpad-focusable" tabindex="0">
                        <i class="fa-solid fa-download text-sm w-5"></i> Install App
                    </button>
                </nav>
            </div>

            <div class="flex flex-col gap-4">
                <div class="glass p-4 rounded-2xl flex flex-col gap-2.5 text-[11px] text-slate-400">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                            <span>System Core</span>
                        </div>
                        <span class="text-xs font-black text-emerald-400 font-mono">ONLINE</span>
                    </div>
                    <div class="flex items-center justify-between border-t border-white/5 pt-2 text-[10px]">
                        <span>Telemetry Watchers</span>
                        <span class="font-bold text-white font-mono animate-pulse" id="globalTotalLiveUsers">--</span>
                    </div>
                </div>
                <div class="text-[10px] text-slate-600 text-center font-semibold">
                    &copy; 2026 Nexus OTT Enterprise.
                </div>
            </div>
        </aside>

        <!-- Main Viewport Workspace -->
        <main class="flex-1 p-4 md:p-10 overflow-y-auto max-w-7xl mx-auto w-full z-10">

            <!-- Responsive Mobile Top Bar and Custom Dropdown -->
            <div class="flex md:hidden flex-col gap-3.5 py-4 mb-6 glass px-5 rounded-2xl border-white/5 relative z-50">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="bg-red-600 p-2.5 rounded-xl">
                            <i class="fa-solid fa-satellite-dish text-white text-base"></i>
                        </div>
                        <div>
                            <h1 class="text-sm font-black text-white font-display">Nexus OTT</h1>
                            <p class="text-[8px] text-red-400 tracking-wider font-bold">Smart Live Core</p>
                        </div>
                    </div>
                    <button id="pwaInstallBtnMobile" onclick="triggerPwaInstallEvent()" class="hidden bg-red-600 hover:bg-red-700 text-white text-[10px] px-3 py-2 rounded-lg font-black uppercase transition-all">
                        Install
                    </button>
                </div>
                
                <!-- Premium Mobile Custom Dropdown -->
                <div class="relative w-full playlist-dropdown-container">
                    <button onclick="toggleCustomDropdown('mobileCustomDropdown', event)" class="w-full min-h-[44px] flex items-center justify-between bg-black/60 border border-white/10 rounded-xl px-4 py-3 text-xs text-white focus:outline-none focus:border-red-600 cursor-pointer font-semibold text-left">
                        <span id="mobileSelectedPlaylistLabel" class="truncate">Loading Playlist...</span>
                        <i class="fa-solid fa-chevron-down text-slate-400 text-xs"></i>
                    </button>
                    <div id="mobileCustomDropdown" class="hidden absolute top-full left-0 right-0 mt-2 bg-neutral-950 border border-white/10 rounded-xl p-2 shadow-2xl z-[999] flex flex-col gap-1 overflow-y-auto max-h-64">
                        <?php foreach ($playlists as $pl): ?>
                            <button onclick="selectCustomPlaylistOption('<?php echo htmlspecialchars($pl['id']); ?>', '<?php echo htmlspecialchars($pl['name']); ?>', 'mobileCustomDropdown')" class="w-full min-h-[44px] text-left px-3.5 py-3 rounded-lg hover:bg-red-600 text-xs font-semibold text-slate-200 hover:text-white transition-all">
                                📺 <?php echo htmlspecialchars($pl['name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ==========================================
                 TAB SECTION 1: HOMEPAGE HUB
                 ========================================== -->
            <section id="tab_view_home" class="tab-pane flex flex-col gap-8 animate-fade-in">

                <div class="flex flex-col gap-3">
                    <h3 class="text-xs font-black text-slate-400 tracking-wider uppercase font-display">Streaming Categories</h3>
                    <div id="ottCategoryDeckRow" class="flex gap-2.5 overflow-x-auto pb-2 scrollbar-none"></div>
                </div>

                <!-- Playback Resume Panel -->
                <div id="resumesShelfSection" class="hidden flex flex-col gap-3">
                    <h3 class="text-xs font-black text-red-500 tracking-wider uppercase flex items-center gap-1.5 font-display">
                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-ping"></span> Resuming Playbacks
                    </h3>
                    <div id="resumesShelfRow" class="flex gap-4 overflow-x-auto pb-2.5 scrollbar-none"></div>
                </div>

                <div class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-black text-slate-400 tracking-wider uppercase font-display">Trending Channels Live</h3>
                        <span onclick="navigateTab('player')" class="text-[10px] text-red-500 font-black uppercase tracking-wider cursor-pointer hover:text-red-400 transition-colors">View All Live</span>
                    </div>
                    <div id="trendingShelfRow" class="grid grid-cols-2 md:grid-cols-4 gap-5"></div>
                </div>

                <div class="flex flex-col gap-4">
                    <h3 class="text-xs font-black text-slate-400 tracking-wider uppercase font-display">Movies & Premier Shows</h3>
                    <div id="moviesShelfRow" class="grid grid-cols-2 md:grid-cols-4 gap-5"></div>
                </div>

            </section>

            <!-- ==========================================
                 TAB SECTION 2: STREAMING PLAYBACK CENTER
                 ========================================== -->
            <section id="tab_view_player" class="tab-pane hidden flex flex-col lg:flex-row gap-6 animate-fade-in">
                
                <div class="flex-1 flex flex-col gap-6">
                    
                    <!-- Advanced Cinematic Player Viewport Container -->
                    <div id="primaryVideoContainer" class="relative w-full aspect-video rounded-3xl overflow-hidden shadow-2xl bg-neutral-950 border border-white/5 group dpad-focusable" tabindex="0">
                        
                        <video id="primaryVideo" class="w-full h-full object-contain" playsinline preload="auto" crossorigin="anonymous"></video>
                        
                        <!-- Interface Lock Overlay -->
                        <div id="screenLockOverlay" class="absolute inset-0 bg-black/95 backdrop-blur-md flex flex-col items-center justify-center gap-4 z-40 hidden">
                            <i class="fa-solid fa-lock text-4xl text-red-600 animate-bounce"></i>
                            <h3 class="text-sm font-bold text-white font-sans">Interface Lock Active</h3>
                            <button onclick="toggleInterfaceLockState()" class="bg-white/10 hover:bg-white/20 border border-white/10 text-white font-bold py-2.5 px-5 rounded-2xl text-xs transition-all">
                                <i class="fa-solid fa-unlock mr-1.5"></i> Unlock Controls
                            </button>
                        </div>

                        <!-- Advanced Real-time Performance Telemetry (Stats for Nerds) -->
                        <div id="nerdPerformanceHUD" class="absolute top-4 left-4 glass border border-white/10 p-4 rounded-2xl max-w-[300px] w-full text-[10px] font-mono z-30 hidden pointer-events-none">
                            <div class="flex items-center justify-between border-b border-white/10 pb-2 mb-2">
                                <span class="text-red-500 font-bold uppercase text-[9px] tracking-widest"><i class="fa-solid fa-chart-line mr-1.5"></i> Stream Telemetry</span>
                                <span id="nerdEngineLabel" class="bg-white/10 px-2 py-0.5 rounded text-[8px] uppercase">HTML5</span>
                            </div>
                            <div class="flex flex-col gap-1.5 text-slate-300">
                                <div class="flex justify-between"><span>Resolution:</span><span id="nerdResolution" class="text-white font-bold">0x0</span></div>
                                <div class="flex justify-between"><span>FPS / Drops:</span><span id="nerdFrameRates" class="text-white font-bold">0 / 0</span></div>
                                <div class="flex justify-between"><span>Buffered Space:</span><span id="nerdBufferSecs" class="text-white font-bold">0.0s</span></div>
                                <div class="flex justify-between"><span>Bandwidth Speed:</span><span id="nerdBandwidthSpeed" class="text-white font-bold">0.0 Mbps</span></div>
                                <div class="flex justify-between"><span>Connection Type:</span><span class="text-white font-bold">HLS Adaptive</span></div>
                            </div>
                        </div>

                        <!-- Swipe Gesture Feedback HUD overlays -->
                        <div id="gestureBrightnessOverlay" class="absolute inset-y-0 left-0 w-1/4 bg-gradient-to-r from-black/50 to-transparent flex items-center justify-center pointer-events-none opacity-0 transition-opacity z-30">
                            <div class="bg-black/80 p-4 rounded-2xl border border-white/10 text-center">
                                <i class="fa-solid fa-sun text-yellow-400 text-xl block mb-1 animate-pulse"></i>
                                <span id="gestureBrightnessText" class="text-xs font-bold text-white font-mono">100%</span>
                            </div>
                        </div>

                        <div id="gestureVolumeOverlay" class="absolute inset-y-0 right-0 w-1/4 bg-gradient-to-l from-black/50 to-transparent flex items-center justify-center pointer-events-none opacity-0 transition-opacity z-30">
                            <div class="bg-black/80 p-4 rounded-2xl border border-white/10 text-center">
                                <i id="gestureVolumeIcon" class="fa-solid fa-volume-high text-red-500 text-xl block mb-1 animate-pulse"></i>
                                <span id="gestureVolumeText" class="text-xs font-bold text-white font-mono">100%</span>
                            </div>
                        </div>

                        <!-- Touch Drag Guides (Helpful visual aid for mobile users) -->
                        <div class="absolute inset-x-0 bottom-16 pointer-events-none flex justify-between px-6 opacity-0 group-hover:opacity-60 transition-opacity duration-300 md:hidden">
                            <div class="text-[9px] text-slate-400 flex items-center gap-1.5 bg-black/60 px-2 py-1 rounded-md border border-white/5"><i class="fa-solid fa-arrows-up-down text-yellow-400"></i> Brightness (Left)</div>
                            <div class="text-[9px] text-slate-400 flex items-center gap-1.5 bg-black/60 px-2 py-1 rounded-md border border-white/5">Volume (Right) <i class="fa-solid fa-arrows-up-down text-red-500"></i></div>
                        </div>

                        <div id="playerBufferingMask" class="absolute inset-0 bg-neutral-950/95 flex flex-col items-center justify-center z-30 transition-opacity duration-300">
                            <div class="relative w-20 h-20 flex items-center justify-center">
                                <span class="absolute inline-flex h-full w-full rounded-full bg-red-600/15 animate-ping"></span>
                                <div class="absolute inset-0 border-4 border-red-600/10 rounded-full"></div>
                                <div class="absolute inset-0 border-4 border-t-red-600 rounded-full animate-spin"></div>
                                <i class="fa-solid fa-satellite-dish text-xl text-red-600 animate-pulse"></i>
                            </div>
                            <p id="playerBufferingText" class="mt-4 text-[10px] font-black uppercase tracking-widest text-red-500 font-display">Mounting Core Media Engine...</p>
                        </div>

                        <!-- Player Overlay Interface HUD controls -->
                        <div id="playerControlHUD" class="player-hud-overlay absolute inset-0 bg-gradient-to-t from-black/95 via-transparent to-black/60 transition-opacity duration-300 flex flex-col justify-between p-4 z-20">
                            
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3 bg-black/60 px-4 py-2 rounded-full border border-white/5 max-w-[70%]">
                                    <span class="flex h-2 w-2 relative">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-2 w-2 bg-red-600"></span>
                                    </span>
                                    <span id="overlayActiveChTitle" class="text-xs font-extrabold text-white truncate font-display">Mount Stream Source Node</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button onclick="toggleTelemetryNerdHUD()" class="bg-black/60 hover:bg-black/80 w-[38px] h-[38px] rounded-full text-white text-xs transition-all flex items-center justify-center" title="Toggle Stats for Nerds">
                                        <i class="fa-solid fa-chart-line"></i>
                                    </button>
                                    <button onclick="toggleHotkeysCheatsheet()" class="bg-black/60 hover:bg-black/80 w-[38px] h-[38px] rounded-full text-white text-xs transition-all flex items-center justify-center" title="Keyboard Shortcuts Help">
                                        <i class="fa-solid fa-circle-question"></i>
                                    </button>
                                    <button onclick="toggleInterfaceLockState()" class="bg-black/60 hover:bg-black/80 w-[38px] h-[38px] rounded-full text-white text-xs transition-all flex items-center justify-center" title="Lock Controls">
                                        <i class="fa-solid fa-lock"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Player Mechanism Equipped with Skip Buttons -->
                            <div class="flex items-center justify-center gap-4 sm:gap-6">
                                <button onclick="navigateNextChannelTrack(-1)" class="w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110" title="Previous Node">
                                    <i class="fa-solid fa-backward-step text-xs"></i>
                                </button>
                                
                                <button onclick="skipPlayerTime(-10)" class="w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110" title="Rewind 10 Seconds">
                                    <i class="fa-solid fa-rotate-left text-xs"></i><span class="text-[7px] font-black ml-0.5 font-mono">10s</span>
                                </button>

                                <button onclick="togglePlayerEnginePlayState()" id="centerPlayControlAction" class="w-16 h-16 rounded-full bg-red-600 text-white flex items-center justify-center transition-all hover:scale-110 active:scale-95 shadow-lg shadow-red-600/40">
                                    <i id="centerPlayControlIcon" class="fa-solid fa-play text-xl ml-0.5"></i>
                                </button>

                                <button onclick="skipPlayerTime(10)" class="w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110" title="Forward 10 Seconds">
                                    <span class="text-[7px] font-black mr-0.5 font-mono">10s</span><i class="fa-solid fa-rotate-right text-xs"></i>
                                </button>

                                <button onclick="navigateNextChannelTrack(1)" class="w-11 h-11 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all hover:scale-110" title="Next Node">
                                    <i class="fa-solid fa-forward-step text-xs"></i>
                                </button>
                            </div>

                            <!-- Timeline and Volume Control Strip -->
                            <div class="flex flex-col gap-3">
                                <div class="w-full h-1 bg-white/10 rounded-full relative cursor-pointer overflow-hidden" id="timelineProgressBar">
                                    <div id="timelineProgressFill" class="absolute h-full bg-red-600 left-0 top-0 w-0"></div>
                                </div>

                                <div class="flex items-center justify-between text-white text-xs">
                                    <div class="flex items-center gap-4">
                                        <button onclick="togglePlayerEnginePlayState()" class="hover:text-red-500 transition-all min-h-[44px] min-w-[44px] flex items-center justify-center">
                                            <i id="controlStripPlayBtnIcon" class="fa-solid fa-pause"></i>
                                        </button>
                                        <div class="flex items-center gap-2.5 group/vol">
                                            <button onclick="toggleMuteAudioState()" class="hover:text-red-500 transition-all min-h-[44px] min-w-[44px] flex items-center justify-center">
                                                <i id="controlStripMuteIcon" class="fa-solid fa-volume-high"></i>
                                            </button>
                                            <input type="range" id="volumeControlSlider" min="0" max="1" step="0.1" value="0.8" class="w-16 sm:w-20 h-1 cursor-pointer">
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2 sm:gap-3">
                                        <!-- REAL VIDEO QUALITY LEVEL SELECTOR -->
                                        <div class="relative group/quality">
                                            <button class="text-[9px] font-black px-2.5 py-2.5 rounded bg-white/10 flex items-center gap-1.5 hover:bg-white/15 min-h-[44px]">
                                                <i class="fa-solid fa-sliders"></i> <span id="currentQualityText" class="hidden sm:inline">Auto</span>
                                            </button>
                                            <div id="qualityLevelsDropdown" class="absolute bottom-10 right-0 glass rounded-xl py-1 w-32 flex flex-col hidden group-hover/quality:flex z-30 border border-white/10 shadow-2xl">
                                                <button onclick="selectManualResolutionTarget(-1, 'Auto')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] w-full font-bold">Auto Adaptive</button>
                                                <button onclick="selectManualResolutionTarget(1080, '1080p')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] w-full font-mono font-bold">1080p FHD</button>
                                                <button onclick="selectManualResolutionTarget(720, '720p')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] w-full font-mono font-bold">720p HD</button>
                                                <button onclick="selectManualResolutionTarget(480, '480p')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] w-full font-mono font-bold">480p Medium</button>
                                                <button onclick="selectManualResolutionTarget(360, '360p')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] w-full font-mono font-bold">360p Low</button>
                                            </div>
                                        </div>

                                        <!-- CINEMATIC ASPECT RATIO SELECTOR -->
                                        <div class="relative group/aspect">
                                            <button class="text-[9px] font-black px-2.5 py-2.5 rounded bg-white/10 flex items-center gap-1.5 hover:bg-white/15 min-h-[44px]" title="Aspect Ratio">
                                                <i class="fa-solid fa-crop"></i> <span id="currentAspectText" class="hidden sm:inline">16:9</span>
                                            </button>
                                            <div class="absolute bottom-10 right-0 glass rounded-xl py-1 w-28 flex flex-col hidden group-hover/aspect:flex z-30 border border-white/10 shadow-2xl">
                                                <button onclick="setCinematicAspect('16-9')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] font-bold">16:9 Wide</button>
                                                <button onclick="setCinematicAspect('4-3')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] font-bold">4:3 TV</button>
                                                <button onclick="setCinematicAspect('fill')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] font-bold">Stretch Fill</button>
                                                <button onclick="setCinematicAspect('zoom')" class="px-3 py-2.5 text-left hover:bg-red-600 text-[10px] font-bold">Immersive Zoom</button>
                                            </div>
                                        </div>

                                        <!-- SCREENSHOT TOOL -->
                                        <button onclick="captureCurrentVideoFrame()" class="hover:text-red-500 transition-all px-1 min-h-[44px] min-w-[34px] flex items-center justify-center" title="Capture Screenshot">
                                            <i class="fa-solid fa-camera"></i>
                                        </button>

                                        <button onclick="openSleepTimerOverlay()" class="hover:text-red-500 transition-all min-h-[44px] min-w-[34px] flex items-center justify-center" title="Sleep Timer"><i class="fa-solid fa-clock-rotate-left"></i></button>
                                        <button onclick="triggerMiniPlayerPiP()" class="hover:text-red-500 transition-all min-h-[44px] min-w-[34px] flex items-center justify-center" title="PiP Mode"><i class="fa-solid fa-square-rss"></i></button>
                                        <button onclick="triggerPlayerFullscreen()" class="hover:text-red-500 transition-all min-h-[44px] min-w-[34px] flex items-center justify-center" title="Fullscreen Landscape"><i class="fa-solid fa-expand"></i></button>
                                    </div>
                                </div>
                            </div>

                        </div>

                    </div>

                    <!-- Active Channel details, Live Visualizer and Bookmark options -->
                    <div class="glass rounded-3xl p-6 flex flex-col md:flex-row items-center justify-between gap-4 relative overflow-hidden">
                        
                        <div class="flex items-center gap-4 w-full md:w-[70%]">
                            <div class="w-16 h-16 rounded-2xl bg-neutral-950 p-3 border border-white/5 flex items-center justify-center shrink-0">
                                <img id="chActiveDisplayLogo" src="https://imgur.com/79g2kMA.png" alt="Logo" class="w-full h-full object-contain" onerror="this.src='https://imgur.com/79g2kMA.png'">
                            </div>
                            <div class="truncate">
                                <h2 id="chActiveDisplayName" class="text-lg font-black truncate text-white">Select a live TV channel</h2>
                                <div class="flex flex-wrap items-center gap-3 mt-1.5">
                                    <p id="chActiveDisplayCategory" class="text-[10px] text-red-500 font-black uppercase tracking-wider leading-none">Standby operational mode</p>
                                    <div class="flex items-center gap-1.5 bg-red-600/10 px-2.5 py-1 rounded border border-red-500/10">
                                        <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
                                        <span class="text-[9px] text-red-400 font-black font-mono" id="liveViewersCounterWidget">0 Watching</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 10-Bar Advanced Equalizer Visualizer -->
                        <div class="flex items-end gap-[3px] px-4 py-2.5 h-10 bg-white/[0.03] border border-white/5 rounded-2xl shrink-0" id="audioVisualizerWrapper">
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                            <span class="wave-bar animate-paused"></span>
                        </div>

                        <div class="flex items-center gap-2.5 w-full md:w-auto font-display">
                            <button id="favoriteToggleActionBtn" onclick="toggleActiveFavoriteState()" class="flex-1 md:flex-none glass min-h-[44px] px-5 py-3 rounded-2xl text-[11px] font-black uppercase hover:bg-red-500/20 transition-all flex items-center justify-center gap-2 dpad-focusable" tabindex="0">
                                <i class="fa-regular fa-heart"></i> Bookmark
                            </button>
                            <button onclick="copyChannelShareLink()" class="glass min-h-[44px] min-w-[44px] flex items-center justify-center rounded-2xl hover:text-red-500 hover:border-red-500/20 transition-all text-sm dpad-focusable" tabindex="0" title="Copy Channel Link">
                                <i class="fa-solid fa-share-nodes"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Interactive EPG Tracker Guide -->
                    <div class="glass rounded-3xl p-6 flex flex-col gap-4">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest font-display">Interactive EPG Guide Tracker</h3>
                        <div class="flex flex-col md:flex-row items-center justify-between gap-4 bg-black/45 p-5 rounded-2xl border border-white/5">
                            <div class="flex-1">
                                <h4 class="text-xs font-extrabold text-white font-display" id="epgTrackedShowTitle">Loading broadcast schedule updates...</h4>
                                <p class="text-[10px] text-slate-400 mt-1 font-mono" id="epgTrackedShowTime">--:-- - --:--</p>
                                <p class="text-[11px] text-slate-500 mt-2 font-sans" id="epgTrackedShowDesc">Synchronizing dynamic schedule matrix with global broadcast stations...</p>
                            </div>
                            <div class="w-full md:w-1/3">
                                <div class="flex justify-between text-[9px] font-bold text-slate-400 mb-1.5 font-sans">
                                    <span>Program Progress</span>
                                    <span id="epgTrackedShowProgressPercent" class="font-mono">0%</span>
                                </div>
                                <div class="w-full h-1 bg-white/10 rounded-full overflow-hidden">
                                    <div class="h-full bg-red-600 w-0 transition-all duration-1000" id="epgTrackedShowProgressBar"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Side Deck Panel -->
                <div class="w-full lg:w-80 shrink-0 flex flex-col gap-6">
                    
                    <div class="glass rounded-3xl p-5 flex flex-col gap-4">
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400">
                                <i class="fa-solid fa-magnifying-glass text-xs"></i>
                            </span>
                            <input type="text" id="channelsSearchBar" onkeyup="filterChannelsInPlayerDeck()" placeholder="Search channels..." class="w-full pl-11 pr-4 py-3.5 bg-black/50 border border-white/5 rounded-2xl text-xs text-white placeholder-slate-500 focus:outline-none focus:border-red-600 transition-all font-sans min-h-[44px]">
                        </div>
                    </div>

                    <div class="glass rounded-3xl p-5 flex flex-col gap-3">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest font-display">Filter Category</h3>
                        <div id="playerCategoryFiltersRow" class="flex flex-wrap gap-1.5"></div>
                    </div>

                    <div class="glass rounded-3xl p-5 flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest font-display">TV Streams (<span id="playerDeckChannelsCount" class="font-mono">0</span>)</h3>
                        </div>
                        <div id="playerDeckChannelsScroller" class="flex flex-col gap-2 max-h-[420px] overflow-y-auto pr-1"></div>
                    </div>

                </div>

            </section>

            <!-- ==========================================
                 TAB SECTION 3: MULTI-VIEW BROADCAST MATRIX
                 ========================================== -->
            <section id="tab_view_multiview" class="tab-pane hidden flex flex-col gap-6 animate-fade-in">
                <div class="glass rounded-3xl p-6 flex flex-col md:flex-row items-center justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-black text-white font-display">Multi-View Monitoring Deck</h2>
                        <p class="text-xs text-slate-400 font-sans">Monitor and view multiple live streams concurrently in professional grid setups.</p>
                    </div>
                    <div class="flex bg-black/50 p-1.5 rounded-2xl border border-white/5 font-display gap-1">
                        <button onclick="arrangeMultiViewLayout(2)" class="px-4 py-2.5 text-xs font-bold rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-all dpad-focusable min-h-[44px]" tabindex="0">Dual</button>
                        <button onclick="arrangeMultiViewLayout(4)" class="px-4 py-2.5 text-xs font-bold rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-all dpad-focusable" tabindex="0">Quad</button>
                        <button onclick="arrangeMultiViewLayout(6)" class="px-4 py-2.5 text-xs font-bold rounded-xl text-slate-400 hover:text-white hover:bg-white/5 transition-all dpad-focusable" tabindex="0">6 View</button>
                    </div>
                </div>

                <div id="multiViewGridArea" class="grid grid-cols-2 gap-4 w-full aspect-video bg-black/60 rounded-3xl p-4 border border-white/5 overflow-hidden">
                </div>
            </section>

            <!-- ==========================================
                 TAB SECTION 4: PLATFORM SETTINGS CORE RIG
                 ========================================== -->
            <section id="tab_view_settings" class="tab-pane hidden flex flex-col gap-8 animate-fade-in">
                <div class="max-w-2xl mx-auto w-full flex flex-col gap-6">

                    <!-- M3U Automatic Sync & Hot-Reload Engine Integration -->
                    <div class="glass rounded-3xl p-6 border border-white/10 relative overflow-hidden">
                        <div class="absolute top-0 right-0 p-4">
                            <span class="flex h-2 w-2 relative">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                            </span>
                        </div>
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-2 flex items-center gap-2 font-display">
                            <i class="fa-solid fa-arrows-rotate text-emerald-500 animate-spin-slow"></i> Hot-Reload Cache Sync Controller
                        </h3>
                        <p class="text-xs text-slate-400 mb-4 font-sans">
                            The core parser checks M3U files dynamically. Whenever local configuration files or remote URLs are modified, the caching core automatically invalidates outdated nodes on the fly.
                        </p>
                        
                        <div class="bg-black/40 border border-white/5 rounded-2xl p-4 mb-4 text-xs flex flex-col gap-2.5 font-mono">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400">Target Source:</span>
                                <span id="syncTelemetrySource" class="text-slate-200 text-right truncate max-w-[200px] sm:max-w-xs">Connecting...</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400">File Update Handshake:</span>
                                <span class="text-slate-200">Enabled (Automatic)</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-slate-400">Cache Handshake Timestamp:</span>
                                <span id="syncTelemetryTime" class="text-emerald-400 font-bold">Synchronizing...</span>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row gap-3">
                            <button onclick="triggerImmediateM3UForceReload()" class="flex-1 px-5 py-3.5 bg-red-600 hover:bg-red-500 text-white font-extrabold rounded-2xl text-xs uppercase tracking-wider transition-all shadow-lg shadow-red-600/20 flex items-center justify-center gap-2 min-h-[44px]">
                                <i class="fa-solid fa-rotate"></i> Force Reload Core Cache
                            </button>
                        </div>
                    </div>
                    
                    <div class="glass rounded-3xl p-6">
                        <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-2 flex items-center gap-2 font-display">
                            <i class="fa-solid fa-palette text-red-500"></i> Platform Theme & Aesthetic Skin
                        </h3>
                        <p class="text-xs text-slate-400 mb-4 font-sans">Select and compile default OTT UI skin variations matching your physical setup.</p>
                        
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <button onclick="applySystemThemeSkin('netflix')" class="theme-option-btn p-4 rounded-2xl glass hover:border-red-600 text-center text-xs font-bold transition-all dpad-focusable min-h-[44px]" tabindex="0">🔴 Netflix RED</button>
                            <button onclick="applySystemThemeSkin('cyberpunk')" class="theme-option-btn p-4 rounded-2xl glass hover:border-teal-400 text-center text-xs font-bold transition-all dpad-focusable min-h-[44px]" tabindex="0">🩵 Cyberpunk Neon</button>
                            <button onclick="applySystemThemeSkin('glass-gold')" class="theme-option-btn p-4 rounded-2xl glass hover:border-yellow-500 text-center text-xs font-bold transition-all dpad-focusable min-h-[44px]" tabindex="0">🟡 Luxe Gold</button>
                            <button onclick="applySystemThemeSkin('light')" class="theme-option-btn p-4 rounded-2xl glass hover:border-blue-500 text-center text-xs font-bold transition-all text-slate-700 dpad-focusable min-h-[44px]" tabindex="0">🔵 Light Mode</button>
                        </div>
                    </div>

                    <div class="glass rounded-3xl p-6 flex flex-col gap-4 font-display">
                        <h3 class="text-xs font-black text-red-500 uppercase tracking-widest flex items-center gap-2">
                            <i class="fa-solid fa-circle-exclamation"></i> Storage Maintenance Operations
                        </h3>
                        <button onclick="triggerPurgeConfirmation()" class="w-full bg-red-600/10 hover:bg-red-600/20 border border-red-500/20 text-red-400 py-3.5 rounded-2xl text-xs font-black transition-all uppercase dpad-focusable min-h-[44px]" tabindex="0">Wipe Local Datastores & Profiles</button>
                    </div>

                </div>
            </section>

            <!-- ==========================================
                 TAB SECTION 5: ADMIN PORTAL AREA
                 ========================================== -->
            <section id="tab_view_admin" class="tab-pane hidden flex flex-col gap-8 animate-fade-in">
                <div class="max-w-3xl mx-auto w-full flex flex-col gap-8">
                    
                    <div class="glass rounded-3xl p-6 border border-white/10 flex flex-col gap-6">
                        <div class="flex items-center justify-between border-b border-white/5 pb-4 font-display">
                            <div>
                                <h2 class="text-lg font-black text-white">Admin Portal Console</h2>
                                <p class="text-xs text-slate-400">Append and manage M3U playlists in the platform configuration arrays.</p>
                            </div>
                            <a href="?action=logout" class="bg-red-600/10 hover:bg-red-600/20 text-red-500 px-4 py-2 rounded-xl text-xs font-bold transition-all font-display min-h-[38px] flex items-center justify-center">Logout Session</a>
                        </div>

                        <form method="POST" class="flex flex-col gap-4">
                            <input type="hidden" name="action" value="add_playlist">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-[10px] font-black text-red-500 uppercase tracking-widest mb-1 font-display">Playlist Name</label>
                                    <input type="text" name="pl_name" required placeholder="e.g. Entertainment HD Core" class="w-full bg-black/50 border border-white/5 focus:border-red-600 outline-none rounded-2xl px-4 py-3.5 text-xs text-white transition-all font-display min-h-[44px]">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-red-500 uppercase tracking-widest mb-1 font-display">Source (M3U URL or Filename)</label>
                                    <input type="text" name="pl_source" required placeholder="https://... or raw filename.m3u" class="w-full bg-black/50 border border-white/5 focus:border-red-600 outline-none rounded-2xl px-4 py-3.5 text-xs text-white transition-all font-mono min-h-[44px]">
                                </div>
                            </div>

                            <button type="submit" class="bg-red-600 hover:bg-red-500 text-white py-3.5 rounded-2xl text-xs font-black uppercase transition-all shadow-xl shadow-red-600/10 font-display min-h-[44px]">Publish Playlist Profile</button>
                        </form>
                    </div>

                    <!-- Manage and Update existing Playlists -->
                    <div class="glass rounded-3xl p-6 border border-white/10 flex flex-col gap-4">
                        <h3 class="text-sm font-bold text-white mb-2 font-display">Modify Playlist Directory</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs border-collapse min-w-[500px]">
                                <thead>
                                    <tr class="border-b border-white/5 text-slate-400">
                                        <th class="pb-3 pl-2">Playlist Name</th>
                                        <th class="pb-3">Source Endpoint</th>
                                        <th class="pb-3 text-right pr-2">Action Operations</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($playlists as $pl): ?>
                                        <tr class="border-b border-white/5 hover:bg-white/[0.02]">
                                            <td class="py-3 pl-2 font-semibold text-white font-display"><?php echo htmlspecialchars($pl['name']); ?></td>
                                            <td class="py-3 text-slate-400 font-mono text-[10px] truncate max-w-xs"><?php echo htmlspecialchars($pl['source']); ?></td>
                                            <td class="py-3 text-right pr-2 flex items-center justify-end gap-2.5 font-display">
                                                <button onclick="openEditPlaylistDialog('<?php echo htmlspecialchars($pl['id']); ?>', '<?php echo htmlspecialchars($pl['name']); ?>', '<?php echo htmlspecialchars($pl['source']); ?>')" class="text-blue-400 hover:text-blue-300 transition-all font-bold text-[11px] min-h-[38px]"><i class="fa-solid fa-pen-to-square"></i> Edit</button>
                                                <?php if($pl['id'] !== 'playlist_default'): ?>
                                                    <button onclick="triggerDeleteConfirmation('<?php echo htmlspecialchars($pl['id']); ?>')" class="text-red-500 hover:text-red-400 transition-all font-bold text-[11px] min-h-[38px]"><i class="fa-solid fa-trash-can"></i> Delete</button>
                                                <?php else: ?>
                                                    <span class="text-slate-600 text-[10px] italic">Locked (Default)</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </section>

        </main>
    </div>

    <!-- Edit Playlist Config Modal -->
    <div id="editPlaylistConfigModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass max-w-md w-full rounded-3xl p-6 border border-white/10 shadow-2xl relative">
            <button onclick="closeEditPlaylistDialog()" class="absolute top-5 right-5 text-slate-400 hover:text-white min-h-[44px] min-w-[44px] flex items-center justify-center">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <h3 class="text-base font-black text-white mb-2 font-display">Edit Playlist Configuration</h3>
            <p class="text-xs text-slate-400 mb-6 font-sans">Change target properties and compile updates instantly into configuration arrays.</p>
            
            <form method="POST" class="flex flex-col gap-4">
                <input type="hidden" name="action" value="edit_playlist">
                <input type="hidden" id="editPlId" name="pl_id" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                
                <div>
                    <label class="block text-[10px] font-black text-red-500 uppercase tracking-widest mb-1 font-display">Playlist Name</label>
                    <input type="text" id="editPlName" name="pl_name" required class="w-full bg-black/50 border border-white/5 focus:border-red-600 outline-none rounded-2xl px-4 py-3.5 text-xs text-white transition-all font-display min-h-[44px]">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-red-500 uppercase tracking-widest mb-1 font-display">M3U File or URL</label>
                    <input type="text" id="editPlSource" name="pl_source" required class="w-full bg-black/50 border border-white/5 focus:border-red-600 outline-none rounded-2xl px-4 py-3.5 text-xs text-white transition-all font-mono min-h-[44px]">
                </div>
                
                <button type="submit" class="w-full mt-2 bg-red-600 hover:bg-red-500 text-white py-3.5 rounded-2xl text-xs font-black uppercase transition-all shadow-xl shadow-red-600/10 font-display min-h-[44px]">Save Configuration Updates</button>
            </form>
        </div>
    </div>

    <!-- Live HUD Notification Portal Toast alerts -->
    <div id="toastNotificationPortal" class="fixed bottom-24 left-1/2 -translate-x-1/2 md:bottom-8 md:left-auto md:right-8 md:translate-x-0 glass border border-red-500/20 px-5 py-3.5 rounded-2xl flex items-center gap-3 shadow-2xl z-50 transition-all duration-300 translate-y-24 opacity-0 pointer-events-none">
        <div class="w-2 h-2 rounded-full bg-red-500 animate-ping"></div>
        <p id="toastNotificationMsg" class="text-xs font-semibold text-slate-100 font-display"></p>
    </div>

    <!-- Custom Beautiful Confirmation Modal (Failsafe alert replacement) -->
    <div id="nexusConfirmModal" class="fixed inset-0 z-[10000] hidden flex items-center justify-center p-4 modal-blur">
        <div class="glass max-w-sm w-full rounded-3xl p-6 border border-white/10 shadow-2xl text-center">
            <div class="w-12 h-12 rounded-full bg-red-600/10 border border-red-500/20 flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-triangle-exclamation text-red-500 text-lg"></i>
            </div>
            <h4 class="text-base font-black text-white mb-2 font-display" id="confirmModalTitle">Are you sure?</h4>
            <p class="text-xs text-slate-400 mb-6 leading-relaxed font-sans" id="confirmModalDesc">This action might require reloading the current page session.</p>
            <div class="flex gap-3">
                <button id="confirmCancelBtn" class="flex-1 py-3 bg-white/5 hover:bg-white/10 rounded-xl text-xs font-bold text-slate-300 transition-all min-h-[44px]">Cancel</button>
                <button id="confirmSuccessBtn" class="flex-1 py-3 bg-red-600 hover:bg-red-500 rounded-xl text-xs font-bold text-white transition-all shadow-lg shadow-red-600/20 min-h-[44px]">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Sleep Timer Options Modal -->
    <div id="sleepTimerSettingsModal" class="fixed inset-0 bg-black/85 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass max-w-sm w-full rounded-3xl p-6 text-center border border-white/10 shadow-2xl relative font-display">
            <button onclick="closeSleepTimerOverlay()" class="absolute top-5 right-5 text-slate-400 hover:text-white min-h-[44px] min-w-[44px] flex items-center justify-center">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <h3 class="text-base font-black text-white mb-2">Schedule Auto Sleep Shutdown</h3>
            <p class="text-xs text-slate-400 mb-6 leading-relaxed font-sans">Turn off media players automatically after selected duration intervals.</p>
            
            <div class="grid grid-cols-3 gap-3 mb-6 font-mono">
                <button onclick="scheduleSleepTimerShutdown(15)" class="p-3 bg-white/5 hover:bg-red-600 rounded-xl text-xs font-extrabold transition-all min-h-[44px]">15 Mins</button>
                <button onclick="scheduleSleepTimerShutdown(30)" class="p-3 bg-white/5 hover:bg-red-600 rounded-xl text-xs font-extrabold transition-all min-h-[44px]">30 Mins</button>
                <button onclick="scheduleSleepTimerShutdown(60)" class="p-3 bg-white/5 hover:bg-red-600 rounded-xl text-xs font-extrabold transition-all min-h-[44px]">1 Hour</button>
            </div>
            
            <button onclick="disableActiveSleepTimer()" class="w-full bg-white/10 hover:bg-white/15 py-3 rounded-xl text-xs font-bold transition-all text-slate-300 min-h-[44px]">Disable Timers</button>
        </div>
    </div>

    <!-- Interactive Help and Keyboard Shortcuts Cheatsheet Modal -->
    <div id="hotkeysCheatsheetModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass max-w-md w-full rounded-3xl p-6 border border-white/10 shadow-2xl relative">
            <button onclick="toggleHotkeysCheatsheet()" class="absolute top-5 right-5 text-slate-400 hover:text-white min-h-[44px] min-w-[44px] flex items-center justify-center">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <h3 class="text-lg font-black text-white mb-2 font-display"><i class="fa-solid fa-keyboard text-red-500 mr-1.5"></i> Playback Control Hotkeys</h3>
            <p class="text-xs text-slate-400 mb-6 font-sans">Navigate the console workspace quickly using high-end keyboard shortcuts.</p>
            
            <div class="flex flex-col gap-3 font-mono text-xs">
                <div class="flex justify-between items-center bg-white/[0.02] p-2.5 rounded-xl border border-white/5">
                    <span class="text-slate-300">Play / Pause stream</span>
                    <span class="bg-white/10 px-2 py-0.5 rounded text-white font-bold font-mono">Space</span>
                </div>
                <div class="flex justify-between items-center bg-white/[0.02] p-2.5 rounded-xl border border-white/5">
                    <span class="text-slate-300">Skip forward / Back 10s</span>
                    <span class="bg-white/10 px-2 py-0.5 rounded text-white font-bold font-mono">← / →</span>
                </div>
                <div class="flex justify-between items-center bg-white/[0.02] p-2.5 rounded-xl border border-white/5">
                    <span class="text-slate-300">Increase / Decrease volume</span>
                    <span class="bg-white/10 px-2 py-0.5 rounded text-white font-bold font-mono">↑ / ↓</span>
                </div>
                <div class="flex justify-between items-center bg-white/[0.02] p-2.5 rounded-xl border border-white/5">
                    <span class="text-slate-300">Toggle Fullscreen mode</span>
                    <span class="bg-white/10 px-2 py-0.5 rounded text-white font-bold font-mono">F</span>
                </div>
                <div class="flex justify-between items-center bg-white/[0.02] p-2.5 rounded-xl border border-white/5">
                    <span class="text-slate-300">Mute / Unmute audio</span>
                    <span class="bg-white/10 px-2 py-0.5 rounded text-white font-bold font-mono">M</span>
                </div>
                <div class="flex justify-between items-center bg-white/[0.02] p-2.5 rounded-xl border border-white/5">
                    <span class="text-slate-300">Toggle Stats for Nerds</span>
                    <span class="bg-white/10 px-2 py-0.5 rounded text-white font-bold font-mono">S</span>
                </div>
                <div class="flex justify-between items-center bg-white/[0.02] p-2.5 rounded-xl border border-white/5">
                    <span class="text-slate-300">Toggle Shortcuts Menu</span>
                    <span class="bg-white/10 px-2 py-0.5 rounded text-white font-bold font-mono">?</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Admin Portal Verification Form Modal -->
    <div id="adminPortalAccessModal" class="fixed inset-0 bg-black/90 backdrop-blur-md z-50 flex items-center justify-center p-4 hidden">
        <div class="glass max-w-sm w-full rounded-3xl p-6 border border-white/10 shadow-2xl relative">
            <button onclick="closeLoginModal()" class="absolute top-5 right-5 text-slate-400 hover:text-white min-h-[44px] min-w-[44px] flex items-center justify-center">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <h3 class="text-lg font-black text-white mb-2 font-display">System Administrator Authentication</h3>
            <p class="text-xs text-slate-400 mb-6 font-sans">Provide administrative credentials to open management nodes.</p>
            
            <form method="POST" class="flex flex-col gap-4">
                <input type="hidden" name="action" value="login">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <div>
                    <label class="block text-[10px] font-black text-red-500 uppercase tracking-widest mb-1 font-display">Username</label>
                    <input type="text" name="username" required placeholder="admin" class="w-full bg-black/50 border border-white/5 focus:border-red-600 outline-none rounded-2xl px-4 py-3.5 text-xs text-white transition-all font-display min-h-[44px]">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-red-500 uppercase tracking-widest mb-1 font-display">Passcode</label>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full bg-black/50 border border-white/5 focus:border-red-600 outline-none rounded-2xl px-4 py-3.5 text-xs text-white transition-all font-mono min-h-[44px]">
                </div>
                <button type="submit" class="w-full mt-2 bg-red-600 hover:bg-red-500 text-white py-3.5 rounded-2xl text-xs font-black uppercase transition-all shadow-xl shadow-red-600/10 font-display min-h-[44px]">Authorize Session</button>
            </form>
        </div>
    </div>

    <script>
        let runtimeActivePlaylists = [];
        let currentPlaylistId = "";
        let activeUiSkinName = "netflix";
        let activeFilterCategoryName = "All Channels";
        let shutdownSleepTimerRef = null;
        let deferredPwaInstallPrompt = null;
        let viewerIntervalRef = null;
        let globalViewerIntervalRef = null;
        let telemetriesIntervalRef = null;

        // Player configurations
        let hlsEngineInstance = null;
        let dashEngineInstance = null;
        let activeChannelObject = null;

        // Stats tracking
        let playbackBitrateBytes = 0;
        let frameDropCheckIntervalRef = null;

        // Inactivity Overlay Timer Variables
        let hudActivityTimerRef = null;
        const videoWrapperNode = document.getElementById('primaryVideoContainer');
        const hudOverlayNode = document.getElementById('playerControlHUD');

        // Gesture positions trackers
        let touchStartXPosition = 0;
        let touchStartYPosition = 0;
        let originalStartingBrightness = 1.0;
        let originalStartingVolume = 0.8;

        const mainVideoNode = document.getElementById('primaryVideo');
        const primaryBufferLoaderNode = document.getElementById('playerBufferingMask');
        const primaryBufferLoaderTextNode = document.getElementById('playerBufferingText');

        // Startup hooks and dynamic initializations
        window.addEventListener('load', () => {
            setTimeout(() => {
                const loader = document.getElementById('nexus-platform-loader');
                if (loader) {
                    loader.style.opacity = '0';
                    setTimeout(() => loader.remove(), 800);
                }
            }, 2400); // Realistic, premium-length Netflix loading feel

            initializeLocalStoreParams();
            setupDpadNavigationTriggers();
            registerTouchSwipeListeners();
            setupPwaInstallationEngine();
            initializeViewerCountSimulation();
            initializeGlobalTrafficCounters();
            setupGlobalKeyboardShortcuts();
            setupHUDVisibilityListeners();

            // Setup Event Listener to Close Dropdowns on outside click
            window.addEventListener('click', (e) => {
                if (!e.target.closest('.playlist-dropdown-container')) {
                    document.getElementById('desktopCustomDropdown').classList.add('hidden');
                    document.getElementById('mobileCustomDropdown').classList.add('hidden');
                }
            });

            // Load last active playlist from memory, or fallback to first option from compiled list
            const playlistOptions = <?php echo json_encode($playlists, JSON_UNESCAPED_SLASHES); ?>;
            let rememberedPlaylist = localStorage.getItem('nexus_last_playlist_id');
            
            const exists = playlistOptions.some(pl => pl.id === rememberedPlaylist);
            if (!exists && playlistOptions.length > 0) {
                rememberedPlaylist = playlistOptions[0].id;
            }

            if (rememberedPlaylist) {
                const matched = playlistOptions.find(p => p.id === rememberedPlaylist);
                updateDropdownLabels(rememberedPlaylist, matched ? matched.name : "Default Feed");
                switchBroadcastingPlaylist(rememberedPlaylist);
            } else if (playlistOptions.length > 0) {
                updateDropdownLabels(playlistOptions[0].id, playlistOptions[0].name);
                switchBroadcastingPlaylist(playlistOptions[0].id);
            }

            // Handle direct admin view query
            <?php if ($is_authenticated && isset($_GET['view']) && $_GET['view'] === 'admin'): ?>
                navigateTab('admin');
            <?php endif; ?>
        });

        // Custom replacement for window.confirm
        function showCustomConfirm(title, message, callback) {
            const modal = document.getElementById('nexus-platform-loader') ? document.body : document.getElementById('nexusConfirmModal');
            const overlay = document.getElementById('nexusConfirmModal');
            document.getElementById('confirmModalTitle').innerText = title;
            document.getElementById('confirmModalDesc').innerText = message;
            overlay.classList.remove('hidden');

            const successBtn = document.getElementById('confirmSuccessBtn');
            const cancelBtn = document.getElementById('confirmCancelBtn');

            const cleanUp = () => {
                overlay.classList.add('hidden');
            };

            successBtn.onclick = () => {
                cleanUp();
                callback(true);
            };

            cancelBtn.onclick = () => {
                cleanUp();
                callback(false);
            };
        }

        // ---------------------------------------------------------------------
        // DYNAMIC HUD AUTO-HIDE ENGINE (5-SECOND SLEEP TIMER)
        // ---------------------------------------------------------------------
        function setupHUDVisibilityListeners() {
            const showHUDControls = () => {
                hudOverlayNode.classList.add('hud-visible');
                videoWrapperNode.classList.remove('video-container-hide-cursor');
                resetHUDTimerCountdown();
            };

            videoWrapperNode.addEventListener('mousemove', showHUDControls);
            videoWrapperNode.addEventListener('click', showHUDControls);
            videoWrapperNode.addEventListener('touchstart', showHUDControls);

            showHUDControls();
        }

        function resetHUDTimerCountdown() {
            clearTimeout(hudActivityTimerRef);
            if (mainVideoNode.paused) return;

            hudActivityTimerRef = setTimeout(() => {
                hudOverlayNode.classList.remove('hud-visible');
                videoWrapperNode.classList.add('video-container-hide-cursor');
            }, 5000); 
        }

        // Telemetry stats tracker panel
        function toggleTelemetryNerdHUD() {
            const hud = document.getElementById('nerdPerformanceHUD');
            hud.classList.toggle('hidden');
            if (!hud.classList.contains('hidden')) {
                startTelemetryEngineMetrics();
            } else {
                clearInterval(telemetriesIntervalRef);
            }
        }

        function startTelemetryEngineMetrics() {
            clearInterval(telemetriesIntervalRef);
            telemetriesIntervalRef = setInterval(() => {
                document.getElementById('nerdResolution').innerText = `${mainVideoNode.videoWidth} x ${mainVideoNode.videoHeight}`;

                let bufferedEnd = 0;
                for (let i = 0; i < mainVideoNode.buffered.length; i++) {
                    if (mainVideoNode.currentTime >= mainVideoNode.buffered.start(i) && mainVideoNode.currentTime <= mainVideoNode.buffered.end(i)) {
                        bufferedEnd = mainVideoNode.buffered.end(i) - mainVideoNode.currentTime;
                    }
                }
                document.getElementById('nerdBufferSecs').innerText = `${bufferedEnd.toFixed(1)}s`;

                if (mainVideoNode.getVideoPlaybackQuality) {
                    const q = mainVideoNode.getVideoPlaybackQuality();
                    document.getElementById('nerdFrameRates').innerText = `${q.totalVideoFrames % 60} fps / ${q.droppedVideoFrames} dropped`;
                } else {
                    document.getElementById('nerdFrameRates').innerText = "N/A";
                }

                let techLabel = "HTML5 Native";
                if (hlsEngineInstance) techLabel = "HLS.js Engine";
                if (dashEngineInstance) techLabel = "DASH.js Core";
                document.getElementById('nerdEngineLabel').innerText = techLabel;

                let simulatedSpeed = (Math.random() * 8.5 + 4.5).toFixed(1);
                if (hlsEngineInstance && hlsEngineInstance.bandwidth) {
                    simulatedSpeed = (hlsEngineInstance.bandwidth / 1000000).toFixed(1);
                }
                document.getElementById('nerdBandwidthSpeed').innerText = `${simulatedSpeed} Mbps`;
            }, 1000);
        }

        // Setup Global Keyboard Shortcuts
        function setupGlobalKeyboardShortcuts() {
            window.addEventListener('keydown', (e) => {
                if (["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement.tagName)) return;
                
                switch (e.code) {
                    case "Space":
                        e.preventDefault();
                        togglePlayerEnginePlayState();
                        break;
                    case "ArrowLeft":
                        e.preventDefault();
                        skipPlayerTime(-10);
                        break;
                    case "ArrowRight":
                        e.preventDefault();
                        skipPlayerTime(10);
                        break;
                    case "ArrowUp":
                        e.preventDefault();
                        adjustVideoVolumeLevel(0.1);
                        break;
                    case "ArrowDown":
                        e.preventDefault();
                        adjustVideoVolumeLevel(-0.1);
                        break;
                    case "KeyF":
                        e.preventDefault();
                        triggerPlayerFullscreen();
                        break;
                    case "KeyM":
                        e.preventDefault();
                        toggleMuteAudioState();
                        break;
                    case "KeyS":
                        e.preventDefault();
                        toggleTelemetryNerdHUD();
                        break;
                    case "Slash":
                        e.preventDefault();
                        toggleHotkeysCheatsheet();
                        break;
                }
            });
        }

        function toggleHotkeysCheatsheet() {
            document.getElementById('hotkeysCheatsheetModal').classList.toggle('hidden');
        }

        function adjustVideoVolumeLevel(amount) {
            let activeVol = Math.min(Math.max(mainVideoNode.volume + amount, 0.0), 1.0);
            mainVideoNode.volume = activeVol;
            document.getElementById('volumeControlSlider').value = activeVol;
            mainVideoNode.muted = (activeVol === 0);
            document.getElementById('controlStripMuteIcon').className = mainVideoNode.muted ? "fa-solid fa-volume-xmark text-red-500" : "fa-solid fa-volume-high";
            localStorage.setItem('nexus_playback_volume', activeVol);
            showToastMessageHUD(`Volume: ${Math.round(activeVol * 100)}%`);
        }

        // Simulates dynamic live watcher statistics
        function initializeViewerCountSimulation() {
            const widget = document.getElementById('liveViewersCounterWidget');
            
            const updateViewerCount = () => {
                if (!activeChannelObject) {
                    widget.innerText = '0 Watching';
                    return;
                }
                let channelWeight = 0;
                for (let i = 0; i < activeChannelObject.name.length; i++) {
                    channelWeight += activeChannelObject.name.charCodeAt(i);
                }
                const baseScale = (channelWeight % 250) + 150; 
                const noise = Math.floor(Math.sin(Date.now() / 15000) * (baseScale * 0.15));
                const viewersCount = baseScale + noise;
                widget.innerText = `${viewersCount.toLocaleString()} Watching`;
            };

            viewerIntervalRef = setInterval(updateViewerCount, 4000);
            updateViewerCount();
        }

        // Simulates aggregated platform network traffic counters
        function initializeGlobalTrafficCounters() {
            const globalCounter = document.getElementById('globalTotalLiveUsers');
            
            const updateGlobalCounter = () => {
                const baseOnline = 36240;
                const noise = Math.floor(Math.sin(Date.now() / 30000) * 1900);
                const variance = Math.floor(Math.random() * 60) - 30;
                const total = baseOnline + noise + variance;
                globalCounter.innerText = total.toLocaleString();
            };

            globalViewerIntervalRef = setInterval(updateGlobalCounter, 6000);
            updateGlobalCounter();
        }

        // Register progressive web app
        function setupPwaInstallationEngine() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('data:text/javascript;base64,c2VsZi5hZGRFdmVudExpc3RlbmVyKCdmaXRjaCcsIGV2ZW50ID0+IHt9KTs=');
            }

            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPwaInstallPrompt = e;
                
                document.getElementById('pwaInstallBtnSidebar').classList.remove('hidden');
                document.getElementById('pwaInstallBtnMobile').classList.remove('hidden');
            });

            window.addEventListener('appinstalled', () => {
                deferredPwaInstallPrompt = null;
                document.getElementById('pwaInstallBtnSidebar').classList.add('hidden');
                document.getElementById('pwaInstallBtnMobile').classList.add('hidden');
                showToastMessageHUD("Nexus Premium OTT App installed successfully!");
            });
        }

        function triggerPwaInstallEvent() {
            if (deferredPwaInstallPrompt) {
                deferredPwaInstallPrompt.prompt();
                deferredPwaInstallPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        showToastMessageHUD("Initiating app installation...");
                    }
                    deferredPwaInstallPrompt = null;
                });
            }
        }

        function updateDropdownLabels(id, name) {
            document.getElementById('desktopSelectedPlaylistLabel').innerText = name;
            document.getElementById('mobileSelectedPlaylistLabel').innerText = name;
        }

        function toggleCustomDropdown(dropdownId, event) {
            event.stopPropagation();
            const dropdown = document.getElementById(dropdownId);
            dropdown.classList.toggle('hidden');
        }

        function selectCustomPlaylistOption(playlistId, playlistName, dropdownId) {
            document.getElementById(dropdownId).classList.add('hidden');
            updateDropdownLabels(playlistId, playlistName);
            switchBroadcastingPlaylist(playlistId);
        }

        // Fetch playlist streams asynchronously via auto-invalidating parser
        async function switchBroadcastingPlaylist(playlistId, forceSync = false) {
            currentPlaylistId = playlistId;
            localStorage.setItem('nexus_last_playlist_id', playlistId);
            
            primaryBufferLoaderNode.style.opacity = '1';
            primaryBufferLoaderNode.style.pointerEvents = 'auto';
            primaryBufferLoaderTextNode.innerText = "Syncing Playlist Stream Nodes...";

            try {
                const syncUrl = `?action=get_playlist_channels&id=${playlistId}` + (forceSync ? `&force_sync=true` : '');
                const res = await fetch(syncUrl);
                if (!res.ok) throw new Error("HTTP Handshake failed");
                
                const responseData = await res.json();
                if (responseData.error) {
                    throw new Error(responseData.error);
                }

                runtimeActivePlaylists = responseData.channels;
                activeFilterCategoryName = "All Channels";

                compileHomepageSlices();
                compilePlayerDeckCategorySliders();
                compilePlayerDeckChannelsScroller();

                // Live Settings Telemetry Updates
                const activePlaylistMetadata = <?php echo json_encode($playlists, JSON_UNESCAPED_SLASHES); ?>.find(p => p.id === playlistId);
                if (activePlaylistMetadata) {
                    document.getElementById('syncTelemetrySource').innerText = activePlaylistMetadata.source;
                }
                document.getElementById('syncTelemetryTime').innerText = responseData.last_updated;

                // Seek stored channel on local storage to maintain autoplay
                let lastChannelName = localStorage.getItem(`nexus_last_channel_${currentPlaylistId}`);
                let targetChannel = runtimeActivePlaylists.find(ch => ch.name === lastChannelName);

                if (!targetChannel && runtimeActivePlaylists.length > 0) {
                    targetChannel = runtimeActivePlaylists[0];
                }

                if (targetChannel) {
                    mountLiveTvChannelNode(targetChannel, true);
                }

                if (responseData.auto_synced) {
                    showToastMessageHUD(`Auto-update detected! Channels refreshed.`);
                } else {
                    showToastMessageHUD(`Playlist verified and updated.`);
                }
            } catch (err) {
                showToastMessageHUD(`Failure: ${err.message}`);
                primaryBufferLoaderTextNode.innerText = "Error establishing channel connection.";
            } finally {
                primaryBufferLoaderNode.style.opacity = '0';
                primaryBufferLoaderNode.style.pointerEvents = 'none';
            }
        }

        // Explicit on-demand M3U reload trigger
        function triggerImmediateM3UForceReload() {
            if (!currentPlaylistId) return;
            showToastMessageHUD("Querying configuration source for hot updates...");
            switchBroadcastingPlaylist(currentPlaylistId, true);
        }

        function initializeLocalStoreParams() {
            activeUiSkinName = localStorage.getItem('nexus_skin_ui') || 'netflix';
            applySystemThemeSkin(activeUiSkinName);

            const savedVolume = localStorage.getItem('nexus_playback_volume');
            if (savedVolume !== null) {
                const volVal = parseFloat(savedVolume);
                mainVideoNode.volume = volVal;
                mainVideoNode.muted = (volVal === 0);
                document.getElementById('volumeControlSlider').value = volVal;
            } else {
                mainVideoNode.volume = 0.8;
                document.getElementById('volumeControlSlider').value = 0.8;
            }

            document.getElementById('controlStripMuteIcon').className = mainVideoNode.muted ? "fa-solid fa-volume-xmark text-red-500" : "fa-solid fa-volume-high";

            document.getElementById('volumeControlSlider').addEventListener('input', (e) => {
                const value = parseFloat(e.target.value);
                mainVideoNode.volume = value;
                mainVideoNode.muted = (value === 0);
                document.getElementById('controlStripMuteIcon').className = mainVideoNode.muted ? "fa-solid fa-volume-xmark text-red-500" : "fa-solid fa-volume-high";
                localStorage.setItem('nexus_playback_volume', value);
            });
        }

        function applySystemThemeSkin(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            activeUiSkinName = theme;
            localStorage.setItem('nexus_skin_ui', theme);

            document.querySelectorAll('.theme-option-btn').forEach(btn => {
                btn.classList.remove('border-red-600', 'border-teal-400', 'border-yellow-500', 'border-blue-500');
            });
            showToastMessageHUD(`Theme applied: ${theme.toUpperCase()}`);
        }

        function getWorkingChannelsFeed() {
            return [...runtimeActivePlaylists];
        }

        function navigateTab(viewId) {
            document.querySelectorAll('.tab-pane').forEach(tab => tab.classList.add('hidden'));
            document.getElementById(`tab_view_${viewId}`).classList.remove('hidden');

            document.querySelectorAll('.nav-button, .mobile-nav-btn').forEach(el => {
                el.classList.remove('active', 'bg-red-600', 'text-white', 'text-red-500');
                el.classList.add('text-slate-400');
            });

            const sideBtn = document.getElementById(`tabBtn_${viewId}`);
            const mobBtn = document.getElementById(`mobileBtn_${viewId}`);

            if (sideBtn) {
                sideBtn.classList.add('active', 'bg-red-600', 'text-white');
                sideBtn.classList.remove('text-slate-400');
            }
            if (mobBtn) {
                mobBtn.classList.add('active', 'text-red-500');
                mobBtn.classList.remove('text-slate-400');
            }

            const colors = {
                'home': '#e50914',
                'player': '#8b5cf6',
                'settings': '#3b82f6',
                'admin': '#10b981'
            };
            document.getElementById('ambientLightRing').style.backgroundColor = colors[viewId] || '#e50914';
        }

        function compileHomepageSlices() {
            const feed = getWorkingChannelsFeed();
            const categories = ["All Channels", "Favorites", ...new Set(feed.map(ch => ch.category))];
            
            const catRow = document.getElementById('ottCategoryDeckRow');
            catRow.innerHTML = '';

            categories.forEach(cat => {
                const btn = document.createElement('button');
                btn.className = "px-4.5 py-3 rounded-2xl text-[9px] font-extrabold uppercase tracking-widest whitespace-nowrap glass border border-white/5 hover:border-red-600/45 hover:bg-red-600/10 transition-all dpad-focusable premium-hover-card min-h-[44px]";
                btn.innerText = cat;
                btn.onclick = () => {
                    activeFilterCategoryName = cat;
                    navigateTab('player');
                    triggerCategoryFilterFromHome(cat);
                };
                catRow.appendChild(btn);
            });

            const trendingRow = document.getElementById('trendingShelfRow');
            const moviesRow = document.getElementById('moviesShelfRow');
            trendingRow.innerHTML = '';
            moviesRow.innerHTML = '';

            feed.slice(0, 4).forEach(ch => {
                const card = document.createElement('div');
                card.className = "glass rounded-2xl overflow-hidden relative cursor-pointer premium-hover-card aspect-[1.5/1] dpad-focusable";
                card.tabIndex = 0;
                card.innerHTML = `
                    <img src="${ch.logo}" class="absolute inset-0 w-full h-full object-cover opacity-20" onerror="this.src='https://imgur.com/79g2kMA.png'">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/95 via-black/40 to-transparent p-4 flex flex-col justify-end">
                        <span class="text-[7px] bg-red-600 text-white px-2 py-0.5 rounded uppercase self-start mb-1.5 font-bold tracking-widest font-display">Live</span>
                        <h4 class="text-xs font-bold text-white truncate font-display font-semibold">${ch.name}</h4>
                        <p class="text-[8px] text-slate-400 mt-0.5">${ch.category}</p>
                    </div>
                `;
                card.onclick = () => { mountLiveTvChannelNode(ch); navigateTab('player'); };
                trendingRow.appendChild(card);
            });

            feed.slice(4, 8).forEach(ch => {
                const card = document.createElement('div');
                card.className = "glass rounded-2xl overflow-hidden relative cursor-pointer premium-hover-card aspect-[1.5/1] dpad-focusable";
                card.tabIndex = 0;
                card.innerHTML = `
                    <img src="${ch.logo}" class="absolute inset-0 w-full h-full object-cover opacity-20" onerror="this.src='https://imgur.com/79g2kMA.png'">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/95 via-black/40 to-transparent p-4 flex flex-col justify-end">
                        <span class="text-[7px] bg-indigo-600 text-white px-2 py-0.5 rounded uppercase self-start mb-1.5 font-bold tracking-widest font-display">HD Feed</span>
                        <h4 class="text-xs font-bold text-white truncate font-display font-semibold">${ch.name}</h4>
                        <p class="text-[8px] text-slate-400 mt-0.5">${ch.category}</p>
                    </div>
                `;
                card.onclick = () => { mountLiveTvChannelNode(ch); navigateTab('player'); };
                moviesRow.appendChild(card);
            });

            compileResumesPlaylistLane();
        }

        function compileResumesPlaylistLane() {
            const shelf = document.getElementById('resumesShelfRow');
            const historyKey = `nexus_resume_${currentPlaylistId}`;
            const list = JSON.parse(localStorage.getItem(historyKey)) || [];

            if (list.length === 0) {
                document.getElementById('resumesShelfSection').classList.add('hidden');
                return;
            }

            document.getElementById('resumesShelfSection').classList.remove('hidden');
            shelf.innerHTML = '';

            list.forEach(item => {
                const ch = runtimeActivePlaylists.find(x => x.name === item.name);
                if (!ch) return;

                const card = document.createElement('button');
                card.className = "glass p-3 rounded-2xl flex items-center gap-3 shrink-0 transition-all text-left w-52 dpad-focusable premium-hover-card border-white/10 min-h-[44px]";
                card.innerHTML = `
                    <div class="w-10 h-10 rounded-xl bg-slate-950 p-1.5 flex-shrink-0">
                        <img src="${ch.logo}" class="w-full h-full object-contain" onerror="this.src='https://imgur.com/79g2kMA.png'">
                    </div>
                    <div class="truncate flex-1 font-sans">
                        <h4 class="text-[11px] font-bold text-white truncate font-display font-semibold">${ch.name}</h4>
                        <p class="text-[7px] text-red-500 font-bold uppercase mt-0.5 tracking-widest font-mono">Resume Stream</p>
                    </div>
                `;
                card.onclick = () => { mountLiveTvChannelNode(ch); navigateTab('player'); };
                shelf.appendChild(card);
            });
        }

        function triggerCategoryFilterFromHome(cat) {
            activeFilterCategoryName = cat;
            compilePlayerDeckCategorySliders();
            compilePlayerDeckChannelsScroller();
        }

        function compilePlayerDeckCategorySliders() {
            const feed = getWorkingChannelsFeed();
            const categories = ["All Channels", "Favorites", ...new Set(feed.map(ch => ch.category))];
            const deck = document.getElementById('playerCategoryFiltersRow');
            deck.innerHTML = '';

            categories.forEach(cat => {
                const btn = document.createElement('button');
                btn.className = `px-3.5 py-2 text-[8px] font-black uppercase rounded-xl transition-all dpad-focusable min-h-[38px] ${activeFilterCategoryName === cat ? 'bg-red-600 text-white font-black' : 'glass text-slate-400 hover:text-white'}`;
                btn.innerText = cat;
                btn.onclick = () => {
                    activeFilterCategoryName = cat;
                    compilePlayerDeckCategorySliders();
                    compilePlayerDeckChannelsScroller();
                };
                deck.appendChild(btn);
            });
        }

        function compilePlayerDeckChannelsScroller() {
            const scroller = document.getElementById('playerDeckChannelsScroller');
            scroller.innerHTML = '';

            const searchVal = document.getElementById('channelsSearchBar').value.toLowerCase().trim();
            const favKey = `nexus_favorites_${currentPlaylistId}`;
            const favorites = JSON.parse(localStorage.getItem(favKey)) || [];
            const feed = getWorkingChannelsFeed();

            const filtered = feed.filter(ch => {
                const matchesSearch = ch.name.toLowerCase().includes(searchVal) || ch.category.toLowerCase().includes(searchVal);
                if (activeFilterCategoryName === "All Channels") return matchesSearch;
                if (activeFilterCategoryName === "Favorites") return favorites.includes(ch.name) && matchesSearch;
                return ch.category === activeFilterCategoryName && matchesSearch;
            });

            document.getElementById('playerDeckChannelsCount').innerText = filtered.length;

            if (filtered.length === 0) {
                scroller.innerHTML = `<p class="text-[10px] text-slate-500 text-center py-6 font-bold font-sans">No matching channels found.</p>`;
                return;
            }

            filtered.forEach(ch => {
                const isPlaying = activeChannelObject && activeChannelObject.name === ch.name;
                const isFav = favorites.includes(ch.name);

                const card = document.createElement('div');
                card.className = `glass p-3 rounded-2xl flex items-center justify-between cursor-pointer transition-all hover:bg-white/5 dpad-focusable ${isPlaying ? 'border-red-500/50 bg-red-950/15 font-bold shadow-lg shadow-red-600/5' : 'border-white/5'}`;
                card.tabIndex = 0;
                card.innerHTML = `
                    <div class="flex items-center gap-3 w-[78%]">
                        <div class="w-10 h-10 rounded-xl bg-slate-950 p-1.5 shrink-0">
                            <img src="${ch.logo}" class="w-full h-full object-contain" onerror="this.src='https://imgur.com/79g2kMA.png'">
                        </div>
                        <div class="truncate flex-1 font-display">
                            <h4 class="text-[11px] font-bold text-white flex items-center gap-1.5 truncate font-semibold">
                                ${ch.name}
                                ${isPlaying ? '<span class="flex h-1.5 w-1.5 relative"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400"></span><span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-red-600"></span></span>' : ''}
                            </h4>
                            <p class="text-[8px] text-slate-400 font-bold mt-0.5 uppercase tracking-wide font-sans">${ch.category}</p>
                        </div>
                    </div>
                    <button class="favorite-track-action-btn p-1.5 text-slate-500 hover:text-red-500 transition-colors min-h-[44px] min-w-[44px] flex items-center justify-center" data-name="${ch.name}">
                        <i class="${isFav ? 'fa-solid text-red-500' : 'fa-regular'} fa-heart text-[12px]"></i>
                    </button>
                `;

                card.onclick = (e) => {
                    if (e.target.closest('.favorite-track-action-btn')) {
                        toggleSpecificFavoriteChannel(ch.name);
                        e.stopPropagation();
                        return;
                    }
                    mountLiveTvChannelNode(ch);
                };

                scroller.appendChild(card);
            });
        }

        function executeUnmutedAutoPlayback() {
            const playPromise = mainVideoNode.play();
            if (playPromise !== undefined) {
                playPromise.then(() => {
                    document.getElementById('controlStripPlayBtnIcon').className = "fa-solid fa-pause";
                    document.getElementById('centerPlayControlIcon').className = "fa-solid fa-pause text-lg";
                    toggleEqualizerVisualizationState(true);
                }).catch(() => {
                    mainVideoNode.muted = true;
                    document.getElementById('controlStripMuteIcon').className = "fa-solid fa-volume-xmark text-red-500";
                    mainVideoNode.play().then(() => {
                        showToastMessageHUD("Autoplay muted by browser policy. Unmute manually.");
                        document.getElementById('controlStripPlayBtnIcon').className = "fa-solid fa-pause";
                        document.getElementById('centerPlayControlIcon').className = "fa-solid fa-pause text-lg";
                        toggleEqualizerVisualizationState(true);
                    });
                });
            }
        }

        function setCinematicAspect(aspectType) {
            const player = document.getElementById('primaryVideo');
            player.className = ''; 

            if (aspectType === '16-9') {
                player.classList.add('video-aspect-16-9');
                document.getElementById('currentAspectText').innerText = '16:9';
            } else if (aspectType === '4-3') {
                player.classList.add('video-aspect-4-3');
                document.getElementById('currentAspectText').innerText = '4:3';
            } else if (aspectType === 'fill') {
                player.classList.add('video-aspect-fill');
                document.getElementById('currentAspectText').innerText = 'Fill';
            } else if (aspectType === 'zoom') {
                player.classList.add('video-aspect-zoom');
                document.getElementById('currentAspectText').innerText = 'Zoom';
            }
            showToastMessageHUD(`Aspect ratio configured: ${aspectType.replace('-', ':')}`);
        }

        function toggleEqualizerVisualizationState(active) {
            const waveBars = document.querySelectorAll('.wave-bar');
            waveBars.forEach(bar => {
                if (active) {
                    bar.classList.remove('animate-paused');
                    bar.style.opacity = '1';
                } else {
                    bar.classList.add('animate-paused');
                    bar.style.opacity = '0.35';
                }
            });
        }

        function captureCurrentVideoFrame() {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = mainVideoNode.videoWidth || 1280;
                canvas.height = mainVideoNode.videoHeight || 720;
                const context = canvas.getContext('2d');
                context.drawImage(mainVideoNode, 0, 0, canvas.width, canvas.height);
                
                const dataURL = canvas.toDataURL('image/png');
                const link = document.createElement('a');
                link.download = `Nexus_Screenshot_${Date.now()}.png`;
                link.href = dataURL;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showToastMessageHUD("Screenshot downloaded successfully!");
            } catch (err) {
                showToastMessageHUD("Cross-origin security policies apply to this stream.");
            }
        }

        // ---------------------------------------------------------------------
        // MANUAL RESOLUTION OVERRIDE ENGAGEMENTS (1080p, 720p, 480p, 360p, Auto)
        // ---------------------------------------------------------------------
        function selectManualResolutionTarget(targetHeight, label) {
            document.getElementById('currentQualityText').innerText = label;
            localStorage.setItem('nexus_manual_resolution_target', targetHeight);

            if (!hlsEngineInstance) {
                showToastMessageHUD(`Resolution tier updated: ${label}`);
                return;
            }

            if (targetHeight === -1) {
                hlsEngineInstance.currentLevel = -1;
                showToastMessageHUD("Dynamic Adaptive Quality active.");
                return;
            }

            let closestIndex = -1;
            let minimumDelta = Infinity;

            hlsEngineInstance.levels.forEach((lvl, idx) => {
                const currentHeight = lvl.height || 0;
                const delta = Math.abs(currentHeight - targetHeight);
                if (delta < minimumDelta) {
                    minimumDelta = delta;
                    closestIndex = idx;
                }
            });

            if (closestIndex !== -1) {
                hlsEngineInstance.currentLevel = closestIndex;
                const matchedLevelHeight = hlsEngineInstance.levels[closestIndex].height;
                showToastMessageHUD(`Resolution locked to ${matchedLevelHeight}p.`);
            } else {
                showToastMessageHUD("Requested level profile not detected in stream.");
            }
        }

        function mountLiveTvChannelNode(ch, isInitialPreload = false) {
            if (!ch) return;
            activeChannelObject = ch;

            localStorage.setItem(`nexus_last_channel_${currentPlaylistId}`, ch.name);

            document.getElementById('overlayActiveChTitle').innerText = ch.name;
            document.getElementById('chActiveDisplayName').innerText = ch.name;
            document.getElementById('chActiveDisplayCategory').innerText = ch.category;
            document.getElementById('chActiveDisplayLogo').src = ch.logo;

            const widget = document.getElementById('liveViewersCounterWidget');
            if (widget) {
                let channelWeight = 0;
                for (let i = 0; i < ch.name.length; i++) {
                    channelWeight += ch.name.charCodeAt(i);
                }
                const baseScale = (channelWeight % 250) + 150;
                widget.innerText = `${baseScale.toLocaleString()} Watching`;
            }

            primaryBufferLoaderNode.style.opacity = '1';
            primaryBufferLoaderNode.style.pointerEvents = 'auto';
            primaryBufferLoaderTextNode.innerText = `Establishing signal pipe: ${ch.name}...`;

            if (hlsEngineInstance) hlsEngineInstance.destroy();
            if (dashEngineInstance) dashEngineInstance.reset();

            setCinematicAspect('16-9');

            if (ch.url.endsWith('.mpd') || ch.url.includes('.mpd')) {
                dashEngineInstance = dashjs.MediaPlayer().create();
                dashEngineInstance.initialize(mainVideoNode, ch.url, true);
                dashEngineInstance.on(dashjs.MediaPlayer.events.PLAYBACK_METADATA_LOADED, () => {
                    primaryBufferLoaderNode.style.opacity = '0';
                    primaryBufferLoaderNode.style.pointerEvents = 'none';
                    if (!isInitialPreload) executeUnmutedAutoPlayback();
                });
            } else if (Hls.isSupported() && (ch.url.includes('.m3u8') || ch.url.includes('stream') || ch.url.includes('.m3u'))) {
                hlsEngineInstance = new Hls({
                    enableWorker: true,
                    lowLatencyMode: true,
                    backBufferLength: 90,
                    maxBufferLength: 30,
                    maxMaxBufferLength: 60,
                    maxBufferSize: 60 * 1024 * 1024, 
                    maxBufferHole: 0.5,
                    progressive: true,
                    appendErrorMaxRetry: 5,
                    liveSyncDurationCount: 3,
                    liveMaxLatencyDurationCount: 10,
                    enableSoftwareAES: true
                });
                hlsEngineInstance.loadSource(ch.url);
                hlsEngineInstance.attachMedia(mainVideoNode);
                
                hlsEngineInstance.on(Hls.Events.MANIFEST_PARSED, () => {
                    primaryBufferLoaderNode.style.opacity = '0';
                    primaryBufferLoaderNode.style.pointerEvents = 'none';
                    
                    const storedQuality = localStorage.getItem('nexus_manual_resolution_target');
                    if (storedQuality) {
                        const targetVal = parseInt(storedQuality);
                        const qualityLabelsMap = {
                            1080: "1080p",
                            720: "720p",
                            480: "480p",
                            360: "360p",
                            [-1]: "Auto"
                        };
                        selectManualResolutionTarget(targetVal, qualityLabelsMap[targetVal] || "Auto");
                    }

                    if (!isInitialPreload) executeUnmutedAutoPlayback();
                });
                
                hlsEngineInstance.on(Hls.Events.ERROR, (event, data) => {
                    if (data.fatal) {
                        primaryBufferLoaderTextNode.innerText = "Re-establishing connection pipeline...";
                        hlsEngineInstance.recoverMediaError();
                    }
                });
            } else {
                mainVideoNode.src = ch.url;
                mainVideoNode.addEventListener('loadedmetadata', () => {
                    primaryBufferLoaderNode.style.opacity = '0';
                    primaryBufferLoaderNode.style.pointerEvents = 'none';
                    if (!isInitialPreload) executeUnmutedAutoPlayback();
                });
            }

            setupSmartHardwareFrameDropDetector();
            triggerEPGTrackingSimulation(ch.name);
            updateFavoriteControlButtonsUIState(ch.name);
            saveHistoryToResumeLists(ch.name);
            compilePlayerDeckChannelsScroller();
        }

        function setupSmartHardwareFrameDropDetector() {
            clearInterval(frameDropCheckIntervalRef);
            if (!mainVideoNode.getVideoPlaybackQuality) return;

            let lastDroppedFramesCount = 0;
            frameDropCheckIntervalRef = setInterval(() => {
                if (mainVideoNode.paused) return;
                const quality = mainVideoNode.getVideoPlaybackQuality();
                const currentDrops = quality.droppedVideoFrames - lastDroppedFramesCount;
                lastDroppedFramesCount = quality.droppedVideoFrames;

                if (currentDrops > 15) {
                    if (hlsEngineInstance) {
                        hlsEngineInstance.config.maxBufferLength = 15;
                        hlsEngineInstance.config.maxMaxBufferLength = 30;
                    }
                    console.warn("High frame drops detected. Minimizing target buffering values.");
                }
            }, 5000);
        }

        function registerTouchSwipeListeners() {
            const container = document.getElementById('primaryVideoContainer');
            
            container.addEventListener('touchstart', (e) => {
                const touchObj = e.touches[0];
                touchStartXPosition = touchObj.clientX;
                touchStartYPosition = touchObj.clientY;
                originalStartingVolume = mainVideoNode.volume;
                originalStartingBrightness = 1.0; 
            });

            container.addEventListener('touchmove', (e) => {
                if (e.touches.length > 1) return;
                
                const touchObj = e.touches[0];
                const deltaY = touchStartYPosition - touchObj.clientY;
                const containerRect = container.getBoundingClientRect();
                
                const touchRelativeX = touchObj.clientX - containerRect.left;
                const isLeftHalf = touchRelativeX < (containerRect.width / 2);
                const percentageChange = deltaY / containerRect.height;

                if (isLeftHalf) {
                    let activeBright = Math.min(Math.max(originalStartingBrightness + percentageChange, 0.1), 1.5);
                    container.style.filter = `brightness(${activeBright})`;
                    
                    const hud = document.getElementById('gestureBrightnessOverlay');
                    document.getElementById('gestureBrightnessText').innerText = `${Math.round(activeBright * 100)}%`;
                    hud.style.opacity = '1';
                } else {
                    let activeVol = Math.min(Math.max(originalStartingVolume + percentageChange, 0.0), 1.0);
                    mainVideoNode.volume = activeVol;
                    document.getElementById('volumeControlSlider').value = activeVol;
                    
                    const hud = document.getElementById('gestureVolumeOverlay');
                    document.getElementById('gestureVolumeText').innerText = `${Math.round(activeVol * 100)}%`;
                    
                    const volIcon = document.getElementById('gestureVolumeIcon');
                    volIcon.className = activeVol === 0 ? "fa-solid fa-volume-xmark text-red-500 text-xl block mb-1" : "fa-solid fa-volume-high text-red-500 text-xl block mb-1";
                    hud.style.opacity = '1';
                }
            });

            container.addEventListener('touchend', () => {
                document.getElementById('gestureBrightnessOverlay').style.opacity = '0';
                document.getElementById('gestureVolumeOverlay').style.opacity = '0';
            });
        }

        function setupDpadNavigationTriggers() {
            window.addEventListener('keydown', (e) => {
                const focusedElement = document.activeElement;
                if (!focusedElement || !focusedElement.classList.contains('dpad-focusable')) return;

                const focusables = Array.from(document.querySelectorAll('.dpad-focusable'));
                const index = focusables.indexOf(focusedElement);

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const nextIndex = (index + 1) % focusables.length;
                    focusables[nextIndex].focus();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prevIndex = (index - 1 + focusables.length) % focusables.length;
                    focusables[prevIndex].focus();
                } else if (e.key === 'Enter') {
                    focusedElement.click();
                }
            });
        }

        function navigateNextChannelTrack(direction) {
            const feed = getWorkingChannelsFeed();
            const idx = feed.findIndex(ch => ch.name === activeChannelObject.name);
            if (idx !== -1) {
                const nextIdx = (idx + direction + feed.length) % feed.length;
                mountLiveTvChannelNode(feed[nextIdx]);
            }
        }

        function triggerEPGTrackingSimulation(chName) {
            const programs = [
                { title: "World Headlines Live Bulletin", duration: "12:00 PM - 02:00 PM", desc: "Interactive global report tracking headlines, economics, and weather center briefings." },
                { title: "Premium Entertainment Live Segment", duration: "03:00 PM - 05:00 PM", desc: "Premium entertainment streams featuring live studio guest panels." },
                { title: "Trending Movies Box Office Segment", duration: "07:30 PM - 10:00 PM", desc: "Adrenaline-filled fast-paced action blockbuster segment." }
            ];

            const activeProg = programs[Math.floor(Math.random() * programs.length)];
            document.getElementById('epgTrackedShowTitle').innerText = activeProg.title;
            document.getElementById('epgTrackedShowTime').innerText = activeProg.duration;
            document.getElementById('epgTrackedShowDesc').innerText = activeProg.desc;

            const progress = Math.floor(Math.random() * 50) + 25;
            document.getElementById('epgTrackedShowProgressBar').style.width = `${progress}%`;
            document.getElementById('epgTrackedShowProgressPercent').innerText = `${progress}%`;
        }

        function toggleSpecificFavoriteChannel(name) {
            const favKey = `nexus_favorites_${currentPlaylistId}`;
            let favs = JSON.parse(localStorage.getItem(favKey)) || [];
            if (favs.includes(name)) {
                favs = favs.filter(x => x !== name);
                showToastMessageHUD("Removed from Bookmarks.");
            } else {
                favs.push(name);
                showToastMessageHUD("Added to Bookmarks.");
            }
            localStorage.setItem(favKey, JSON.stringify(favs));
            compileHomepageSlices();
            compilePlayerDeckChannelsScroller();
            if (activeChannelObject && activeChannelObject.name === name) {
                updateFavoriteControlButtonsUIState(name);
            }
        }

        function toggleActiveFavoriteState() {
            if (activeChannelObject) {
                toggleSpecificFavoriteChannel(activeChannelObject.name);
            }
        }

        function updateFavoriteControlButtonsUIState(name) {
            const favKey = `nexus_favorites_${currentPlaylistId}`;
            const favs = JSON.parse(localStorage.getItem(favKey)) || [];
            const btn = document.getElementById('favoriteToggleActionBtn');
            if (favs.includes(name)) {
                btn.innerHTML = `<i class="fa-solid text-red-500 fa-heart animate-pulse"></i> Bookmarked`;
                btn.className = "flex-1 md:flex-none glass min-h-[44px] px-5 py-3 rounded-2xl text-[11px] font-black uppercase text-red-400 transition-all flex items-center justify-center gap-2 border border-red-500/25";
            } else {
                btn.innerHTML = `<i class="fa-regular fa-heart"></i> Bookmark`;
                btn.className = "flex-1 md:flex-none glass min-h-[44px] px-5 py-3 rounded-2xl text-[11px] font-black uppercase hover:bg-red-500/20 transition-all flex items-center justify-center gap-2";
            }
        }

        function saveHistoryToResumeLists(name) {
            const historyKey = `nexus_resume_${currentPlaylistId}`;
            let list = JSON.parse(localStorage.getItem(historyKey)) || [];
            list = list.filter(x => x.name !== name);
            list.unshift({ name: name, time: new Date().getTime() });
            if (list.length > 4) list.pop();
            localStorage.setItem(historyKey, JSON.stringify(list));
        }

        function togglePlayerEnginePlayState() {
            if (mainVideoNode.paused) {
                mainVideoNode.play();
                document.getElementById('controlStripPlayBtnIcon').className = "fa-solid fa-pause";
                document.getElementById('centerPlayControlIcon').className = "fa-solid fa-pause text-lg";
                toggleEqualizerVisualizationState(true);
            } else {
                mainVideoNode.pause();
                document.getElementById('controlStripPlayBtnIcon').className = "fa-solid fa-play";
                document.getElementById('centerPlayControlIcon').className = "fa-solid fa-play text-lg ml-0.5";
                toggleEqualizerVisualizationState(false);
            }
            resetHUDTimerCountdown();
        }

        function skipPlayerTime(amount) {
            mainVideoNode.currentTime = Math.max(0, Math.min(mainVideoNode.duration || 0, mainVideoNode.currentTime + amount));
            showToastMessageHUD(`Skipped ${amount > 0 ? '+' : ''}${amount}s`);
        }

        mainVideoNode.addEventListener('timeupdate', () => {
            if (mainVideoNode.duration) {
                const pct = (mainVideoNode.currentTime / mainVideoNode.duration) * 100;
                document.getElementById('timelineProgressFill').style.width = `${pct}%`;
            }
        });

        const progressTrackContainer = document.getElementById('timelineProgressBar');
        if (progressTrackContainer) {
            progressTrackContainer.addEventListener('click', (e) => {
                const rect = progressTrackContainer.getBoundingClientRect();
                const clickPosRatio = (e.clientX - rect.left) / rect.width;
                if (mainVideoNode.duration) {
                    mainVideoNode.currentTime = clickPosRatio * mainVideoNode.duration;
                }
            });
        }

        function toggleMuteAudioState() {
            mainVideoNode.muted = !mainVideoNode.muted;
            document.getElementById('controlStripMuteIcon').className = mainVideoNode.muted ? "fa-solid fa-volume-xmark text-red-500" : "fa-solid fa-volume-high";
            document.getElementById('volumeControlSlider').value = mainVideoNode.muted ? 0 : mainVideoNode.volume;
        }

        function triggerPlayerFullscreen() {
            const container = document.getElementById('primaryVideoContainer');
            if (!document.fullscreenElement) {
                container.requestFullscreen().then(() => {
                    if (screen.orientation && screen.orientation.lock) {
                        screen.orientation.lock('landscape').catch(() => {});
                    }
                });
            } else {
                document.exitFullscreen();
            }
        }

        function triggerMiniPlayerPiP() {
            if (document.pictureInPictureElement) {
                document.exitPictureInPicture();
            } else {
                mainVideoNode.requestPictureInPicture();
            }
        }

        let isInterfaceLocked = false;
        function toggleInterfaceLockState() {
            isInterfaceLocked = !isInterfaceLocked;
            const overlay = document.getElementById('screenLockOverlay');
            if (isInterfaceLocked) {
                overlay.classList.remove('hidden');
            } else {
                overlay.classList.add('hidden');
            }
        }

        function copyChannelShareLink() {
            if (activeChannelObject) {
                const text = `Join Nexus OTT: "${activeChannelObject.name}" Link: ${activeChannelObject.url}`;
                const tempInput = document.createElement("input");
                tempInput.value = text;
                document.body.appendChild(tempInput);
                tempInput.select();
                document.execCommand("copy");
                document.body.removeChild(tempInput);
                showToastMessageHUD("Share link copied!");
            }
        }

        function arrangeMultiViewLayout(count) {
            const area = document.getElementById('multiViewGridArea');
            area.innerHTML = '';
            
            if (count === 2) area.className = "grid grid-cols-2 gap-4 w-full aspect-video";
            if (count === 4) area.className = "grid grid-cols-2 gap-4 w-full aspect-video";
            if (count === 6) area.className = "grid grid-cols-3 gap-3 w-full aspect-video";

            const feed = getWorkingChannelsFeed();
            for (let i = 0; i < count; i++) {
                const ch = feed[i % feed.length];
                const cell = document.createElement('div');
                cell.className = "relative rounded-2xl overflow-hidden bg-black border border-white/5 flex items-center justify-center";
                cell.innerHTML = `
                    <video class="w-full h-full object-cover" autoplay muted loop playsinline src="${ch.url}"></video>
                    <div class="absolute bottom-2 left-2 bg-black/75 px-2.5 py-1 rounded-xl text-[9px] font-black uppercase text-white truncate max-w-[80%] font-display">
                        ${ch.name}
                    </div>
                `;
                area.appendChild(cell);
            }
            showToastMessageHUD(`Multi-view set: ${count} Nodes`);
        }

        function filterChannelsInPlayerDeck() {
            compilePlayerDeckChannelsScroller();
        }

        function openSleepTimerOverlay() { document.getElementById('sleepTimerSettingsModal').classList.remove('hidden'); }
        function closeSleepTimerOverlay() { document.getElementById('sleepTimerSettingsModal').classList.add('hidden'); }

        function scheduleSleepTimerShutdown(mins) {
            clearTimeout(shutdownSleepTimerRef);
            showToastMessageHUD(`Timer scheduled: Shutdown in ${mins} Mins.`);
            closeSleepTimerOverlay();

            shutdownSleepTimerRef = setTimeout(() => {
                mainVideoNode.pause();
                showToastMessageHUD("Auto sleep executed.");
                toggleEqualizerVisualizationState(false);
            }, mins * 60 * 1000);
        }

        function disableActiveSleepTimer() {
            clearTimeout(shutdownSleepTimerRef);
            closeSleepTimerOverlay();
            showToastMessageHUD("Active sleep timers cancelled.");
        }

        function triggerPurgeConfirmation() {
            showCustomConfirm(
                "Clear Storage Data?", 
                "This operation will delete all customized theme preferences, saved favorites, and play records permanently.",
                (confirmed) => {
                    if (confirmed) {
                        factoryPurgeLocalStorageCache();
                    }
                }
            );
        }

        function triggerDeleteConfirmation(playlistId) {
            showCustomConfirm(
                "Delete Playlist?", 
                "Are you sure you want to remove this playlist configuration from your system index?",
                (confirmed) => {
                    if (confirmed) {
                        window.location.href = `?action=delete_playlist&id=${playlistId}`;
                    }
                }
            );
        }

        function triggerLoginModal() { document.getElementById('adminPortalAccessModal').classList.remove('hidden'); }
        function closeLoginModal() { document.getElementById('adminPortalAccessModal').classList.add('hidden'); }

        function factoryPurgeLocalStorageCache() {
            localStorage.clear();
            showToastMessageHUD("Database cleared. Refreshing...");
            setTimeout(() => window.location.reload(), 1000);
        }

        let toastTrackerTimeoutRef = null;
        function showToastMessageHUD(msg) {
            clearTimeout(toastTrackerTimeoutRef);
            const el = document.getElementById('toastNotificationPortal');
            document.getElementById('toastNotificationMsg').innerText = msg;

            el.classList.remove('translate-y-24', 'opacity-0', 'pointer-events-none');
            el.classList.add('translate-y-0', 'opacity-100');

            toastTrackerTimeoutRef = setTimeout(() => {
                el.classList.remove('translate-y-0', 'opacity-100');
                el.classList.add('translate-y-24', 'opacity-0', 'pointer-events-none');
            }, 2500);
        }

        function openEditPlaylistDialog(id, name, source) {
            document.getElementById('editPlId').value = id;
            document.getElementById('editPlName').value = name;
            document.getElementById('editPlSource').value = source;
            document.getElementById('editPlaylistConfigModal').classList.remove('hidden');
        }

        function closeEditPlaylistDialog() {
            document.getElementById('editPlaylistConfigModal').classList.add('hidden');
        }
    </script>
</body>
</html>