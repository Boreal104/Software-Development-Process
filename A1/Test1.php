<?php
// Load Eurostat JSON dataset
$url = "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/une_rt_m?geo=LT&unit=PC_ACT&sex=T&age=TOTAL";
$json = file_get_contents($url);
$data = json_decode($json, true);

// Extract mapping of index → time label
$timeMapping = array_flip($data["dimension"]["time"]["category"]["index"]);

// Extract values
$values = $data["value"];

// Build a time → value array
$timeSeries = [];
foreach ($values as $index => $val) {
    if (isset($timeMapping[$index])) {
        $timeSeries[$timeMapping[$index]] = $val;
    }
}

// Sort by date
ksort($timeSeries);

// Get available months
$months = array_keys($timeSeries);

// Get selected range from form
$from = $_GET['from'] ?? reset($months); // default = earliest
$to   = $_GET['to']   ?? end($months);   // default = latest

$errorMessage = "";
$filteredSeries = [];
$avgSelected = $maxSelected = $minSelected = $trendSlope = null;
$maxDate = $minDate = null;

if ($from > $to) {
    $errorMessage = "Error: 'From' date cannot be later than 'To' date.";
} else {
    // Filter dataset between selected range
    $filteredSeries = array_filter(
        $timeSeries,
        function ($key) use ($from, $to) {
            return $key >= $from && $key <= $to;
        },
        ARRAY_FILTER_USE_KEY
    );

    // Compute stats if we have values
    $validValues = array_filter($filteredSeries, fn($v) => $v !== null);
    if (count($validValues) > 0) {
        // Average
        $avgSelected = array_sum($validValues) / count($validValues);

        // Max & Min
        $maxSelected = max($validValues);
        $minSelected = min($validValues);

        // Dates for Max & Min
        $maxDate = array_keys($validValues, $maxSelected)[0];
        $minDate = array_keys($validValues, $minSelected)[0];

        // Linear regression slope (trend)
        $x = range(0, count($validValues) - 1); // time as sequential integers
        $y = array_values($validValues);

        $n = count($x);
        $sumX = array_sum($x);
        $sumY = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
        }

        // slope m = (nΣxy − ΣxΣy) / (nΣx² − (Σx)²)
        $denominator = ($n * $sumX2 - $sumX * $sumX);
        if ($denominator != 0) {
            $trendSlope = ($n * $sumXY - $sumX * $sumY) / $denominator;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Unemployment Rate in Lithuania</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; }
        #chart-container {
            position: relative;
            height: 600px;
            width: 100%;
            max-width: 1200px;
            margin: 20px auto;
        }
        table { border-collapse: collapse; margin-top: 20px; width: 80%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
        th { background: #f2f2f2; }
    </style>
</head>
<body>
    <h2>Unemployment Rate in Lithuania (Eurostat)</h2>

    <!-- Form -->
    <form method="get">
        From:
        <select name="from">
            <?php foreach ($months as $m): ?>
                <option value="<?= $m ?>" <?= $m == $from ? "selected" : "" ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
        To:
        <select name="to">
            <?php foreach ($months as $m): ?>
                <option value="<?= $m ?>" <?= $m == $to ? "selected" : "" ?>><?= $m ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Update</button>
    </form>

    <?php if ($errorMessage): ?>
        <div class="error"><?= $errorMessage ?></div>
    <?php else: ?>
        <p>
            Average: <b><?= $avgSelected !== null ? round($avgSelected, 2) . "%" : "NA" ?></b><br>
            Max: <b><?= $maxSelected !== null ? $maxSelected . "%" : "NA" ?></b>
            <?= $maxDate ? " (in <b>$maxDate</b>)" : "" ?><br>
            Min: <b><?= $minSelected !== null ? $minSelected . "%" : "NA" ?></b>
            <?= $minDate ? " (in <b>$minDate</b>)" : "" ?><br>
            Trend slope (m): <b><?= $trendSlope !== null ? round($trendSlope, 4) : "NA" ?></b>
        </p>

        <div id="chart-container">
            <canvas id="chart"></canvas>
        </div>

        <script>
            const ctx = document.getElementById('chart');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode(array_keys($filteredSeries)) ?>,
                    datasets: [{
                        label: 'Unemployment Rate (%)',
                        data: <?= json_encode(array_values($filteredSeries)) ?>,
                        borderColor: 'blue',
                        backgroundColor: 'rgba(0, 0, 255, 0.1)',
                        fill: true,
                        tension: 0.2,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        showLine: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        tooltip: { enabled: true },
                        title: {
                            display: true,
                            text: 'Lithuania Monthly Unemployment Rate'
                        }
                    },
                    scales: {
                        y: { title: { display: true, text: '%' } },
                        x: { title: { display: true, text: 'Month' } }
                    }
                }
            });
        </script>

        <h3>Data Table</h3>
        <table>
            <tr><th>Month</th><th>Unemployment Rate (%)</th></tr>
            <?php
            foreach ($filteredSeries as $month => $rate) {
                echo "<tr>";
                echo "<td>" . $month . "</td>";
                echo "<td>" . ($rate !== null ? $rate : "NA") . "</td>";
                echo "</tr>";
            }
            ?>
        </table>
    <?php endif; ?>
</body>
</html>
