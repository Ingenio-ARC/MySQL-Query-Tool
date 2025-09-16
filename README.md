# MySQL Query Tool (single-file PHP)

A minimal single-file PHP application to run MySQL queries and save them for later reuse.

Files:
- `mysql_query_tool.php` - The app. Edit DB credentials at the top of the file.
- `saved_queries.json` - Created automatically next to the PHP file to store saved queries.

Quick start (requires PHP and access to the MySQL server):

1. Edit `mysql_query_tool.php` and replace the dummy DB credentials with your own.
2. From the directory containing `mysql_query_tool.php`, run the PHP built-in server:

```bash
php -S localhost:8000
```

3. Open `http://localhost:8000/mysql_query_tool.php` in your browser.

Security notes:
- This tool is intentionally small and not hardened. Do not expose it to untrusted networks.
- Running arbitrary SQL can modify or delete data. Use caution.

Session login:
- The app now prompts for MySQL credentials in a small login form. Those credentials are stored in the PHP session (not on disk) so you don't have to re-enter them for each query.
- Credentials expire after 60 minutes by default. To change the expiry, edit the `$sessionExpirySeconds` variable in `mysql_query_tool.php`.
- When you log out the session-stored credentials are cleared.

License: Public domain (use at your own risk).