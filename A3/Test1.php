<?php
declare(strict_types=1);

/*   EurostatClient   */
class EurostatClient
{
    private string $url;

    public function __construct(
        private string $geo = 'LT',
        private string $unit = 'PC_ACT',
        private string $sex = 'T',   // T = Total / All, M = Male, F = Female
        private string $age = 'TOTAL'
    ) {
        $this->url = sprintf(
            'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/une_rt_m?geo=%s&unit=%s&sex=%s&age=%s',
            rawurlencode($this->geo),
            rawurlencode($this->unit),
            rawurlencode($this->sex),
            rawurlencode($this->age)
        );
    }

    /* Fetch time series from Eurostat and return array keyed by time (e.g. "2020-01") */
    public function fetch(): array
    {
        $json = @file_get_contents($this->url);
        if ($json === false) {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $timeIndex = $data['dimension']['time']['category']['index'] ?? [];
        $timeMapping = array_flip($timeIndex);
        $values = $data['value'] ?? [];
        $series = [];

        foreach ($values as $idx => $val) {
            if (isset($timeMapping[$idx])) {
                $series[$timeMapping[$idx]] = $val;
            }
        }

        ksort($series);
        return $series;
    }
}

/*   DataProcessor   */
class DataProcessor
{
    public function __construct(private array $series) {}

    public function filterRange(string $from, string $to): array
    {
        return array_filter(
            $this->series,
            fn($key) => $key >= $from && $key <= $to,
            ARRAY_FILTER_USE_KEY
        );
    }

    private function validValues(array $filtered): array
    {
        return array_values(array_filter($filtered, fn($v) => $v !== null));
    }

    private function validAssoc(array $filtered): array
    {
        return array_filter($filtered, fn($v) => $v !== null);
    }

    public function average(array $filtered): ?float
    {
        $vals = $this->validValues($filtered);
        if (empty($vals)) return null;
        return array_sum($vals) / count($vals);
    }

    public function maximum(array $filtered): ?array
    {
        $vals = $this->validAssoc($filtered);
        if (empty($vals)) return null;
        $max = max(array_values($vals));
        $date = array_search($max, $vals, true);
        return ['value' => $max, 'date' => $date];
    }

    public function minimum(array $filtered): ?array
    {
        $vals = $this->validAssoc($filtered);
        if (empty($vals)) return null;
        $min = min(array_values($vals));
        $date = array_search($min, $vals, true);
        return ['value' => $min, 'date' => $date];
    }

    public function median(array $filtered): ?float
    {
        $vals = $this->validValues($filtered);
        if (empty($vals)) return null;
        sort($vals);
        $c = count($vals);
        return ($c % 2 === 0) ? ($vals[$c/2 - 1] + $vals[$c/2]) / 2 : $vals[(int)floor($c/2)];
    }

    public function stddev(array $filtered): ?float
    {
        $vals = $this->validValues($filtered);
        $n = count($vals);
        if ($n === 0) return null;
        $mean = array_sum($vals) / $n;
        $sumSq = 0.0;
        foreach ($vals as $v) $sumSq += ($v - $mean) * ($v - $mean);
        return sqrt($sumSq / $n);
    }

    public function pctChange(array $filtered): ?float
    {
        $vals = $this->validValues($filtered);
        if (count($vals) < 2) return null;
        $first = reset($vals);
        $last = end($vals);
        return is_numeric($first) && $first != 0 ? (($last - $first) / $first) * 100 : null;
    }

    public function trendSlope(array $filtered): ?array
    {
        $y = $this->validValues($filtered);
        $n = count($y); if ($n === 0) return null;
        $x = range(0, $n - 1);
        $sumX = array_sum($x); $sumY = array_sum($y);
        $sumXY = 0.0; $sumX2 = 0.0;
        for ($i = 0; $i < $n; $i++) { $sumXY += $x[$i] * $y[$i]; $sumX2 += $x[$i] * $x[$i]; }
        $den = ($n * $sumX2 - $sumX * $sumX);
        if ($den == 0) return ['slope' => 0.0, 'intercept' => $sumY / $n];
        $slope = ($n * $sumXY - $sumX * $sumY) / $den;
        $intercept = ($sumY - $slope * $sumX) / $n;
        return ['slope' => $slope, 'intercept' => $intercept];
    }

