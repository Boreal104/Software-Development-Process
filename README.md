# Software-Development-Process

# Lithuania Unemployment Rate Analysis (Eurostat API)

## 📌 Project Goal
The aim of this project is to demonstrate how to:
- Select a dataset from a reputable open-data portal.
- Load it programmatically (via API).
- Process it with simple transformations.
- Compute relevant metrics.
- Visualize the results as an interactive figure and a table.

We chose the **monthly unemployment rate in Lithuania** from **Eurostat** because:
- It is a widely recognized socio-economic indicator.
- Data is updated regularly and openly accessible.
- The Eurostat API provides structured JSON responses, which makes it suitable for programmatic use.

---

## 📊 Dataset Details
- **Title**: Unemployment rate, monthly data (% of active population)  
- **Source**: [Eurostat](https://ec.europa.eu/eurostat)  
- **API endpoint used**: https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/une_rt_m?geo=LT&unit=PC_ACT&sex=T&age=TOTAL
- **Format**: JSON  
- **Coverage**: Lithuania (`geo=LT`), both sexes (`sex=T`), all ages (`age=TOTAL`), unit = % of active population (`unit=PC_ACT`).  

The dataset provides **monthly unemployment rates** expressed as a percentage of the active population.

---

## ⚙️ Methodology

### 1. Data Loading
- The script directly queries the Eurostat REST API.  
- Response is received in **JSON format**, which includes:
- `dimension` → metadata (time, geo, unit, etc.).
- `value` → unemployment rates indexed by time.

### 2. Data Transformation
- Extract the **time mapping** from Eurostat’s index → date labels (e.g., `2020M01`).
- Rebuild a dictionary of `{month → unemployment rate}`.
- Sort the data chronologically.
- Apply filters based on the **user-selected date range** via HTML form.

### 3. Metrics Computed
For the filtered data:
- **Average unemployment rate** = mean of available values.  
- **Maximum unemployment rate** = highest value and its corresponding month.  
- **Minimum unemployment rate** = lowest value and its corresponding month.  
- **Trend slope (m)** = gradient of the best-fit line (`y = mx + c`), indicating whether unemployment is increasing (`m > 0`), decreasing (`m < 0`), or stable (`m ≈ 0`).

### 4. Output
- **Interactive Line Chart** (Chart.js):
- Blue line showing unemployment rate over time.
- Dots represent monthly values.
- Hover tooltip shows exact values.
- **Summary Indicators** displayed above the chart:
- Average, Maximum (with date), Minimum (with date), Trend slope.
- **Data Table** showing month → unemployment rate for the selected range.
- **Error Handling**:
- If “From” date is later than “To” date, an error message is displayed.

---

## 📈 Results & Interpretation
- The line chart makes it easy to observe **trends and fluctuations** in unemployment over time.  
- The computed **average** helps summarize the selected range.  
- The **maximum and minimum** values highlight the extremes (e.g., economic peaks or recovery phases).  
- The **trend slope** (`m`) quantifies direction:
- Positive slope → unemployment gradually increasing.
- Negative slope → unemployment decreasing.
- Near-zero slope → stable unemployment rate.

### Example Interpretation
- Average (2015–2024): **7.1%**  
- Maximum: **11.2% (2020M05)** – peak during COVID-19 crisis.  
- Minimum: **5.3% (2019M10)** – lowest before the pandemic.  
- Trend slope `m = -0.05` → unemployment has slightly decreased on average across the selected period.

---
## 🖼️ Screenshots (Demonstration of Features)

To illustrate the functionality of this project, three example screenshots are provided:

### 1. Default View – Full Dataset
- **File**: `Default.png`  
- **Description**:  
  This screenshot shows the default page load.  
  - All available data from Eurostat is displayed.  
  - The line chart covers the **entire period**.  
  - A data table lists the monthly unemployment rates.  
  - Computed indicators (average, min, max, trend slope) summarize the dataset.  

![Default View](./Default.png)

---

### 2. Custom Range – User Filtering
- **File**: `Custom.png`  
- **Description**:  
  This screenshot demonstrates selecting a **custom date range** using the dropdowns.  
  - Only values between the chosen `From` and `To` dates are shown.  
  - The line chart updates dynamically to reflect this range.  
  - The table and indicators (avg, min, max, slope) are recalculated for the subset.  
  - Useful for analyzing specific **time periods** (e.g., during COVID-19).  

![Custom Range](./Custom.png)

---

### 3. Error Case – Invalid Selection
- **File**: `Error.png`  
- **Description**:  
  This screenshot shows the system’s **error handling**.  
  - If the user selects a `From` date **later** than the `To` date, the script displays a red error message.  
  - No chart or table is shown until the selection is corrected.  
  - This prevents misinterpretation of data and improves user experience.  

![Error Case](./Error.png)

---

## 🖥️ Usage Instructions

### 1. Clone the Repository
```bash
git clone https://github.com/yourusername/unemployment-lithuania.git
cd unemployment-lithuania
