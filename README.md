# MySQL Query Tool (single-file PHP)

Minimal single-file PHP app to run MySQL queries, browse tables, and save favorite queries. ⚡

Files:
- `mysql_query_tool.php` — The app (edit DB creds at the top if you want static creds).
- `saved_queries.json` — Auto-created to store saved queries.

Quick start (requires PHP and access to the MySQL server):

1. Edit `mysql_query_tool.php` and replace the dummy DB credentials with your own (or use the login form in-app).
2. From the directory containing `mysql_query_tool.php`, run the PHP built-in server:

```bash
php -S localhost:8000
```

3. Open `http://localhost:8000/mysql_query_tool.php` in your browser.

Features ✨
- SQL editor with Ace autocompletion.
- Saved queries panel with update-in-place: when you load and edit a saved query, pressing Save overwrites that entry. Use a new name to save as new.
- Database selector updates the tables list via AJAX — no page reloads required.
- CSV export with configurable separator (, or ;).
 - Table view with pagination including First/Prev/Next/Last controls.
 - Table schema viewer: when viewing a table, click "Show Schema" to see DESCRIBE output and SHOW CREATE TABLE (toggleable).

Dark Mode Starfield 🌌
- Runs only in dark mode. Most stars drift along an oblique direction; a gentle global rotation makes it feel like a night sky.
- A subset of bluish stars (rgb(81, 147, 255)) drift in a different direction. Their count reflects the latest “row impact”: rows returned (SELECT) or affected (INSERT/UPDATE/DELETE), with sensible caps.
- Performance-friendly: total stars are capped and scale with viewport size.
- Tunables (in `mysql_query_tool.php`, search for `STARFIELD`): `maxStars`, `maxAltFraction`, `baseSpeed`, `altSpeed`, `speedJitter`, `obliqueAngleDeg`, `altOffsetDeg`, `verticalJitter`, `rotationSpeed`, `enableRotation`.

Security 🔒
- This tool is intentionally small and not hardened. Do not expose it to untrusted networks.
- Running arbitrary SQL can modify or delete data. Proceed with caution.

Session & Credentials 🔑
- Login form stores credentials in the PHP session (not on disk) for convenience.
- Credentials expire by default after 8 hours. Adjust `$sessionExpirySeconds` in `mysql_query_tool.php`.
- Logging out clears session-stored credentials and recent “row impact”.

License ✅
Public domain (use at your own risk).