    public function movingAverage(array $filtered, int $window = 3): array
    {
        $vals = array_values($filtered);
        $n = count($vals);
        $ma = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($i + 1 >= $window) {
                $slice = array_slice($vals, $i - $window + 1, $window);
                $ma[$i] = array_sum($slice) / count($slice);
            }
        }
        return $ma;
    }
}

/*   AnalysisManager   */
class AnalysisManager
{
    private array $availableAnalyses = [
        'average' => ['Average', 'average'],
        'max' => ['Max', 'maximum'],
        'min' => ['Min', 'minimum'],
        'median' => ['Median', 'median'],
        'stddev' => ['Std Dev', 'stddev'],
        'pct_change' => ['Pct Change (%)', 'pctChange'],
        'trend_slope' => ['Trend Slope', 'trendSlope']
    ];

    public function __construct(private DataProcessor $processor) {}

    public function getAvailable(): array { return $this->availableAnalyses; }

    public function run(array $selected, array $filtered, int $movingWindow = 3): array
    {
        $results = [];
        foreach ($selected as $key) {
            if (!isset($this->availableAnalyses[$key])) continue;
            $method = $this->availableAnalyses[$key][1];
            if (!method_exists($this->processor, $method)) continue;
            $results[$key] = $this->processor->{$method}($filtered);
        }
        return $results;
    }

    public function summaryLines(array $results): array
    {
        $lines = [];
        foreach ($results as $key => $res) {
            $label = $this->availableAnalyses[$key][0] ?? $key;
            if ($res === null) {
                $lines[] = "$label: NA";
                continue;
            }
            match ($key) {
                'average' => $lines[] = sprintf('%s: <strong>%.2f%%</strong>', $label, (float)$res),
                'max' => $lines[] = sprintf('%s: <strong>%.2f%%</strong> (in <strong>%s</strong>)', $label, (float)$res['value'], $res['date'] ?? ''),
                'min' => $lines[] = sprintf('%s: <strong>%.2f%%</strong> (in <strong>%s</strong>)', $label, (float)$res['value'], $res['date'] ?? ''),
                'median' => $lines[] = sprintf('%s: <strong>%.2f%%</strong>', $label, (float)$res),
                'stddev' => $lines[] = sprintf('%s: <strong>%.4f</strong>', $label, (float)$res),
                'pct_change' => $lines[] = sprintf('%s: <strong>%.2f%%</strong>', $label, (float)$res),
                'trend_slope' => $lines[] = sprintf('%s: <strong>%.6f</strong>', $label, (float)$res['slope']),
                default => $lines[] = $label . ': (unknown format)'
            };
        }
        return $lines;
    }
}

/*   ChartManager   */
class ChartManager
{
    private array $availableCharts = [
        'line' => 'Line',
        'bar' => 'Bar',
        'scatter' => 'Scatter',
        'trendline' => 'Trend Line',
        'moving_avg' => 'Moving Average'
    ];

    private array $colors = [
        'line' => 'rgba(54,162,235,1)',
        'line_bg' => 'rgba(54,162,235,0.2)',
        'bar' => 'rgba(255,159,64,0.8)',
        'scatter' => 'rgba(75,192,192,1)',
        'moving_avg' => 'rgba(153,102,255,1)',
        'trendline' => 'rgba(255,99,132,0.8)'
    ];

    public function __construct(private DataProcessor $processor) {}

    public function getAvailable(): array { return $this->availableCharts; }

