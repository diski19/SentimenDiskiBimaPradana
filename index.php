<?php
/**
 * ======================================================================
 * APLIKASI DASHBOARD ANALISIS SENTIMEN EKONOMI NASIONAL
 * Backend: PHP Native (PDO MySQL, cURL)
 * Frontend: HTML5, Tailwind CSS, Vanilla JS, Chart.js
 * ======================================================================
 */

// ==========================================
// 1. KONFIGURASI DATABASE & APIFY
// ==========================================
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'ekonomi_sentimen';

// Kredensial Apify Terkonfigurasi
$apify_token = 'apify_api_cMKSbhcD4YWqRobPUCZbSYd4RFnTI12c1SpP'; 
$apify_actor = 'apify/instagram-comment-scraper';

// ==========================================
// 2. KONEKSI & SETUP DATABASE OTOMATIS
// ==========================================
try {
    // Konek tanpa nama DB dulu untuk mengecek/membuat DB
    $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Buat database jika belum ada
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");
    
    // Buat tabel komentar jika belum ada
    $table_query = "
        CREATE TABLE IF NOT EXISTS komentar_ekonomi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ig_url VARCHAR(255) NOT NULL,
            username VARCHAR(100) NOT NULL,
            teks_komentar TEXT NOT NULL,
            sentimen ENUM('Positif', 'Negatif', 'Netral') NOT NULL,
            kategori VARCHAR(50) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $pdo->exec($table_query);

} catch (PDOException $e) {
    die(json_encode(['status' => 'error', 'message' => 'Gagal koneksi ke Database: ' . $e->getMessage()]));
}

// ==========================================
// 3. FUNGSI ANALISIS TEKS CERDAS (NLP SEDERHANA)
// ==========================================
// Berfungsi untuk mengolah data teks dari scraper menjadi sentimen dan kategori topik ekonomi
function analyzeText($text) {
    $text_lower = strtolower($text);
    
    $kata_negatif = ['buruk', 'gagal', 'hancur', 'turun', 'krisis', 'sulit', 'mahal', 'PHK', 'inflasi', 'anjlok', 'kecewa', 'utang', 'susah', 'miskin'];
    $kata_positif = ['bagus', 'naik', 'keren', 'mantap', 'solusi', 'berhasil', 'maju', 'tumbuh', 'bantuan', 'apresiasi', 'dukung', 'senang'];
    $kata_tanya   = ['kenapa', 'bagaimana', 'kapan', 'tanya', 'mohon info', 'apakah', 'cara'];

    $score = 0;
    $kategori = 'Umum';

    // Cek sentimen
    foreach ($kata_negatif as $kata) { if (strpos($text_lower, $kata) !== false) { $score--; $kategori = 'Kritik'; } }
    foreach ($kata_positif as $kata) { if (strpos($text_lower, $kata) !== false) { $score++; $kategori = 'Pujian'; } }
    foreach ($kata_tanya as $kata) { if (strpos($text_lower, $kata) !== false) { $kategori = 'Pertanyaan'; } }

    if ($score > 0) return ['sentimen' => 'Positif', 'kategori' => $kategori];
    if ($score < 0) return ['sentimen' => 'Negatif', 'kategori' => $kategori];
    return ['sentimen' => 'Netral', 'kategori' => $kategori];
}

// ==========================================
// 4. ROUTING API (BACKEND LOGIC)
// ==========================================
$action = isset($_GET['api']) ? $_GET['api'] : '';

