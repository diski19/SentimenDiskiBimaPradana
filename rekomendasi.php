<?php
require 'config.php';

// Ambil kumpulan teks kritik/negatif untuk disetor ke Gemini AI
$queryNegatif = mysqli_query($conn, "SELECT text_komentar FROM komentar WHERE sentimen = 'Negatif'");
$gabungan_keluhan = "";
$no = 1;
while($row = mysqli_fetch_assoc($queryNegatif)) {
    $gabungan_keluhan .= $no . ". " . $row['text_komentar'] . "\n";
    $no++;
}

$hasil_gemini = "Belum ada data keluhan untuk dianalisis.";

if (!empty($gabungan_keluhan)) {
    // Setup API Gemini
    $apiKey = "AQ.Ab8RN6Lj6xkdANaTUuXX3dRW4MkXp-5Ss1gWULZSMkoV-TPfBg";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . $apiKey;

    $prompt = "Berikut adalah daftar keluhan masyarakat tentang ekonomi nasional di media sosial:\n" . $gabungan_keluhan . 
              "\n\nBerdasarkan data di atas, tolong buatkan ringkasan analisis global dan berikan rekomendasi tindakan operasionalnya seperti format pada soal.";

    $payload = json_encode([
        "contents" => [["parts" => [["text" => $prompt]]]]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $hasil_gemini = $result['candidates'][0]['content']['parts'][0]['text'] ?? "Gagal mengambil rekomendasi AI.";
}
?>

<!DOCTYPE html>
<html>
<head><title>Rekomendasi Laporan AI</title></head>
<body>
    <h2>📋 AI Recommendations (Laporan Otomatis)</h2>
    <a href="dashboard.php">⬅️ Kembali ke Dashboard</a>
    <hr>
    
    <div style="background-color: #f9f9f9; padding: 15px; border-left: 4px solid green; font-family: Arial, sans-serif; line-height: 1.6;">
        <strong>Laporan Analisis Global:</strong><br>
        <?php echo nl2br(htmlspecialchars($hasil_gemini)); ?>
    </div>
</body>
</html>