    public function buildDatasets(array $selectedCharts, array $filtered, int $movingWindow = 3): array
    {
        $datasets = [];

        foreach ($selectedCharts as $key) {
            if (!isset($this->availableCharts[$key])) continue;
            switch ($key) {
                case 'line':
                    $datasets[] = [
                        'label' => 'Unemployment Rate',
                        'data' => array_values($filtered),
                        'type' => 'line',
                        'tension' => 0.15,
                        'borderColor' => $this->colors['line'],
                        'backgroundColor' => $this->colors['line_bg'],
                        'pointRadius' => 3,
                        'fill' => false
                    ];
                    break;
                case 'bar':
                    $datasets[] = [
                        'label' => 'Unemployment (bar)',
                        'data' => array_values($filtered),
                        'type' => 'bar',
                        'backgroundColor' => $this->colors['bar']
                    ];
                    break;
                case 'scatter':
                    $labels = array_keys($filtered);
                    $scat = [];
                    $vals = array_values($filtered);
                    for ($i = 0; $i < count($vals); $i++) {
                        $scat[] = ['x' => $labels[$i], 'y' => $vals[$i]];
                    }
                    $datasets[] = [
                        'label' => 'Scatter points',
                        'data' => $scat,
                        'type' => 'scatter',
                        'showLine' => false,
                        'pointRadius' => 5,
                        'backgroundColor' => $this->colors['scatter']
                    ];
                    break;
                case 'moving_avg':
                    $ma = $this->processor->movingAverage($filtered, max(1, $movingWindow));
                    $datasets[] = [
                        'label' => 'Moving Average',
                        'data' => $ma,
                        'type' => 'line',
                        'borderDash' => [6, 4],
                        'tension' => 0.2,
                        'pointRadius' => 0,
                        'borderColor' => $this->colors['moving_avg']
                    ];
                    break;
                case 'trendline':
                    $trend = $this->processor->trendSlope($filtered);
                    if ($trend !== null) {
                        $n = count($filtered);
                        $pts = [];
                        for ($i = 0; $i < $n; $i++) $pts[] = $trend['slope'] * $i + $trend['intercept'];
                        $datasets[] = [
                            'label' => 'Trend Line (best fit)',
                            'data' => $pts,
                            'type' => 'line',
                            'borderColor' => $this->colors['trendline'],
                            'pointRadius' => 0,
                            'tension' => 0
                        ];
                    }
                    break;
            }
        }

        if (empty($datasets)) {
            $datasets[] = [
                'label' => 'Unemployment Rate',
                'data' => array_values($filtered),
                'type' => 'line',
                'tension' => 0.15,
                'borderColor' => $this->colors['line']
            ];
        }

        return $datasets;
    }
}

/*  ReportBuilder   */
class ReportBuilder
{
    public function run(): void
    {
        // sex filter from GET (T, M, F)
        $sex = $this->getParam('sex', 'T');

        // create client and fetch series
        $client = new EurostatClient(sex: $sex);
        $series = $client->fetch();

        if (empty($series)) {
            echo '<h2>Unable to fetch data from Eurostat. Please check network or try again later.</h2>';
            exit;
        }

        $months = array_keys($series);
        $defaultFrom = reset($months);
        $defaultTo = end($months);

        // Use GET params 'from' and 'to' (these will be set by hidden inputs on submit)
        $from = $this->getParam('from', $defaultFrom);
        $to = $this->getParam('to', $defaultTo);

        // keep inputs safe
        $analysesSelected = $this->getArrayParam('analyses');
        $chartsSelected = $this->getArrayParam('charts');
        $title = htmlspecialchars($this->getParam('report_title', 'Unemployment Report'));
        $description = htmlspecialchars($this->getParam('report_description', 'Report generated from Eurostat dataset.'));
        $movingWindow = (int)$this->getParam('moving_window', 3);

        if ($from > $to) {
            $errorMessage = "Error: 'From' date cannot be later than 'To' date.";
        } else {
            $errorMessage = '';
        }

        $processor = new DataProcessor($series);
        $filtered = $processor->filterRange($from, $to);

        $analysisManager = new AnalysisManager($processor);
        $analysisResults = empty($errorMessage) ? $analysisManager->run($analysesSelected, $filtered, $movingWindow) : [];
        $summaryLines = $analysisManager->summaryLines($analysisResults);

        $chartManager = new ChartManager($processor);
        $datasets = $chartManager->buildDatasets($chartsSelected, $filtered, $movingWindow);

        $labelsJson = json_encode(array_keys($filtered));
        $datasetsJson = json_encode($datasets);

        $this->renderHtml($months, $from, $to, $analysesSelected, $chartsSelected, $title, $description, $movingWindow, $errorMessage, $summaryLines, $filtered, $labelsJson, $datasetsJson, $sex);
    }

    private function getParam(string $name, $default = null)
    {
        $val = filter_input(INPUT_GET, $name, FILTER_DEFAULT);
        return $val === null ? $default : $val;
    }

    private function getArrayParam(string $name): array
    {
        $arr = $_GET[$name] ?? [];
        return is_array($arr) ? $arr : [];
    }

