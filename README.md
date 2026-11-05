# FloodWatch — local flood monitoring dashboard

FloodWatch is a standalone PHP dashboard for XAMPP, MySQL, and an ESP32. It stores incoming readings in a local MySQL database, plots recent water levels, estimates rate of rise and time to danger, and displays a searchable-by-export historical log without depending on a cloud API.

## Setup

1. Put this directory in XAMPP's `htdocs` folder.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Import [`schema.sql`](./schema.sql) into MySQL using phpMyAdmin's **Import** tab, or run it in the MySQL command-line client.
4. Open `http://localhost/FloodDetectingSystem/`. The default connection in [`config.php`](./config.php) matches a standard XAMPP installation (`root`, no password). Set `FLOOD_DB_HOST`, `FLOOD_DB_NAME`, `FLOOD_DB_USER`, and `FLOOD_DB_PASSWORD` to override it.
5. Read [the setup guide](./setup.php) for ESP32 payload fields and LAN setup.

## Send readings

Make an HTTP `POST` to `/FloodDetectingSystem/ingest.php` with `Content-Type: application/json`:

```json
{
  "water_level_cm": 18.2,
  "distance_cm": 6.8,
  "temperature_c": 29.4,
  "humidity_pct": 82,
  "rain_detected": true,
  "rain_probability_pct": 65,
  "alert_level": "warning"
}
```

`water_level_cm` is required. The other measurements are optional; supported `alert_level` values are `normal`, `watch`, `warning`, and `danger`. For example, if your hardware reports the distance from a fixed sensor to the surface, calculate water depth on the ESP32 as `sensorHeightCm - measuredDistanceCm` and send that as `water_level_cm`. The `distance_cm` field is retained separately for display and history.

An optional `INGEST_API_KEY` in `config.php` enables an `X-API-Key` header check. The dashboard's JSON refresh endpoint is `api.php`. Rain probability is recorded from a supplied forecast value; the DHT11 supplies temperature and humidity, not rain probability. Set the warning and danger thresholds in `index.php` for your installation.

If the database is empty or unavailable, the dashboard shows clearly labeled, illustrative data. Live connectivity indicators become active once readings are present.
