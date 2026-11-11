# DG65-Toolkit Battery Monitor

🇩🇪 [Deutsche Fassung](README.md)

Monitors the batteries of the devices in your home (wireless sensors, thermostats, window contacts, smoke detectors …) for Symcon. It finds the battery values by itself, merges several signals of one device and tells you honestly **how reliable** a value is.

The module follows the language of your Symcon (English or German): forms, variables, findings, notifications and the tile.

## What it does differently

- **Three times instead of one:** the age of the battery value, the device's last sign of life and the device itself. A device that has not sent anything for weeks (radio silence) is something else than a low battery, and an "OK" from 2023 is not OK.
- **Data quality made visible:** contradiction between the "low" flag and the percentage, values outside 0–100 %, outdated battery values.
- **Finding instead of maintaining:** variable profiles (`~Battery`, `~Battery.Reversed`, `~Battery.100`) and typical identifiers; a dry run shows beforehand what would be found and why something is excluded.
- **No false hits from home storage:** energy storage systems, vehicle batteries, aggregate values (the "weakest battery" of a room) and script variables are excluded with a reason.

## Setup

1. Add the module via the module management: `https://github.com/DG65/Batteriewaechter`, or install it from the Module Store.
2. Create a "Battery Monitor" instance.
3. Click "Search again now", then check "What would be found?".
4. Under "Device settings", mark event sensors (window contacts, smoke detectors), critical devices and devices without age check.
5. Link the variables with the HTML tables into your WebFront, or add the instance as a tile. For your own indicators (e.g. in IPSView) there are counters as integer variables for empty, low, radio silence, check data, status unknown, soon empty and action required.

## Cell type, forecast, shopping

- **Stock, preventive replacement, entering past replacements:** Under "Forecast and shopping" you enter the batteries you have at home (the shopping list only shows what is missing) and, for critical devices, a fixed replacement interval in months (smoke detectors: replacement even when the level is still good). Under "Acknowledge and battery log" you add earlier replacements with a date. In the tile, "By location" groups the list, `BWACH_DeviceListCsv` returns the device list as CSV.
- **Shopping list to go:** In the tile under "Shopping", "Copy" copies the list including the replacement round as text, "Send" sends it via push or email. Optionally you enter a link template with `{Zelltyp}` under "Forecast and shopping" (for example the search page of your retailer); the tile then shows a search link behind each line. Empty by default: no retailer is built in and nothing is sent anywhere.
- **Groups instead of single entries:** In the "Groups" panel a rule sets cell type, number of cells, event sensor, critical or "Exclude" for all devices matching its pattern (by location/category, system/module or name, several patterns separated by commas). The first matching rule from the top applies; a device's own setting wins as soon as it differs from the default. New devices matching a rule get its values automatically. The "Applies" column in "Device settings" shows the result per device.
- **Cell type per device** under "Device settings" (e.g. CR2032, 3× AAA). The list already contains every detected device; just choose cell type and number of cells and click "Apply". The monitor then converts voltages into a state of charge and counts the shopping list. The conversion is an **approximation** using typical discharge curves per cell chemistry, not data sheet values of a manufacturer, and is always marked as "calculated from voltage". The cell type cannot be determined reliably from the voltage alone (3 V: coin cell or two alkaline cells); the monitor only names candidates.
- **Forecast:** The module records the history of each device itself and estimates the days remaining ("lasts about 23 more days (medium confidence)"). This needs at least 4 data points over 14 days since the last battery replacement and a state of charge that changes in fine steps. Many devices only report coarse steps or very rarely; there the module honestly says "days remaining unknown" with the reason. "Battery empty soon" is only reported with high or medium confidence.
- **Shopping list and replacement round:** "4× CR2032, 7× AAA" for the next 30 days (adjustable), the replacement round bundled by location with a suggested date. Devices without a cell type are listed separately.
- **Lifetime:** From the recorded battery replacements (log) the module derives the time between two replacements per device, cell type and system. This needs at least two replacements per device and only grows over time.
- **Learned reporting behavior and "probably removed":** If a device reports very regularly, the monitor detects radio silence earlier than with the fixed threshold (never later). A device that has been silent for more than 60 days is suggested as "probably removed".

## Conspicuous devices, radio quality, cold, queries

- **Comparison with similar devices:** If a device discharges at least twice as fast as the median of its group (same system, same cell type, at least 4 devices with a known discharge rate), the monitor marks it and names possible causes (defective device, poor radio link, continuous transmitting). This only appears in the tables, the tile and the weekly report, not as a push.
- **Radio quality:** Where a device provides it (e.g. Zigbee `linkquality`, RSSI), the monitor shows and rates it. The scales differ per system; unknown ones are only shown.
- **Influence of cold:** With an outdoor temperature variable ("Forecast and shopping") the monitor evaluates whether batteries discharge faster in the cold. This needs at least 3 devices with 3 periods each in cold and warm weather, so at the earliest after one winter; until then it says "not enough data yet".
- **Querying sleeping Z-Wave and Matter devices and Comet WiFi thermostats:** Enabled per device (column "Query"), off by default. If the battery value is older than configured, the monitor sends a status request at most every 7 days and shows whether the device answered. A sleeping Z-Wave device only answers when it wakes up next; until then the request waits in the queue of the Z-Wave instance. For Matter, `MATTER_RequestStatus` is called on the power source instance (endpoint 0). A Comet WiFi thermostat receives the "all fields" request via `CWIFI_SendAction`, because only that request contains the battery level; it noticeably wakes the device, so it only runs with the checkbox set and at the configured interval. Without an answer after 6 hours, "no answer" applies.
- **Import from BY_BatterieMonitor:** In the "Notifications" panel the push, email and SMTP settings of an old instance can be copied into the form. They are only saved with "Apply".

