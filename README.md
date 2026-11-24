# FloodWatch — local flood monitoring dashboard

FloodWatch is a PHP dashboard for XAMPP, MySQL, and an ESP32. Sensor readings are stored in a local MySQL database; optional alarm controls update Blynk Cloud so the ESP32 can change buzzer modes.

## Setup

1. Put this directory in XAMPP's `htdocs` folder.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Import [`schema.sql`](./database/schema.sql) into MySQL using phpMyAdmin's **Import** tab, or run it in the MySQL command-line client.
4. Open `http://localhost/FloodDetectingSystem/backend/`. The default connection in [`config.php`](./backend/config.php) matches a standard XAMPP installation (`root`, no password). Set `FLOOD_DB_HOST`, `FLOOD_DB_NAME`, `FLOOD_DB_USER`, and `FLOOD_DB_PASSWORD` to override it.
5. Read [the setup guide](./backend/setup.php) for ESP32 payload fields, LAN setup, and Blynk alarm control.
6. Copy [`config.local.example.php`](./backend/config.local.example.php) to `config.local.php` and put your Blynk device token in that local file. It is excluded from Git; the website uses it on the server to set the ESP32's V2 alarm mode.

## Send readings

Make an HTTP `POST` to `/FloodDetectingSystem/backend/ingest.php` with `Content-Type: application/json`:

```json
{
  "water_level_cm": 18.2,
  "distance_cm": 6.8,
  "temperature_c": 29.4,
  "humidity_pct": 82,
  "rain_detected": true,
  "rain_sensor_value": 4095,
  "rain_probability_pct": 65,
  "alert_level": "warning"
}
```

`water_level_cm` is required. The other measurements are optional; supported `alert_level` values are `normal`, `watch`, `warning`, and `danger`. `rain_detected` controls the Rain/No rain status, while `rain_sensor_value` stores the raw analog reading (0–4095 for a 12-bit ESP32 ADC). For example, if your hardware reports the distance from a fixed sensor to the surface, calculate water depth on the ESP32 as `sensorHeightCm - measuredDistanceCm` and send that as `water_level_cm`. The `distance_cm` field is retained separately for display and history.

An optional `INGEST_API_KEY` in `config.php` enables an `X-API-Key` header check. The dashboard's JSON refresh endpoint is `backend/api.php`. Rain probability is recorded from a supplied forecast value; the DHT11 supplies temperature and humidity, not rain probability. Set the warning and danger thresholds in `backend/index.php` for your installation.

The dashboard's **Alarm Control** buttons update Blynk V2 using the device HTTPS API: `0` is Auto, `1` is Siren ON, and `2` is Silence. The ESP32 firmware must keep its `BLYNK_WRITE(V2)` handler and matching buzzer logic enabled.

If the database is empty or unavailable, the dashboard shows clearly labeled, illustrative data. Live connectivity indicators become active once readings are present.