    private function renderHtml(array $months, string $from, string $to, array $analysesSelected, array $chartsSelected, string $title, string $description, int $movingWindow, string $errorMessage, array $summaryLines, array $filteredSeries, string $labelsJson, string $datasetsJson, string $sex): void
    {
        $analysisManager = new AnalysisManager(new DataProcessor([]));
        $availableAnalyses = $analysisManager->getAvailable();

        $chartManager = new ChartManager(new DataProcessor([]));
        $availableCharts = $chartManager->getAvailable();

        // Render HTML
        ?>
        <!doctype html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?= $title ?></title>

            <!-- Chart.js -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

            <!-- noUiSlider (dual-handle slider) for date range -->
            <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/nouislider@15.7.1/dist/nouislider.min.css">
            <script src="https://cdn.jsdelivr.net/npm/nouislider@15.7.1/dist/nouislider.min.js"></script>

            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .container { max-width: 1200px; margin: 0 auto; }
                .controls { margin-bottom: 20px; background: #f8f8f8; padding: 12px; border-radius: 6px; }
                label { margin-right: 8px; }
                #chart-container { position: relative; height: 500px; width: 100%; }
                table { border-collapse: collapse; width: 100%; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
                th { background: #f2f2f2; }
                .slider-wrap { margin-top: 10px; margin-bottom: 10px; }
                .no-print { }

                @media print {
                    .controls, .no-print { display:none !important; }
                    .print-header { display:block; margin-bottom:10px; }
                }

                .print-header { display:none; }

                /* Make the slider track thin and centered */
                #dateSlider.noUi-target {
                    height: 6px !important;
                    margin-top: 16px;
                    margin-bottom: 12px;
                }

                /* Connect (selected range) bar */
                #dateSlider .noUi-connect {
                    background: #3b82f6 !important; /* blue */
                    height: 6px !important;
                    top: 0 !important;
                }

                /* Base track */
                #dateSlider .noUi-base {
                    height: 6px !important;
                    top: 0 !important;
                }

                /* Smaller circular handles */
                #dateSlider .noUi-handle {
                    width: 14px !important;
                    height: 14px !important;
                    border-radius: 50% !important;
                    background: #ffffff !important;
                    border: 2px solid #555 !important;
                    box-shadow: none !important;

                    /* center the knob on the track */
                    top: -4px !important;

                    transform: translateX(-5px);

                    cursor: grab;
                }