if ($action) {
    header('Content-Type: application/json');
    
    if ($action === 'dashboard_data') {
        // --- API: AMBIL DATA DASHBOARD ---
        try {
            // Ambil semua data
            $stmt = $pdo->query("SELECT * FROM komentar_ekonomi ORDER BY created_at DESC");
            $komentar = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Hitung statistik
            $total = count($komentar);
            $positif = 0; $negatif = 0; $netral = 0;
            $kategori_count = ['Kritik' => 0, 'Pujian' => 0, 'Pertanyaan' => 0, 'Umum' => 0];
            $kritik_mendesak = 0; // Negatif + Kritik

            foreach ($komentar as $row) {
                if ($row['sentimen'] == 'Positif') $positif++;
                if ($row['sentimen'] == 'Negatif') $negatif++;
                if ($row['sentimen'] == 'Netral') $netral++;
                
                if (isset($kategori_count[$row['kategori']])) {
                    $kategori_count[$row['kategori']]++;
                } else {
                    $kategori_count[$row['kategori']] = 1;
                }

                if ($row['sentimen'] == 'Negatif' && $row['kategori'] == 'Kritik') {
                    $kritik_mendesak++;
                }
            }

            echo json_encode([
                'status' => 'success',
                'stats' => [
                    'total' => $total,
                    'positif' => $positif,
                    'negatif' => $negatif,
                    'netral' => $netral,
                    'kritik_mendesak' => $kritik_mendesak
                ],
                'chart_kategori' => $kategori_count,
                'data' => $komentar
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'scrape' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // --- API: JALANKAN SCRAPER APIFY VIA CURL ---
        $data = json_decode(file_get_contents('php://input'), true);
        $url_ig = filter_var($data['url'], FILTER_SANITIZE_URL);

        if (!$url_ig) {
            echo json_encode(['status' => 'error', 'message' => 'URL tidak valid.']);
            exit;
        }

        // Integrasi Apify Menggunakan cURL
        if ($apify_token !== 'MASUKKAN_TOKEN_APIFY_ANDA_DISINI' && !empty($apify_token) && trim($apify_token) !== '') {
            
            // SANITISASI OTOMATIS: Ganti "/" menjadi "~" agar cocok dengan format API endpoint Apify
            $sanitized_actor = str_replace('/', '~', trim($apify_actor));
            
            // Memanggil Apify Run-Sync API (Membatasi eksekusi sinkron hingga 120 detik)
            $apify_url = "https://api.apify.com/v2/acts/{$sanitized_actor}/run-sync-get-dataset-items?token={$apify_token}&timeout=120";
            
            // Konfigurasi input payload yang kompatibel dengan sebagian besar scraper Instagram Apify
            $payload = json_encode([
                'directUrls' => [$url_ig],
                'resultsLimit' => 15,
                'searchType' => 'hashtag', // Default fallback
                'searchLimit' => 1
            ]);

            $ch = curl_init($apify_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 130); // Berikan toleransi di atas batas timeout API
            
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Periksa jika ada error pada jaringan cURL
            if (!empty($curl_error)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Masalah Jaringan/Koneksi Server: ' . $curl_error . '. Pastikan server lokal Anda terhubung ke internet.'
                ]);
                exit;
            }

            $scraped_data = json_decode($response, true);

            // Analisis respon kegagalan dari Apify
            if ($httpcode < 200 || $httpcode >= 300) {
                $error_detail = 'Kesalahan tidak dikenal.';
                if (is_array($scraped_data) && isset($scraped_data['error']['message'])) {
                    $error_detail = $scraped_data['error']['message'];
                } elseif (is_array($scraped_data) && isset($scraped_data['message'])) {
                    $error_detail = $scraped_data['message'];
                } else if ($httpcode === 401) {
                    $error_detail = 'Token Apify tidak valid atau salah ketik.';
                } else if ($httpcode === 404) {
                    $error_detail = "Aktor '{$sanitized_actor}' tidak ditemukan di Apify Store. Pastikan ID aktor Anda benar.";
                } else if ($httpcode === 429) {
                    $error_detail = 'Kuota bulanan Apify Anda telah habis atau terkena rate limit.';
                } else if ($httpcode === 201 || $httpcode === 202) {
                    $error_detail = 'Proses scraping memakan waktu terlalu lama (timeout di server Apify). Gunakan URL post dengan jumlah komentar yang lebih sedikit.';
                }
                
                echo json_encode([
                    'status' => 'error',
                    'message' => "Apify Error [HTTP {$httpcode}]: {$error_detail}"
                ]);
                exit;
            }
        } else {
            // MODE SIMULASI (Jika token Apify tidak diatur)
            sleep(2); 
            $scraped_data = [
                ['ownerUsername' => 'budi_ekonomi', 'text' => 'Kebijakan pajak yang baru ini sangat memberatkan masyarakat kecil, inflasi makin terasa!'],
                ['ownerUsername' => 'siti_bisnis', 'text' => 'Bagus sekali program BLT ini, sangat membantu UMKM kami untuk kembali tumbuh.'],
                ['ownerUsername' => 'anton.123', 'text' => 'Kapan pendaftaran subsidi tepat sasaran dibuka lagi min? Mohon info.'],
                ['ownerUsername' => 'dian_invest', 'text' => 'Pertumbuhan ekonomi kuartal ini luar biasa mantap, apresiasi untuk pemerintah.'],
                ['ownerUsername' => 'rakyat_biasa', 'text' => 'Harga sembako mahal semua, krisis di depan mata, pemerintah gagal mengontrol pasar!']
            ];
            $httpcode = 200;
        }

        if ($httpcode >= 200 && $httpcode < 300 && is_array($scraped_data)) {
            // Simpan ke Database
            $inserted = 0;
            $stmt = $pdo->prepare("INSERT INTO komentar_ekonomi (ig_url, username, teks_komentar, sentimen, kategori) VALUES (?, ?, ?, ?, ?)");
            
            foreach ($scraped_data as $item) {
                // Ekstraksi data dari JSON Apify (menangani beberapa variasi skema payload Instagram)
                $username = $item['ownerUsername'] ?? $item['owner']['username'] ?? $item['username'] ?? 'anonymous';
                $teks = $item['text'] ?? $item['commentText'] ?? $item['caption'] ?? '';
                
                if (empty($teks)) continue;

                // Analisis sentimen otomatis
                $analisis = analyzeText($teks);
                
                $stmt->execute([$url_ig, $username, $teks, $analisis['sentimen'], $analisis['kategori']]);
                $inserted++;
            }

            if ($inserted === 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Data berhasil ditarik dari Apify, namun tidak ada teks komentar yang ditemukan dalam payload respon.'
                ]);
            } else {
                echo json_encode(['status' => 'success', 'message' => "Berhasil menarik dan menganalisis $inserted komentar."]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Gagal memproses data dari Apify. Format respon tidak sesuai.']);
        }
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Endpoint tidak ditemukan.']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Analisis Sentimen Ekonomi Nasional</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #f8fafc; }
        .spinner { border: 3px solid rgba(255,255,255,0.2); border-radius: 50%; border-top: 3px solid #fff; width: 44px; height: 44px; animation: spin 0.8s cubic-bezier(0.4, 0, 0.2, 1) infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="text-slate-800 antialiased min-h-screen flex flex-col">

    <nav class="bg-slate-900 text-white border-b border-slate-800 sticky top-0 z-40 backdrop-blur-md bg-opacity-95">
        <div class="max-w-7xl mx-auto px-6 py-4 flex justify-between items-center">
            <h1 class="text-xl font-bold tracking-tight flex items-center gap-3">
                <div class="p-2 bg-indigo-600 rounded-lg text-white shadow-md shadow-indigo-500/20">
                    <i class="fa-solid fa-chart-pie fa-sm"></i>
                </div>
                <span>Eco<span class="text-indigo-400 font-medium">Sentimen</span></span>
            </h1>
            <div class="flex items-center gap-2 text-xs text-slate-400 font-medium bg-slate-800 px-3 py-1.5 rounded-full border border-slate-700">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span> Live Monitor
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8 space-y-8 w-full flex-1">

        <div id="notification" class="hidden p-4 rounded-xl text-sm font-medium border shadow-sm transition-all duration-300" role="alert"></div>

        <section class="bg-white rounded-2xl shadow-sm p-6 border border-slate-200/60 transition-all hover:shadow-md">
            <div class="mb-4">
                <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                    <i class="fa-brands fa-instagram text-xl text-pink-500"></i> Tarik Data Komentar Baru
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">Masukkan tautan publik kiriman Instagram untuk mengekstrak opini publik secara langsung.</p>
            </div>
            <form id="scraperForm" class="flex flex-col md:flex-row gap-3">
                <div class="relative flex-1">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <i class="fa-solid fa-link text-xs"></i>
                    </div>
                    <input type="url" id="ig_url" required placeholder="https://www.instagram.com/p/..." 
                           class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50 border border-slate-200 geometries rounded-xl focus:bg-white focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 outline-none transition-all placeholder:text-slate-400">
                </div>
                <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold py-2.5 px-6 rounded-xl shadow-sm transition-all flex justify-center items-center gap-2 active:scale-95">
                    <i class="fa-solid fa-wand-magic-sparkles text-xs text-indigo-400"></i> Ekstraksi & Analisis
                </button>
            </form>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="bg-white rounded-2xl shadow-sm p-6 border border-slate-200/60 flex items-center justify-between transition-all hover:shadow-md">
                <div class="space-y-1">
                    <p class="text-xs text-slate-500 font-semibold tracking-wider uppercase">Total Komentar Dianalisis</p>
                    <h3 class="text-3xl font-bold text-slate-900 tracking-tight" id="stat_total">0</h3>
                </div>
                <div class="p-3.5 bg-slate-50 text-slate-700 rounded-xl border border-slate-100">
                    <i class="fa-solid fa-database text-lg text-slate-500"></i>
                </div>
            </div>
            
            <div class="bg-white rounded-2xl shadow-sm p-6 border border-slate-200/60 flex items-center justify-between transition-all hover:shadow-md">
                <div class="space-y-1">
                    <p class="text-xs text-slate-500 font-semibold tracking-wider uppercase">Sentimen Positif</p>
                    <h3 class="text-3xl font-bold text-emerald-600 tracking-tight" id="stat_positif">0%</h3>
                </div>
                <div class="p-3.5 bg-emerald-50 text-emerald-600 rounded-xl border border-emerald-100/50">
                    <i class="fa-solid fa-face-smile text-lg"></i>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm p-6 border border-slate-200/60 flex items-center justify-between transition-all hover:shadow-md">
                <div class="space-y-1">
                    <p class="text-xs text-rose-500 font-semibold tracking-wider uppercase">Kritik Mendesak (Action Req)</p>
                    <h3 class="text-3xl font-bold text-rose-600 tracking-tight" id="stat_mendesak">0</h3>
                </div>
                <div class="p-3.5 bg-rose-50 text-rose-600 rounded-xl border border-rose-100/50 relative">
                    <span class="absolute top-3 right-3 flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-rose-500"></span>
                    </span>
                    <i class="fa-solid fa-triangle-exclamation text-lg"></i>
                </div>
            </div>
        </section>

        <section class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-white rounded-2xl shadow-sm p-6 border border-slate-200/60 flex flex-col transition-all hover:shadow-md">
                <div class="mb-4">
                    <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                        <i class="fa-solid fa-pie-chart text-indigo-500"></i> Proporsi Sentimen Ekonomi
                    </h3>
                </div>
                <div class="relative flex-1 min-h-[240px] flex items-center justify-center">
                    <canvas id="sentimenChart"></canvas>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm p-6 border border-slate-200/60 flex flex-col transition-all hover:shadow-md">
                <div class="mb-4">
                    <h3 class="text-sm font-bold text-slate-800 flex items-center gap-2">
                        <i class="fa-solid fa-chart-simple text-indigo-500"></i> Tren Topik & Kategori Komentar
                    </h3>
                </div>
                <div class="relative flex-1 min-h-[240px]">
                    <canvas id="kategoriChart"></canvas>
                </div>
            </div>
        </section>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200/60 overflow-hidden transition-all hover:shadow-md">
            <div class="p-5 border-b border-slate-100 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-50/50">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Riwayat Komentar</h2>
                </div>
                
                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <div class="relative flex-1 sm:flex-initial">
                        <select id="filter_sentimen" class="w-full appearance-none bg-white border border-slate-200 rounded-xl pl-3 pr-8 py-2 text-xs font-medium text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all cursor-pointer">
                            <option value="all">Semua Sentimen</option>
                            <option value="Positif">Positif</option>
                            <option value="Negatif">Negatif</option>
                            <option value="Netral">Netral</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-2.5 flex items-center pointer-events-none text-slate-400 text-[10px]">
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>
                    </div>
                    <div class="relative flex-1 sm:flex-initial">
                        <select id="filter_kategori" class="w-full appearance-none bg-white border border-slate-200 rounded-xl pl-3 pr-8 py-2 text-xs font-medium text-slate-700 outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all cursor-pointer">
                            <option value="all">Semua Kategori</option>
                            <option value="Kritik">Kritik</option>
                            <option value="Pujian">Pujian</option>
                            <option value="Pertanyaan">Pertanyaan</option>
                            <option value="Umum">Umum</option>
                        </select>
                        <div class="absolute inset-y-0 right-0 pr-2.5 flex items-center pointer-events-none text-slate-400 text-[10px]">
                            <i class="fa-solid fa-chevron-down"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="overflow-x-auto w-full">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="text-xs font-semibold text-slate-500 uppercase tracking-wider bg-slate-50/70 border-b border-slate-100">
                            <th scope="col" class="px-6 py-3.5 w-1/5">Username</th>
                            <th scope="col" class="px-6 py-3.5 w-2/5">Teks Komentar Asli</th>
                            <th scope="col" class="px-6 py-3.5 w-1/5 text-center">Sentimen</th>
                            <th scope="col" class="px-6 py-3.5 w-1/5 text-center">Kategori</th>
                        </tr>
                    </thead>
                    <tbody id="table_body" class="divide-y divide-slate-100 text-slate-700">
                        <tr><td colspan="4" class="text-center py-10 text-slate-400 text-xs font-medium">Memuat data...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <div id="loading_overlay" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm flex flex-col items-center justify-center z-50 hidden transition-all duration-300">
        <div class="bg-slate-900 border border-slate-800 p-8 rounded-2xl shadow-2xl flex flex-col items-center max-w-sm mx-4 text-center">
            <div class="spinner mb-5"></div>
            <h2 class="text-white text-base font-bold tracking-tight">Menjalankan Apify Scraper...</h2>
            <p class="text-slate-400 text-xs mt-2 leading-relaxed">Menarik data dari Instagram dan melakukan analisis sentimen cerdas.</p>
            <p class="text-indigo-400 text-[11px] mt-1">Mohon tunggu, proses ini mungkin memakan waktu beberapa saat.</p>
            <div class="w-full bg-slate-800 h-1 rounded-full overflow-hidden mt-5">
                <div class="bg-indigo-500 h-full rounded-full animate-[loading_1.5s_ease-in-out_infinite]" style="animation-duration: 2s; width: 45%"></div>
            </div>
        </div>
    </div>

    <script>
        // Global variables
        let globalData = [];
        let chartSentimenInstance = null;
        let chartKategoriInstance = null;

        // Inisialisasi awal
        document.addEventListener('DOMContentLoaded', () => {
            fetchDashboardData();

            // Event Listeners
            document.getElementById('scraperForm').addEventListener('submit', handleScrape);
            document.getElementById('filter_sentimen').addEventListener('change', renderTable);
            document.getElementById('filter_kategori').addEventListener('change', renderTable);
        });

        // Tampilkan notifikasi
        function showNotification(message, type = 'success') {
            const notif = document.getElementById('notification');
            notif.className = `p-4 mb-4 text-xs font-semibold rounded-xl border shadow-sm ${type === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-100' : 'bg-rose-50 text-rose-800 border-rose-100'}`;
            notif.innerHTML = `<i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'} text-base mr-2 align-middle"></i> ${message}`;
            notif.classList.remove('hidden');
            setTimeout(() => {
                if (type !== 'error') {
                    notif.classList.add('hidden');
                }
            }, 8000);
        }

        // Fetch Data dari PHP Backend
        async function fetchDashboardData() {
            try {
                const response = await fetch('?api=dashboard_data');
                const result = await response.json();
                
                if (result.status === 'success') {
                    globalData = result.data;
                    updateExecutiveSummary(result.stats);
                    updateCharts(result.stats, result.chart_kategori);
                    renderTable();
                } else {
                    console.error("Gagal load data:", result.message);
                }
            } catch (error) {
                console.error('Error fetching data:', error);
            }
        }

        // Handle Submit Form Scraper
        async function handleScrape(e) {
            e.preventDefault();
            const urlInput = document.getElementById('ig_url').value;
            const overlay = document.getElementById('loading_overlay');
            
            // Tampilkan Syncing State
            overlay.classList.remove('hidden');

            try {
                const response = await fetch('?api=scrape', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ url: urlInput })
                });
                
                const result = await response.json();
                overlay.classList.add('hidden');

                if (result.status === 'success') {
                    showNotification(result.message, 'success');
                    document.getElementById('ig_url').value = '';
                    fetchDashboardData(); // Refresh dashboard
                } else {
                    showNotification(result.message, 'error');
                }
            } catch (error) {
                overlay.classList.add('hidden');
                showNotification('Terjadi kesalahan jaringan atau server lokal Anda mengalami timeout.', 'error');
            }
        }

        // Update Ringkasan Eksekutif
        function updateExecutiveSummary(stats) {
            document.getElementById('stat_total').textContent = stats.total;
            
            const persentasePositif = stats.total > 0 ? Math.round((stats.positif / stats.total) * 100) : 0;
            document.getElementById('stat_positif').textContent = persentasePositif + '%';
            
            document.getElementById('stat_mendesak').textContent = stats.kritik_mendesak;
        }

        // Update Visualisasi (Chart.js)
        function updateCharts(stats, kategoriCount) {
            if (chartSentimenInstance) chartSentimenInstance.destroy();
            if (chartKategoriInstance) chartKategoriInstance.destroy();

            // 1. Chart Lingkaran (Sentimen)
            const ctxPie = document.getElementById('sentimenChart').getContext('2d');
            chartSentimenInstance = new Chart(ctxPie, {
                type: 'doughnut',
                data: {
                    labels: ['Negatif', 'Positif', 'Netral'],
                    datasets: [{
                        data: [stats.negatif, stats.positif, stats.netral],
                        backgroundColor: ['#f43f5e', '#10b981', '#64748b'],
                        borderWidth: 4,
                        borderColor: '#ffffff',
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { 
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                padding: 20,
                                font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' },
                                color: '#475569'
                            }
                        }
                    },
                    cutout: '75%'
                }
            });

            // 2. Bar Chart (Kategori)
            const ctxBar = document.getElementById('kategoriChart').getContext('2d');
            
            const labelsBar = Object.keys(kategoriCount);
            const dataBar = Object.values(kategoriCount);

            chartKategoriInstance = new Chart(ctxBar, {
                type: 'bar',
                data: {
                    labels: labelsBar,
                    datasets: [{
                        label: 'Jumlah Komentar',
                        data: dataBar,
                        backgroundColor: '#6366f1',
                        hoverBackgroundColor: '#4f46e5',
                        borderRadius: 8,
                        barThickness: 24
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { 
                            beginAtZero: true, 
                            ticks: { stepSize: 1, color: '#94a3b8', font: { family: 'Plus Jakarta Sans', size: 11 } },
                            grid: { color: '#f1f5f9' }
                        },
                        x: {
                            ticks: { color: '#64748b', font: { family: 'Plus Jakarta Sans', size: 11, weight: '500' } },
                            grid: { display: false }
                        }
                    }
                }
            });
        }

        // Render Tabel dengan Filter
        function renderTable() {
            const tbody = document.getElementById('table_body');
            const f_sentimen = document.getElementById('filter_sentimen').value;
            const f_kategori = document.getElementById('filter_kategori').value;

            tbody.innerHTML = ''; // Clear tabel

            // Terapkan filter
            const filteredData = globalData.filter(item => {
                const matchSentimen = f_sentimen === 'all' || item.sentimen === f_sentimen;
                const matchKategori = f_kategori === 'all' || item.kategori === f_kategori;
                return matchSentimen && matchKategori;
            });

            if (filteredData.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-center py-10 text-slate-400 text-xs italic font-medium bg-white">Tidak ada data yang sesuai dengan filter.</td></tr>`;
                return;
            }

            // Generate baris tabel
            filteredData.forEach(item => {
                let colorSentimen = 'bg-slate-100 text-slate-700 border-slate-200';
                if(item.sentimen === 'Positif') colorSentimen = 'bg-emerald-50 text-emerald-700 border-emerald-200/60';
                if(item.sentimen === 'Negatif') colorSentimen = 'bg-rose-50 text-rose-700 border-rose-200/60';

                let colorKategori = 'bg-sky-50 text-sky-700 border border-sky-100';
                if(item.kategori === 'Kritik') colorKategori = 'bg-amber-50 text-amber-700 border border-amber-100';

                const tr = document.createElement('tr');
                tr.className = 'bg-white hover:bg-slate-50/80 transition-all';
                tr.innerHTML = `
                    <td class="px-6 py-4 text-xs font-semibold text-slate-900">@${item.username}</td>
                    <td class="px-6 py-4 text-xs text-slate-600 font-medium max-w-sm break-words leading-relaxed">${item.teks_komentar}</td>
                    <td class="px-6 py-4 text-center">
                        <span class="inline-block px-2.5 py-1 rounded-full text-[10px] font-bold border ${colorSentimen}">
                            ${item.sentimen}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <span class="inline-block px-2.5 py-1 rounded-lg text-[10px] font-semibold ${colorKategori}">
                            ${item.kategori}
                        </span>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
    </script>
</body>
</html>
