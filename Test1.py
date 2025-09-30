import requests
import pandas as pd
import matplotlib.pyplot as plt

# 1. Load Eurostat data
url = "https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data/une_rt_m?geo=LT&unit=PC_ACT&sex=T&age=TOTAL"
response = requests.get(url)
data = response.json()

# 2. Extract time mapping
time_index = data["dimension"]["time"]["category"]["index"]
index_to_time = {v: k for k, v in time_index.items()}

# 3. Build aligned dataframe
records = []
for idx_str, val in data["value"].items():
    idx = int(idx_str)
    if idx in index_to_time:
        records.append({
            "time": index_to_time[idx],
            "unemployment_rate": val
        })

df = pd.DataFrame(records)
df["time"] = pd.to_datetime(df["time"], format="%Y-%m")
df = df.sort_values("time")

# 4. Define COVID stages
pre_covid = df[(df["time"] >= "2015-01") & (df["time"] < "2020-01")]
covid = df[(df["time"] >= "2020-01") & (df["time"] <= "2021-12")]
post_covid = df[df["time"] >= "2022-01"]

# 5. Plot all stages on one chart
plt.figure(figsize=(14,7))
plt.plot(pre_covid["time"], pre_covid["unemployment_rate"], label="Pre-COVID (2015-2019)", color="green")
plt.plot(covid["time"], covid["unemployment_rate"], label="COVID Era (2020-2021)", color="red")
plt.plot(post_covid["time"], post_covid["unemployment_rate"], label="Post-COVID (2022-Now)", color="blue")

plt.title("Lithuania: Unemployment Rate Trends (Eurostat)")
plt.xlabel("Year")
plt.ylabel("Unemployment Rate (%)")
plt.legend()
plt.grid(True)
plt.tight_layout()
plt.savefig("covid_trends.png", dpi=300)
plt.show()

# 6. Compute stage averages
summary = pd.DataFrame({
    "Stage": ["Pre-COVID (2015-2019)", "COVID Era (2020-2021)", "Post-COVID (2022-Now)"],
    "Average Unemployment (%)": [
        pre_covid["unemployment_rate"].mean(),
        covid["unemployment_rate"].mean(),
        post_covid["unemployment_rate"].mean()
    ]
})

print("\nUnemployment Stage Averages:")
print(summary)

# 7. Output trend insights
pre_avg = pre_covid["unemployment_rate"].mean()
covid_avg = covid["unemployment_rate"].mean()
post_avg = post_covid["unemployment_rate"].mean()

print("\nTrend Insights:")
if covid_avg > pre_avg:
    print(f"- Unemployment increased during COVID: {pre_avg:.2f}% → {covid_avg:.2f}%")
else:
    print(f"- Unemployment decreased during COVID: {pre_avg:.2f}% → {covid_avg:.2f}%")

if post_avg < covid_avg:
    print(f"- Post-COVID recovery: unemployment fell to {post_avg:.2f}%")
else:
    print(f"- Post-COVID unemployment remained high at {post_avg:.2f}%")