                #dateSlider .noUi-handle:active {
                    cursor: grabbing;
                }
            </style>

        </head>
        <body>
        <div class="container">
            <h1>Unemployment Rate (Lithuania) — Interactive Report</h1>

            <form method="get" class="controls no-print" id="controlsForm">
                <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
                    <!-- Gender selection -->
                    <div>
                        <label for="sex">Gender</label><br>
                        <select id="sex" name="sex">
                            <option value="T" <?= $sex === 'T' ? 'selected' : '' ?>>All</option>
                            <option value="M" <?= $sex === 'M' ? 'selected' : '' ?>>Male</option>
                            <option value="F" <?= $sex === 'F' ? 'selected' : '' ?>>Female</option>
                        </select>
                    </div>

                    <!-- Date range: visible date inputs (no name) + single noUiSlider + hidden inputs for submission -->
                    <div style="flex:1; min-width:320px;">
                        <label>Date Range</label><br>

                        <!-- visible date inputs: no 'name' attribute so they are NOT submitted directly
                             note: we append -01 so browser accepts as a valid date value -->
                        <input type="date" id="fromDate" value="<?= htmlspecialchars($from) ?>-01" style="width:140px;">
                        &nbsp;–&nbsp;
                        <input type="date" id="toDate" value="<?= htmlspecialchars($to) ?>-01" style="width:140px;"><br>

                        <div class="slider-wrap">
                            <div id="dateSlider"></div>
                        </div>

                        <!-- hidden fields to be submitted (still YYYY-MM) -->
                        <input type="hidden" id="from" name="from" value="<?= htmlspecialchars($from) ?>">
                        <input type="hidden" id="to" name="to" value="<?= htmlspecialchars($to) ?>">
                    </div>

                    <!-- Analyses checkboxes -->
                    <div>
                        <strong>Analyses</strong><br>
                        <?php foreach ($availableAnalyses as $k => $arr): ?>
                            <label style="display:block;white-space:nowrap;">
                                <input type="checkbox" name="analyses[]" value="<?= $k ?>" <?= in_array($k, $analysesSelected) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($arr[0]) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <!-- Charts checkboxes -->
                    <div>
                        <strong>Charts</strong><br>
                        <?php foreach ($availableCharts as $k => $lab): ?>
                            <label style="display:block;white-space:nowrap;">
                                <input type="checkbox" name="charts[]" value="<?= $k ?>" <?= in_array($k, $chartsSelected) ? 'checked' : '' ?> class="chart-checkbox" data-chart-key="<?= $k ?>">
                                <?= htmlspecialchars($lab) ?>
                            </label>
                            <?php if ($k === 'moving_avg'): ?>
                                <div class="moving-window-wrapper" style="display:<?= in_array('moving_avg', $chartsSelected) ? 'block' : 'none' ?>; margin-top:6px; margin-left:4px;">
                                    <label for="moving_window">Moving window</label>
                                    <input id="moving_window" name="moving_window" type="number" min="1" max="24" value="<?= $movingWindow ?>">
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>

                    <!-- Title & description -->
                    <div style="min-width:260px;">
                        <label for="report_title">Report Title</label><br>
                        <input id="report_title" name="report_title" type="text" value="<?= $title ?>" style="width:100%;"><br>
                        <label for="report_description">Report Description</label><br>
                        <textarea id="report_description" name="report_description" style="width:100%;" rows="2"><?= $description ?></textarea>
                    </div>

                    <!-- Actions -->
                    <div style="display:flex; gap:8px; align-items:center;">
                        <button type="submit">Update</button>
                        <button type="button" onclick="preparePrintable()">Print Report</button>
                        <a class="no-print" href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" title="Reset">Reset</a>
                    </div>
                </div>
            </form>

            <div class="print-header" id="printHeader">
                <h2 id="printTitle"></h2>
                <div id="printDescription"></div>
                <hr>
            </div>

            <?php if ($errorMessage): ?>
                <div class="error"><?= htmlspecialchars($errorMessage) ?></div>
            <?php else: ?>

                <section id="summary">
                    <h3>Summary</h3>
                    <p>
                        <?php foreach ($summaryLines as $line): ?>
                            <?= $line ?><br>
                        <?php endforeach; ?>
                    </p>
                </section>

                <section id="charts">
                    <h3>Charts</h3>
                    <div id="chart-container"><canvas id="chartCanvas"></canvas></div>
                </section>

                <section id="data-table">
                    <h3>Data Table</h3>
                    <table>
                        <thead><tr><th>Month</th><th>Unemployment Rate (%)</th></tr></thead>
                        <tbody>
                        <?php foreach ($filteredSeries as $month => $rate): ?>
                            <tr><td><?= htmlspecialchars($month) ?></td><td><?= $rate !== null ? $rate : 'NA' ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

            <?php endif; ?>

        </div>

        <script>
            // Chart data from PHP
            const labels = <?= $labelsJson ?>;
            const datasetsFromServer = <?= $datasetsJson ?>;
            // Deep copy
            const datasets = datasetsFromServer.map(ds => Object.assign({}, ds));

            // Build Chart.js chart
            const ctx = document.getElementById('chartCanvas').getContext('2d');
            const chart = new Chart(ctx, {
                type: 'line',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true },
                        title: { display: true, text: 'Lithuania Unemployment: Selected Charts' }
                    },
                    scales: {
                        x: { title: { display: true, text: 'Month' } },
                        y: { title: { display: true, text: '%' } }
                    }
                }
            });

            // Show/hide moving window input when moving_avg checkbox toggled
            document.querySelectorAll('.chart-checkbox').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    const key = cb.dataset.chartKey;
                    if (key === 'moving_avg') {
                        const wrapper = document.querySelector('.moving-window-wrapper');
                        if (wrapper) wrapper.style.display = cb.checked ? 'block' : 'none';
                    }
                });
            });

            function preparePrintable() {
                document.getElementById('printTitle').innerText = document.getElementById('report_title')?.value || 'Unemployment Report';
                document.getElementById('printDescription').innerText = document.getElementById('report_description')?.value || '';
                document.getElementById('printHeader').style.display = 'block';
                setTimeout(() => { window.print(); }, 200);
            }

            /*    noUiSlider (dual-handle) integration    */
            (function () {
                // months array (same order as server)
                const months = <?= json_encode($months) ?>;

                // Elements
                const sliderEl = document.getElementById('dateSlider');
                const fromDateEl = document.getElementById('fromDate'); // visible (no name) input (YYYY-MM-01)
                const toDateEl = document.getElementById('toDate');     // visible (YYYY-MM-01)
                const fromHiddenEl = document.getElementById('from');   // hidden, submitted (YYYY-MM)
                const toHiddenEl = document.getElementById('to');       // hidden, submitted (YYYY-MM)

                // helper: get YYYY-MM key from possible inputs (YYYY-MM or YYYY-MM-01)
                function monthKeyFrom(val) {
                    if (typeof val !== 'string') return '';
                    if (val.length >= 7) return val.slice(0,7);
                    return val;
                }

                // helper: find index in months array; returns -1 if not found
                function getIndex(val) {
                    const key = monthKeyFrom(val);
                    return months.indexOf(key);
                }

                // Determine initial start indices; robust fallbacks so slider doesn't start at [0,0]
                let startFromIdx = getIndex(fromHiddenEl.value);
                let startToIdx = getIndex(toHiddenEl.value);
                if (startFromIdx === -1) startFromIdx = 0;
                if (startToIdx === -1) startToIdx = Math.max(0, months.length - 1);

                // Create slider with numeric indices that map to months array
                noUiSlider.create(sliderEl, {
                    start: [ startFromIdx, startToIdx ],
                    connect: true,
                    range: { min: 0, max: Math.max(0, months.length - 1) },
                    step: 1,
                    // remove tooltips above knobs (they were enabled previously)
                    tooltips: [false, false]
                });

                // When slider updates, set visible date inputs and hidden submit fields
                sliderEl.noUiSlider.on('update', function (values, handle) {
                    const fromIndex = Math.round(values[0]);
                    const toIndex = Math.round(values[1]);

                    // Ensure order
                    const a = Math.min(fromIndex, toIndex);
                    const b = Math.max(fromIndex, toIndex);

                    // map to month strings (YYYY-MM)
                    const fromStr = months[a] || months[0];
                    const toStr = months[b] || months[months.length - 1];

                    // update visible date inputs (append -01 so HTML date accepts the value)
                    fromDateEl.value = fromStr + "-01";
                    toDateEl.value   = toStr + "-01";

                    // update hidden inputs (these are submitted) — keep YYYY-MM format
                    fromHiddenEl.value = fromStr;
                    toHiddenEl.value = toStr;
                });

                // When visible date inputs change, update slider
                function updateSliderFromDates() {
                    // visible inputs have YYYY-MM-01 format; slice to YYYY-MM
                    let fiKey = monthKeyFrom(fromDateEl.value);
                    let tiKey = monthKeyFrom(toDateEl.value);

                    let fi = getIndex(fiKey);
                    let ti = getIndex(tiKey);

                    if (fi === -1) fi = 0;
                    if (ti === -1) ti = months.length - 1;

                    // If user swapped dates, swap values in both visible inputs and hidden inputs, and set slider accordingly
                    if (fi > ti) {
                        const tmpFi = fi, tmpTi = ti;
                        fi = tmpTi; ti = tmpFi;

                        const tmpFrom = months[fi] || months[0];
                        const tmpTo = months[ti] || months[months.length - 1];
                        fromDateEl.value = tmpFrom + "-01";
                        toDateEl.value = tmpTo + "-01";
                        fromHiddenEl.value = tmpFrom;
                        toHiddenEl.value = tmpTo;
                    } else {
                        // update hidden fields to match visible
                        fromHiddenEl.value = months[fi] || months[0];
                        toHiddenEl.value = months[ti] || months[months.length - 1];
                    }

                    sliderEl.noUiSlider.set([fi, ti]);
                }

                // Wire change events (change = when user leaves field; input could be used for instant)
                fromDateEl.addEventListener('change', updateSliderFromDates);
                toDateEl.addEventListener('change', updateSliderFromDates);

                // Ensure hidden fields reflect initial visible inputs (visible inputs have -01)
                fromHiddenEl.value = monthKeyFrom(fromDateEl.value) || months[0];
                toHiddenEl.value = monthKeyFrom(toDateEl.value) || months[months.length - 1];
            })();
        </script>
        </body>
        </html>
        <?php
    }
}

/* Run the application */
$app = new ReportBuilder();
$app->run();