## Honest limits

- The **discharge curves** are typical approximations, not measured and not manufacturer-specific.
- The **forecast** needs weeks of history and devices that report the state of charge finely enough. Z-Wave sensors that show "100 %" until shortly before empty get none; there only "empty/low" and the radio silence detection help.
- **Lifetime, influence of cold and the comparison with similar devices** need data that only builds up over months. In the test bench they are covered with simulated histories, not with long-term data of real devices.
- There is deliberately **no cell type library by device model**: without a reliable, maintained source it would guess models.
- Zigbee2MQTT is connected according to its documentation but not tested with a real battery device; HomeMatic and Netatmo are confirmed by feedback from the forum. Matter is tested with one device (IKEA door contact). Matter batteries live in a separate instance for endpoint 0 (power source), which you create by hand.
- The English user interface has not yet been seen in an English Symcon installation.

## Tile

The instance can be added as a tile in the tile visualization. It shows the devices by urgency; the filter row at the top shows only what matters right now (action needed, empty, low, radio silence, check data), "Log" shows the recorded battery replacements. Tapping a row expands it: reasons, age of the battery value, sign of life and the buttons to acknowledge (can be switched off under "Status"). The tile has no title of its own; the instance name provides it.

## Notifications (off by default)

Switch them on under "Notifications". The monitor reports "battery empty", "battery low" and "radio silence"; doubtful data (outdated, contradiction) only appears in the weekly report and the tables.

- **Not on every check:** first notification, then a reminder after N days (critical devices every 2 days), then quiet. A new, worse finding is a first notification again.
- **Bundled:** several findings of one run = one message.
- **Quiet hours** (default 22–7 h): notifications are sent later, not dropped; critical devices may break through.
- **Escalation** only for critical devices: after 24 hours without acknowledgement a second notification via the escalation channels.
- **Weekly report** (default Monday 8 h) with all devices that have a finding.
- **Delivery:** push to all found tile visualizations and WebFront instances (or a selection) and/or email via an SMTP instance. "Send test notification" shows what arrived.

## Acknowledge and battery log

Under "Acknowledge and battery log" choose a device and an action: **I replaced it** (goes into the log with the date, 3 days of waiting until the device reports its new level), **Remind me later** or **Out of service** (no longer monitored, can be put back into service at any time, also under "Device settings"). The log also detects replacements by itself: the percentage rises by at least 25 points (adjustable) or the "low" flag is reset. If the flag flickers (e.g. with HomeMatic), "Detect battery replacements automatically" limits detection to the percentage jump or switches it off; for single devices or by group rule, use the column "Replacement detection" in the device settings.

## Supported systems

| System | Detection | State |
|---|---|---|
| Z-Wave, Symcon profiles `~Battery*` | profile, ident | tested on a real installation |
| CometWiFi, Shelly (percentage and voltage), Froggit (each sensor at the gateway is its own entry), Botvac | profile, ident | tested on a real installation |
| HomeMatic (`LOWBAT`, `LOW_BAT`, `OPERATING_VOLTAGE`, `UNREACH`) | ident | confirmed by forum feedback |
| Matter (`BatPercentRemaining`; from Symcon 9.1 also "Matter Composite Device" with `E0_PowerSource_…`, settings and log move over from the old instances; `BatReplacementNeeded`, replacement description) | ident | seen on an IKEA contact; battery voltage via Matter not yet seen |
| Netatmo (level 0 to 5 with profile) | profile, ident | confirmed by feedback |
| Blink (levels from a Symcon presentation) | presentation, ident | built from an exported variable, feedback pending |
| Zigbee2MQTT (`battery`, `battery_low`) | ident | **according to documentation, not tested with a real device** |

## Scripts

`BWACH_Search`, `BWACH_Check`, `BWACH_Preview`, `BWACH_SendTest`, `BWACH_UnretireAll`, `BWACH_ShoppingText`, `BWACH_SendShopping`, `BWACH_DeviceListCsv`, `BWACH_DeviceListJson(<InstanceID>, true|false)` (true = only action required; the evaluated devices as JSON for your own scripts and chart modules, including the effective thresholds and the forecast), `BWACH_Diagnosis`, `BWACH_AcknowledgePlace(<InstanceID>, '<location>')`, `BWACH_AddReplacement(<InstanceID>, '<key>', 'DD.MM.YYYY')`, `BWACH_Acknowledge(<InstanceID>, '<key>', 'getauscht'|'zurueckgestellt'|'ausser_betrieb')`, `BWACH_Unretire(<InstanceID>, '<key>')`, `BWACH_DeleteDetectedChangesOf(<InstanceID>, '<key>')` (delete automatically detected replacements of one device), `BWACH_UndoAcknowledge`, `BWACH_ResetFormLayout`. All return a text in the language of your Symcon. The action codes (`getauscht` …) stay German in every language.

## Test bench

```
php .tools/test-bwach.php        # test bench without Symcon
php .tools/mutation-bwach.php    # mutation tests
php .tools/check-standalone.php  # no unguarded calls into other modules
php .tools/check-locale.php      # every translated text is in locale.json
```

## License

PolyForm Noncommercial 1.0.0: free for private and non-commercial use, commercial use only with a separate license. See `LICENSE`.
