<?php
require 'config.php';

// Handle tombol aksi (Tandai Selesai)
if (isset($_GET['aksi_id'])) {
    $id = $_GET['aksi_id'];
    mysqli_query($conn, "UPDATE komentar SET status_respon = 'Selesai' WHERE id = $id");
    header("Location: dashboard.php");
}

// Fitur Filter
$filter_sentimen = $_GET['f_sentimen'] ?? '';
$where_clause = "";
if ($filter_sentimen != '') {
    $where_clause = "WHERE sentimen = '$filter_sentimen'";
}

// 1. Hitung Statistik Utama (Poin 3a)
$total = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM komentar"))['t'];
$negatif = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM komentar WHERE sentimen='Negatif'"))['t'];
$positif = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM komentar WHERE sentimen='Positif'"))['t'];
$kritik_mendesak = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as t FROM komentar WHERE kategori='Kritik' AND status_respon='Belum'"))['t'];

// Hitung persentase
$persen_negatif = $total > 0 ? ($negatif / $total) * 100 : 0;
$persen_positif = $total > 0 ? ($positif / $total) * 100 : 0;
?>

<!DOCTYPE html>
<html>
<head><title>Dashboard Utama</title></head>
<body>
    <h2>Dashboard Analisis Data</h2>
    <a href="index.php">⬅️ Tarik Data Baru</a> | <a href="rekomendasi.php" style="font-weight: bold; color: green;">📋 Lihat Rekomendasi AI Gemini</a>
    <hr>

    <h3>📊 Statistik Utama</h3>
    <ul>
        <li>Total Komentar: <?php echo $total; ?></li>
        <li>Persentase Positif: <?php echo round($persen_positif, 1); ?>%</li>
        <li>Persentase Negatif (Warna Merah): <span style="color: red; font-weight: bold;"><?php echo round($persen_negatif, 1); ?>%</span></li>
        <li>🚨 Kritik Mendesak Butuh Respon: <strong><?php echo $kritik_mendesak; ?></strong></li>
    </ul>
    <hr>

    <h3>🔍 Filter Data</h3>
    <form method="GET">
        Filter Sentimen:
        <select name="f_sentimen" onchange="this.form.submit()">
            <option value="">-- Semua Sentimen --</option>
            <option value="Positif" <?php if($filter_sentimen=='Positif') echo 'selected'; ?>>Positif</option>
            <option value="Negatif" <?php if($filter_sentimen=='Negatif') echo 'selected'; ?>>Negatif</option>
            <option value="Netral" <?php if($filter_sentimen=='Netral') echo 'selected'; ?>>Netral</option>
        </select>
    </form><br>

    <table border="1" cellpadding="8" cellspacing="0" style="width: 100%;">
        <thead>
            <tr style="background-color: #f2f2f2;">
                <th>Username</th>
                <th>Komentar</th>
                <th>Sentimen</th>
                <th>Kategori</th>
                <th>Status</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query = mysqli_query($conn, "SELECT * FROM komentar $where_clause ORDER BY id DESC");
            while ($row = mysqli_fetch_assoc($query)) {
                // Warna teks berdasarkan sentimen (Poin 3b1)
                $warna = '#000';
                if ($row['sentimen'] == 'Positif') $warna = 'green';
                if ($row['sentimen'] == 'Negatif') $warna = 'red';
                if ($row['sentimen'] == 'Netral') $warna = 'gray';
            ?>
            <tr>
                <td><?php echo $row['username']; ?></td>
                <td><?php echo $row['text_komentar']; ?></td>
                <td style="color: <?php echo $warna; ?>; font-weight: bold;"><?php echo $row['sentimen']; ?></td>
                <td><?php echo $row['kategori']; ?></td>
                <td><?php echo $row['status_respon']; ?></td>
                <td>
                    <?php if ($row['status_respon'] == 'Belum'): ?>
                        <a href="dashboard.php?aksi_id=<?php echo $row['id']; ?>">Tandai Selesai</a>
                    <?php else: ?>
                        ✅ Selesai
                    <?php endif; ?>
                </td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</body>
</html>